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
		// Ein frisch gemeldeter Lauf ist immer jetzt — eine mitgeschickte
		// Zeitangabe gilt nur beim Nachreichen der Vorgeschichte.
		unset( $daten['at'] );

		$log = array_merge( array( self::sanitize( $daten ) ), self::entries() );
		$log = array_slice( $log, 0, self::MAX );

		update_option( self::OPT_LOG, $log, false );

		// Ein abgeschlossener Lauf beendet die Ankuendigung — sonst haengt das
		// Banner bis zum Ablauf der Frist auf der Seite.
		self::stop();

		return $log;
	}

	/**
	 * Die ganze Vorgeschichte auf einmal uebernehmen.
	 *
	 * Das Panel kennt alle Laeufe, diese Seite nur die, die ihr seit dem
	 * Einbau gemeldet wurden. Ohne diesen Weg stuende hier "noch keine
	 * Sicherung", obwohl seit Wochen gesichert wird.
	 *
	 * Ersetzt bewusst statt zu ergaenzen: das Panel ist die Quelle, und
	 * Zusammenfuehren erzeugte doppelte Zeilen.
	 *
	 * @param array<int,array<string,mixed>> $laeufe Neueste zuerst.
	 * @return array<int,array<string,mixed>>
	 */
	public static function replace( array $laeufe ) {
		$log = array();

		foreach ( $laeufe as $eintrag ) {
			if ( ! is_array( $eintrag ) ) {
				continue;
			}

			$log[] = self::sanitize( $eintrag );

			if ( count( $log ) >= self::MAX ) {
				break;
			}
		}

		update_option( self::OPT_LOG, $log, false );

		return $log;
	}

	/**
	 * Einen Eintrag auf das bringen, was hier angezeigt wird.
	 *
	 * @param array<string,mixed> $daten
	 * @return array<string,mixed>
	 */
	protected static function sanitize( array $daten ) {
		$status = isset( $daten['status'] ) ? sanitize_key( (string) $daten['status'] ) : 'ok';
		$at     = isset( $daten['at'] ) ? trim( (string) $daten['at'] ) : '';

		return array(
			'at'       => '' !== $at ? $at : gmdate( 'c' ),
			'status'   => in_array( $status, array( 'ok', 'failed' ), true ) ? $status : 'ok',
			'bytes'    => max( 0, (int) ( $daten['bytes'] ?? 0 ) ),
			'files'    => max( 0, (int) ( $daten['files'] ?? 0 ) ),
			'seconds'  => max( 0, (int) ( $daten['seconds'] ?? 0 ) ),
			'snapshot' => substr( (string) preg_replace( '/[^a-f0-9]/i', '', (string) ( $daten['snapshot'] ?? '' ) ), 0, 64 ),
			'message'  => sanitize_text_field( (string) ( $daten['message'] ?? '' ) ),
		);
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
	public static function start( $seconds = 600, $label = '', $fortschritt = array() ) {
		$seconds = min( self::MAX_LEASE, max( 60, (int) $seconds ) );
		$bisher  = self::running();

		$fortschritt = is_array( $fortschritt ) ? $fortschritt : array();
		$done        = max( 0, (int) ( isset( $fortschritt['done'] ) ? $fortschritt['done'] : 0 ) );
		$total       = max( 0, (int) ( isset( $fortschritt['total'] ) ? $fortschritt['total'] : 0 ) );

		$state = array(
			'until'   => time() + $seconds,
			'started' => $bisher ? (int) $bisher['started'] : time(),
			'label'   => sanitize_text_field( (string) $label ),
			'phase'   => sanitize_text_field( (string) ( isset( $fortschritt['phase'] ) ? $fortschritt['phase'] : '' ) ),
			// Mehr erledigt als insgesamt gibt es nicht. Ohne die Klammer
			// stuende auf der Kundenseite irgendwann "1200 von 900".
			'done'    => $total > 0 ? min( $done, $total ) : $done,
			'total'   => $total,
			'at'      => time(),
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

		$done  = max( 0, (int) ( $state['done'] ?? 0 ) );
		$total = max( 0, (int) ( $state['total'] ?? 0 ) );

		return array(
			'until'   => (int) $state['until'],
			'started' => (int) ( $state['started'] ?? time() ),
			'label'   => (string) ( $state['label'] ?? '' ),
			'phase'   => (string) ( $state['phase'] ?? '' ),
			'done'    => $total > 0 ? min( $done, $total ) : $done,
			'total'   => $total,
			'at'      => (int) ( $state['at'] ?? $state['started'] ?? time() ),
			'percent' => $total > 0 ? (int) floor( min( $done, $total ) / $total * 100 ) : null,
		);
	}

	/**
	 * Der Fortschritt in einem Satz — oder leer, wenn es nichts zu sagen gibt.
	 *
	 * @param array<string,mixed> $running
	 * @return string
	 */
	public static function progress_text( array $running ) {
		$phase = trim( (string) ( $running['phase'] ?? '' ) );
		$total = (int) ( $running['total'] ?? 0 );
		$done  = (int) ( $running['done'] ?? 0 );

		if ( $total > 0 ) {
			$zahlen = sprintf(
				'%s von %s (%d %%)',
				number_format_i18n( min( $done, $total ) ),
				number_format_i18n( $total ),
				(int) floor( min( $done, $total ) / $total * 100 )
			);

			return '' !== $phase ? $phase . ': ' . $zahlen : $zahlen;
		}

		return $phase;
	}

	/**
	 * Wie alt die letzte Meldung ist.
	 *
	 * Das Panel meldet sich regelmaessig. Bleibt das lange aus, laeuft der
	 * Vorgang vielleicht noch, aber die Zahlen stimmen nicht mehr — das
	 * gehoert dazugesagt, statt einen alten Stand als aktuell auszugeben.
	 *
	 * @param array<string,mixed> $running
	 * @return bool
	 */
	public static function stale( array $running ) {
		return ( time() - (int) ( $running['at'] ?? 0 ) ) > 600;
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
