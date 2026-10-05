<?php
defined( 'ABSPATH' ) || exit;

/**
 * Was der Kunde von seinen Sicherungen sieht.
 *
 * Die Sicherung selbst laeuft im Panel — diese Seite weiss davon nichts. Das
 * Panel meldet nach jedem Lauf kurz, was herauskam; hier wird es aufbewahrt
 * und angezeigt. Dazu der Hinweis waehrend eines laufenden Laufs.
 */
class NLC_Backuplog {

	/** Letzte Laeufe, neueste zuerst. */
	const OPT_LOG = 'nlc_backup_log';

	/** Laeuft gerade eine Sicherung? array( until, started, label ) */
	const OPT_RUNNING = 'nlc_backup_running';

	/** Mehr braucht niemand, und die Option soll klein bleiben. */
	const MAX = 20;

	/** Laenger als das haelt eine Ankuendigung nie ohne neues Lebenszeichen. */
	const MAX_LEASE = 1800;

	/**
	 * Einen abgeschlossenen Lauf vermerken.
	 *
	 * @param array<string,mixed> $daten
	 * @return array<int,array<string,mixed>>
	 */
	public static function record( array $daten ) {
		$status = isset( $daten['status'] ) ? sanitize_key( (string) $daten['status'] ) : 'ok';

		$eintrag = array(
			'at'       => gmdate( 'c' ),
			'status'   => in_array( $status, array( 'ok', 'failed' ), true ) ? $status : 'ok',
			'bytes'    => max( 0, (int) ( $daten['bytes'] ?? 0 ) ),
			'files'    => max( 0, (int) ( $daten['files'] ?? 0 ) ),
			'seconds'  => max( 0, (int) ( $daten['seconds'] ?? 0 ) ),
			'snapshot' => substr( preg_replace( '/[^a-f0-9]/i', '', (string) ( $daten['snapshot'] ?? '' ) ), 0, 64 ),
			'message'  => sanitize_text_field( (string) ( $daten['message'] ?? '' ) ),
		);

		$log = array_merge( array( $eintrag ), self::entries() );
		$log = array_slice( $log, 0, self::MAX );

		update_option( self::OPT_LOG, $log, false );

		// Ein abgeschlossener Lauf beendet die Ankuendigung — sonst haengt das
		// Banner bis zum Ablauf der Frist auf der Seite.
		self::stop();

		return $log;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function entries() {
		$log = get_option( self::OPT_LOG, array() );

		return is_array( $log ) ? array_values( array_filter( $log, 'is_array' ) ) : array();
	}

	/**
	 * Der letzte Lauf — oder null, wenn es noch keinen gab.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function latest() {
		$log = self::entries();

		return $log ? $log[0] : null;
	}

	/**
	 * Eine laufende Sicherung ankuendigen.
	 *
	 * Bewusst mit Ablaufzeit statt als Dauerzustand: stirbt das Panel mitten im
	 * Lauf, verschwindet der Hinweis von selbst. Ein Banner, das nur ein
	 * "fertig" vom Panel wieder loswird, haengt sonst ewig auf der Kundenseite.
	 *
	 * @param int    $seconds Wie lange die Ankuendigung ohne neues Lebenszeichen gilt.
	 * @param string $label
	 * @return array<string,mixed>
	 */
	public static function start( $seconds = 600, $label = '' ) {
		$seconds = min( self::MAX_LEASE, max( 60, (int) $seconds ) );
		$bisher  = self::running();

		$state = array(
			'until'   => time() + $seconds,
			'started' => $bisher ? (int) $bisher['started'] : time(),
			'label'   => sanitize_text_field( (string) $label ),
		);

		update_option( self::OPT_RUNNING, $state, true );

		return $state;
	}

	public static function stop() {
		delete_option( self::OPT_RUNNING );
	}

	/**
	 * Laeuft gerade eine Sicherung? Abgelaufene Ankuendigungen zaehlen nicht.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function running() {
		$state = get_option( self::OPT_RUNNING, null );

		if ( ! is_array( $state ) || empty( $state['until'] ) ) {
			return null;
		}

		if ( (int) $state['until'] <= time() ) {
			delete_option( self::OPT_RUNNING );
			return null;
		}

		return array(
			'until'   => (int) $state['until'],
			'started' => (int) ( $state['started'] ?? time() ),
			'label'   => (string) ( $state['label'] ?? '' ),
		);
	}

	/**
	 * Groesse lesbar machen.
	 *
	 * @param int $bytes
	 * @return string
	 */
	public static function size( $bytes ) {
		$bytes = (int) $bytes;

		if ( $bytes <= 0 ) {
			return '—';
		}

		$einheiten = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$i         = (int) floor( log( $bytes, 1024 ) );
		$i         = min( $i, count( $einheiten ) - 1 );

		return number_format_i18n( $bytes / pow( 1024, $i ), $i > 1 ? 1 : 0 ) . ' ' . $einheiten[ $i ];
	}

	/**
	 * Dauer lesbar machen.
	 *
	 * @param int $seconds
	 * @return string
	 */
	public static function duration( $seconds ) {
		$seconds = max( 0, (int) $seconds );

		if ( $seconds < 60 ) {
			return $seconds . ' s';
		}

		$minuten = (int) floor( $seconds / 60 );

		if ( $minuten < 60 ) {
			return $minuten . ' min';
		}

		return sprintf( '%d h %02d min', (int) floor( $minuten / 60 ), $minuten % 60 );
	}
}
