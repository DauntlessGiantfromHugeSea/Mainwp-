<?php
/**
 * Winziger Server, der Manifest, Service Worker, Offline-Seite und die
 * statischen Dateien so ausliefert wie das Panel — nur ohne Datenbank.
 */
$root = realpath( dirname( dirname( __DIR__ ) ) );
define( 'NL_ROOT', $root );
define( 'NL_SRC', $root . '/src' );
define( 'NL_VIEWS', $root . '/views' );
define( 'NL_RESOURCES', $root . '/resources' );
define( 'NL_STORAGE', sys_get_temp_dir() . '/nlc-serve-storage' );
define( 'NL_VERSION', '1.0.0' );

spl_autoload_register( static function ( string $c ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = NL_SRC . '/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );

$base = 'http://' . ( $_SERVER['HTTP_HOST'] ?? 'localhost:8099' );
function url( string $path = '' ): string { return $GLOBALS['base'] . '/' . ltrim( $path, '/' ); }
function csrf_field(): string { return ''; }
require_once NL_SRC . '/helpers.php';
require_once NL_SRC . '/view-helpers.php';

$ref = new ReflectionClass( \NorthLab\Core\Setting::class );
foreach ( $ref->getProperties( ReflectionProperty::IS_STATIC ) as $prop ) {
	$prop->setAccessible( true );
	$prop->setValue( null, array( 'agency_name' => 'NorthLab', 'agency_color' => '#f9907a' ) );
}

$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$c    = new \NorthLab\Controller\PwaController();
$req  = new \NorthLab\Core\Request();

if ( '/manifest.webmanifest' === $path ) { $c->manifest( $req ); }
if ( '/sw.js' === $path )                { $c->serviceWorker( $req ); }
if ( '/offline' === $path )              { $c->offline( $req ); exit; }

// --- Symbol: echte Auslieferung ueber den IconController -----------------
if ( preg_match( '#^/branding/icon/([a-z0-9-]{4,20})\\.png$#', $path, $m ) ) {
	$ic = new \NorthLab\Controller\IconController();
	$r  = new \NorthLab\Core\Request();
	$r->params = array( 'name' => $m[1] );
	$ic->raster( $r );
	exit;
}
if ( '/branding/icon.svg' === $path ) {
	$ic = new \NorthLab\Controller\IconController();
	$ic->svg( new \NorthLab\Core\Request() );
	exit;
}

// Statische Dateien
$file = $root . '/public' . $path;
if ( is_file( $file ) ) {
	$types = array( 'css' => 'text/css', 'js' => 'text/javascript', 'png' => 'image/png', 'svg' => 'image/svg+xml' );
	$ext   = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
	header( 'Content-Type: ' . ( $types[ $ext ] ?? 'application/octet-stream' ) );
	readfile( $file );
	exit;
}

// --- Symbol: Quelle ausliefern und erzeugte Fassungen annehmen ------------
if ( '/branding/logo' === $path ) {
	header( 'Content-Type: image/svg+xml' );
	readfile( __DIR__ . '/logo.svg' );
	exit;
}
if ( '/branding/icons' === $path ) {
	$out = sys_get_temp_dir() . '/nlc-icons';
	@mkdir( $out );
	$saved = 0;
	foreach ( array( 'icon-512', 'icon-192', 'apple-touch-icon', 'favicon-32' ) as $name ) {
		if ( empty( $_POST[ $name ] ) ) { continue; }
		if ( ! preg_match( '#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $_POST[ $name ], $m ) ) { continue; }
		file_put_contents( $out . '/' . $name . '.png', base64_decode( $m[1] ) );
		$saved++;
	}
	header( 'Content-Type: application/json' );
	echo json_encode( array( 'ok' => $saved > 0, 'saved' => $saved, 'errors' => array() ) );
	exit;
}
if ( '/icon-page' === $path ) {
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Symbol</title>',
		'<link rel="stylesheet" href="', e( url( '/assets/css/app.css' ) ), '"></head><body>',
		'<input type="hidden" name="_token" value="testtoken">',
		'<input type="text" id="icon_bg" value="#000000">',
		'<input type="number" id="icon_padding" value="18">',
		'<div class="card" id="icon-box" data-icon-source="', e( url( '/branding/logo' ) ), '">',
		'<div class="card-body"><canvas id="icon-canvas" width="256" height="256"></canvas>',
		'<div class="notice" data-icon-status>Wird geladen…</div>',
		'<button class="btn primary" type="button" data-icon-save disabled>Symbole erzeugen</button>',
		'</div></div>',
		'<script src="', e( url( '/assets/js/icon.js' ) ), '" defer></script></body></html>';
	exit;
}

// --- Push-Endpunkte, wie das Panel sie anbietet ---------------------------
$record = sys_get_temp_dir() . '/nlc-subscribe.json';

if ( '/push/key' === $path ) {
	header( 'Content-Type: application/json' );
	echo json_encode( array( 'ok' => true, 'key' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U', 'devices' => 0 ) );
	exit;
}
if ( '/push/subscribe' === $path ) {
	file_put_contents( $record, json_encode( $_POST ) );
	header( 'Content-Type: application/json' );
	echo json_encode( array( 'ok' => true, 'devices' => 1 ) );
	exit;
}
if ( '/push/last-subscribe' === $path ) {
	header( 'Content-Type: application/json' );
	echo is_file( $record ) ? (string) file_get_contents( $record ) : '{}';
	exit;
}
if ( '/push-page' === $path ) {
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<!doctype html><html lang="de"><head><meta charset="utf-8">',
		'<meta name="viewport" content="width=device-width, initial-scale=1">',
		'<link rel="manifest" href="', e( url( '/manifest.webmanifest' ) ), '">',
		'<link rel="stylesheet" href="', e( url( '/assets/css/app.css' ) ), '"><title>Meldungen</title></head><body>',
		'<input type="hidden" name="_token" value="testtoken">',
		'<div class="card" id="push-box"><div class="card-body">',
		'<div class="notice" data-push-status>Wird geprüft…</div>',
		'<button class="btn primary" type="button" data-push-enable hidden>Meldungen einschalten</button>',
		'<button class="btn" type="button" data-push-disable hidden>Auf diesem Gerät abschalten</button>',
		'</div></div>',
		'<form id="push-test" hidden><button>Probemeldung</button></form>',
		'<script src="', e( url( '/assets/js/app.js' ) ), '" defer></script>',
		'<script src="', e( url( '/assets/js/push.js' ) ), '" defer></script>',
		'</body></html>';
	exit;
}

// Eine Seite, die die Huelle laedt — steht fuer das echte Panel.
header( 'Content-Type: text/html; charset=utf-8' );
echo '<!doctype html><html lang="de"><head><meta charset="utf-8">',
	'<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">',
	'<meta name="theme-color" content="#08080a">',
	'<link rel="manifest" href="', e( url( '/manifest.webmanifest' ) ), '">',
	'<link rel="apple-touch-icon" href="', e( url( '/assets/icons/apple-touch-icon.png' ) ), '">',
	'<title>Übersicht · NorthLab</title>',
	'<link rel="stylesheet" href="', e( url( '/assets/css/app.css' ) ), '"></head>',
	'<body><h1 style="color:#f1f1f4;font-family:system-ui;padding:20px">Panel</h1>',
	'<script src="', e( url( '/assets/js/app.js' ) ), '" defer></script></body></html>';
