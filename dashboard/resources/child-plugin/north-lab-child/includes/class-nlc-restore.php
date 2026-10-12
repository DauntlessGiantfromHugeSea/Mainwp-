<?php
defined( 'ABSPATH' ) || exit;

/**
 * Eine Sicherung zurückspielen — die schreibende Seite.
 *
 * Das ist die einzige Stelle im ganzen Plugin, die in die Installation
 * schreibt und die Datenbank überschreibt. Alles andere liest nur. Deshalb:
 *
 * - Ein eigener Schalter, und der steht anders als alle übrigen
 *   standardmässig auf AUS. Wer zurückspielen will, schaltet es bewusst ein.
 * - Geschrieben wird erst in einen Ablageordner, dann an die richtige
 *   Stelle verschoben. Eine halb übertragene Datei ersetzt damit nie eine
 *   ganze.
 * - Jeder Pfad wird gegen das Wurzelverzeichnis geprüft, bevor überhaupt
 *   ein Ordner entsteht — nicht erst danach wie beim Lesen, wo realpath()
 *   die Arbeit machen kann.
 * - Die Datenbank wird in Schritten eingespielt, wie sie auch in Schritten
 *   exportiert wird. Ein Zeitlimit mitten im Import wäre sonst ein halb
 *   überschriebener Bestand.
 */
class NLC_Restore {

	/** Wo die ankommenden Teile liegen, bis sie vollständig sind. */
	const DIR = 'nlc-restore';

	/** So viel SQL wird je Schritt eingespielt. */
	const SQL_CHUNK = 262144;

	/** Merkt sich, wie weit der Datenbank-Import ist. */
	const OPT_SQL = 'nlc_restore_sql';

	/**
	 * Der Ablageordner. Liegt im Upload-Verzeichnis, nicht in wp-content
	 * selbst — sonst spielte sich eine Wiederherstellung von wp-content
	 * über die eigene Ablage.
	 *
	 * @param bool $create
	 * @return string|null
	 */
	public static function staging( $create = true ) {
		$uploads = wp_upload_dir();
		$basis   = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';

		if ( '' === $basis ) {
			return null;
		}

		$pfad = $basis . '/' . self::DIR;

		if ( ! is_dir( $pfad ) ) {
			if ( ! $create ) {
				return null;
			}

			wp_mkdir_p( $pfad );

			// Nichts davon gehoert ins Web. index.php fuer den Fall, dass
			// die .htaccess nicht greift.
			file_put_contents( $pfad . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
			file_put_contents( $pfad . '/index.php', "<?php\n// Nichts zu sehen.\n" );
		}

		return is_dir( $pfad ) ? $pfad : null;
	}

	/**
	 * Wohin eine Datei gehört — geprüft, bevor irgendetwas angelegt wird.
	 *
	 * Anders als beim Lesen kann realpath() hier nicht helfen: das Ziel
	 * existiert ja noch nicht. Also wird der Pfad selbst zerlegt und jedes
	 * ".." verworfen, statt darauf zu hoffen, dass das Dateisystem es
	 * schon richten wird.
	 *
	 * @param string $root     Schluessel aus NLC_Backup::roots().
	 * @param string $relative
	 * @return string|null
	 */
	public static function target( $root, $relative ) {
		$roots = NLC_Backup::roots();
		$key   = isset( $roots[ $root ] ) ? $root : 'content';
		$basis = realpath( $roots[ $key ] );

		if ( false === $basis ) {
			return null;
		}

		$relative = str_replace( '\\', '/', (string) $relative );

		if ( 'content' === $key && 0 === strpos( $relative, 'wp-content/' ) ) {
			$relative = substr( $relative, strlen( 'wp-content/' ) );
		}

		$relative = ltrim( $relative, '/' );

		if ( '' === $relative || false !== strpos( $relative, "\0" ) ) {
			return null;
		}

		$teile = array();

		foreach ( explode( '/', $relative ) as $stueck ) {
			if ( '' === $stueck || '.' === $stueck ) {
				continue;
			}

			// Kein Aufstieg. Nicht wegkuerzen, sondern abweisen — wer ".."
			// schickt, meint etwas anderes als das, was hier erlaubt ist.
			if ( '..' === $stueck ) {
				return null;
			}

			$teile[] = $stueck;
		}

		if ( ! $teile ) {
			return null;
		}

		// Kein Weg an der eigenen Absicherung vorbei: das Plugin darf sich
		// nicht selbst ueberschreiben, waehrend es laeuft.
		$pfad = implode( '/', $teile );

		if ( 0 === strpos( $pfad, 'plugins/north-lab-child/' ) || 'plugins/north-lab-child' === $pfad ) {
			return null;
		}

		return $basis . '/' . $pfad;
	}

	/**
	 * Ein Stück einer Datei entgegennehmen.
	 *
	 * @param string $root
	 * @param string $relative
	 * @param int    $offset
	 * @param string $base64
	 * @return array<string,mixed>|WP_Error
	 */
	public static function receive( $root, $relative, $offset, $base64 ) {
		$ziel = self::target( $root, $relative );

		if ( null === $ziel ) {
			return new WP_Error( 'nlc_restore_path', 'Dieser Pfad liegt ausserhalb des erlaubten Bereichs.', array( 'status' => 400 ) );
		}

		$ablage = self::staging();

		if ( null === $ablage ) {
			return new WP_Error( 'nlc_restore_dir', 'Der Ablageordner liess sich nicht anlegen.', array( 'status' => 500 ) );
		}

		$roh = base64_decode( (string) $base64, true );

		if ( false === $roh ) {
			return new WP_Error( 'nlc_restore_data', 'Der übertragene Teil ist beschädigt.', array( 'status' => 400 ) );
		}

		// Ein Name je Ziel, nicht je Übertragung: setzt der Lauf nach einem
		// Abbruch neu an, wird dieselbe Ablage weitergeschrieben.
		$ablageDatei = $ablage . '/' . hash( 'sha256', $root . '|' . $relative ) . '.part';

		$offset = max( 0, (int) $offset );

		if ( 0 === $offset && is_file( $ablageDatei ) ) {
			unlink( $ablageDatei );
		}

		$fh = fopen( $ablageDatei, 0 === $offset ? 'wb' : 'cb' );

		if ( ! $fh ) {
			return new WP_Error( 'nlc_restore_open', 'Die Ablage liess sich nicht beschreiben.', array( 'status' => 500 ) );
		}

		fseek( $fh, $offset );
		$geschrieben = fwrite( $fh, $roh );
		fclose( $fh );

		if ( false === $geschrieben ) {
			return new WP_Error( 'nlc_restore_write', 'Der Teil liess sich nicht ablegen.', array( 'status' => 500 ) );
		}

		return array( 'written' => (int) $geschrieben, 'size' => (int) filesize( $ablageDatei ) );
	}

	/**
	 * Eine vollständig übertragene Datei an ihren Platz legen.
	 *
	 * Erst jetzt wird etwas überschrieben — und nur, wenn die Prüfsumme
	 * stimmt. Eine abgebrochene Übertragung ersetzt damit nie eine Datei,
	 * die vorher in Ordnung war.
	 *
	 * @param string $root
	 * @param string $relative
	 * @param string $sha256
	 * @return array<string,mixed>|WP_Error
	 */
	public static function commit( $root, $relative, $sha256 ) {
		$ziel = self::target( $root, $relative );

		if ( null === $ziel ) {
			return new WP_Error( 'nlc_restore_path', 'Dieser Pfad liegt ausserhalb des erlaubten Bereichs.', array( 'status' => 400 ) );
		}

		$ablage      = self::staging( false );
		$ablageDatei = null === $ablage ? '' : $ablage . '/' . hash( 'sha256', $root . '|' . $relative ) . '.part';

		if ( '' === $ablageDatei || ! is_file( $ablageDatei ) ) {
			return new WP_Error( 'nlc_restore_missing', 'Zu dieser Datei wurde nichts übertragen.', array( 'status' => 400 ) );
		}

		$sha256 = strtolower( trim( (string) $sha256 ) );

		if ( ! preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
			unlink( $ablageDatei );

			return new WP_Error( 'nlc_restore_sum', 'Es wurde keine brauchbare Prüfsumme mitgeschickt.', array( 'status' => 400 ) );
		}

		if ( ! hash_equals( $sha256, (string) hash_file( 'sha256', $ablageDatei ) ) ) {
			unlink( $ablageDatei );

			return new WP_Error( 'nlc_restore_sum', 'Die übertragene Datei stimmt nicht mit der Prüfsumme überein.', array( 'status' => 409 ) );
		}

		$ordner = dirname( $ziel );

		if ( ! is_dir( $ordner ) && ! wp_mkdir_p( $ordner ) ) {
			return new WP_Error( 'nlc_restore_mkdir', 'Das Zielverzeichnis liess sich nicht anlegen.', array( 'status' => 500 ) );
		}

		// rename innerhalb desselben Dateisystems ist unteilbar: entweder
		// steht die neue Datei da oder die alte. Nie etwas dazwischen.
		if ( ! @rename( $ablageDatei, $ziel ) ) {
			if ( ! @copy( $ablageDatei, $ziel ) ) {
				return new WP_Error( 'nlc_restore_move', 'Die Datei liess sich nicht an ihren Platz legen.', array( 'status' => 500 ) );
			}

			unlink( $ablageDatei );
		}

		return array( 'placed' => true, 'bytes' => (int) filesize( $ziel ) );
	}

	/* ------------------------------------------------------- Datenbank */

	/**
	 * Den Datenbank-Import vorbereiten.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function start_database() {
		$ablage = self::staging();

		if ( null === $ablage ) {
			return new WP_Error( 'nlc_restore_dir', 'Der Ablageordner liess sich nicht anlegen.', array( 'status' => 500 ) );
		}

		$datei = $ablage . '/datenbank.sql';

		if ( is_file( $datei ) ) {
			unlink( $datei );
		}

		update_option( self::OPT_SQL, array( 'offset' => 0, 'statements' => 0, 'started' => time() ), false );

		return array( 'ready' => true );
	}

	/**
	 * Ein Stück des SQL-Exports entgegennehmen.
	 *
	 * @param int    $offset
	 * @param string $base64
	 * @return array<string,mixed>|WP_Error
	 */
	public static function receive_database( $offset, $base64 ) {
		$ablage = self::staging();

		if ( null === $ablage ) {
			return new WP_Error( 'nlc_restore_dir', 'Der Ablageordner liess sich nicht anlegen.', array( 'status' => 500 ) );
		}

		$roh = base64_decode( (string) $base64, true );

		if ( false === $roh ) {
			return new WP_Error( 'nlc_restore_data', 'Der übertragene Teil ist beschädigt.', array( 'status' => 400 ) );
		}

		$datei  = $ablage . '/datenbank.sql';
		$offset = max( 0, (int) $offset );

		$fh = fopen( $datei, 0 === $offset ? 'wb' : 'cb' );

		if ( ! $fh ) {
			return new WP_Error( 'nlc_restore_open', 'Die Ablage liess sich nicht beschreiben.', array( 'status' => 500 ) );
		}

		fseek( $fh, $offset );
		fwrite( $fh, $roh );
		fclose( $fh );

		return array( 'size' => (int) filesize( $datei ) );
	}

	/**
	 * Ein Stück des Exports wirklich einspielen.
	 *
	 * In Schritten, weil ein grosser Bestand jedes Zeitlimit sprengt. Der
	 * Stand steht in einer Option — bricht der Aufruf ab, geht es beim
	 * nächsten dort weiter, wo es aufgehört hat.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public static function step_database() {
		global $wpdb;

		$ablage = self::staging( false );
		$datei  = null === $ablage ? '' : $ablage . '/datenbank.sql';

		if ( '' === $datei || ! is_file( $datei ) ) {
			return new WP_Error( 'nlc_restore_missing', 'Es liegt kein Export zum Einspielen bereit.', array( 'status' => 400 ) );
		}

		$stand  = get_option( self::OPT_SQL, array() );
		$stand  = is_array( $stand ) ? $stand : array();
		$offset = max( 0, (int) ( $stand['offset'] ?? 0 ) );
		$gesamt = (int) filesize( $datei );

		if ( $offset >= $gesamt ) {
			return array( 'done' => true, 'offset' => $offset, 'total' => $gesamt, 'statements' => (int) ( $stand['statements'] ?? 0 ) );
		}

		$fh = fopen( $datei, 'rb' );

		if ( ! $fh ) {
			return new WP_Error( 'nlc_restore_open', 'Der Export liess sich nicht lesen.', array( 'status' => 500 ) );
		}

		fseek( $fh, $offset );

		$puffer  = '';
		$gelesen = 0;
		$anzahl  = (int) ( $stand['statements'] ?? 0 );

		// Zeilenweise, damit eine Anweisung nie in der Mitte zerschnitten
		// wird. Das ist der Grund, warum hier nicht einfach blockweise
		// gelesen wird.
		while ( ! feof( $fh ) && $gelesen < self::SQL_CHUNK ) {
			$zeile = fgets( $fh );

			if ( false === $zeile ) {
				break;
			}

			$gelesen += strlen( $zeile );
			$getrimmt = trim( $zeile );

			if ( '' === $getrimmt || 0 === strpos( $getrimmt, '--' ) || 0 === strpos( $getrimmt, '/*' ) ) {
				continue;
			}

			$puffer .= $zeile;

			if ( ';' === substr( $getrimmt, -1 ) ) {
				$wpdb->query( $puffer ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$puffer = '';
				$anzahl++;
			}
		}

		$neu = ftell( $fh );
		fclose( $fh );

		update_option(
			self::OPT_SQL,
			array( 'offset' => (int) $neu, 'statements' => $anzahl, 'started' => (int) ( $stand['started'] ?? time() ) ),
			false
		);

		return array(
			'done'       => (int) $neu >= $gesamt,
			'offset'     => (int) $neu,
			'total'      => $gesamt,
			'statements' => $anzahl,
		);
	}

	/* ------------------------------------------------------- Freigabe */

	/**
	 * Den Freigabecode beim Dashboard einlösen.
	 *
	 * Geprüft wird dort, nicht hier. Das ist der Punkt: die Kundenseite
	 * kennt weder den Code noch seinen Hash und kann ihn deshalb nicht in
	 * Ruhe durchprobieren — jeder Versuch geht über die Leitung und wird
	 * dort mitgezählt.
	 *
	 * @param string $code
	 * @return array<string,mixed>
	 */
	public static function redeem( $code ) {
		$verbindung = NLC_Options::connection();

		if ( ! $verbindung || empty( $verbindung['dashboard_url'] ) || empty( $verbindung['connection_id'] ) ) {
			return array( 'ok' => false, 'error' => 'Diese Website ist mit keinem Dashboard verbunden.' );
		}

		$code = preg_replace( '/\D/', '', (string) $code );

		if ( 6 !== strlen( (string) $code ) ) {
			return array( 'ok' => false, 'error' => 'Bitte die sechs Ziffern aus dem Code eingeben.' );
		}

		$antwort = wp_remote_post(
			rtrim( (string) $verbindung['dashboard_url'], '/' ) . '/api/child/restore/' . rawurlencode( (string) $verbindung['connection_id'] ),
			array(
				'timeout' => 30,
				'body'    => array( 'code' => $code ),
			)
		);

		if ( is_wp_error( $antwort ) ) {
			return array( 'ok' => false, 'error' => 'Das Dashboard war nicht erreichbar: ' . $antwort->get_error_message() );
		}

		$daten = json_decode( (string) wp_remote_retrieve_body( $antwort ), true );
		$daten = is_array( $daten ) ? $daten : array();

		if ( empty( $daten['ok'] ) ) {
			return array(
				'ok'    => false,
				'error' => (string) ( $daten['error'] ?? 'Der Code stimmt nicht oder ist abgelaufen.' ),
			);
		}

		return array( 'ok' => true, 'label' => (string) ( $daten['label'] ?? '' ) );
	}

	/**
	 * Läuft gerade etwas? Fragt das Dashboard, weil nur das es weiss.
	 *
	 * @return array<string,mixed>
	 */
	public static function remote_state() {
		$verbindung = NLC_Options::connection();

		if ( ! $verbindung || empty( $verbindung['dashboard_url'] ) || empty( $verbindung['connection_id'] ) ) {
			return array( 'running' => false );
		}

		$antwort = wp_remote_get(
			rtrim( (string) $verbindung['dashboard_url'], '/' ) . '/api/child/restore/' . rawurlencode( (string) $verbindung['connection_id'] ),
			array( 'timeout' => 15 )
		);

		if ( is_wp_error( $antwort ) ) {
			return array( 'running' => false );
		}

		$daten = json_decode( (string) wp_remote_retrieve_body( $antwort ), true );

		return is_array( $daten ) ? $daten : array( 'running' => false );
	}

	/**
	 * Hinter sich aufräumen.
	 *
	 * @return array<string,mixed>
	 */
	public static function cleanup() {
		$ablage = self::staging( false );
		$weg    = 0;

		if ( null !== $ablage ) {
			foreach ( (array) glob( $ablage . '/*' ) as $datei ) {
				if ( is_file( $datei ) && ! in_array( basename( (string) $datei ), array( '.htaccess', 'index.php' ), true ) ) {
					unlink( (string) $datei );
					$weg++;
				}
			}
		}

		delete_option( self::OPT_SQL );

		// Was im Cache liegt, stammt vom Stand vor dem Zurueckspielen.
		if ( class_exists( 'NLC_Cache' ) ) {
			NLC_Cache::flush();
		}

		return array( 'removed' => $weg );
	}
}
