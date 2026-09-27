<?php
/**
 * Der Verbindungsablauf auf der Kundenseite: Code pruefen, verbrauchen,
 * Verbindung speichern — und was passiert, wenn die Antwort verloren geht.
 */
declare( strict_types = 1 );

require __DIR__ . '/child-stubs.php';

function wp_hash_password( $p ) { return 'hash:' . hash( 'sha256', (string) $p ); }
function wp_check_password( $p, $h ) { return hash_equals( (string) $h, 'hash:' . hash( 'sha256', (string) $p ) ); }
function sanitize_text_field_x( $v ) { return trim( strip_tags( (string) $v ) ); }

require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-options.php';

$fails = 0;
$n     = 0;
function check( string $label, bool $ok ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label\n"; }
}
function reset_all(): void {
	$GLOBALS['options']    = array();
	$GLOBALS['transients'] = array();
}

/* ------------------------------------------------------- Schreibweisen */

reset_all();
$code = NLC_Options::generate_connect_code();

check( 'Code ist 32 Zeichen Grossbuchstaben-Hex', (bool) preg_match( '/^[0-9A-F]{32}$/', $code ) );
check( 'Richtiger Code wird angenommen', null === NLC_Options::check_connect_code( $code ) );
check( 'Kleinschreibung wird angenommen', null === NLC_Options::check_connect_code( strtolower( $code ) ) );
check( 'Fuehrendes Leerzeichen stoert nicht', null === NLC_Options::check_connect_code( '  ' . $code ) );
check( 'Zeilenumbruch am Ende stoert nicht', null === NLC_Options::check_connect_code( $code . "\n" ) );
check( 'Leerzeichen mittendrin stoeren nicht', null === NLC_Options::check_connect_code( substr( $code, 0, 8 ) . ' ' . substr( $code, 8 ) ) );

/* ------------------------------------- Die drei Gruende sind unterscheidbar */

reset_all();
$missing = NLC_Options::check_connect_code( 'IRGENDWAS' );
check( 'Ohne erzeugten Code: eigener Grund', is_string( $missing ) && str_contains( $missing, 'kein Verbindungscode bereit' ) );
check( 'Und der sagt, was zu tun ist', is_string( $missing ) && str_contains( $missing, 'neuen erzeugen' ) );

reset_all();
$code = NLC_Options::generate_connect_code();
$GLOBALS['options']['nlc_connect_code']['expires'] = time() - 1;
$expired = NLC_Options::check_connect_code( $code );
check( 'Abgelaufen: eigener Grund', is_string( $expired ) && str_contains( $expired, 'abgelaufen' ) );
check( 'Und die Gueltigkeitsdauer steht dabei', is_string( $expired ) && str_contains( $expired, '60 Minuten' ) );
check( 'Ein abgelaufener Code wird entfernt', ! isset( $GLOBALS['options']['nlc_connect_code'] ) );

reset_all();
$code  = NLC_Options::generate_connect_code();
$wrong = NLC_Options::check_connect_code( str_repeat( 'A', 32 ) );
check( 'Falscher Code: eigener Grund', is_string( $wrong ) && str_contains( $wrong, 'stimmt nicht' ) );
check( 'Ein falscher Versuch verbraucht den Code nicht', null === NLC_Options::check_connect_code( $code ) );

/* --------------------------------------------------- Verbrauchen */

reset_all();
$code = NLC_Options::generate_connect_code();
check( 'Verbrauchen klappt einmal', NLC_Options::consume_connect_code( $code ) );
check( 'Und danach nicht mehr', ! NLC_Options::consume_connect_code( $code ) );

/* ----------------------------------- Verlorene Antwort, ehrliche Wiederholung */

reset_all();
$code = NLC_Options::generate_connect_code();
$key  = "-----BEGIN PUBLIC KEY-----\nMIIB...\n-----END PUBLIC KEY-----";
$id   = 'abc123def456';

NLC_Options::consume_connect_code( $code );
NLC_Options::save_connection( array( 'connection_id' => $id, 'public_key' => $key, 'dashboard_url' => 'https://panel.test' ) );
NLC_Options::remember_connect( $code, $id, $key );

check( 'Dieselbe Anfrage darf sich wiederholen', NLC_Options::was_just_connected( $code, $id, $key ) );
check( 'Auch mit anderer Schreibweise', NLC_Options::was_just_connected( strtolower( $code ) . ' ', $id, $key ) );

check( 'Anderer Code zaehlt nicht', ! NLC_Options::was_just_connected( str_repeat( 'B', 32 ), $id, $key ) );
check( 'Andere Kennung zaehlt nicht', ! NLC_Options::was_just_connected( $code, 'fremd0000000', $key ) );
check( 'Anderer Schluessel zaehlt nicht', ! NLC_Options::was_just_connected( $code, $id, 'anderer schluessel' ) );

// Nach Ablauf der Merkfrist ist Schluss.
$GLOBALS['transients']['nlc_last_connect']['exp'] = time() - 1;
check( 'Nach der Merkfrist zaehlt es nicht mehr', ! NLC_Options::was_just_connected( $code, $id, $key ) );

// Ohne bestehende Verbindung darf nichts durchgehen.
reset_all();
NLC_Options::remember_connect( $code, $id, $key );
check( 'Ohne gespeicherte Verbindung zaehlt es nicht', ! NLC_Options::was_just_connected( $code, $id, $key ) );

// Und die Merkfrist alleine oeffnet keine Tuer: ohne passenden Code nichts.
reset_all();
NLC_Options::save_connection( array( 'connection_id' => $id, 'public_key' => $key, 'dashboard_url' => 'https://panel.test' ) );
NLC_Options::remember_connect( $code, $id, $key );
check( 'Ein fremder Code oeffnet die Wiederholung nicht', ! NLC_Options::was_just_connected( 'FREMD', $id, $key ) );

/* -------------- Der Fall, der es ausgeloest hat: spaeterer Fehler */

// Alte Reihenfolge war: Code verbrauchen, DANN den Schluessel pruefen. Ein
// ungueltiger Schluessel liess den Code verbrannt zurueck.
reset_all();
$code = NLC_Options::generate_connect_code();

// So laeuft es jetzt: erst pruefen ...
$problem = NLC_Options::check_connect_code( $code );
check( 'Pruefen allein verbraucht nichts', null === $problem );

// ... dann faellt der Schluessel durch, und der Code lebt weiter.
check( 'Nach abgelehntem Schluessel gilt der Code noch', null === NLC_Options::check_connect_code( $code ) );

// Erst der erfolgreiche Durchlauf verbraucht ihn.
NLC_Options::consume_connect_code( $code );
check( 'Erst der Erfolg verbraucht den Code', null !== NLC_Options::check_connect_code( $code ) );

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
