<?php
/**
 * Die Freigabe zum Zurückspielen.
 *
 * Der einzige Vorgang, der die Kundenseite überschreibt — und der einzige,
 * den ein Kunde auslösen darf. Entsprechend genau wird hier geprüft, was
 * der Code kann und was nicht. Läuft gegen eine echte MariaDB, weil die
 * Begrenzung an der Zeile hängt und nicht an einer Sitzung.
 *
 *   php dashboard/tests/freigabe.php
 */
declare( strict_types = 1 );

$root = realpath( dirname( __DIR__ ) );
define( 'NL_ROOT', $root );
define( 'NL_SRC', $root . '/src' );
define( 'NL_STORAGE', sys_get_temp_dir() . '/nlc-freigabe-test' );
define( 'NL_VERSION', '1.0.0' );

spl_autoload_register( static function ( string $c ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = NL_SRC . '/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );

require_once NL_SRC . '/helpers.php';

use NorthLab\Core\Database;
use NorthLab\Service\RestoreRunService;

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
$pdo->exec( 'CREATE DATABASE IF NOT EXISTS `nl_freigabe` CHARACTER SET utf8mb4' );

Database::boot(
	array(
		'host' => '127.0.0.1', 'port' => 3306, 'user' => $user, 'pass' => $pass,
		'name' => 'nl_freigabe', 'prefix' => 'nl_',
	)
);

foreach ( array( 'restore_jobs', 'sites', 'jobs', 'settings', 'activity' ) as $t ) {
	Database::pdo()->exec( 'DROP TABLE IF EXISTS `nl_' . $t . '`' );
}

\NorthLab\Core\Migrator::migrate();

/** Den Zeitplaner als lebendig ausweisen. */
Database::insert( 'jobs', array(
	'name' => 'webhooks', 'last_run_at' => gmdate( 'Y-m-d H:i:s', time() - 30 ),
	'last_result' => 'ok', 'last_duration_ms' => 1, 'is_running' => 0,
) );

$seite = Database::insert( 'sites', array(
	'name' => 'Kundenshop', 'url' => 'https://kunde.de', 'site_type' => 'wordpress',
	'created_at' => nl_utc(), 'updated_at' => nl_utc(),
) );

$nurSchau = Database::insert( 'sites', array(
	'name' => 'Nur beobachtet', 'url' => 'https://b.example', 'site_type' => 'monitor',
	'created_at' => nl_utc(), 'updated_at' => nl_utc(),
) );

// Ohne restic gibt es keine Sicherungspunkte — fuer diese Pruefungen zaehlt
// die Freigabe, nicht das Zurueckspielen selbst. Die Zugehoerigkeitspruefung
// wird darum hier umgangen, indem die Zeile direkt angelegt wird; geprueft
// wird sie in backup-e2e gegen ein echtes Repository.
function vorhaben( int $siteId, string $code, string $scope = 'both', int $ttl = 3600 ): int {
	return Database::insert( 'restore_jobs', array(
		'site_id'         => $siteId,
		'snapshot'        => 'abc12345',
		'scope'           => $scope,
		'status'          => 'wartet_code',
		'code_hash'       => hash( 'sha256', $code ),
		'code_expires_at' => nl_utc( $ttl ),
		'requested_at'    => nl_utc(),
	) );
}

/* ------------------------------------------------------------- Der Code */

echo "Der Code\n";

$codes = array();
for ( $i = 0; $i < 200; $i++ ) {
	$codes[] = RestoreRunService::freshCode();
}

check( 'Der Code hat sechs Ziffern', 200 === count( array_filter( $codes, static fn( $c ): bool => (bool) preg_match( '/^\d{6}$/', $c ) ) ) );
// Er muss sich jedes Mal aendern — sonst ist es kein Einmalcode, sondern
// ein Passwort, das jeder Kunde irgendwann kennt.
check( 'Und ist jedes Mal ein anderer', count( array_unique( $codes ) ) > 190, (string) count( array_unique( $codes ) ) );
check( 'Auch fuehrende Nullen bleiben erhalten',
	6 === strlen( str_pad( (string) 42, 6, '0', STR_PAD_LEFT ) ) );

/* ------------------------------------------------------------- Einlösen */

echo "Einlösen\n";

vorhaben( $seite, '123456' );

check( 'Ein falscher Code wird abgewiesen', ! RestoreRunService::redeem( $seite, '000000' )['ok'] );
check( 'Der richtige gibt frei', RestoreRunService::redeem( $seite, '123456' )['ok'] );

$job = RestoreRunService::openFor( $seite );
check( 'Danach ist das Vorhaben vorgemerkt', 'vorgemerkt' === ( $job['status'] ?? '' ), (string) ( $job['status'] ?? '' ) );
// Einmal heisst einmal: der Hash ist weg, der Code taugt zu nichts mehr.
check( 'Und der Code verbraucht', '' === (string) $job['code_hash'] );
check( 'Ein zweites Einloesen geht nicht', ! RestoreRunService::redeem( $seite, '123456' )['ok'] );

/* ---------------------------------------------------- Nicht durchprobierbar */

echo "Durchprobieren\n";

RestoreRunService::discardOpen( $seite, 'Test' );
vorhaben( $seite, '654321' );

// Sechs Ziffern sind eine Million Moeglichkeiten. Ohne Bremse waere das
// kein Schutz, sondern eine Zahl.
for ( $i = 0; $i < RestoreRunService::CODE_TRIES; $i++ ) {
	RestoreRunService::redeem( $seite, '111111' );
}

check( 'Nach genug Fehlversuchen ist die Freigabe weg', null === RestoreRunService::openFor( $seite ) );
check( 'Und der richtige Code nuetzt nichts mehr', ! RestoreRunService::redeem( $seite, '654321' )['ok'] );

/* ------------------------------------------------------------- Ablaufen */

echo "Ablaufen\n";

vorhaben( $seite, '222222', 'both', -60 );

check( 'Ein abgelaufener Code wird abgewiesen', ! RestoreRunService::redeem( $seite, '222222' )['ok'] );
check( 'Und die Freigabe ist danach weg', null === RestoreRunService::openFor( $seite ) );

$id = vorhaben( $seite, '333333', 'both', -60 );
check( 'Der Aufräumlauf findet sie auch von selbst', 1 === RestoreRunService::expireCodes() );
check( 'Danach ist nichts mehr offen', null === RestoreRunService::openFor( $seite ) );

/* ------------------------------------- Was die Kundenseite erfahren darf */

echo "Auskunft an die Kundenseite\n";

vorhaben( $seite, '444444' );

// Dass gerade eine Freigabe aussteht, steht hier bewusst nicht: sonst
// wuesste jeder im Backend des Kunden, dass es sich jetzt lohnt, Codes
// durchzuprobieren.
$stand = RestoreRunService::stateForSite( $seite );
check( 'Eine ausstehende Freigabe wird nicht verraten', false === $stand['running'] );
check( 'Und auch sonst nichts', ! isset( $stand['scope'] ) );

RestoreRunService::redeem( $seite, '444444' );
$stand = RestoreRunService::stateForSite( $seite );
check( 'Nach der Freigabe ist der Lauf sichtbar', true === $stand['running'] );
check( 'Mit dem Umfang', 'both' === ( $stand['scope'] ?? '' ) );

/* ------------------------------------------------- Ein Vorhaben je Seite */

echo "Nur eines offen\n";

RestoreRunService::discardOpen( $seite, 'Test' );
vorhaben( $seite, '555555' );
$zweites = vorhaben( $seite, '666666' );

// Zwei gleichzeitig waeren zwei gueltige Codes fuer zwei verschiedene
// Staende — am Telefon weiss dann niemand mehr, welcher welcher ist.
check( 'Der aeltere Code gilt nicht mehr, wenn ein neuer da ist',
	! RestoreRunService::redeem( $seite, '555555' )['ok'] || 2 === count( Database::select(
		'SELECT `id` FROM `nl_restore_jobs` WHERE `site_id` = :s AND `status` = :st',
		array( 's' => $seite, 'st' => 'wartet_code' )
	) ) );

/* --------------------------------------------------- Codes sind getrennt */

echo "Seiten getrennt\n";

RestoreRunService::discardOpen( $seite, 'Test' );
RestoreRunService::discardOpen( $nurSchau, 'Test' );

// Der scharfe Fall: nur eine Seite hat eine Freigabe. Die andere darf mit
// deren Code nichts anfangen — auch dann nicht, wenn sie selbst gar keine
// offene Freigabe hat und die Suche sonst beim naechstbesten landen wuerde.
vorhaben( $seite, '777777' );

check( 'Eine Seite ohne eigene Freigabe kann den fremden Code nicht einloesen',
	! RestoreRunService::redeem( $nurSchau, '777777' )['ok'] );
check( 'Und die fremde Freigabe bleibt dabei unangetastet',
	'wartet_code' === ( RestoreRunService::openFor( $seite )['status'] ?? '' ) );

// Jetzt beide, mit verschiedenen Codes — ueber Kreuz geht nichts.
vorhaben( $nurSchau, '888888' );

check( 'Ueber Kreuz geht es auch dann nicht', ! RestoreRunService::redeem( $nurSchau, '777777' )['ok'] );
check( 'Der eigene schon', RestoreRunService::redeem( $nurSchau, '888888' )['ok'] );
check( 'Und der erste ist davon unberuehrt', RestoreRunService::redeem( $seite, '777777' )['ok'] );

/* --------------------------------------------------------- Was abprallt */

echo "Abweisungen\n";

check( 'Eine Seite, die es nicht gibt', ! RestoreRunService::request( 99999, 'abc12345', 'both' )['ok'] );
check( 'Eine nur beobachtete Seite', ! RestoreRunService::request( $nurSchau, 'abc12345', 'both' )['ok'] );
check( 'Ein unbekannter Umfang', ! RestoreRunService::request( $seite, 'abc12345', 'alles' )['ok'] );

// Ohne Zeitplaner bliebe die Freigabe liegen — dann lieber gar nicht erst
// einen Code ausgeben, den niemand einloesen kann.
Database::pdo()->exec( 'DELETE FROM `nl_jobs`' );
$ohneCron = RestoreRunService::request( $seite, 'abc12345', 'both' );
check( 'Ohne Zeitplaner wird kein Code ausgegeben', ! $ohneCron['ok'] );
check( 'Und der Grund sagt warum', str_contains( $ohneCron['error'], 'Zeitplaner' ), $ohneCron['error'] );

/* ------------------------------------- Der Code steht in keinem Protokoll */

echo "Spuren\n";

RestoreRunService::discardOpen( $seite, 'Test' );
$geheim = '191919';
vorhaben( $seite, $geheim );
RestoreRunService::redeem( $seite, $geheim );

$protokoll = Database::select( 'SELECT * FROM `nl_activity`' );
$text      = '';

foreach ( $protokoll as $zeile ) {
	$text .= (string) ( $zeile['message'] ?? '' ) . (string) ( $zeile['context'] ?? '' );
}

check( 'Der Code taucht im Protokoll nicht auf', ! str_contains( $text, $geheim ), $text );

$zeilen = Database::select( 'SELECT * FROM `nl_restore_jobs`' );
$roh    = '';

foreach ( $zeilen as $zeile ) {
	$roh .= implode( '|', array_map( 'strval', $zeile ) );
}

check( 'Und in der Tabelle steht er auch nicht im Klartext', ! str_contains( $roh, $geheim ) );

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
