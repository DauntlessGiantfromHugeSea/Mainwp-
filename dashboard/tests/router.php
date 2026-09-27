<?php
declare( strict_types = 1 );
$root = realpath( dirname( __DIR__ ) );
define( 'NL_SRC', $root . '/src' );
spl_autoload_register( static function ( string $c ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = NL_SRC . '/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );

use NorthLab\Core\Router;

$router = new Router();
$hit    = null;
$mark   = static function ( string $name ) use ( &$hit ) {
	return static function () use ( $name, &$hit ) { $hit = $name; };
};

// Reihenfolge exakt wie in public/index.php.
$router->get( '/sites', $mark( 'index' ) );
$router->get( '/sites/new', $mark( 'new' ) );
$router->post( '/sites', $mark( 'store' ) );
$router->post( '/sites/bulk', $mark( 'bulk' ) );
$router->get( '/sites/{id:\d+}', $mark( 'show' ) );
$router->post( '/sites/{id:\d+}', $mark( 'update' ) );
$router->post( '/sites/{id:\d+}/sync', $mark( 'sync' ) );
$router->post( '/sites/{id:\d+}/maintenance', $mark( 'maintenance' ) );
$router->post( '/sites/{id:\d+}/token', $mark( 'token' ) );
$router->post( '/sites/{id:\d+}/maintenance-mode', $mark( 'mmode' ) );
$router->get( '/sites/{id:\d+}/users', $mark( 'users' ) );
$router->post( '/sites/{id:\d+}/users', $mark( 'users-store' ) );
$router->post( '/sites/{id:\d+}/login', $mark( 'login' ) );
$router->post( '/sites/{id:\\d+}/child-update', $mark( 'child-update' ) );
$router->post( '/api/child/manifest', $mark( 'manifest' ) );
$router->get( '/branding/icon.svg', $mark( 'icon-svg' ) );
$router->get( '/branding/icon/{name:[a-z0-9-]{4,20}}.png', $mark( 'icon-png' ) );
$router->get( '/branding/logo', $mark( 'icon-source' ) );
$router->post( '/branding/icons', $mark( 'icon-store' ) );
$router->get( '/push/key', $mark( 'push-key' ) );
$router->post( '/push/subscribe', $mark( 'push-subscribe' ) );
$router->get( '/api/child/package/{connection:[A-Za-z0-9_-]{8,64}}', $mark( 'package' ) );

$expected = array(
	'GET /sites'                      => 'index',
	'GET /sites/new'                  => 'new',
	'GET /sites/7'                    => 'show',
	'POST /sites/7'                   => 'update',
	'POST /sites/bulk'                => 'bulk',
	'POST /sites/7/sync'              => 'sync',
	'POST /sites/7/maintenance'       => 'maintenance',
	'POST /sites/7/maintenance-mode'  => 'mmode',
	'POST /sites/7/token'             => 'token',
	'GET /sites/7/users'              => 'users',
	'POST /sites/7/users'             => 'users-store',
	'POST /sites/7/login'             => 'login',
	'POST /sites/7/child-update'      => 'child-update',
	'POST /api/child/manifest'        => 'manifest',
	'GET /branding/icon.svg'          => 'icon-svg',
	'GET /branding/icon/icon-192.png' => 'icon-png',
	'GET /branding/icon/favicon-32.png' => 'icon-png',
	'GET /branding/logo'              => 'icon-source',
	'POST /branding/icons'            => 'icon-store',
	'GET /push/key'                   => 'push-key',
	'POST /push/subscribe'            => 'push-subscribe',
	// Push darf nicht unter /api/ liegen — dort greift keine CSRF-Pruefung.
	'POST /api/push/subscribe'        => null,
	// Weder Pfadwechsel noch fremde Endungen duerfen greifen.
	'GET /branding/icon/../../etc.png' => null,
	'GET /branding/icon/x.png'        => null,
	'GET /api/child/package/abc123def456' => 'package',
	// Zu kurz, zu lang oder mit Schraegstrich: darf keine Route treffen.
	'GET /api/child/package/kurz'     => null,
	'GET /api/child/package/' . str_repeat( 'a', 65 ) => null,
	'GET /api/child/package/abc/../../etc' => null,
);

$ref    = new ReflectionClass( Router::class );
$prop   = $ref->getProperty( 'routes' );
$prop->setAccessible( true );
$routes = $prop->getValue( $router );

$fails = 0;
foreach ( $expected as $request => $want ) {
	[ $method, $path ] = explode( ' ', $request, 2 );
	$hit               = null;

	// Genau die Auswahl aus Router::dispatch nachbilden.
	foreach ( $routes as $route ) {
		if ( ! preg_match( $route['regex'], $path ) ) {
			continue;
		}
		if ( $route['method'] !== $method ) {
			continue;
		}
		( $route['handler'] )();
		break;
	}

	if ( $hit !== $want ) {
		$fails++;
		printf( "  FEHLT: %-40s erwartet %-16s bekam %s\n", $request, var_export( $want, true ), var_export( $hit, true ) );
	}
}

$zusatz = 0;

function check( string $label, bool $ok ): void {
	global $fails, $zusatz;
	$zusatz++;
	if ( ! $ok ) {
		$fails++;
		echo "  FEHLT: $label\n";
	}
}

/* ---------------------------------------- Zurueck nach dem Absenden */

/**
 * back() ist geschuetzt — ein Erbe macht es pruefbar.
 */
final class RueckwegProbe extends \NorthLab\Controller\BaseController {
	public function ziel( \NorthLab\Core\Request $request, string $fallback ): string {
		return $this->back( $request, $fallback );
	}
}

$probe = new RueckwegProbe();
$anfrage = new \NorthLab\Core\Request();

$zurueck = static function ( string $referer, string $host ) use ( $probe, $anfrage ): string {
	$_SERVER['HTTP_REFERER'] = $referer;
	$_SERVER['HTTP_HOST']    = $host;
	return $probe->ziel( $anfrage, '/ausweich' );
};

check( 'Zurueck zur Herkunftsseite',
	'/sites/7' === $zurueck( 'https://panel.example/sites/7', 'panel.example' ) );
check( 'Mit Abfrageteil',
	'/sites/7?tab=backup' === $zurueck( 'https://panel.example/sites/7?tab=backup', 'panel.example' ) );

// Der Fall, der lange danebenging: HTTP_HOST traegt den Port, parse_url nicht.
check( 'Auch wenn das Panel auf einem eigenen Port laeuft',
	'/sites/7' === $zurueck( 'http://127.0.0.1:8090/sites/7', '127.0.0.1:8090' ) );
check( 'Und bei Grossschreibung im Wirtsnamen',
	'/sites/7' === $zurueck( 'https://Panel.Example/sites/7', 'panel.example' ) );

check( 'Eine fremde Seite bestimmt die Weiterleitung nicht',
	'/ausweich' === $zurueck( 'https://boese.example/sites/7', 'panel.example' ) );
check( 'Auch ein aehnlicher Name nicht',
	'/ausweich' === $zurueck( 'https://panel.example.boese.tld/x', 'panel.example' ) );
check( 'Ohne Herkunft die Ausweichadresse',
	'/ausweich' === $zurueck( '', 'panel.example' ) );
check( 'Ein relativer Verweis wird uebernommen',
	'/sites/7' === $zurueck( '/sites/7', 'panel.example' ) );

unset( $_SERVER['HTTP_REFERER'], $_SERVER['HTTP_HOST'] );


printf( "%d Prüfungen, %d Fehler\n", count( $expected ) + $zusatz, $fails );
exit( $fails ? 1 : 0 );
