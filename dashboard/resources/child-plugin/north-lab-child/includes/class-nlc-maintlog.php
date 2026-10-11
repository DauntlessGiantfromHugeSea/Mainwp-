<?php
defined( 'ABSPATH' ) || exit;

/**
 * Was die Agentur an dieser Seite gewartet hat — und wann.
 *
 * Dieselbe Bauart wie NLC_Backuplog: das Panel ist die Quelle, die
 * Kundenseite haelt nur eine Kopie zum Anzeigen. Deshalb ersetzen statt
 * ergaenzen — Zusammenfuehren erzeugte doppelte Zeilen, sobald eine Antwort
 * einmal unterwegs verloren ging.
 *
 * Der Kunde sieht damit in seinem eigenen Backend, dass die Seite betreut
 * wird, ohne dass er dafuer irgendwo anders hinmuss. Das ist der Zweck: nicht
 * noch ein Bericht, sondern eine Antwort an der Stelle, an der die Frage
 * aufkommt.
 */
class NLC_Maintlog {

	/** Hier steht der Verlauf. */
	const OPT_LOG = 'nlc_maint_log';

	/** Und hier, was sonst noch zur Betreuung gehoert (SSL, offene Updates). */
	const OPT_STAND = 'nlc_maint_stand';

	/** Mehr als das braucht auf einer Kundenseite niemand. */
	const MAX = 20;

	/**
	 * Den Verlauf ersetzen.
	 *
	 * @param array<int,array<string,mixed>> $eintraege Neueste zuerst.
	 * @return array<int,array<string,mixed>>
	 */
	public static function replace( array $eintraege ) {
		$log = array();

		foreach ( $eintraege as $eintrag ) {
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
	 * Alles, was von aussen kommt, wird hier beschnitten — auch wenn es vom
	 * eigenen Panel kommt und signiert ist. Eine Anzeige, die darauf baut,
	 * dass die Gegenseite schon das Richtige schickt, ist keine Anzeige.
	 *
	 * @param array<string,mixed> $daten
	 * @return array<string,mixed>
	 */
	protected static function sanitize( array $daten ) {
		$at    = trim( (string) ( $daten['at'] ?? '' ) );
		$teile = array();

		foreach ( (array) ( $daten['items'] ?? array() ) as $eintrag ) {
			if ( ! is_array( $eintrag ) ) {
				continue;
			}

			$typ = sanitize_key( (string) ( $eintrag['type'] ?? '' ) );

			$teile[] = array(
				'type'  => in_array( $typ, array( 'core', 'plugin', 'theme', 'translation' ), true ) ? $typ : 'plugin',
				'name'  => sanitize_text_field( (string) ( $eintrag['name'] ?? $eintrag['slug'] ?? '' ) ),
				'from'  => self::version( $eintrag['from'] ?? '' ),
				'to'    => self::version( $eintrag['to'] ?? '' ),
			);

			if ( count( $teile ) >= 50 ) {
				break;
			}
		}

		return array(
			'at'     => '' !== $at ? $at : gmdate( 'c' ),
			'kind'   => 'backup' === sanitize_key( (string) ( $daten['kind'] ?? 'update' ) ) ? 'backup' : 'update',
			'ok'     => ! empty( $daten['ok'] ),
			'count'  => max( 0, (int) ( $daten['count'] ?? count( $teile ) ) ),
			'failed' => max( 0, (int) ( $daten['failed'] ?? 0 ) ),
			'note'   => sanitize_text_field( (string) ( $daten['note'] ?? '' ) ),
			'items'  => $teile,
		);
	}

	/**
	 * Eine Versionsangabe — Ziffern, Punkte, Bindestriche, sonst nichts.
	 *
	 * @param mixed $wert
	 * @return string
	 */
	protected static function version( $wert ) {
		$wert = preg_replace( '/[^0-9A-Za-z.\-+]/', '', (string) $wert );

		return substr( (string) $wert, 0, 24 );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function entries() {
		$log = get_option( self::OPT_LOG, array() );

		return is_array( $log ) ? array_values( array_filter( $log, 'is_array' ) ) : array();
	}

	/**
	 * Der letzte Eintrag — oder null.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function latest() {
		$log = self::entries();

		return $log ? $log[0] : null;
	}

	/**
	 * Den Betreuungsstand setzen: was das Panel weiss, die Seite aber nicht.
	 *
	 * Die Restlaufzeit des Zertifikats etwa kennt nur das Panel, weil sie aus
	 * der Ueberwachung kommt.
	 *
	 * @param array<string,mixed> $daten
	 * @return array<string,mixed>
	 */
	public static function set_state( array $daten ) {
		$stand = array(
			'at'       => gmdate( 'c' ),
			'ssl_days' => isset( $daten['ssl_days'] ) && is_numeric( $daten['ssl_days'] ) ? (int) $daten['ssl_days'] : null,
			'ssl_until'=> sanitize_text_field( (string) ( $daten['ssl_until'] ?? '' ) ),
			'pending'  => max( 0, (int) ( $daten['pending'] ?? 0 ) ),
			'backup_at'=> sanitize_text_field( (string) ( $daten['backup_at'] ?? '' ) ),
			'contact'  => sanitize_text_field( (string) ( $daten['contact'] ?? '' ) ),
		);

		update_option( self::OPT_STAND, $stand, false );

		return $stand;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function state() {
		$stand = get_option( self::OPT_STAND, array() );

		return is_array( $stand ) ? $stand : array();
	}

	/**
	 * Wie viele Pakete im letzten Lauf — als Satz.
	 *
	 * @param array<string,mixed> $eintrag
	 * @return string
	 */
	public static function summary( array $eintrag ) {
		$anzahl = (int) ( $eintrag['count'] ?? 0 );

		if ( empty( $eintrag['ok'] ) ) {
			return 'Wartung mit Fehler' . ( '' !== (string) $eintrag['note'] ? ': ' . $eintrag['note'] : '' );
		}

		if ( 0 === $anzahl ) {
			return 'Geprüft, nichts einzuspielen';
		}

		return sprintf( 1 === $anzahl ? '%d Aktualisierung eingespielt' : '%d Aktualisierungen eingespielt', $anzahl );
	}

	/** Die Namen eines Eintrags als lesbare Aufzaehlung. */
	public static function names( array $eintrag, $max = 6 ) {
		$namen = array();

		foreach ( (array) ( $eintrag['items'] ?? array() ) as $teil ) {
			$name = trim( (string) ( $teil['name'] ?? '' ) );

			if ( '' !== $name ) {
				$namen[] = $name . ( '' !== (string) $teil['to'] ? ' ' . $teil['to'] : '' );
			}
		}

		if ( ! $namen ) {
			return '';
		}

		$rest  = count( $namen ) - $max;
		$namen = array_slice( $namen, 0, $max );

		return implode( ', ', $namen ) . ( $rest > 0 ? sprintf( ' und %d weitere', $rest ) : '' );
	}
}
