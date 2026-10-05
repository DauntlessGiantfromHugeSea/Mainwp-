<?php
/**
 * Abgleich gegen die Luecken-Datenbank — gegen einen echten HTTP-Server,
 * der die API nachstellt.
 *
 * Der gefaehrlichste Fehlschlag waere eine leere Liste: sie liest sich wie
 * "keine Luecken". Darum wird hier vor allem geprueft, dass in jedem Fall,
 * in dem nichts geprueft werden konnte, auch nichts behauptet wird.
 */
declare( strict_types = 1 );

$src = realpath( dirname( __DIR__ ) . '/src' );
$tmp = sys_get_temp_dir() . '/nl-luecken';

exec( 'rm -rf ' . escapeshellarg( $tmp ) );
mkdir( $tmp . '/wurzel', 0700, true );
mkdir( $tmp . '/storage', 0700, true );

file_put_contents(
	$tmp . '/wurzel/config.php',
	"<?php return array( 'app' => array( 'key' => '" . base64_encode( str_repeat( 'v', 32 ) ) . "', 'url' => 'https://panel.example' ) );\n"
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
use NorthLab\Core\Crypto;
use NorthLab\Core\Database;
use NorthLab\Core\Migrator;
use NorthLab\Core\Setting;
use NorthLab\Repository\SiteRepository;
use NorthLab\Service\VulnerabilityService;

$fails = 0;
$n     = 0;
function check( string $label, bool $ok, string $extra = '' ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label" . ( '' !== $extra ? " ($extra)" : '' ) . "\n"; }
}

Config::load( NL_ROOT . '/config.php' );

shell_exec( 'mariadb -u root -e ' . escapeshellarg(
	'DROP DATABASE IF EXISTS nl_vuln;CREATE DATABASE nl_vuln CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
	. "GRANT ALL ON nl_vuln.* TO 'nl'@'127.0.0.1';FLUSH PRIVILEGES;"
) . ' 2>&1' );

Database::boot( array( 'host' => '127.0.0.1', 'port' => 3306, 'name' => 'nl_vuln', 'user' => 'nl', 'pass' => 'nlpass', 'prefix' => 'nl_' ) );
Migrator::migrate();

/* ------------------------------------------------ Reine Entscheidungslogik */

$daten = array(
	'woo' => array(
		'vulnerabilities' => array(
			array( 'id' => '1', 'title' => 'Alt', 'fixed_in' => '2.5.0', 'cvss' => array( 'severity' => 'high' ) ),
			array( 'id' => '2', 'title' => 'Ohne Fix', 'fixed_in' => null ),
		),
	),
);

$alt = VulnerabilityService::affecting( $daten, '2.0.0' );
check( 'Eine zu alte Fassung ist betroffen', 2 === count( $alt ), (string) count( $alt ) );

$neu = VulnerabilityService::affecting( $daten, '2.5.0' );
check( 'Genau die behobene Fassung ist nicht mehr betroffen', 1 === count( $neu ), (string) count( $neu ) );
check( 'Die ohne Fix bleibt', 'Ohne Fix' === ( $neu[0]['title'] ?? '' ) );

$neuer = VulnerabilityService::affecting( $daten, '3.1.0' );
check( 'Eine neuere auch nicht', 1 === count( $neuer ) );

// Ohne bekannte Fassung lieber melden als verschweigen.
$ohne = VulnerabilityService::affecting( $daten, '' );
check( 'Ohne Fassungsangabe wird gemeldet statt geschwiegen', 2 === count( $ohne ) );

check( 'Die Schwere kommt mit', 'high' === ( $alt[0]['severity'] ?? '' ), (string) ( $alt[0]['severity'] ?? '' ) );

// Die Form der Antwort darf sich aendern, ohne dass still "keine Luecken"
// herauskommt.
check( 'Verschachtelte Form wird gelesen', 2 === count( VulnerabilityService::vulnerabilities( $daten ) ) );
check( 'Flache Form auch',
	1 === count( VulnerabilityService::vulnerabilities( array( 'vulnerabilities' => array( array( 'id' => 'x' ) ) ) ) ) );
check( 'Unbekannte Form ergibt nichts', array() === VulnerabilityService::vulnerabilities( array( 'quatsch' => 1 ) ) );

check( 'Die Kennung wird aus dem Dateinamen geholt', 'woocommerce' === VulnerabilityService::slug( 'woocommerce/woocommerce.php' ) );
check( 'Eine blosse Kennung bleibt', 'akismet' === VulnerabilityService::slug( 'akismet' ) );
// Ein Aufstiegsversuch ergibt leer, nicht etwa den Rest des Pfads: das
// erste Stueck ist "..", und was davon bleibt, ist nichts. Leer heisst
// uebersprungen — genau richtig, denn so etwas gehoert nicht in eine URL.
check( 'Ein Aufstiegsversuch ergibt nichts', '' === VulnerabilityService::slug( '../boese' ),
	VulnerabilityService::slug( '../boese' ) );
check( 'Auch mit fuehrendem Schraegstrich', '' === VulnerabilityService::slug( '/etc/passwd' ),
	VulnerabilityService::slug( '/etc/passwd' ) );
check( 'Sonderzeichen fliegen raus', 'meinplugin' === VulnerabilityService::slug( 'mein plugin!' ),
	VulnerabilityService::slug( 'mein plugin!' ) );
check( 'Bindestriche bleiben', 'wp-super-cache' === VulnerabilityService::slug( 'wp-super-cache/wp-cache.php' ) );

/* ------------------------------------- Ohne Schluessel wird nichts behauptet */

$siteId = Database::insert( 'sites', array(
	'name' => 'Kunde', 'url' => 'https://kunde.example', 'status' => 'connected',
	'created_at' => nl_utc(), 'updated_at' => nl_utc(),
) );

SiteRepository::storePayload( $siteId, array(
	'plugins' => array(
		array( 'slug' => 'loechrig/loechrig.php', 'name' => 'Löchrig', 'version' => '2.0.0' ),
		array( 'slug' => 'sauber', 'name' => 'Sauber', 'version' => '1.2.0' ),
		array( 'slug' => 'unbekannt', 'name' => 'Unbekannt', 'version' => '1.0.0' ),
	),
) );

check( 'Ohne Schluessel gilt es als nicht eingerichtet', ! VulnerabilityService::configured() );

$ohneKey = VulnerabilityService::check( $siteId );
check( 'Und es wird nichts geprueft', ! $ohneKey['ok'] );
check( 'Die Liste bleibt leer', array() === $ohneKey['findings'] );
check( 'Es wird gesagt, dass die Quelle fehlt',
	false !== strpos( $ohneKey['error'], 'kein WPScan-Schlüssel' ), $ohneKey['error'] );
check( 'Und ausdruecklich, dass nichts behauptet wird',
	false !== strpos( $ohneKey['error'], 'nichts behauptet' ), $ohneKey['error'] );

/* --------------------------------------------- Mit Schluessel, echter Abruf */

$port = 8096;
$laeuft = static function () use ( $port ): bool {
	$fp = @fsockopen( '127.0.0.1', $port, $e, $s, 0.3 );
	if ( $fp ) { fclose( $fp ); return true; }
	return false;
};

$server = null;
if ( ! $laeuft() ) {
	$server = proc_open(
		sprintf( 'exec php -S 127.0.0.1:%d %s', $port, escapeshellarg( __DIR__ . '/servers/wpscan-router.php' ) ),
		array( 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
		$pipes
	);
	for ( $i = 0; $i < 40 && ! $laeuft(); $i++ ) { usleep( 100000 ); }
}

if ( ! $laeuft() ) {
	echo "  FEHLT: Der Testserver auf 127.0.0.1:$port startet nicht\n";
	printf( "%d Prüfungen, %d Fehler\n", $n + 1, $fails + 1 );
	exit( 1 );
}

$basis = 'http://127.0.0.1:' . $port . '/';

Setting::setMany( array(
	'wpscan_endpoint'    => $basis,
	'wpscan_api_key'     => Crypto::encrypt( 'uk1_testschluessel' ),
	'wpscan_budget'      => '20',
	'wpscan_budget_day'  => '',
	'wpscan_budget_used' => '0',
) );

/** Wie oft der Testserver gefragt wurde. */
$abrufe = static function () use ( $basis ): int {
	$c = curl_init( $basis . '__abrufe' );
	curl_setopt_array( $c, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => array( 'Authorization: Token token=x' ) ) );
	$a = (string) curl_exec( $c );
	curl_close( $c );
	return (int) $a;
};
$reset = static function () use ( $basis ): void {
	$c = curl_init( $basis . '__reset' );
	curl_setopt_array( $c, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => array( 'Authorization: Token token=x' ) ) );
	curl_exec( $c );
	curl_close( $c );
};

$reset();

check( 'Mit Schluessel gilt es als eingerichtet', VulnerabilityService::configured() );

$ergebnis = VulnerabilityService::check( $siteId );

check( 'Der Abgleich laeuft', $ergebnis['ok'], $ergebnis['error'] );
check( 'Drei Plugins geprueft', 3 === $ergebnis['checked'], (string) $ergebnis['checked'] );

$titel = array_column( $ergebnis['findings'], 'title' );
check( 'Die SQL-Luecke wird gefunden', in_array( 'Authenticated SQL Injection', $titel, true ), implode( ' | ', $titel ) );
check( 'Die ungepatchte auch', in_array( 'Ungepatchte Lücke ohne Fassung', $titel, true ) );
check( 'Das saubere Plugin faellt nicht auf', 2 === count( $ergebnis['findings'] ), (string) count( $ergebnis['findings'] ) );
check( 'Der Plugin-Name steht dabei', 'Löchrig' === ( $ergebnis['findings'][0]['name'] ?? '' ) );
check( 'Und die behebende Fassung', '2.5.0' === ( $ergebnis['findings'][0]['fixed_in'] ?? '' ) );

$vorher = $abrufe();
check( 'Es wurde wirklich abgefragt', $vorher >= 3, (string) $vorher );

/* ------------------------------------------- Der Zwischenspeicher greift */

VulnerabilityService::check( $siteId );
check( 'Der zweite Durchgang fragt nicht erneut', $vorher === $abrufe(), sprintf( '%d -> %d', $vorher, $abrufe() ) );

// Auch eine unbekannte Kennung wird gemerkt - sonst wird jede Woche
// erneut nach etwas gefragt, das es dort nicht gibt.
$unbekannt = Database::selectOne(
	'SELECT `payload` FROM `' . Database::table( 'vuln_cache' ) . "` WHERE `slug` = 'unbekannt'"
);
check( 'Eine unbekannte Kennung wird auch gemerkt', null !== $unbekannt );

/* ------------------------------------------------------------- Das Budget */

Setting::setMany( array( 'wpscan_budget' => '1', 'wpscan_budget_day' => gmdate( 'Y-m-d' ), 'wpscan_budget_used' => '1' ) );
check( 'Das Budget ist aufgebraucht', 0 === VulnerabilityService::budgetLeft() );

$reset();
$neuesPlugin = VulnerabilityService::lookup( 'nochnie' );
check( 'Ohne Budget wird nicht abgefragt', 0 === $abrufe(), (string) $abrufe() );
check( 'Und nichts behauptet', null === $neuesPlugin );

// Ein Plugin, das schon im Speicher liegt, geht trotzdem - ein alter Stand
// ist besser als gar keiner.
check( 'Bekanntes aus dem Speicher geht weiter', null !== VulnerabilityService::lookup( 'loechrig' ) );

Setting::setMany( array( 'wpscan_budget' => '20', 'wpscan_budget_used' => '0' ) );
check( 'Am naechsten Tag ist das Budget wieder da', 20 === VulnerabilityService::budgetLeft() );

/* ----------------------------------------- Kontingent der Gegenseite erschoepft */

$reset();
Setting::set( 'wpscan_budget_used', '0' );
$zuviel = VulnerabilityService::lookup( 'zuviel' );

check( 'Eine 429-Antwort gilt nicht als "keine Luecken"', null === $zuviel );
check( 'Und das Budget wird fuer heute geschlossen', 0 === VulnerabilityService::budgetLeft() );

if ( is_resource( $server ) ) {
	proc_terminate( $server );
	proc_close( $server );
}

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
