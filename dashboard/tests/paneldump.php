<?php
/**
 * Export der Panel-Datenbank: einmal hin, einmal zurueck, dann vergleichen.
 *
 * Laeuft gegen eine echte MariaDB. Ein Export, den man nicht wieder einspielen
 * kann, ist keine Sicherung — deshalb wird hier wirklich zurueckgespielt.
 */
declare( strict_types = 1 );

$root = realpath( dirname( __DIR__ ) );
define( 'NL_ROOT', $root );
define( 'NL_SRC', $root . '/src' );
define( 'NL_STORAGE', sys_get_temp_dir() . '/nlc-dump-test' );
define( 'NL_VERSION', '1.0.0' );

spl_autoload_register( static function ( string $c ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = NL_SRC . '/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );

require_once NL_SRC . '/helpers.php';

use NorthLab\Core\Database;
use NorthLab\Service\PanelBackup;

$fails = 0;
$n     = 0;
function check( string $label, bool $ok, string $extra = '' ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label" . ( '' !== $extra ? " ($extra)" : '' ) . "\n"; }
}

exec( 'rm -rf ' . escapeshellarg( NL_STORAGE ) );
mkdir( NL_STORAGE, 0700, true );

$base = array(
	'host' => '127.0.0.1', 'port' => 3306, 'user' => 'nl', 'pass' => 'nlpass', 'prefix' => 'nl_',
);

Database::boot( $base + array( 'name' => 'nlpanel' ) );

// Aufraeumen und eine ueberschaubare Panel-Datenbank bauen.
Database::pdo()->exec( 'DROP TABLE IF EXISTS `nl_settings`, `nl_sites`, `fremd_tabelle`' );
Database::pdo()->exec(
	'CREATE TABLE `nl_settings` (
		`name` VARCHAR(191) NOT NULL PRIMARY KEY,
		`value` LONGTEXT NULL
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
Database::pdo()->exec(
	'CREATE TABLE `nl_sites` (
		`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
		`name` VARCHAR(191) NOT NULL,
		`private_key` LONGTEXT NULL,
		`notes` TEXT NULL,
		`sort` INT NOT NULL DEFAULT 0
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
// Etwas, das dem Panel nicht gehoert — das darf nicht mitgesichert werden.
Database::pdo()->exec( 'CREATE TABLE `fremd_tabelle` (`x` INT)' );
Database::pdo()->exec( "INSERT INTO `fremd_tabelle` VALUES (1)" );

$heikel = array(
	array( 'Grün & Söhne GmbH', "-----BEGIN KEY-----\nabc\n-----END KEY-----\n", "Zeile1\nZeile2\tTab", 3 ),
	array( "Apostroph's Laden", "\\backslash\\", null, 0 ),
	array( 'Anführung "doppelt"', 'x', "Semikolon; -- Kommentar\n#Raute", -7 ),
	array( 'Emoji 🌊 und Umlaut äöüß', 'y', str_repeat( 'lang ', 400 ), 42 ),
	array( "Null\x00Byte? nein: Steuerzeichen \x01\x02", 'z', '', 1 ),
);

$insert = Database::pdo()->prepare( 'INSERT INTO `nl_sites` (`name`,`private_key`,`notes`,`sort`) VALUES (?,?,?,?)' );
foreach ( $heikel as $row ) { $insert->execute( $row ); }

$settings = Database::pdo()->prepare( 'INSERT INTO `nl_settings` (`name`,`value`) VALUES (?,?)' );
$settings->execute( array( 'agency_name', 'NorthLab' ) );
$settings->execute( array( 'restic_password', 'v2:abc+/def==' ) );
$settings->execute( array( 'leer', null ) );

$vorher = Database::select( 'SELECT * FROM `nl_sites` ORDER BY `id`' );

/* ------------------------------------------------------------- Exportieren */

$target = NL_STORAGE . '/datenbank.sql.gz';
$bytes  = PanelBackup::dump( $target );

check( 'Der Export entsteht', is_file( $target ) );
check( 'Und ist nicht leer', $bytes > 0, (string) $bytes );
check( 'Die gemeldete Groesse stimmt', $bytes === filesize( $target ) );

$sql = (string) file_get_contents( 'compress.zlib://' . $target );
if ( '' === $sql ) { $sql = (string) shell_exec( 'gzip -dc ' . escapeshellarg( $target ) ); }

check( 'Der Export ist gzip-gepackt', "\x1f\x8b" === substr( (string) file_get_contents( $target ), 0, 2 ) );
check( 'Beide Panel-Tabellen sind drin',
	str_contains( $sql, 'CREATE TABLE `nl_sites`' ) && str_contains( $sql, 'CREATE TABLE `nl_settings`' ) );
check( 'Eine fremde Tabelle bleibt draussen', ! str_contains( $sql, 'fremd_tabelle' ) );
check( 'Der Zeichensatz wird gesetzt', str_contains( $sql, 'SET NAMES utf8mb4' ) );
check( 'Fremdschluessel sind waehrend des Einspielens aus',
	str_contains( $sql, 'SET FOREIGN_KEY_CHECKS=0' ) && str_contains( $sql, 'SET FOREIGN_KEY_CHECKS=1' ) );

/* ---------------------------------------------------------- Zurueckspielen */

// Die zweite Datenbank ueber die Kommandozeile, weil root hier ueber den
// Unix-Socket angemeldet wird und nicht ueber TCP.
shell_exec(
	'mariadb -u root -e ' . escapeshellarg(
		'DROP DATABASE IF EXISTS nlpanel_zurueck;'
		. 'CREATE DATABASE nlpanel_zurueck CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
		. "GRANT ALL ON nlpanel_zurueck.* TO 'nl'@'127.0.0.1';FLUSH PRIVILEGES;"
	) . ' 2>&1'
);

$einspielen = shell_exec(
	'gzip -dc ' . escapeshellarg( $target ) . ' | mariadb -u root nlpanel_zurueck 2>&1'
);

check( 'Das Einspielen laeuft ohne Meldung durch', '' === trim( (string) $einspielen ), trim( (string) $einspielen ) );

Database::boot( $base + array( 'name' => 'nlpanel_zurueck' ) );
$nachher = Database::select( 'SELECT * FROM `nl_sites` ORDER BY `id`' );

check( 'Gleiche Zeilenzahl', count( $vorher ) === count( $nachher ),
	count( $vorher ) . ' vs ' . count( $nachher ) );
check( 'Jede Zeile ist Feld für Feld dieselbe', $vorher === $nachher );

$zurueck = array();
foreach ( Database::select( 'SELECT * FROM `nl_settings`' ) as $row ) {
	$zurueck[ $row['name'] ] = $row['value'];
}

check( 'Einstellungen kommen mit', 'NorthLab' === ( $zurueck['agency_name'] ?? '' ) );
check( 'Auch ein verschluesselter Wert', 'v2:abc+/def==' === ( $zurueck['restic_password'] ?? '' ) );
check( 'NULL bleibt NULL', array_key_exists( 'leer', $zurueck ) && null === $zurueck['leer'] );

// Der Punkt, an dem ein selbstgebauter Export sonst scheitert.
$namen = array_column( $nachher, 'name' );
check( 'Apostroph ueberlebt', in_array( "Apostroph's Laden", $namen, true ) );
check( 'Anfuehrungszeichen ueberleben', in_array( 'Anführung "doppelt"', $namen, true ) );
check( 'Backslash ueberlebt',
	'\\backslash\\' === ( $nachher[1]['private_key'] ?? '' ) );
check( 'Umlaute und Emoji ueberleben', in_array( 'Emoji 🌊 und Umlaut äöüß', $namen, true ) );
check( 'Steuerzeichen ueberleben',
	in_array( "Null\x00Byte? nein: Steuerzeichen \x01\x02", $namen, true ) );
check( 'Zeilenumbrueche im Schluessel ueberleben',
	str_contains( (string) ( $nachher[0]['private_key'] ?? '' ), "\n-----END KEY-----" ) );
check( 'Negative Zahlen ueberleben', -7 === (int) ( $nachher[2]['sort'] ?? 0 ) );

// Ein zweiter Export darf nicht an bestehenden Tabellen scheitern.
$zweit = PanelBackup::dump( NL_STORAGE . '/zweiter.sql.gz' );
check( 'Ein zweiter Export laeuft ebenfalls', $zweit > 0 );
$sql2 = (string) shell_exec( 'gzip -dc ' . escapeshellarg( NL_STORAGE . '/zweiter.sql.gz' ) );
check( 'Er wirft bestehende Tabellen vorher weg', str_contains( $sql2, 'DROP TABLE IF EXISTS `nl_sites`' ) );

/* -------------------------------------------------------- Grosse Tabelle */

Database::boot( $base + array( 'name' => 'nlpanel' ) );
Database::pdo()->exec( 'DROP TABLE IF EXISTS `nl_viele`' );
Database::pdo()->exec( 'CREATE TABLE `nl_viele` (`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY, `t` VARCHAR(50)) ENGINE=InnoDB' );

$viele = Database::pdo()->prepare( 'INSERT INTO `nl_viele` (`t`) VALUES (?)' );
for ( $i = 0; $i < 655; $i++ ) { $viele->execute( array( 'zeile-' . $i ) ); }

$gross = (string) shell_exec( 'gzip -dc ' . escapeshellarg( ( PanelBackup::dump( NL_STORAGE . '/gross.sql.gz' ) ? NL_STORAGE . '/gross.sql.gz' : '' ) ) );

check( 'Viele Zeilen werden auf mehrere INSERTs verteilt',
	substr_count( $gross, 'INSERT INTO `nl_viele`' ) >= 4,
	(string) substr_count( $gross, 'INSERT INTO `nl_viele`' ) );
check( 'Und es fehlt keine', str_contains( $gross, "'zeile-0'" ) && str_contains( $gross, "'zeile-654'" ) );

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
