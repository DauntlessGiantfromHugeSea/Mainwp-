<?php
defined( 'ABSPATH' ) || exit;

/**
 * Zentraler Zugriff auf die Optionen des Child-Plugins.
 */
class NLC_Options {

	const OPT_CONNECTION   = 'nlc_connection';   // array: connection_id, public_key, dashboard_url, dashboard_name, connected_at
	const OPT_CONNECT_CODE = 'nlc_connect_code'; // array: hash, expires
	const OPT_SETTINGS     = 'nlc_settings';
	const OPT_LAST_CONTACT = 'nlc_last_contact';

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
	 * Prüft und verbraucht einen Verbindungscode.
	 *
	 * @param string $code
	 * @return bool
	 */
	public static function consume_connect_code( $code ) {
		$stored = get_option( self::OPT_CONNECT_CODE, null );
		if ( ! is_array( $stored ) || empty( $stored['hash'] ) ) {
			return false;
		}
		if ( empty( $stored['expires'] ) || $stored['expires'] < time() ) {
			delete_option( self::OPT_CONNECT_CODE );
			return false;
		}
		if ( ! wp_check_password( (string) $code, $stored['hash'] ) ) {
			return false;
		}
		delete_option( self::OPT_CONNECT_CODE );
		return true;
	}

	public static function touch_last_contact() {
		update_option( self::OPT_LAST_CONTACT, time(), false );
	}
}
