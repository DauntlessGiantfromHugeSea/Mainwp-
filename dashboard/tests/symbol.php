<?php
/**
 * Das Menüsymbol auf den Kundenseiten.
 *
 * Der Punkt dieses Laufs: es hängt nicht mehr am Branding. Wer das Plugin
 * hat, bekommt das Symbol — ohne dass jemand im Panel etwas anwendet.
 * Läuft gegen eine echte MariaDB, weil der Merker je Seite in der Zeile
 * steht und nicht in einer Sitzung.
 *
 *   php dashboard/tests/symbol.php
 */
declare( strict_types = 1 );

/**
 * Statt zu telefonieren: mitschreiben. Muss vor dem Autoloader stehen —
 * der überspringt, was schon deklariert ist.
 */
namespace NorthLab\Service {
	class ChildClient {
		/** @var array<int,array<string,mixed>> */
		public static array $gesendet = array();
		/** Adressen, die scheitern sollen. */
		public static array $kaputt = array();

		public static function post( array $site, string $route, array $body = array(), ?int $timeout = null ): array {
			self::$gesendet[] = array( 'site' => (int) $site['id'], 'route' => $route, 'body' => $body );

			if ( in_array( (int) $site['id'], self::$kaputt, true ) ) {
				return array( 'ok' => false, 'status' => 500, 'error' => 'kaputt', 'data' => array() );
			}

			return array( 'ok' => true, 'status' => 200, 'error' => '', 'data' => array( 'symbol' => true ) );
		}
	}
}

namespace {

$root = realpath( dirname( __DIR__ ) );
define( 'NL_ROOT', $root );
define( 'NL_SRC', $root . '/src' );
define( 'NL_STORAGE', sys_get_temp_dir() . '/nlc-symbol-test' );
define( 'NL_VERSION', '1.0.0' );

spl_autoload_register( static function ( string $c ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = NL_SRC . '/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );

require_once NL_SRC . '/helpers.php';

use NorthLab\Core\Database;
use NorthLab\Repository\SiteRepository;
use NorthLab\Service\ChildClient;
use NorthLab\Service\IconService;
use NorthLab\Service\MenuGlyphService;

$fails = 0;
$n     = 0;
function check( string $label, bool $ok, string $extra = '' ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label" . ( '' !== $extra ? " ($extra)" : '' ) . "\n"; }
}

exec( 'rm -rf ' . escapeshellarg( NL_STORAGE ) );
mkdir( NL_STORAGE . '/branding', 0700, true );

$user = getenv( 'NL_TEST_DB_USER' ) ?: 'nl';
$pass = getenv( 'NL_TEST_DB_PASS' ) ?: 'nlpass';

$pdo = new PDO( 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $user, $pass );
$pdo->exec( 'CREATE DATABASE IF NOT EXISTS `nl_symbol` CHARACTER SET utf8mb4' );

Database::boot(
	array(
		'host' => '127.0.0.1', 'port' => 3306, 'user' => $user, 'pass' => $pass,
		'name' => 'nl_symbol', 'prefix' => 'nl_',
	)
);

foreach ( array( 'sites', 'jobs', 'settings', 'activity' ) as $t ) {
	Database::pdo()->exec( 'DROP TABLE IF EXISTS `nl_' . $t . '`' );
}

\NorthLab\Core\Migrator::migrate();

/* --------------------------------------------------------------- Seiten */

/**
 * @return int
 */
function seite( string $name, string $url, string $version, string $status = 'connected', int $pausiert = 0, string $typ = 'wordpress' ) {
	return Database::insert( 'sites', array(
		'name' => $name, 'url' => $url, 'site_type' => $typ, 'status' => $status,
		'is_paused' => $pausiert, 'child_version' => $version,
		'connection_id' => bin2hex( random_bytes( 8 ) ),
		'created_at' => nl_utc(), 'updated_at' => nl_utc(),
	) );
}

$neu     = seite( 'Neu', 'https://neu.de', '1.9.0' );
$alt     = seite( 'Alt', 'https://alt.de', '1.8.0' );
$pause   = seite( 'Pause', 'https://pause.de', '1.9.0', 'connected', 1 );
$fremd   = seite( 'Fremd', 'https://fremd.de', '', 'connected', 0, 'generic' );
$zweit   = seite( 'Zweite', 'https://zweite.de', '2.0.0' );

/* ------------------------------------------------------------- Der Stand */

// Der Punkt dieser Runde: ohne dass jemand etwas hinterlegt, gilt das
// mitgelieferte Zeichen — und damit hat jede Seite eines.
check( 'Das mitgelieferte Zeichen liegt im Panel', is_file( IconService::shippedPath() ) );
check( 'Ohne Upload gibt es trotzdem ein Zeichen', IconService::menuGlyph()['ok'] );
check( 'Aber keines eigenes', ! IconService::hasOwnGlyph() );
$mitgeliefert = MenuGlyphService::stamp();
check( 'Und damit auch einen Stand', 'ohne' !== $mitgeliefert && 32 === strlen( $mitgeliefert ) );

$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><circle cx="32" cy="32" r="28"/></svg>';
file_put_contents( NL_STORAGE . '/branding/glyph.svg', $svg );

$stand = MenuGlyphService::stamp();
check( 'Mit eigenem Zeichen ist der Stand ein Hash', 32 === strlen( $stand ) && 'ohne' !== $stand );
check( 'Und ein anderer als der des mitgelieferten', $stand !== $mitgeliefert );
check( 'Jetzt gibt es ein eigenes', IconService::hasOwnGlyph() );
check( 'Derselbe Inhalt ergibt denselben Stand', $stand === MenuGlyphService::stamp() );

file_put_contents( NL_STORAGE . '/branding/glyph.svg', str_replace( 'r="28"', 'r="24"', $svg ) );
check( 'Ein anderer Inhalt ergibt einen anderen Stand', $stand !== MenuGlyphService::stamp() );
file_put_contents( NL_STORAGE . '/branding/glyph.svg', $svg );
check( 'Und zurueck ergibt wieder den alten', $stand === MenuGlyphService::stamp() );

/* ------------------------------------------------------------ Wer braucht */

$holen = static function ( int $id ): array {
	return (array) Database::selectOne(
		'SELECT * FROM `' . Database::table( 'sites' ) . '` WHERE `id` = :i', array( 'i' => $id )
	);
};

check( 'Eine Seite ohne Merker braucht es', MenuGlyphService::needsPush( $holen( $neu ) ) );
check( 'Eine zu alte Fassung bekommt nichts', ! MenuGlyphService::needsPush( $holen( $alt ) ) );
check( 'Eine Nicht-WordPress-Seite bekommt nichts', ! MenuGlyphService::needsPush( $holen( $fremd ) ) );
check( 'Eine neuere Fassung bekommt es', MenuGlyphService::needsPush( $holen( $zweit ) ) );

/* ------------------------------------------------------------- Das Senden */

ChildClient::$gesendet = array();
check( 'Senden gelingt', MenuGlyphService::push( $holen( $neu ) ) );
check( 'Genau eine Anfrage', 1 === count( ChildClient::$gesendet ) );
check( 'An die richtige Stelle', '/symbol' === ChildClient::$gesendet[0]['route'] );
check( 'Mit dem Zeichen', ( ChildClient::$gesendet[0]['body']['glyph'] ?? '' ) === IconService::menuGlyph()['data'] );
check( 'Und dem Stand', ( ChildClient::$gesendet[0]['body']['stamp'] ?? '' ) === $stand );
check( 'Der Merker steht jetzt in der Zeile', $stand === (string) $holen( $neu )['glyph_stamp'] );
check( 'Und damit braucht die Seite nichts mehr', ! MenuGlyphService::needsPush( $holen( $neu ) ) );

ChildClient::$gesendet = array();
MenuGlyphService::afterSync( $holen( $neu ) );
check( 'Ein Sync ohne Aenderung kostet keine Anfrage', array() === ChildClient::$gesendet );

// Scheitert das Senden, darf der Merker nicht stehen — sonst bliebe die
// Seite fuer immer ohne Symbol.
ChildClient::$kaputt   = array( $zweit );
ChildClient::$gesendet = array();
check( 'Ein Fehlschlag meldet sich als Fehlschlag', ! MenuGlyphService::push( $holen( $zweit ) ) );
check( 'Und hinterlaesst keinen Merker', '' === (string) $holen( $zweit )['glyph_stamp'] );
check( 'Die Seite braucht es also weiterhin', MenuGlyphService::needsPush( $holen( $zweit ) ) );
ChildClient::$kaputt = array();

/* ------------------------------------------------------------ Alle Seiten */

ChildClient::$gesendet = array();
$runde = MenuGlyphService::pushAll();

// Nur die zweite Seite ist offen: die erste hat den Stand schon, die alte
// Fassung kann noch nicht, die pausierte und die fremde zaehlen gar nicht mit.
check( 'Die offene Seite bekommt es', 1 === $runde['sent'], 'gesendet: ' . $runde['sent'] );
check( 'Keine scheitert', 0 === $runde['failed'] );
check( 'Die alte Fassung bleibt offen', 1 === $runde['skipped'], 'offen: ' . $runde['skipped'] );

$ziele = array_column( ChildClient::$gesendet, 'site' );
check( 'Genau die eine Seite', array( $zweit ) === $ziele );
check( 'Die pausierte Seite wird nicht behelligt', ! in_array( $pause, $ziele, true ) );
check( 'Die fremde Seite auch nicht', ! in_array( $fremd, $ziele, true ) );
check( 'Die zu alte Seite auch nicht', ! in_array( $alt, $ziele, true ) );
check( 'Die Seite mit dem Stand auch nicht', ! in_array( $neu, $ziele, true ) );

// Genau der Punkt dieser Aenderung: das Symbol faehrt nicht mehr im
// Branding mit. Stuende es wieder dort, kaeme es nur dort an, wo jemand
// Branding angewandt hat — und diese Pruefung wuerde rot.
check(
	'Das Branding traegt das Symbol nicht mehr',
	! array_key_exists( 'menu_icon', \NorthLab\Service\BrandingService::design() )
);
check(
	'Und nirgends sonst in den Branding-Daten',
	! str_contains( (string) json_encode( \NorthLab\Service\BrandingService::design() ), 'menu_icon' )
);

ChildClient::$gesendet = array();
$nochmal = MenuGlyphService::pushAll();
check( 'Ein zweiter Durchgang schickt nichts mehr', 0 === $nochmal['sent'] && array() === ChildClient::$gesendet );

// Ein neues Zeichen erreicht alle, ohne dass jemand etwas anwendet.
file_put_contents( NL_STORAGE . '/branding/glyph.svg', str_replace( 'r="28"', 'r="20"', $svg ) );
ChildClient::$gesendet = array();
$dritte = MenuGlyphService::pushAll();
check( 'Ein neues Zeichen geht erneut an alle', 2 === $dritte['sent'], 'gesendet: ' . $dritte['sent'] );

// Ein eigenes Zeichen zuruecknehmen fuehrt nicht ins Nichts, sondern auf
// das mitgelieferte — auch das will verteilt werden.
IconService::clearGlyph();
ChildClient::$gesendet = array();
$vierte = MenuGlyphService::pushAll();
check( 'Auch das Zuruecknehmen wird verteilt', 2 === $vierte['sent'], 'gesendet: ' . $vierte['sent'] );
check( 'Danach gilt wieder das mitgelieferte', $mitgeliefert === ( ChildClient::$gesendet[0]['body']['stamp'] ?? '' ) );
check( 'Und es ist etwas unterwegs, nicht nichts', '' !== ( ChildClient::$gesendet[0]['body']['glyph'] ?? '' ) );

// Nur wenn die mitgelieferte Datei selbst fehlt, bleibt es beim Schild von
// WordPress. Das ist der einzige Fall, in dem eine Seite ohne Zeichen bleibt.
$beiseite = NL_STORAGE . '/logo-beiseite.svg';
rename( IconService::shippedPath(), $beiseite );
check( 'Ohne die mitgelieferte Datei gibt es kein Zeichen', ! IconService::menuGlyph()['ok'] );
check( 'Und der Stand heisst "ohne"', 'ohne' === MenuGlyphService::stamp() );
rename( $beiseite, IconService::shippedPath() );
check( 'Zurueckgelegt gilt sie wieder', $mitgeliefert === MenuGlyphService::stamp() );

/* ----------------------------------------------------- Mitgeliefertes Logo */

check( 'Es ist eine SVG-Datei', str_contains( (string) file_get_contents( IconService::shippedPath() ), '<svg' ) );
check( 'Es laesst sich zu einer Form bereinigen', '' !== IconService::silhouette( (string) file_get_contents( IconService::shippedPath() ) ) );
check( 'Ohne eigene Quelle gilt das fertige Symbol', ! IconService::hasSource() );
check( 'Und das ist das mitgelieferte', str_contains( IconService::svg(), '<rect width="512" height="512"' ) );

// Die Dateien, die der Browser-Reiter und das Handy wirklich anfassen:
// liegen sie nicht bereit, zeigt das Panel ueberall das alte Zeichen.
foreach ( array_keys( IconService::SIZES ) as $groesse ) {
	check( 'Die Fassung ' . $groesse . ' ist mitgeliefert', null !== IconService::rasterPath( $groesse ) );
	check( 'Und sie ist ein PNG', 'image/png' === (string) ( @getimagesize( (string) IconService::rasterPath( $groesse ) )['mime'] ?? '' ) );
}

// Jede Groesse eine eigene Datei: frueher lieferte das Panel fuer 32 Pixel
// ein 192er aus, das der Browser dann selbst herunterrechnen musste.
$wege = array();
foreach ( array_keys( IconService::SIZES ) as $groesse ) {
	$wege[] = basename( (string) IconService::rasterPath( $groesse ) );
}
check( 'Keine Groesse leiht sich die Datei einer anderen', count( $wege ) === count( array_unique( $wege ) ) );

echo "\n$n Prüfungen, $fails Fehler\n";
exit( $fails > 0 ? 1 : 0 );

}
