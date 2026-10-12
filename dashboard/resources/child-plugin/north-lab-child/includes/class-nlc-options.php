<?php
defined( 'ABSPATH' ) || exit;

/**
 * Zentraler Zugriff auf die Optionen des Child-Plugins.
 */
class NLC_Options {

	const OPT_CONNECTION   = 'nlc_connection';   // array: connection_id, public_key, dashboard_url, dashboard_name, connected_at
	const OPT_CONNECT_CODE = 'nlc_connect_code'; // array: hash, expires

	/** Merkt kurz, welche Verbindung zuletzt zustande kam — fuer ehrliche Wiederholungen. */
	const LAST_CONNECT     = 'nlc_last_connect';
	const LAST_CONNECT_TTL = 600;
	const OPT_SETTINGS     = 'nlc_settings';
	const OPT_LAST_CONTACT = 'nlc_last_contact';

	/** Fassung, auf die der letzte Aufraeum-Lauf die Optionen gebracht hat. */
	const OPT_UPGRADED     = 'nlc_upgraded';

	/**
	 * Welche Nachzieh-Schritte zwischen zwei Fassungen anfallen.
	 *
	 * Eigene Methode, weil daran das Heikle haengt: ein Schritt darf genau
	 * einmal greifen. Greift er zweimal, stellt er zurueck, was jemand
	 * danach bewusst anders eingestellt hat.
	 *
	 * @param string $from Fassung, auf der die Optionen stehen.
	 * @param string $to   Fassung, die jetzt laeuft.
	 * @return array<int,string>
	 */
	public static function upgrade_steps( $from, $to ) {
		$schritte = array();

		// Jeder Schritt traegt seine eigene Fassung — und zwar beidseitig:
		// er faellt an, wenn die Optionen darunter stehen und die laufende
		// Fassung ihn schon kennt. Ein gemeinsamer Waechter davor waere
		// bequemer und zugleich die Stelle, an der ein spaeterer Schritt
		// still uebersprungen wird.
		//
		// 1.9.0: Das Zurueckspielen gehoert zur Betreuung und nicht an einen
		// Haken, den im Ernstfall erst jemand suchen muss.
		if ( version_compare( (string) $from, '1.9.0', '<' ) && version_compare( (string) $to, '1.9.0', '>=' ) ) {
			$schritte[] = 'allow_restore';
		}

		return $schritte;
	}

	/**
	 * Gespeicherte Optionen auf den Stand der laufenden Fassung bringen.
	 *
	 * Laeuft bei jedem Aufruf an, tut aber nur nach einem Update etwas: ein
	 * Plugin-Update loest den Aktivierungs-Haken nicht aus, ein geaenderter
	 * Standardwert erreicht also von allein keine Installation, in der die
	 * Optionen schon einmal gespeichert wurden.
	 */
	public static function upgrade() {
		$from = (string) get_option( self::OPT_UPGRADED, '0' );

		// Der Normalfall: nichts zu tun. Steht hier nur, damit nicht jeder
		// Seitenaufruf eine Option schreibt — entschieden wird in
		// upgrade_steps().
		if ( version_compare( $from, NLC_VERSION, '>=' ) ) {
			return;
		}

		$schritte = self::upgrade_steps( $from, NLC_VERSION );
		$stored   = get_option( self::OPT_SETTINGS, null );

		// Eine frische Installation hat noch gar keine Optionen — die legt
		// bootstrap_defaults() an, und zwar schon mit dem neuen Stand.
		if ( is_array( $stored ) && in_array( 'allow_restore', $schritte, true ) ) {
			$stored['allow_restore'] = true;
			update_option( self::OPT_SETTINGS, $stored );
		}

		update_option( self::OPT_UPGRADED, NLC_VERSION, false );
	}

	/**
	 * Standardwerte anlegen, ohne bestehende zu überschreiben.
	 */
	public static function bootstrap_defaults() {
		if ( false === get_option( self::OPT_SETTINGS, false ) ) {
			add_option( self::OPT_SETTINGS, self::default_settings() );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function default_settings() {
		return array(
			'allow_updates'     => true,
			'allow_install'     => true,
			'allow_user_mgmt'   => true,
			'allow_maintenance' => true,
			'allow_content'     => true,
			'allow_backup'      => true,
			'allow_autologin'   => true,
			'allow_mmode'       => true,
			'allow_self_update' => true,
			'allow_branding'    => true,
			'allow_links'       => true,
			'allow_maintlog'    => true,
			// Steht an, weil die eigentliche Sperre davor sitzt: ohne den
			// Freigabecode aus dem Panel faengt kein Zurueckspielen an. Ein
			// zweiter Haken davor haette nur bedeutet, dass im Ernstfall
			// erst jemand diesen Haken suchen muss.
			'allow_restore'     => true,
			'ip_allowlist'      => '',
			'require_ssl'       => false,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function settings() {
		$stored = get_option( self::OPT_SETTINGS, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), self::default_settings() );
	}

	/**
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public static function setting( $key, $default = null ) {
		$settings = self::settings();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * @param array<string,mixed> $values
	 */
	public static function save_settings( array $values ) {
		update_option( self::OPT_SETTINGS, wp_parse_args( $values, self::default_settings() ) );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function connection() {
		$conn = get_option( self::OPT_CONNECTION, null );
		return is_array( $conn ) && ! empty( $conn['public_key'] ) ? $conn : null;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function save_connection( array $data ) {
		update_option( self::OPT_CONNECTION, $data, false );
	}

	public static function clear_connection() {
		delete_option( self::OPT_CONNECTION );
		delete_option( self::OPT_LAST_CONTACT );
	}

	public static function is_connected() {
		return null !== self::connection();
	}

	/**
	 * Erzeugt einen einmaligen Verbindungscode (1 Stunde gültig).
	 * Gespeichert wird nur der Hash.
	 *
	 * @return string Klartext-Code für die Anzeige im Admin.
	 */
	public static function generate_connect_code() {
		$code = strtoupper( bin2hex( random_bytes( 16 ) ) );
		update_option(
			self::OPT_CONNECT_CODE,
			array(
				'hash'    => wp_hash_password( $code ),
				'expires' => time() + HOUR_IN_SECONDS,
			),
			false
		);
		return $code;
	}

	/**
	 * Schreibweise vereinheitlichen.
	 *
	 * Beim Kopieren rutscht leicht ein Leerzeichen oder Zeilenumbruch mit; der
	 * Code besteht ohnehin nur aus Grossbuchstaben und Ziffern.
	 *
	 * @param string $code
	 * @return string
	 */
	public static function normalize_code( $code ) {
		return strtoupper( (string) preg_replace( '/\s+/', '', (string) $code ) );
	}

	/**
	 * Warum ein Code nicht angenommen wird — oder null, wenn alles passt.
	 *
	 * Getrennt vom Verbrauchen, damit erst alles geprueft werden kann und der
	 * Code nicht schon weg ist, wenn danach noch etwas schiefgeht.
	 *
	 * @param string $code
	 * @return string|null
	 */
	public static function check_connect_code( $code ) {
		$stored = get_option( self::OPT_CONNECT_CODE, null );

		if ( ! is_array( $stored ) || empty( $stored['hash'] ) ) {
			return 'Auf dieser Seite liegt kein Verbindungscode bereit. Er wurde entweder nie erzeugt '
				. 'oder bereits benutzt. Bitte dort unter Einstellungen → NorthLab einen neuen erzeugen.';
		}

		if ( empty( $stored['expires'] ) || $stored['expires'] < time() ) {
			delete_option( self::OPT_CONNECT_CODE );
			return 'Der Verbindungscode ist abgelaufen — er gilt 60 Minuten. Bitte einen neuen erzeugen.';
		}

		if ( ! wp_check_password( self::normalize_code( $code ), $stored['hash'] ) ) {
			return 'Der Verbindungscode stimmt nicht mit dem überein, der auf dieser Seite erzeugt wurde.';
		}

		return null;
	}

	/**
	 * Prüft und verbraucht einen Verbindungscode.
	 *
	 * @param string $code
	 * @return bool
	 */
	public static function consume_connect_code( $code ) {
		if ( null !== self::check_connect_code( $code ) ) {
			return false;
		}

		delete_option( self::OPT_CONNECT_CODE );

		return true;
	}

	/**
	 * Haelt fest, welche Verbindung gerade zustande kam.
	 *
	 * @param string $code
	 * @param string $connection_id
	 * @param string $public_key
	 */
	public static function remember_connect( $code, $connection_id, $public_key ) {
		set_transient(
			self::LAST_CONNECT,
			array(
				'code' => hash( 'sha256', self::normalize_code( $code ) ),
				'id'   => (string) $connection_id,
				'key'  => hash( 'sha256', (string) $public_key ),
			),
			self::LAST_CONNECT_TTL
		);
	}

	/**
	 * War genau diese Anfrage eben schon einmal erfolgreich?
	 *
	 * Die Antwort auf die erste Anfrage geht manchmal unterwegs verloren — etwa
	 * weil das Panel vorher in eine Zeitueberschreitung laeuft. Der Code ist dann
	 * verbraucht, und ein zweiter Versuch mit demselben Code waere "ungueltig",
	 * obwohl die Verbindung laengst steht. Stimmen Code, Kennung und Schluessel
	 * ueberein, ist es dieselbe Anfrage und darf denselben Erfolg melden.
	 *
	 * @param string $code
	 * @param string $connection_id
	 * @param string $public_key
	 * @return bool
	 */
	public static function was_just_connected( $code, $connection_id, $public_key ) {
		$last = get_transient( self::LAST_CONNECT );

		if ( ! is_array( $last ) || empty( $last['code'] ) ) {
			return false;
		}

		$connection = self::connection();

		if ( ! $connection || ! hash_equals( (string) $connection['connection_id'], (string) $connection_id ) ) {
			return false;
		}

		return hash_equals( (string) $last['code'], hash( 'sha256', self::normalize_code( $code ) ) )
			&& hash_equals( (string) $last['id'], (string) $connection_id )
			&& hash_equals( (string) $last['key'], hash( 'sha256', (string) $public_key ) );
	}

	public static function touch_last_contact() {
		update_option( self::OPT_LAST_CONTACT, time(), false );
	}
}
