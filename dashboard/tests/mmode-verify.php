<?php
/**
 * Die Gegenprobe des Wartungsmodus — gegen einen echten HTTP-Server.
 *
 * Gemeldet war: Wartungsmodus eingeschaltet, Seite bleibt live. Dass die
 * Kundenseite die Einstellung gespeichert hat, beweist naemlich nichts; ein
 * Cache davor liefert weiter sein altes HTML. Hier wird geprueft, dass das
 * Panel genau diesen Unterschied erkennt statt Erfolg zu melden.
 */
declare( strict_types = 1 );

$src = realpath( dirname( __DIR__ ) . '/src' );
$tmp = sys_get_temp_dir() . '/nl-mmode';

exec( 'rm -rf ' . escapeshellarg( $tmp ) );
mkdir( $tmp . '/wurzel', 0700, true );
mkdir( $tmp . '/storage', 0700, true );

file_put_contents(
	$tmp . '/wurzel/config.php',
	"<?php return array( 'app' => array( 'key' => '" . base64_encode( str_repeat( 'm', 32 ) ) . "', 'url' => 'https://panel.example' ) );\n"
);

define( 'NL_ROOT', $tmp . '/wurzel' );
define( 'NL_SRC', $src );
define( 'NL_STORAGE', $tmp . '/storage' );
define( 'NL_VERSION', '1.0.0' );

spl_autoload_register( static function ( string $c ) use ( $src ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = $src . '/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );

require_once $src . '/helpers.php';

use NorthLab\Core\Config;
use NorthLab\Core\Database;
use NorthLab\Core\Migrator;
use NorthLab\Service\MaintenanceModeService;

$fails = 0;
$n     = 0;
function check( string $label, bool $ok, string $extra = '' ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label" . ( '' !== $extra ? " ($extra)" : '' ) . "\n"; }
}

Config::load( NL_ROOT . '/config.php' );

shell_exec( 'mariadb -u root -e ' . escapeshellarg(
	'DROP DATABASE IF EXISTS nl_mmode;CREATE DATABASE nl_mmode CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
	. "GRANT ALL ON nl_mmode.* TO 'nl'@'127.0.0.1';FLUSH PRIVILEGES;"
) . ' 2>&1' );

Database::boot( array( 'host' => '127.0.0.1', 'port' => 3306, 'name' => 'nl_mmode', 'user' => 'nl', 'pass' => 'nlpass', 'prefix' => 'nl_' ) );
Migrator::migrate();

/* ------------------------------------------------- Kundenseite nachstellen */

$port   = 8097;
$server = null;

$laeuft = static function () use ( $port ): bool {
	$fp = @fsockopen( '127.0.0.1', $port, $e, $s, 0.3 );
	if ( $fp ) { fclose( $fp ); return true; }
	return false;
};

if ( ! $laeuft() ) {
	$server = proc_open(
		sprintf( 'exec php -S 127.0.0.1:%d %s', $port, escapeshellarg( __DIR__ . '/servers/site-router.php' ) ),
		array( 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
		$pipes
	);
	for ( $i = 0; $i < 40 && ! $laeuft(); $i++ ) { usleep( 100000 ); }
}

if ( ! $laeuft() ) {
	echo "  FEHLT: Der Testserver auf 127.0.0.1:$port startet nicht\n";
	echo "1 Prüfungen, 1 Fehler\n";
	exit( 1 );
}

$siteId = Database::insert(
	'sites',
	array(
		'name'       => 'Testkunde',
		'url'        => 'http://127.0.0.1:' . $port . '/',
		'status'     => 'connected',
		'verify_ssl' => 0,
		'created_at' => gmdate( 'Y-m-d H:i:s' ),
		'updated_at' => gmdate( 'Y-m-d H:i:s' ),
	)
);

/** Adresse der Testseite umstellen und erneut von aussen nachsehen. */
$pruefe = static function ( string $pfad ) use ( $siteId, $port ): array {
	Database::update( 'sites', array( 'url' => 'http://127.0.0.1:' . $port . $pfad ), array( 'id' => $siteId ) );
	return MaintenanceModeService::verify( $siteId );
};

/* ------------------------------------------------------------- Der Normalfall */

$r = $pruefe( '/wartung' );
check( 'Wartungsseite mit 503 wird erkannt', $r['geprueft'] && $r['versteckt'], 'Status ' . $r['status'] );
check( 'Und als Erfolg gemeldet', false !== strpos( $r['hinweis'], 'Besucher sehen die Wartungsseite' ) );

$r = $pruefe( '/wartung-200' );
check( 'Wartungsseite mit 200 wird am Marker erkannt', $r['geprueft'] && $r['versteckt'] );

/* ----------------------------------------- Der gemeldete Fall: Seite bleibt live */

$r = $pruefe( '/live' );
check( 'Lebende Seite gilt nicht als versteckt', $r['geprueft'] && ! $r['versteckt'] );
check( 'Und wird deutlich benannt', false !== strpos( $r['hinweis'], 'weiterhin mit HTTP 200' ), $r['hinweis'] );

/* ------------------------------------------------------- Cache als Verdaechtiger */

$r = $pruefe( '/cf-hit' );
check( 'Cloudflare-Treffer wird erkannt', ! $r['versteckt'] && false !== strpos( $r['cache'], 'Cloudflare' ), $r['cache'] );
check( 'Und als Ursache benannt', false !== strpos( $r['hinweis'], 'Cache' ) );

$r = $pruefe( '/xcache' );
check( 'X-Cache-Treffer wird erkannt', false !== strpos( $r['cache'], 'x-cache' ), $r['cache'] );

$r = $pruefe( '/age-alt' );
check( 'Age ueber null zaehlt als Cache', false !== strpos( $r['cache'], 'Age' ), $r['cache'] );

/* ------------------- Kein falscher Alarm: nicht jede Kopfzeile ist ein Treffer */

$r = $pruefe( '/cf-miss' );
check( 'Cloudflare-MISS ist kein Cache-Treffer', '' === $r['cache'], $r['cache'] );

$r = $pruefe( '/age-null' );
check( 'Age: 0 ist kein Cache-Treffer', '' === $r['cache'], $r['cache'] );

$r = $pruefe( '/wortfalle' );
check( 'Das blosse Wort "maintenance" macht keine Wartungsseite', ! $r['versteckt'] );

/* ------------------------------ Abschalten: haengt die Wartungsseite im Cache? */

$r = $pruefe( '/cf-hit-wartung' );
check( 'Wartungsseite aus dem Cache gilt weiter als versteckt', $r['versteckt'] );

/* ------------------- Nicht erreichbar heisst nicht "greift nicht" ------------ */

Database::update( 'sites', array( 'url' => 'http://127.0.0.1:1/' ), array( 'id' => $siteId ) );
$r = MaintenanceModeService::verify( $siteId );
check( 'Ein gescheiterter Abruf gilt als ungeprueft', ! $r['geprueft'] );
check( 'Und behauptet nicht, der Modus greife nicht', ! $r['versteckt'] && false !== strpos( $r['hinweis'], 'sagt nichts' ), $r['hinweis'] );

Database::update( 'sites', array( 'url' => '' ), array( 'id' => $siteId ) );
$r = MaintenanceModeService::verify( $siteId );
check( 'Ohne hinterlegte Adresse gilt es als ungeprueft', ! $r['geprueft'] );

if ( is_resource( $server ) ) {
	proc_terminate( $server );
	proc_close( $server );
}

echo "$n Prüfungen, $fails Fehler\n";
exit( $fails > 0 ? 1 : 0 );
