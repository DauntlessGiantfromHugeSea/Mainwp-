<?php
/**
 * Die automatische Einrichtung gegen einen echten SSH-Server.
 *
 * Der Server auf 127.0.0.1:2223 nimmt ein Passwort entgegen, kennt einen
 * SFTP-Dienst und legt authorized_keys an — genau das, was eine Storage Box
 * auch tut. Was hier durchlaeuft, laeuft dort auch.
 */
declare( strict_types = 1 );

$src = realpath( dirname( __DIR__ ) . '/src' );
$tmp = sys_get_temp_dir() . '/nlc-setup';

exec( 'rm -rf ' . escapeshellarg( $tmp ) );
mkdir( $tmp . '/wurzel', 0700, true );
mkdir( $tmp . '/storage', 0700, true );

file_put_contents(
	$tmp . '/wurzel/config.php',
	"<?php return array( 'app' => array( 'key' => '" . base64_encode( str_repeat( 'w', 32 ) ) . "' ) );\n"
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
use NorthLab\Service\BackupService;
use NorthLab\Service\Restic;

$fails = 0;
$n     = 0;
function check( string $label, bool $ok, string $extra = '' ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label" . ( '' !== $extra ? " ($extra)" : '' ) . "\n"; }
}

/** @param array<int,array{label:string,ok:bool,detail:string}> $steps */
function schritt( array $steps, string $label ): ?array {
	foreach ( $steps as $s ) { if ( $s['label'] === $label ) { return $s; } }
	return null;
}

function zeige( array $steps ): string {
	$out = array();
	foreach ( $steps as $s ) { $out[] = ( $s['ok'] ? '+' : '-' ) . ' ' . $s['label'] . ': ' . $s['detail']; }
	return implode( ' / ', $out );
}

Config::load( NL_ROOT . '/config.php' );

shell_exec( 'mariadb -u root -e ' . escapeshellarg(
	'DROP DATABASE IF EXISTS nl_setup;CREATE DATABASE nl_setup CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
	. "GRANT ALL ON nl_setup.* TO 'nl'@'127.0.0.1';FLUSH PRIVILEGES;"
) . ' 2>&1' );

Database::boot( array( 'host' => '127.0.0.1', 'port' => 3306, 'name' => 'nl_setup', 'user' => 'nl', 'pass' => 'nlpass', 'prefix' => 'nl_' ) );
Migrator::migrate();

// Auf dem "Speicher" aufraeumen: weder Schluessel noch altes Repository.
shell_exec( 'rm -rf /home/boxuser/.ssh /home/boxuser/repo' );

$repo = 'sftp://boxuser@127.0.0.1:2223/repo';

Setting::setMany( array(
	'backup_target_type' => 'sftp',
	'restic_repository'  => $repo,
	'sftp_repository'    => $repo,
	'restic_password'    => Crypto::encrypt( 'langes-repository-passwort' ),
	// Auf Wunsch gegen eine bestimmte restic-Fassung pruefen.
	'restic_binary'      => (string) getenv( 'NL_TEST_RESTIC' ),
) );

check( 'Die Art wird als sftp erkannt', 'sftp' === Restic::type() );
check( 'Vorher ist nichts eingerichtet', ! Restic::hasKey() && ! Restic::hostKnown() );

/* ------------------------------------------------- Ein Knopf, alles fertig */

$steps = Restic::autoSetup( 'StorageBoxGeheim123' );

check( 'Alle Schritte melden Erfolg',
	count( array_filter( $steps, static fn( $s ) => ! $s['ok'] ) ) === 0, zeige( $steps ) );
check( 'restic wurde gefunden', ( schritt( $steps, 'restic gefunden' )['ok'] ?? false ) );
check( 'Ein Schluesselpaar wurde erzeugt', ( schritt( $steps, 'Schlüsselpaar erzeugt' )['ok'] ?? false ) );
check( 'Der Wirtsschluessel wurde abgerufen', ( schritt( $steps, 'Wirtsschlüssel abgerufen' )['ok'] ?? false ) );
check( 'Und uebernommen', ( schritt( $steps, 'Wirtsschlüssel übernommen' )['ok'] ?? false ) );
check( 'Der Fingerabdruck wird genannt',
	str_contains( (string) ( schritt( $steps, 'Wirtsschlüssel übernommen' )['detail'] ?? '' ), 'SHA256:' ) );
check( 'Der Schluessel liegt auf dem Speicher',
	( schritt( $steps, 'Schlüssel auf dem Speicher abgelegt' )['ok'] ?? false ) );
check( 'Das Repository ist erreichbar', ( schritt( $steps, 'Repository erreichbar' )['ok'] ?? false ) );

// restic haengt an die Erfolgsmeldung einen Absatz ueber das Passwort. Der
// gehoert nicht in eine Schrittzeile.
$meldung = (string) ( schritt( $steps, 'Repository erreichbar' )['detail'] ?? '' );

check( 'Die Meldung ist eine einzige Zeile', ! str_contains( $meldung, "\n" ), $meldung );
check( 'Und nicht der ganze restic-Absatz',
	! str_contains( $meldung, 'irrecoverably lost' ), $meldung );
check( 'Sie nennt aber das angelegte Repository',
	str_contains( $meldung, 'created restic repository' ), $meldung );

// Gegenprobe auf der anderen Seite.
$authorized = (string) @file_get_contents( '/home/boxuser/.ssh/authorized_keys' );

check( 'Auf dem Speicher steht wirklich ein Schluessel', str_contains( $authorized, 'ssh-ed25519 ' ) );
check( 'Und zwar genau der des Panels',
	str_contains( $authorized, explode( ' ', Restic::publicKey() )[1] ) );
check( 'Genau einer, nicht zwei',
	1 === count( array_filter( explode( "\n", $authorized ), static fn( $l ) => '' !== trim( $l ) ) ) );
check( 'Das Repository liegt auf dem Speicher', is_file( '/home/boxuser/repo/config' ) );
check( 'Das Passwort des Speichers wurde nirgends abgelegt',
	! str_contains( (string) json_encode( Setting::all() ), 'StorageBoxGeheim123' ) );
check( 'Das Hilfsprogramm bleibt nicht liegen', ! is_file( Restic::sshDir() . '/askpass.sh' ) );

/* ------------------------------------ Und jetzt wirklich darauf sichern */

Database::insert( 'sites', array(
	'name' => 'Testkunde', 'url' => 'https://test.example',
	'created_at' => nl_utc(), 'updated_at' => nl_utc(),
) );

$panel = BackupService::runPanel();

check( 'Eine echte Sicherung ueber SFTP laeuft durch', $panel['ok'], $panel['error'] );

$snapshots = Restic::snapshots( 'panel' );

check( 'Der Sicherungspunkt liegt auf dem Speicher', 1 === count( $snapshots ), (string) count( $snapshots ) );
check( 'Auf dem Speicher sind Daten angekommen',
	count( (array) glob( '/home/boxuser/repo/data/*' ) ) > 0 );

/* --------------------------------------------- Ein zweiter Lauf ist ruhig */

$wieder = Restic::autoSetup( '' );

check( 'Ohne Passwort laeuft die Einrichtung trotzdem durch',
	count( array_filter( $wieder, static fn( $s ) => ! $s['ok'] ) ) === 0, zeige( $wieder ) );
check( 'Der vorhandene Schluessel wird wiederverwendet',
	null !== schritt( $wieder, 'Schlüsselpaar vorhanden' ) );
check( 'Das bestehende Repository wird erkannt',
	str_contains( (string) ( schritt( $wieder, 'Repository erreichbar' )['detail'] ?? '' ), 'besteht bereits' ) );
check( 'Auf dem Speicher steht immer noch genau ein Schluessel',
	1 === count( array_filter( explode( "\n", (string) file_get_contents( '/home/boxuser/.ssh/authorized_keys' ) ),
		static fn( $l ) => '' !== trim( $l ) ) ) );

/* ------------- Der Weg ohne Passwort: Schluessel kommt von woanders ------- */

// So laeuft es, wenn der Schluessel ueber das Hetzner-Konto hinterlegt wurde
// statt ueber ssh-copy-id: das Passwortfeld bleibt leer.
shell_exec( 'rm -rf /home/boxuser/.ssh /home/boxuser/repo' );

// "Jemand anderes" legt den Schluessel ab — hier von Hand, im Ernstfall die
// Weboberflaeche von Hetzner.
shell_exec( 'install -d -o boxuser -g boxuser -m 700 /home/boxuser/.ssh' );
shell_exec( 'install -o boxuser -g boxuser -m 600 ' . escapeshellarg( Restic::keyPath() . '.pub' ) . ' /home/boxuser/.ssh/authorized_keys' );

$ohnePasswort = Restic::autoSetup( '' );

check( 'Ohne Passwort laeuft die Einrichtung vollstaendig durch',
	count( array_filter( $ohnePasswort, static fn( $s ) => ! $s['ok'] ) ) === 0, zeige( $ohnePasswort ) );
check( 'Das Ablegen wird uebersprungen',
	str_contains( (string) ( schritt( $ohnePasswort, 'Schlüssel auf dem Speicher' )['detail'] ?? '' ), 'Übersprungen' ) );
check( 'Mit dem Hinweis, dass er anders dorthin muss',
	str_contains( (string) ( schritt( $ohnePasswort, 'Schlüssel auf dem Speicher' )['detail'] ?? '' ), 'Hetzner-Konto' ) );
check( 'Und das Repository entsteht trotzdem',
	( schritt( $ohnePasswort, 'Repository erreichbar' )['ok'] ?? false ) );
check( 'Es liegt danach auf dem Speicher', is_file( '/home/boxuser/repo/config' ) );

// Und eine echte Sicherung geht darueber auch.
$sicherung = BackupService::runPanel();
check( 'Eine Sicherung laeuft ohne je ein Passwort gesehen zu haben', $sicherung['ok'], $sicherung['error'] );

/* ------------------------------------------------- Falsches Passwort */

shell_exec( 'rm -rf /home/boxuser/.ssh' );
$falsch = Restic::installKey( 'das-ist-nicht-das-passwort' );

check( 'Ein falsches Passwort scheitert', ! $falsch['ok'] );
check( 'Und sagt auch, woran es lag',
	str_contains( $falsch['error'], 'Passwort wurde abgelehnt' ), $falsch['error'] );

/* ------------------------------- Der Fall, den man nicht wegklicken darf */

$scan = Restic::scanHostKey();

check( 'Ohne Aenderung kein Widerspruch', ! Restic::hostKeyConflict( $scan['keys'] ) );

// Einen anderen Schluessel unterschieben, als kaeme jemand dazwischen.
$echt      = (string) file_get_contents( Restic::knownHostsPath() );
$manipuliert = preg_replace_callback(
	'/^(\[127\.0\.0\.1\]:2223 \S+ )(\S+)/m',
	static fn( $m ) => $m[1] . base64_encode( 'ein-ganz-anderer-schluessel' ),
	$echt
);
file_put_contents( Restic::knownHostsPath(), $manipuliert );

check( 'Ein abweichender Wirtsschluessel faellt auf', Restic::hostKeyConflict( $scan['keys'] ) );

$abgebrochen = Restic::autoSetup( 'StorageBoxGeheim123' );
$geprueft    = schritt( $abgebrochen, 'Wirtsschlüssel geprüft' );

check( 'Die Einrichtung bricht daraufhin ab', null !== $geprueft && ! $geprueft['ok'] );
check( 'Mit einer Erklaerung, die zum Nachsehen auffordert',
	null !== $geprueft && str_contains( $geprueft['detail'], 'anderen Schlüssel' ) );
check( 'Der untergeschobene Eintrag wird nicht stillschweigend ersetzt',
	str_contains( (string) file_get_contents( Restic::knownHostsPath() ), base64_encode( 'ein-ganz-anderer-schluessel' ) ) );
check( 'Und es wird kein Schluessel abgelegt', ! is_file( '/home/boxuser/.ssh/authorized_keys' ) );

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
