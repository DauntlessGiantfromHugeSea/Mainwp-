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

require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-options.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-maintenance-mode.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-login.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-actions.php';

$fails = 0;
$n     = 0;
function check( string $label, bool $ok ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label\n"; }
}

/* ------------------------------------------------------------ Wartungsmodus */

check( 'Standardmaessig aus', empty( NLC_Maintenance_Mode::state()['enabled'] ) );

$state = NLC_Maintenance_Mode::save( array( 'enabled' => true, 'until' => 0, 'headline' => 'Wartungsmodus' ) );
check( 'Laesst sich einschalten', ! empty( $state['enabled'] ) );
check( 'Merkt sich den Startzeitpunkt', $state['started_at'] > 0 );

$GLOBALS['current'] = 0;
check( 'Besucher sehen die Wartungsseite', NLC_Maintenance_Mode::should_hide() );

$GLOBALS['users'][5] = array( 'login' => 'redakteur', 'roles' => array( 'editor' ), 'can' => true, 'meta' => array() );
$GLOBALS['current']  = 5;
check( 'Redakteure arbeiten weiter', ! NLC_Maintenance_Mode::should_hide() );
$GLOBALS['current'] = 0;

// Abgelaufenes Fenster
NLC_Maintenance_Mode::save( array( 'enabled' => true, 'until' => time() - 10 ) );
check( 'Abgelaufenes Fenster schaltet sich ab', ! NLC_Maintenance_Mode::state()['enabled'] );
check( 'Und der Zustand ist auch gespeichert', empty( get_option( 'nlc_maintenance_mode' )['enabled'] ) );

NLC_Maintenance_Mode::save( array( 'enabled' => true, 'until' => time() + 600 ) );
check( 'Laufendes Fenster bleibt an', NLC_Maintenance_Mode::state()['enabled'] );

// Farben
check( 'Hex mit sechs Stellen bleibt', '#ff33aa' === NLC_Maintenance_Mode::sanitize_color( '#FF33AA' ) );
check( 'Kurzform wird ausgeschrieben', '#ff33aa' === NLC_Maintenance_Mode::sanitize_color( '#f3a' ) );
check( 'Unsinn faellt auf die Vorgabe', '#f9907a' === NLC_Maintenance_Mode::sanitize_color( 'javascript:alert(1)' ) );
check( 'Leer faellt auf die Vorgabe', '#f9907a' === NLC_Maintenance_Mode::sanitize_color( '' ) );

// Seite rendern
$page = NLC_Maintenance_Mode::page(
	array(
		'headline' => 'Wartungsmodus',
		'message'  => 'Gleich zurück.',
		'color'    => '#ff3399',
		'logo'     => 'https://north-flow.de/api/assets/abc',
		'until'    => time() + 1800,
	)
);
check( 'Seite zeigt die Ueberschrift', str_contains( $page, '<h1><span class="pulse"></span>Wartungsmodus</h1>' ) );
check( 'Seite nutzt die gewaehlte Farbe', str_contains( $page, '--brand: #ff3399;' ) );
check( 'Seite bindet das Logo ein', str_contains( $page, 'src="https://north-flow.de/api/assets/abc"' ) );
check( 'Seite nennt keine Uhrzeit', ! str_contains( $page, 'Voraussichtlich' ) );
check( 'Auch sonst keine Uhrzeit im Text', ! preg_match( '/\d{1,2}:\d{2}/', strip_tags( $page ) ) );
check( 'Seite bleibt aus dem Index', str_contains( $page, 'noindex, nofollow' ) );
check( 'Seite laedt nichts von aussen ausser dem Logo', 1 === substr_count( $page, 'https://' ) );

// Kein Logo: Name statt Bild
$plain = NLC_Maintenance_Mode::page( array( 'logo' => '' ) );
check( 'Ohne Logo steht der Seitenname da', str_contains( $plain, '<div class="wordmark">Kundenseite</div>' ) );

// Einschleusversuche
$evil = NLC_Maintenance_Mode::page(
	array(
		'headline' => 'Hallo</h1><script>alert(1)</script>',
		'message'  => '<img src=x onerror=alert(2)>',
		'color'    => '#000"><script>alert(3)</script>',
		'logo'     => 'javascript:alert(4)',
	)
);
check( 'Ueberschrift wird maskiert', ! str_contains( $evil, '<script>' ) );
check( 'Text wird maskiert', ! str_contains( $evil, '<img src=x' ) );
check( 'Der Text steht als Klartext da', str_contains( $evil, '&lt;img src=x onerror=alert(2)&gt;' ) );
check( 'Farbe faellt auf die Vorgabe zurueck', str_contains( $evil, '--brand: #f9907a;' ) );
check( 'Nicht-URL im Logo wird verworfen', ! str_contains( $evil, 'javascript:' ) );

/* -------------------------------------------------------- Ein-Klick-Anmeldung */

NLC_Options::save_settings( array( 'allow_autologin' => true ) );
$GLOBALS['users'][1] = array( 'login' => 'chef', 'roles' => array( 'administrator' ), 'can' => true, 'meta' => array() );
$GLOBALS['users'][9] = array( 'login' => 'rollenlos', 'roles' => array(), 'can' => false, 'meta' => array() );

$issued = NLC_Login::issue( array() );
check( 'Ohne Angabe wird der aelteste Administrator gewaehlt', 'chef' === ( $issued['user']['login'] ?? '' ) );
check( 'Adresse zeigt auf die Startseite', str_starts_with( (string) $issued['url'], 'https://kunde.de/?northlab-login=' ) );

parse_str( (string) parse_url( $issued['url'], PHP_URL_QUERY ), $query );
$token = $query['northlab-login'];
check( 'Token ist 64 Hex-Zeichen lang', (bool) preg_match( '/^[0-9a-f]{64}$/', $token ) );
check( 'Der Klartext-Token steht nicht im Speicher', ! isset( $GLOBALS['transients'][ 'nlc_login_' . $token ] ) );
check( 'Gespeichert wird nur der Hash', isset( $GLOBALS['transients'][ 'nlc_login_' . hash( 'sha256', $token ) ] ) );

$login = new NLC_Login();

// Einloesen
$_GET['northlab-login'] = $token;
$GLOBALS['current']     = 0;
try { $login->maybe_login(); } catch ( ExitSignal $e ) {}
check( 'Anmeldung setzt ein Cookie', 1 === count( $GLOBALS['cookies'] ) );
check( 'Und zwar ohne "angemeldet bleiben"', false === $GLOBALS['cookies'][0]['remember'] );
check( 'Danach geht es ins Backend', 'https://kunde.de/wp-admin/' === end( $GLOBALS['redirects'] ) );
check( 'Der Token ist verbraucht', false === get_transient( 'nlc_login_' . hash( 'sha256', $token ) ) );

// Zweiter Versuch mit demselben Token
$GLOBALS['current'] = 0;
$before             = count( $GLOBALS['died'] );
try { $login->maybe_login(); } catch ( ExitSignal $e ) {}
check( 'Ein zweites Mal geht nicht', count( $GLOBALS['died'] ) === $before + 1 );

// Abgelaufener Token
$issued2 = NLC_Login::issue( array( 'login' => 'chef' ) );
parse_str( (string) parse_url( $issued2['url'], PHP_URL_QUERY ), $q2 );
$key = 'nlc_login_' . hash( 'sha256', $q2['northlab-login'] );
$GLOBALS['transients'][ $key ]['v']['issued'] = time() - 200;
$_GET['northlab-login']                       = $q2['northlab-login'];
$GLOBALS['current']                           = 0;
$before                                       = count( $GLOBALS['died'] );
try { $login->maybe_login(); } catch ( ExitSignal $e ) {}
check( 'Ein alter Token wird abgewiesen', count( $GLOBALS['died'] ) === $before + 1 );

// Erfundener Token
$_GET['northlab-login'] = str_repeat( 'a', 64 );
$before                 = count( $GLOBALS['died'] );
try { $login->maybe_login(); } catch ( ExitSignal $e ) {}
check( 'Ein erfundener Token wird abgewiesen', count( $GLOBALS['died'] ) === $before + 1 );

// Unsinn statt Token
$_GET['northlab-login'] = '../../etc/passwd';
$before                 = count( $GLOBALS['died'] );
try { $login->maybe_login(); } catch ( ExitSignal $e ) {}
check( 'Kein Hex-Token wird sofort abgewiesen', count( $GLOBALS['died'] ) === $before + 1 );

// Ohne Parameter passiert nichts
unset( $_GET['northlab-login'] );
$before = count( $GLOBALS['died'] ) + count( $GLOBALS['redirects'] );
$login->maybe_login();
check( 'Normale Aufrufe bleiben unberuehrt', count( $GLOBALS['died'] ) + count( $GLOBALS['redirects'] ) === $before );

// Abgeschaltet
NLC_Options::save_settings( array( 'allow_autologin' => false ) );
$off = NLC_Login::issue( array() );
check( 'Abgeschaltet gibt es keine Adresse', empty( $off['ok'] ) && '' === ( $off['url'] ?? '' ) );
NLC_Options::save_settings( array( 'allow_autologin' => true ) );

// Konto ohne Rolle
$none = NLC_Login::issue( array( 'user_id' => 9 ) );
check( 'Ein rollenloses Konto bekommt keine Adresse', empty( $none['ok'] ) );

// Unbekannter Benutzer
$gone = NLC_Login::issue( array( 'user_id' => 4242 ) );
check( 'Unbekannter Benutzer wird abgewiesen', empty( $gone['ok'] ) );

/* --------------------------------------------------- Befristete Konten */

$GLOBALS['deleted'] = array();
$GLOBALS['users']   = array(
	1  => array( 'login' => 'chef', 'roles' => array( 'administrator' ), 'can' => true, 'meta' => array() ),
	20 => array( 'login' => 'abgelaufen', 'roles' => array( 'editor' ), 'can' => true, 'meta' => array( 'nlc_expires_at' => time() - 60 ) ),
	21 => array( 'login' => 'laeuft-noch', 'roles' => array( 'editor' ), 'can' => true, 'meta' => array( 'nlc_expires_at' => time() + 3600 ) ),
	22 => array( 'login' => 'unbefristet', 'roles' => array( 'editor' ), 'can' => true, 'meta' => array() ),
);

$removed = NLC_Actions::purge_expired_users();

check( 'Genau ein Konto wird entfernt', array( 'abgelaufen' ) === $removed );
check( 'Das laufende Konto bleibt', isset( $GLOBALS['users'][21] ) );
check( 'Das unbefristete Konto bleibt', isset( $GLOBALS['users'][22] ) );
check( 'Der Administrator bleibt', isset( $GLOBALS['users'][1] ) );
check( 'Inhalte gehen an den aeltesten Administrator', 1 === ( $GLOBALS['deleted'][0]['reassign'] ?? 0 ) );

// Ein abgelaufener Administrator, der selbst Empfaenger waere, darf nicht sich
// selbst zugewiesen und geloescht werden.
$GLOBALS['deleted'] = array();
$GLOBALS['users']   = array(
	1 => array( 'login' => 'chef', 'roles' => array( 'administrator' ), 'can' => true, 'meta' => array( 'nlc_expires_at' => time() - 60 ) ),
);
$removed = NLC_Actions::purge_expired_users();
check( 'Der einzige Administrator wird nicht geloescht', array() === $removed && isset( $GLOBALS['users'][1] ) );
check( 'Seine Befristung wird stattdessen aufgehoben', ! isset( $GLOBALS['users'][1]['meta']['nlc_expires_at'] ) );

/* --------------------------------------------------------- Passwort setzen */

$GLOBALS['users']              = array( 3 => array( 'login' => 'kunde', 'roles' => array( 'editor' ), 'can' => true, 'meta' => array() ) );
$GLOBALS['sessions_destroyed'] = array();
$GLOBALS['passwords']          = array();

$result = NLC_Actions::user_action( 'set-password', array( 'id' => 3 ) );
check( 'Passwort setzen meldet Erfolg', ! empty( $result['success'] ) );
check( 'Das neue Passwort kommt genau einmal zurueck', 24 === strlen( (string) ( $result['password'] ?? '' ) ) );
check( 'Offene Sitzungen werden beendet', 1 === count( $GLOBALS['sessions_destroyed'] ) );
check( 'Das Passwort steht in keiner Meldung', ! str_contains( (string) $result['message'], (string) $result['password'] ) );

$gone = NLC_Actions::user_action( 'set-password', array( 'id' => 999 ) );
check( 'Unbekannter Benutzer wird abgewiesen', empty( $gone['success'] ) );

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
