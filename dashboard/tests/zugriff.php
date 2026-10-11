<?php
/**
 * Wer darf was sehen — und was bleibt liegen, wenn eine Seite geht.
 *
 * Laeuft gegen eine echte MariaDB, weil es hier um SQL geht: um die Frage,
 * ob eine Einschraenkung wirklich in der Abfrage landet, und ob eine leere
 * Liste gueltiges SQL ergibt. Beides laesst sich nicht behaupten, das muss
 * die Datenbank beantworten.
 *
 *   php dashboard/tests/zugriff.php
 */
declare( strict_types = 1 );

$root = realpath( dirname( __DIR__ ) );
define( 'NL_ROOT', $root );
define( 'NL_SRC', $root . '/src' );
define( 'NL_RESOURCES', $root . '/resources' );
define( 'NL_VIEWS', $root . '/views' );
define( 'NL_STORAGE', sys_get_temp_dir() . '/nlc-zugriff-test' );
define( 'NL_VERSION', '1.0.0' );

spl_autoload_register( static function ( string $c ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = NL_SRC . '/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );

require_once NL_SRC . '/helpers.php';

use NorthLab\Core\Database;
use NorthLab\Core\Http;
use NorthLab\Repository\ReportRepository;
use NorthLab\Repository\SiteRepository;
use NorthLab\Service\BackupService;
use NorthLab\Service\ChildPackager;

$fails = 0;
$n     = 0;
function check( string $label, bool $ok, string $extra = '' ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label" . ( '' !== $extra ? " ($extra)" : '' ) . "\n"; }
}

exec( 'rm -rf ' . escapeshellarg( NL_STORAGE ) );
mkdir( NL_STORAGE, 0700, true );

$user = getenv( 'NL_TEST_DB_USER' ) ?: 'nl';
$pass = getenv( 'NL_TEST_DB_PASS' ) ?: 'nlpass';

$pdo = new PDO( 'mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $user, $pass );
$pdo->exec( 'CREATE DATABASE IF NOT EXISTS `nl_zugriff` CHARACTER SET utf8mb4' );

Database::boot(
	array(
		'host' => '127.0.0.1', 'port' => 3306, 'user' => $user, 'pass' => $pass,
		'name' => 'nl_zugriff', 'prefix' => 'nl_',
	)
);

foreach ( array( 'reports', 'sites', 'clients', 'user_sites', 'site_data', 'updates', 'uptime_events', 'update_jobs' ) as $t ) {
	Database::pdo()->exec( 'DROP TABLE IF EXISTS `nl_' . $t . '`' );
}

\NorthLab\Core\Migrator::migrate();

/* ------------------------------------------------------------ Bestand */

$kundeA = Database::insert( 'clients', array( 'name' => 'Kunde A', 'created_at' => nl_utc() ) );
$kundeB = Database::insert( 'clients', array( 'name' => 'Kunde B', 'created_at' => nl_utc() ) );

function seite( string $name, string $url, ?int $client ): int {
	return Database::insert(
		'sites',
		array(
			'name' => $name, 'url' => $url, 'client_id' => $client,
			'created_at' => nl_utc(), 'updated_at' => nl_utc(),
		)
	);
}

$seiteA = seite( 'Shop A', 'https://a.example', $kundeA );
$seiteB = seite( 'Blog B', 'https://b.example', $kundeB );

$berichtA   = ReportRepository::insert( array( 'client_id' => $kundeA, 'site_id' => $seiteA, 'title' => 'A im Mai', 'period_start' => nl_utc(), 'period_end' => nl_utc(), 'status' => 'ready', 'html' => '<p>A</p>', 'created_at' => nl_utc() ) );
$berichtB   = ReportRepository::insert( array( 'client_id' => $kundeB, 'site_id' => $seiteB, 'title' => 'B im Mai', 'period_start' => nl_utc(), 'period_end' => nl_utc(), 'status' => 'ready', 'html' => '<p>B</p>', 'created_at' => nl_utc() ) );
$berichtAll = ReportRepository::insert( array( 'client_id' => null, 'site_id' => null, 'title' => 'Alle Seiten', 'period_start' => nl_utc(), 'period_end' => nl_utc(), 'status' => 'ready', 'html' => '<p>alle</p>', 'created_at' => nl_utc() ) );

/* ------------------------------------------------------- Berichte lesen */

echo "Berichte\n";

$alle = ReportRepository::recent( 60, 0, null );
check( 'Wer alles sieht, sieht alle drei Berichte', 3 === count( $alle ), (string) count( $alle ) );

$nurA  = ReportRepository::recent( 60, 0, array( $seiteA ) );
$titel = array_column( $nurA, 'title' );
sort( $titel );
check( 'Ein auf Seite A beschraenktes Konto sieht nur dessen Bericht', array( 'A im Mai' ) === $titel, implode( ', ', $titel ) );

// Der eigentliche Punkt: die Nummer hochzaehlen half frueher.
check( 'Der eigene Bericht ist sichtbar', ReportRepository::visibleTo( (array) ReportRepository::find( $berichtA ), array( $seiteA ) ) );
check( 'Der fremde Bericht ist es nicht', ! ReportRepository::visibleTo( (array) ReportRepository::find( $berichtB ), array( $seiteA ) ) );
check( 'Ein Bericht ueber alle Seiten gehoert niemandem einzeln', ! ReportRepository::visibleTo( (array) ReportRepository::find( $berichtAll ), array( $seiteA ) ) );
check( 'Wer alles sieht, sieht auch den', ReportRepository::visibleTo( (array) ReportRepository::find( $berichtAll ), null ) );

// Ueber den Kunden: eine zweite Seite desselben Kunden macht dessen
// Kundenbericht sichtbar, auch ohne direkten Seitenbezug.
$berichtKundeA = ReportRepository::insert( array( 'client_id' => $kundeA, 'site_id' => null, 'title' => 'A gesamt', 'period_start' => nl_utc(), 'period_end' => nl_utc(), 'status' => 'ready', 'html' => '', 'created_at' => nl_utc() ) );
check( 'Der Kundenbericht ist fuer wen eine Seite des Kunden betreut sichtbar', ReportRepository::visibleTo( (array) ReportRepository::find( $berichtKundeA ), array( $seiteA ) ) );
check( 'Und fuer wen das nicht tut, nicht', ! ReportRepository::visibleTo( (array) ReportRepository::find( $berichtKundeA ), array( $seiteB ) ) );

/* ------------------------------------------- Konto ohne jede Zuordnung */

echo "Leere Zuordnung\n";

// Ein Konto mit Seitenbindung, dem noch keine Seite zugeteilt wurde, liefert
// eine leere Liste. Daraus wurde frueher "IN 1=0" — ein Syntaxfehler, kein
// leeres Ergebnis. Die Startseite antwortete mit einem Serverfehler.
[ $in, $params ] = Database::inClause( array(), 'vis' );
check( 'Die leere Liste ergibt etwas, das hinter IN passt', '(NULL)' === $in, $in );

$fehler = '';
try {
	$treffer = Database::select( 'SELECT `id` FROM `nl_sites` WHERE `id` IN ' . $in, $params );
} catch ( Throwable $e ) {
	$fehler  = $e->getMessage();
	$treffer = array();
}
check( 'Und die Abfrage laeuft durch', '' === $fehler, $fehler );
check( 'Sie findet nichts', array() === $treffer );

check( 'Die Seitenliste bleibt leer statt zu scheitern', array() === SiteRepository::all( array( 'site_ids' => array() ) ) );
check( 'Die Berichtsliste ebenso', array() === ReportRepository::recent( 60, 0, array() ) );

/* ------------------------------------------------- Sicherungs-Namensraum */

echo "Namensraum der Sicherungen\n";

// Der Wirtsname kommt aus der Adresse und ist kein Schluessel.
check(
	'Zwei Seiten unter demselben Wirt haben verschiedene Kennzeichen',
	BackupService::tagFor( 11 ) !== BackupService::tagFor( 12 )
);
check(
	'Aber denselben Wirt — genau deshalb braucht es das Kennzeichen',
	BackupService::hostFor( array( 'id' => 11, 'url' => 'https://kunde.de/shop' ) )
		=== BackupService::hostFor( array( 'id' => 12, 'url' => 'https://kunde.de/blog' ) )
);

// "panel" gehoert der Eigensicherung. In der liegen config.php und der
// vollstaendige Datenbankabzug.
check(
	'Eine Seite kann den Wirt der Eigensicherung nicht an sich reissen',
	'panel' !== BackupService::hostFor( array( 'id' => 7, 'url' => 'https://panel' ) )
);
check(
	'Sie bekommt stattdessen ihren eigenen',
	'site-7' === BackupService::hostFor( array( 'id' => 7, 'url' => 'https://panel' ) )
);
check(
	'Gross geschrieben zaehlt genauso',
	'site-7' === BackupService::hostFor( array( 'id' => 7, 'url' => 'https://PANEL' ) )
);
check(
	'Ein normaler Wirt bleibt, wie er ist',
	'kunde.de' === BackupService::hostFor( array( 'id' => 7, 'url' => 'https://kunde.de/shop' ) )
);

/* --------------------------------------- Adressen aus der Kundenantwort */

echo "Adressen von der Kundenseite\n";

check( 'Dieselbe Adresse passt', Http::sameHost( 'https://kunde.de/wp-admin/', 'https://kunde.de' ) );
check( 'Gross- und Kleinschreibung spielt keine Rolle', Http::sameHost( 'https://KUNDE.de/wp-admin/', 'https://kunde.de' ) );
check( 'Ein fremder Wirt passt nicht', ! Http::sameHost( 'https://angreifer.example/wp-login', 'https://kunde.de' ) );
check( 'javascript: ist keine Adresse', ! Http::sameHost( 'javascript:alert(1)', 'https://kunde.de' ) );
check( 'Leer auch nicht', ! Http::sameHost( '', 'https://kunde.de' ) );
check( 'Ein Unterwirt ist ein anderer Wirt', ! Http::sameHost( 'https://evil.kunde.de.angreifer.example/', 'https://kunde.de' ) );

/* ---------------------------------------- Seite entfernen raeumt wirklich */

echo "Seite entfernen\n";

Database::insert( 'update_jobs', array( 'site_id' => $seiteB, 'status' => 'wartet', 'requested_at' => nl_utc(), 'total' => 0 ) );
check( 'Vorher steht ein Auftrag da', 1 === count( Database::select( 'SELECT `id` FROM `nl_update_jobs` WHERE `site_id` = :s', array( 's' => $seiteB ) ) ) );

SiteRepository::delete( $seiteB );
check(
	'Nach dem Entfernen nicht mehr — sonst greift ihn der Zeitplaner ewig auf',
	array() === Database::select( 'SELECT `id` FROM `nl_update_jobs` WHERE `site_id` = :s', array( 's' => $seiteB ) )
);

/* ----------------------------------------------- Auslieferung des Plugins */

echo "Child-Paket\n";

// Das Panel signiert die Pruefsumme des Pakets mit dem Schluessel der
// Verbindung. Gebildet wird sie ueber das Verzeichnis auf der Platte — also
// darf nur ausgeliefert werden, was jemand bewusst festgehalten hat.
$quelle = ChildPackager::sourcePath();
$lock   = dirname( $quelle ) . '/version.lock';
$teile  = preg_split( '/\s+/', trim( (string) file_get_contents( $lock ) ) ) ?: array();

check( 'Die Sperrdatei nennt die Version des Plugins', ( $teile[0] ?? '' ) === ChildPackager::version(), (string) ( $teile[0] ?? '' ) );
check( 'Und die Pruefsumme des Quellverzeichnisses', hash_equals( (string) ( $teile[1] ?? '' ), ChildPackager::sourceChecksum( $quelle ) ) );

// Wird dort etwas geaendert, ohne es festzuhalten, darf nichts mehr raus.
$probe = $quelle . '/.zugriff-probe.php';
file_put_contents( $probe, "<?php\n// nur fuer den Prueflauf\n" );

$gestoppt = false;
try {
	ChildPackager::build( true );
} catch ( Throwable $e ) {
	$gestoppt = str_contains( $e->getMessage(), 'version.lock' );
}
unlink( $probe );

check( 'Unangemeldeter Code wird nicht ausgeliefert', $gestoppt );
check( 'Danach geht es wieder', hash_equals( (string) ( $teile[1] ?? '' ), ChildPackager::sourceChecksum( $quelle ) ) );

/* ------------------------------------------------------------ Kopfzeilen */

echo "Kopfzeilen\n";

// Kopfzeilen lassen sich in der Kommandozeile nicht pruefen — header() tut
// dort nichts. Also einmal wirklich ueber HTTP.
$port   = 8731;
$laeuft = static function () use ( $port ): bool {
	$fp = @fsockopen( '127.0.0.1', $port, $e, $t, 0.3 );
	if ( $fp ) { fclose( $fp ); return true; }
	return false;
};

$server = null;

if ( ! $laeuft() ) {
	$server = proc_open(
		sprintf( 'exec php -S 127.0.0.1:%d %s', $port, escapeshellarg( __DIR__ . '/servers/header-router.php' ) ),
		array( 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
		$pipes
	);
	for ( $i = 0; $i < 40 && ! $laeuft(); $i++ ) { usleep( 100000 ); }
}

if ( ! $laeuft() ) {
	check( 'Der Testserver fuer die Kopfzeilen startet', false );
} else {
	$hole = static function ( string $pfad ) use ( $port ): string {
		$ctx = stream_context_create( array( 'http' => array( 'ignore_errors' => true, 'timeout' => 5 ) ) );
		@file_get_contents( 'http://127.0.0.1:' . $port . $pfad, false, $ctx );

		return implode( "\n", $http_response_header ?? array() );
	};

	$kopf = $hole( '/' );

	// Das Panel war von Anfang an darauf eingerichtet — eigene Skriptdateien,
	// kein CDN, kein Inline-Handler. Ausgeliefert wurde die Kopfzeile nie.
	check( 'Jede Seite bringt eine Content-Security-Policy mit', str_contains( $kopf, 'Content-Security-Policy:' ), $kopf );
	check( 'Skripte nur von hier', str_contains( $kopf, "script-src 'self'" ) );
	check( 'Und ausdruecklich nicht aus dem Dokument', ! str_contains( $kopf, "script-src 'self' 'unsafe-inline'" ) );
	check( 'Stile duerfen inline bleiben — die Views arbeiten mit style-Attributen', str_contains( $kopf, "style-src 'self' 'unsafe-inline'" ) );
	check( 'Formulare gehen nur hierher zurueck', str_contains( $kopf, "form-action 'self'" ) );
	check( 'Kein fremder Rahmen', str_contains( $kopf, "frame-ancestors 'self'" ) );
	check( 'Keine base-Umleitung', str_contains( $kopf, "base-uri 'none'" ) );
	check( 'Der Typ wird nicht geraten', str_contains( $kopf, 'X-Content-Type-Options: nosniff' ) );

	// 404 und 403 setzten ihre Kopfzeilen frueher selbst und liessen dabei
	// alles weg.
	foreach ( array( '/404' => '404', '/403' => '403' ) as $pfad => $name ) {
		$k = $hole( $pfad );
		check( "Auch die $name-Seite hat die Richtlinie", str_contains( $k, 'Content-Security-Policy:' ), $k );
		check( "Und auch sie darf nicht in einen fremden Rahmen", str_contains( $k, 'X-Frame-Options: SAMEORIGIN' ) );
	}
}

if ( is_resource( $server ) ) {
	proc_terminate( $server );
	proc_close( $server );
}

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
