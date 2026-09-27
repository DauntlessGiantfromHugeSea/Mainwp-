<?php
/**
 * Erzeugt ein Geraete-Schluesselpaar, verschluesselt damit eine Nachricht mit
 * dem Panel-Code und gibt alles als JSON aus — Node prueft es gegen.
 */
declare( strict_types = 1 );

$root = realpath( dirname( __DIR__ ) );
define( 'NL_SRC', $root . '/src' );
spl_autoload_register( static function ( string $c ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = NL_SRC . '/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );

use NorthLab\Core\WebPush;

// Ein Geraet, wie es der Browser anlegen wuerde.
$device = openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) );
openssl_pkey_export( $device, $devicePem );
$d = openssl_pkey_get_details( $device );

$p256dh = WebPush::b64(
	"\x04" . str_pad( $d['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $d['ec']['y'], 32, "\0", STR_PAD_LEFT )
);
$authRaw = random_bytes( 16 );
$auth    = WebPush::b64( $authRaw );

$plaintext = json_encode( array( 'title' => 'Seite offline', 'body' => 'kundenseite.de antwortet nicht.' ), JSON_UNESCAPED_UNICODE );

$encrypted = WebPush::encrypt( $plaintext, $p256dh, $auth );

// VAPID zusaetzlich, damit Node auch die Signatur pruefen kann.
$vapid            = WebPush::generateKeys();
$vapid['subject'] = 'mailto:hallo@north-lab.de';
$token            = WebPush::vapidToken( 'https://fcm.googleapis.com', $vapid );

echo json_encode(
	array(
		'devicePem'  => $devicePem,
		'p256dh'     => $p256dh,
		'auth'       => $auth,
		'plaintext'  => $plaintext,
		'body'       => base64_encode( $encrypted ),
		'vapidPub'   => $vapid['public'],
		'vapidToken' => $token,
	)
), "\n";
