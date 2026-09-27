<?php
/**
 * Einstellungen: der Zwischenspeicher darf nie ein unvollstaendiges Bild
 * liefern. Laeuft gegen eine echte MariaDB.
 */
declare( strict_types = 1 );

$root = dirname( __DIR__ );

define( 'NL_ROOT', $root );
define( 'NL_SRC', $root . '/src' );
define( 'NL_STORAGE', sys_get_temp_dir() . '/nlc-setting-test' );
define( 'NL_VERSION', '1.0.0' );

spl_autoload_register( static function ( string $c ) use ( $root ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = $root . '/src/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );

require_once $root . '/src/helpers.php';

use NorthLab\Core\Database;
use NorthLab\Core\Setting;

$fails = 0;
$n     = 0;
function check( string $label, bool $ok, string $extra = '' ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label" . ( '' !== $extra ? " ($extra)" : '' ) . "\n"; }
}

$benutzer = getenv( 'NL_TEST_DB_USER' ) ?: 'nl';
$passwort = getenv( 'NL_TEST_DB_PASS' ) ?: 'nlpass';

shell_exec( 'mariadb -u root -e ' . escapeshellarg(
	'DROP DATABASE IF EXISTS nl_setting;CREATE DATABASE nl_setting CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
	. "GRANT ALL ON nl_setting.* TO '$benutzer'@'127.0.0.1';FLUSH PRIVILEGES;"
) . ' 2>&1' );

Database::boot( array(
	'host' => '127.0.0.1', 'port' => 3306, 'name' => 'nl_setting',
	'user' => $benutzer, 'pass' => $passwort, 'prefix' => 'nl_',
) );

Database::pdo()->exec(
	'CREATE TABLE `nl_settings` (
		`name` VARCHAR(191) NOT NULL PRIMARY KEY,
		`value` LONGTEXT NULL
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

Database::pdo()->exec( "INSERT INTO `nl_settings` VALUES ('agency_name','NorthLab'), ('backup_hour','3'), ('backup_enabled','1')" );

/* ------------------------------------------------------- Gewoehnlich lesen */

check( 'Ein vorhandener Wert kommt zurueck', 'NorthLab' === Setting::get( 'agency_name' ) );
check( 'Ein fehlender liefert die Vorgabe', 'ersatz' === Setting::get( 'gibtsnicht', 'ersatz' ) );
check( 'Zahlen', 3 === Setting::getInt( 'backup_hour' ) );
check( 'Wahrheitswerte', Setting::getBool( 'backup_enabled' ) );

/* ------------------- Schreiben, bevor irgendetwas gelesen wurde ----------- */

// Genau hier lag der Fehler: die Zuweisung an den noch leeren Zwischenspeicher
// legte ein Feld mit einem einzigen Eintrag an. all() hielt das fuer geladen,
// und jede weitere Abfrage im selben Aufruf bekam die Vorgabe — obwohl in der
// Datenbank etwas anderes stand.
Setting::flush();
Setting::set( 'irgendwas', 'neu' );

check( 'Nach einem Schreibvorgang ist der neue Wert da', 'neu' === Setting::get( 'irgendwas' ) );
check( 'Und die uebrigen Werte sind nicht verschwunden',
	'NorthLab' === Setting::get( 'agency_name' ), Setting::get( 'agency_name', '(leer)' ) );
check( 'Auch die Zahlen nicht', 3 === Setting::getInt( 'backup_hour' ) );
check( 'Der Zwischenspeicher ist vollstaendig', count( Setting::all() ) >= 4, (string) count( Setting::all() ) );

/* --------------------- Dasselbe fuer setMany und forget ------------------- */

Setting::flush();
Setting::setMany( array( 'eins' => '1', 'zwei' => '2' ) );

check( 'setMany: beide Werte stehen', '1' === Setting::get( 'eins' ) && '2' === Setting::get( 'zwei' ) );
check( 'setMany: der Rest ueberlebt', 'NorthLab' === Setting::get( 'agency_name' ) );

Setting::flush();
Setting::forget( 'eins' );

check( 'forget: der Wert ist weg', '' === Setting::get( 'eins' ) );
check( 'forget: der Rest ueberlebt', 'NorthLab' === Setting::get( 'agency_name' ) );

/* ------------------------------------------ Geschrieben wird auch wirklich */

Setting::flush();
check( 'Nach dem Leeren kommt der Wert aus der Datenbank', 'neu' === Setting::get( 'irgendwas' ) );
check( 'Und der geloeschte bleibt geloescht', '' === Setting::get( 'eins' ) );

/* ----------------------------------------------------------- Feldwerte */

Setting::set( 'liste', array( 'a', 'b' ) );
check( 'Ein Feld wird als JSON abgelegt und kommt als Feld zurueck',
	array( 'a', 'b' ) === Setting::getArray( 'liste' ) );
Setting::set( 'ja', true );
check( 'Wahr wird zu 1', '1' === Setting::get( 'ja' ) );

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
