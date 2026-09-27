<?php
/**
 * Prueft die neuen Child-Plugin-Klassen mit einem winzigen WordPress-Ersatz:
 * nur die Funktionen, die diese Klassen wirklich anfassen.
 */
declare( strict_types = 1 );

define( 'ABSPATH', '/tmp/wp/' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['options']    = array();
$GLOBALS['transients'] = array();
$GLOBALS['users']      = array();
$GLOBALS['redirects']  = array();
$GLOBALS['died']       = array();
$GLOBALS['cookies']    = array();
$GLOBALS['current']    = 0;

function get_option( $k, $d = false ) { return $GLOBALS['options'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['options'][ $k ] = $v; return true; }
function add_option( $k, $v ) { $GLOBALS['options'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['options'][ $k ] ); return true; }
function set_transient( $k, $v, $ttl ) { $GLOBALS['transients'][ $k ] = array( 'v' => $v, 'exp' => time() + $ttl ); return true; }
function get_transient( $k ) {
	$t = $GLOBALS['transients'][ $k ] ?? null;
	if ( ! $t || $t['exp'] < time() ) { return false; }
	return $t['v'];
}
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); return true; }
function wp_parse_args( $a, $d ) { return array_merge( $d, is_array( $a ) ? $a : array() ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); }
/**
 * Wie WordPress: nur erlaubte Protokolle passieren, alles andere wird leer.
 * Genau darauf verlaesst sich die Wartungsseite beim Logo.
 */
function nlc_test_allowed_url( $v ) {
	$v     = trim( (string) $v );
	$parts = parse_url( $v );
	if ( false === $parts || empty( $parts['scheme'] ) ) {
		return '';
	}
	return in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ? $v : '';
}
function esc_url_raw( $v ) { return nlc_test_allowed_url( $v ); }
function esc_url( $v ) { return htmlspecialchars( nlc_test_allowed_url( $v ), ENT_QUOTES ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_html__( $v, $d = '' ) { return $v; }
function get_bloginfo( $k ) { return 'Kundenseite'; }
function get_locale() { return 'de_DE'; }
function wp_date( $f, $ts ) { return gmdate( $f, $ts ); }
function home_url( $p = '/' ) { return 'https://kunde.de' . $p; }
function admin_url( $p = '' ) { return 'https://kunde.de/wp-admin/' . $p; }
function add_query_arg( $k, $v, $url ) { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $k . '=' . rawurlencode( $v ); }
function status_header( $c ) { $GLOBALS['status'] = $c; }
function nocache_headers() {}
function wp_doing_cron() { return false; }
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function is_user_logged_in() { return $GLOBALS['current'] > 0; }
function current_user_can( $cap ) { return $GLOBALS['current'] > 0 && ! empty( $GLOBALS['users'][ $GLOBALS['current'] ]['can'] ); }
function add_action( ...$a ) {}
function wp_set_current_user( $id ) { $GLOBALS['current'] = $id; }
function wp_set_auth_cookie( $id, $remember ) { $GLOBALS['cookies'][] = array( 'id' => $id, 'remember' => $remember ); }
function do_action( ...$a ) {}
function update_user_meta( $id, $k, $v ) { $GLOBALS['users'][ $id ]['meta'][ $k ] = $v; return true; }
function get_user_meta( $id, $k, $single = false ) { return $GLOBALS['users'][ $id ]['meta'][ $k ] ?? ''; }
function delete_user_meta( $id, $k ) { unset( $GLOBALS['users'][ $id ]['meta'][ $k ] ); return true; }
function wp_safe_redirect( $u ) { $GLOBALS['redirects'][] = $u; throw new ExitSignal( 'redirect' ); }
function wp_die( $m, $t = '', $a = array() ) { $GLOBALS['died'][] = $m; throw new ExitSignal( 'die' ); }
function get_userdata( $id ) {
	if ( empty( $GLOBALS['users'][ $id ] ) ) { return false; }
	$u              = new stdClass();
	$u->ID          = $id;
	$u->user_login  = $GLOBALS['users'][ $id ]['login'];
	$u->roles       = $GLOBALS['users'][ $id ]['roles'];
	return $u;
}
function get_user_by( $f, $v ) {
	foreach ( $GLOBALS['users'] as $id => $u ) {
		if ( $u['login'] === $v ) { return get_userdata( $id ); }
	}
	return false;
}
function get_users( $args ) {
	$out = array();
	foreach ( $GLOBALS['users'] as $id => $u ) {
		if ( ! empty( $args['role'] ) && ! in_array( $args['role'], $u['roles'], true ) ) { continue; }

		// Nur die eine Meta-Abfrage, die purge_expired_users stellt.
		if ( ! empty( $args['meta_key'] ) ) {
			$value = $u['meta'][ $args['meta_key'] ] ?? null;
			if ( null === $value || '' === $value ) { continue; }
			if ( '<=' === ( $args['meta_compare'] ?? '=' ) && (int) $value > (int) $args['meta_value'] ) { continue; }
		}

		// Wie WordPress: 'fields' => 'ID' liefert blanke IDs, keine Objekte.
		$out[] = 'ID' === ( $args['fields'] ?? '' ) ? $id : get_userdata( $id );
	}
	return array_slice( $out, 0, $args['number'] ?? 100 );
}
function wp_set_password( $password, $id ) { $GLOBALS['passwords'][ $id ] = $password; }
function wp_delete_user( $id, $reassign = null ) {
	$GLOBALS['deleted'][] = array( 'id' => $id, 'reassign' => $reassign );
	unset( $GLOBALS['users'][ $id ] );
	return true;
}
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_user( $v ) { return preg_replace( '/[^a-zA-Z0-9._\-]/', '', (string) $v ); }
function sanitize_email( $v ) { return (string) $v; }
function wp_generate_password( $len = 12, ...$rest ) { return substr( bin2hex( random_bytes( 32 ) ), 0, $len ); }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message ?: 'Fehler'; }
}
class WP_User_Query {
	public function __construct( $a ) {}
	public function get_results() { return array(); }
}
class WP_Session_Tokens {
	public static function get_instance( $id ) { return new self(); }
	public function destroy_all() { $GLOBALS['sessions_destroyed'][] = true; }
}

class ExitSignal extends RuntimeException {}

