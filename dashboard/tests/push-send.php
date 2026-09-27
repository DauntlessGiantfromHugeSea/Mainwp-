<?php
/**
 * Der Versandweg von WebPush::send gegen einen nachgebauten Push-Dienst:
 * Kopfzeilen, Statusauswertung, und was bei abgelehnten Geraeten passiert.
 */
declare( strict_types = 1 );

$root = realpath( dirname( __DIR__ ) );
define( 'NL_SRC', $root . '/src' );
define( 'NL_VERSION', '1.0.0' );
spl_autoload_register( static function ( string $c ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = NL_SRC . '/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );

use NorthLab\Core\WebPush;

$fails = 0;
$n     = 0;
function check( string $label, bool $ok ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label\n"; }
}

// Ein Geraet, wie der Browser es anlegt.
$device = openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) );
$d      = openssl_pkey_get_details( $device );
$p256dh = WebPush::b64( "\x04" . str_pad( $d['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $d['ec']['y'], 32, "\0", STR_PAD_LEFT ) );
$auth   = WebPush::b64( random_bytes( 16 ) );

$vapid            = WebPush::generateKeys();
$vapid['subject'] = 'mailto:hallo@north-lab.de';

/* ------------------------------------------------------------ Schluessel */

check( 'Oeffentlicher Schluessel ist ein 65-Byte-Punkt', 65 === strlen( WebPush::unb64( $vapid['public'] ) ) );
check( 'Und beginnt mit 0x04', "\x04" === WebPush::unb64( $vapid['public'] )[0] );
check( 'Privater Schluessel ist 32 Byte', 32 === strlen( WebPush::unb64( $vapid['private'] ) ) );
check( 'Zwei Aufrufe ergeben verschiedene Paare', WebPush::generateKeys()['public'] !== $vapid['public'] );

/* ------------------------------------------------- Verschluesselung */

$one = WebPush::encrypt( 'hallo', $p256dh, $auth );
$two = WebPush::encrypt( 'hallo', $p256dh, $auth );
check( 'Gleicher Text ergibt zweimal anderes Ergebnis', $one !== $two );
check( 'Der Umschlag hat die erwartete Mindestlaenge', strlen( $one ) >= 16 + 4 + 1 + 65 + 16 );

// Ein Geraet mit unsinnigen Schluesseln darf nicht zu einem Versand fuehren.
$bad = WebPush::send(
	array( 'endpoint' => 'https://push.test/x', 'p256dh' => 'unsinn', 'auth' => $auth ),
	array( 'title' => 'x' ),
	$vapid
);
check( 'Unsinniger Geraeteschluessel wird abgefangen', 0 === $bad['status'] && '' !== $bad['error'] );

$shortAuth = WebPush::send(
	array( 'endpoint' => 'https://push.test/x', 'p256dh' => $p256dh, 'auth' => WebPush::b64( 'kurz' ) ),
	array( 'title' => 'x' ),
	$vapid
);
check( 'Zu kurzes Geheimnis wird abgefangen', 0 === $shortAuth['status'] );

$badEndpoint = WebPush::send(
	array( 'endpoint' => 'keine-adresse', 'p256dh' => $p256dh, 'auth' => $auth ),
	array( 'title' => 'x' ),
	$vapid
);
check( 'Unsinniger Endpunkt wird abgefangen', 0 === $badEndpoint['status'] );

/* --------------------------------------------------- Echter Versandweg */

$base = 'http://127.0.0.1:8098';

foreach ( array( 201, 404, 410, 429 ) as $expected ) {
	$result = WebPush::send(
		array( 'endpoint' => $base . '/push/' . $expected, 'p256dh' => $p256dh, 'auth' => $auth ),
		array( 'title' => 'Seite offline', 'body' => 'kundenseite.de antwortet nicht.' ),
		$vapid
	);
	check( sprintf( 'Antwort %d wird durchgereicht', $expected ), $expected === $result['status'] );
}

// Was der Dienst gesehen hat.
$seen = json_decode( (string) file_get_contents( $base . '/last' ), true );

check( 'Inhaltskodierung ist aes128gcm', 'aes128gcm' === ( $seen['headers']['content-encoding'] ?? '' ) );
check( 'Inhaltstyp ist binaer', 'application/octet-stream' === ( $seen['headers']['content-type'] ?? '' ) );
check( 'TTL ist gesetzt', ctype_digit( (string) ( $seen['headers']['ttl'] ?? '' ) ) );
check( 'Dringlichkeit ist gesetzt', '' !== ( $seen['headers']['urgency'] ?? '' ) );

$authHeader = (string) ( $seen['headers']['authorization'] ?? '' );
check( 'Nachweis ist ein VAPID-Kopf', str_starts_with( $authHeader, 'vapid t=' ) );
check( 'Und traegt den oeffentlichen Schluessel', str_contains( $authHeader, ', k=' . $vapid['public'] ) );

preg_match( '/t=([^,]+)/', $authHeader, $m );
$parts = explode( '.', $m[1] ?? '' );
check( 'Das Token hat drei Teile', 3 === count( $parts ) );

$claims = json_decode( (string) WebPush::unb64( $parts[1] ?? '' ), true );
check( 'Der Empfaenger ist der Push-Dienst, nicht der volle Pfad', 'http://127.0.0.1:8098' === ( $claims['aud'] ?? '' ) );
check( 'Der Absender steht drin', 'mailto:hallo@north-lab.de' === ( $claims['sub'] ?? '' ) );

check( 'Die Laenge im Kopf passt zum Rumpf', (int) ( $seen['headers']['content-length'] ?? 0 ) === (int) $seen['length'] );
check( 'Der Rumpf ist nicht der Klartext', ! str_contains( (string) base64_decode( (string) $seen['body'] ), 'kundenseite.de' ) );

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
