<?php
defined( 'ABSPATH' ) || exit;

/**
 * Signaturprüfung für alle Dashboard-Requests.
 *
 * Signiert wird (RSA-SHA256, privater Schlüssel liegt ausschließlich im Dashboard):
 *   connection_id . "\n" . timestamp . "\n" . nonce . "\n" . METHOD . "\n" . route . "\n" . sha256(body)
 */
class NLC_Auth {

	const HEADER_CONNECTION = 'x-nl-connection';
	const HEADER_TIMESTAMP  = 'x-nl-timestamp';
	const HEADER_NONCE      = 'x-nl-nonce';
	const HEADER_SIGNATURE  = 'x-nl-signature';

	const MAX_SKEW      = 300; // Sekunden
	const NONCE_TTL     = 900; // Sekunden
	const NONCE_PREFIX  = 'nlc_nonce_';

	/**
	 * permission_callback für alle geschützten Routen.
	 *
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public static function verify( WP_REST_Request $request ) {
		if ( ! function_exists( 'openssl_verify' ) ) {
			return new WP_Error( 'nlc_no_openssl', 'Auf dieser Seite fehlt die OpenSSL-Erweiterung von PHP.', array( 'status' => 501 ) );
		}

		$connection = NLC_Options::connection();
		if ( ! $connection ) {
			return new WP_Error( 'nlc_not_connected', 'Diese Seite ist mit keinem Dashboard verbunden.', array( 'status' => 403 ) );
		}

		$ip_error = self::check_ip_allowlist();
		if ( is_wp_error( $ip_error ) ) {
			return $ip_error;
		}

		if ( NLC_Options::setting( 'require_ssl' ) && ! is_ssl() ) {
			return new WP_Error( 'nlc_ssl_required', 'Nur verschlüsselte Verbindungen sind erlaubt.', array( 'status' => 403 ) );
		}

		$conn_id   = (string) $request->get_header( self::HEADER_CONNECTION );
		$timestamp = (string) $request->get_header( self::HEADER_TIMESTAMP );
		$nonce     = (string) $request->get_header( self::HEADER_NONCE );
		$signature = (string) $request->get_header( self::HEADER_SIGNATURE );

		if ( '' === $conn_id || '' === $timestamp || '' === $nonce || '' === $signature ) {
			return new WP_Error( 'nlc_missing_headers', 'Signatur-Header fehlen.', array( 'status' => 401 ) );
		}

		if ( ! hash_equals( (string) $connection['connection_id'], $conn_id ) ) {
			return new WP_Error( 'nlc_unknown_connection', 'Unbekannte Verbindung.', array( 'status' => 403 ) );
		}

		if ( abs( time() - (int) $timestamp ) > self::MAX_SKEW ) {
			return new WP_Error( 'nlc_stale_request', 'Zeitstempel außerhalb des erlaubten Fensters.', array( 'status' => 401 ) );
		}

		$nonce_key = self::NONCE_PREFIX . md5( $conn_id . '|' . $nonce );
		if ( false !== get_transient( $nonce_key ) ) {
			return new WP_Error( 'nlc_replay', 'Request wurde bereits verarbeitet.', array( 'status' => 409 ) );
		}

		$payload = self::canonical_payload( $request, $conn_id, $timestamp, $nonce );
		$binary  = base64_decode( $signature, true );

		if ( false === $binary ) {
			return new WP_Error( 'nlc_bad_signature', 'Signatur ist nicht base64-kodiert.', array( 'status' => 401 ) );
		}

		$key = openssl_pkey_get_public( $connection['public_key'] );
		if ( false === $key ) {
			return new WP_Error( 'nlc_bad_key', 'Hinterlegter öffentlicher Schlüssel ist ungültig.', array( 'status' => 500 ) );
		}

		$ok = openssl_verify( $payload, $binary, $key, OPENSSL_ALGO_SHA256 );
		if ( 1 !== $ok ) {
			return new WP_Error( 'nlc_bad_signature', 'Signatur konnte nicht verifiziert werden.', array( 'status' => 401 ) );
		}

		set_transient( $nonce_key, 1, self::NONCE_TTL );
		NLC_Options::touch_last_contact();

		return true;
	}

	/**
	 * Baut die zu signierende Zeichenkette.
	 *
	 * @param WP_REST_Request $request
	 * @param string          $conn_id
	 * @param string          $timestamp
	 * @param string          $nonce
	 * @return string
	 */
	public static function canonical_payload( WP_REST_Request $request, $conn_id, $timestamp, $nonce ) {
		$body  = (string) $request->get_body();
		$route = '/' . ltrim( (string) $request->get_route(), '/' );

		return implode(
			"\n",
			array(
				$conn_id,
				$timestamp,
				$nonce,
				strtoupper( (string) $request->get_method() ),
				$route,
				hash( 'sha256', $body ),
			)
		);
	}

	/**
	 * Optionale IP-Allowlist aus den Child-Einstellungen.
	 *
	 * @return true|WP_Error
	 */
	protected static function check_ip_allowlist() {
		$raw = trim( (string) NLC_Options::setting( 'ip_allowlist', '' ) );
		if ( '' === $raw ) {
			return true;
		}

		$remote = self::remote_ip();
		if ( '' === $remote ) {
			return new WP_Error( 'nlc_ip_unknown', 'Client-IP konnte nicht ermittelt werden.', array( 'status' => 403 ) );
		}

		foreach ( preg_split( '/[\s,]+/', $raw ) as $entry ) {
			$entry = trim( (string) $entry );
			if ( '' === $entry ) {
				continue;
			}
			if ( self::ip_matches( $remote, $entry ) ) {
				return true;
			}
		}

		return new WP_Error( 'nlc_ip_blocked', 'IP-Adresse steht nicht auf der Allowlist.', array( 'status' => 403 ) );
	}

	/**
	 * @return string
	 */
	public static function remote_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Vergleicht eine IP mit einem Eintrag (exakt oder CIDR, IPv4 und IPv6).
	 *
	 * @param string $ip
	 * @param string $entry
	 * @return bool
	 */
	public static function ip_matches( $ip, $entry ) {
		if ( false === strpos( $entry, '/' ) ) {
			return hash_equals( $entry, $ip );
		}

		list( $subnet, $bits ) = explode( '/', $entry, 2 );
		$bits = (int) $bits;

		$ip_bin     = @inet_pton( $ip );
		$subnet_bin = @inet_pton( $subnet );
		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$max_bits = strlen( $ip_bin ) * 8;
		if ( $bits < 0 || $bits > $max_bits ) {
			return false;
		}

		$full_bytes = intdiv( $bits, 8 );
		$rest_bits  = $bits % 8;

		if ( $full_bytes > 0 && 0 !== substr_compare( $ip_bin, substr( $subnet_bin, 0, $full_bytes ), 0, $full_bytes ) ) {
			return false;
		}

		if ( 0 === $rest_bits ) {
			return true;
		}

		$mask = ~( ( 1 << ( 8 - $rest_bits ) ) - 1 ) & 0xFF;
		return ( ord( $ip_bin[ $full_bytes ] ) & $mask ) === ( ord( $subnet_bin[ $full_bytes ] ) & $mask );
	}
}
