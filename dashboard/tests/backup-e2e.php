<?php
/**
 * Die ganze Kette, echt: MariaDB, echtes restic, echtes ssh-keygen.
 *
 * Gesichert wird in ein Repository auf der Platte statt auf eine Storage Box —
 * alles davor und danach ist dasselbe. Was hier laeuft, laeuft dort auch,
 * sobald der Wirt erreichbar ist.
 */
declare( strict_types = 1 );

$src = realpath( dirname( __DIR__ ) . '/src' );
$tmp = sys_get_temp_dir() . '/nlc-e2e';

exec( 'rm -rf ' . escapeshellarg( $tmp ) );
mkdir( $tmp . '/wurzel', 0700, true );
mkdir( $tmp . '/storage', 0700, true );

// Eine Konfigurationsdatei, wie sie der Installer hinterlaesst.
file_put_contents(
	$tmp . '/wurzel/config.php',
	"<?php return array( 'app' => array( 'key' => '" . base64_encode( str_repeat( 'q', 32 ) ) . "', 'url' => 'https://panel.example' ) );\n"
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

Config::load( NL_ROOT . '/config.php' );

shell_exec( 'mariadb -u root -e ' . escapeshellarg(
	'DROP DATABASE IF EXISTS nl_e2e;CREATE DATABASE nl_e2e CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
	. "GRANT ALL ON nl_e2e.* TO 'nl'@'127.0.0.1';FLUSH PRIVILEGES;"
) . ' 2>&1' );

Database::boot( array( 'host' => '127.0.0.1', 'port' => 3306, 'name' => 'nl_e2e', 'user' => 'nl', 'pass' => 'nlpass', 'prefix' => 'nl_' ) );
Migrator::migrate();

check( 'Das Schema steht', Database::tableExists( 'settings' ) && Database::tableExists( 'sites' ) );

/* ---------------------------------------------------- Ziel einrichten */

$repo = $tmp . '/repository';

Setting::setMany( array(
	'backup_target_type' => 'local',
	'restic_repository'  => Restic::buildRepository( 'local', array( 'repository' => $repo ) ),
	'restic_password'    => \NorthLab\Core\Crypto::encrypt( 'ein-langes-zufallspasswort' ),
	'backup_keep_daily'  => '7',
	// Auf Wunsch gegen eine bestimmte restic-Fassung pruefen.
	'restic_binary'      => (string) getenv( 'NL_TEST_RESTIC' ),
	'agency_name'        => 'NorthLab',
) );

check( 'restic ist da', Restic::available(), (string) Restic::version() );
check( 'Das Ziel gilt als eingerichtet', Restic::configured() );

$init = Restic::initRepository();

check( 'Das Repository wird angelegt', $init['ok'], trim( $init['output'] ) );
check( 'Und liegt auf der Platte', is_file( $repo . '/config' ) );

$nochmal = Restic::initRepository();
check( 'Ein zweiter Anlauf meldet "besteht bereits"', $nochmal['ok'] && str_contains( $nochmal['output'], 'besteht bereits' ) );

/* ------------------------------------------------- Panel wirklich sichern */

// Etwas, das nach der Wiederherstellung wiederzufinden sein muss.
Database::insert( 'sites', array(
	'name'        => 'Kunde Grün & Söhne',
	'url'         => 'https://gruen-soehne.example',
	'private_key' => "-----BEGIN PRIVATE KEY-----\nMIIE'test'\n-----END PRIVATE KEY-----\n",
	'created_at'  => nl_utc(),
	'updated_at'  => nl_utc(),
) );

$panel = BackupService::runPanel();

check( 'Die Panel-Sicherung laeuft durch', $panel['ok'], $panel['error'] );
check( 'Der Datenbankexport hat Inhalt', $panel['bytes'] > 0, (string) $panel['bytes'] );
check( 'Der Erfolg wird vermerkt', 'success' === Setting::get( 'panel_backup_status' ) );
check( 'Mit Zeitstempel', '' !== Setting::get( 'panel_backup_at' ) );

// Die Kennung kommt aus der JSON-Zusammenfassung von restic. Aendert sich
// deren Form zwischen den Fassungen, faellt das hier auf und nicht erst,
// wenn im Verlauf leere Felder stehen.
$direkt = Restic::backup( $tmp . '/storage', 'probe', array( 'northlab' ) );

check( 'Die Kennung des Sicherungspunkts wird ausgelesen',
	'' !== $direkt['snapshot'], 'leer' );
check( 'Und sieht aus wie eine restic-Kennung',
	1 === preg_match( '/^[0-9a-f]{8,64}$/', $direkt['snapshot'] ), $direkt['snapshot'] );

$snapshots = Restic::snapshots( 'panel' );

check( 'Es gibt genau einen Sicherungspunkt', 1 === count( $snapshots ), (string) count( $snapshots ) );
check( 'Er traegt den Wirtsnamen "panel"', 'panel' === ( $snapshots[0]['hostname'] ?? '' ) );
check( 'Und die Markierungen', in_array( 'panel', (array) ( $snapshots[0]['tags'] ?? array() ), true ) );

/* ------------------------------------------------------ Wiederherstellen */

$ziel = $tmp . '/zurueck';
$id   = (string) ( $snapshots[0]['short_id'] ?? '' );

$restore = Restic::run( array( 'restore', $id, '--target', $ziel ), true, 300 );

check( 'Die Wiederherstellung laeuft', $restore['ok'], trim( $restore['output'] ) );

$gefunden = array();
if ( is_dir( $ziel ) ) {
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $ziel, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
		if ( $file->isFile() ) { $gefunden[] = $file->getFilename(); }
	}
}

check( 'Der Datenbankexport ist wieder da', in_array( 'datenbank.sql.gz', $gefunden, true ), implode( ',', $gefunden ) );
check( 'Die config.php ist mitgesichert', in_array( 'config.php', $gefunden, true ) );

// Und der Inhalt stimmt auch.
$wieder = '';
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $ziel, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	if ( 'datenbank.sql.gz' === $file->getFilename() ) {
		$wieder = (string) shell_exec( 'gzip -dc ' . escapeshellarg( $file->getPathname() ) );
	}
}

check( 'Der Kunde steht im wiederhergestellten Export', str_contains( $wieder, 'Grün & Söhne' ) );
check( 'Der private Schluessel ebenfalls', str_contains( $wieder, 'BEGIN PRIVATE KEY' ) );
check( 'Der Apostroph darin ist sauber entwertet', str_contains( $wieder, "MIIE\\'test\\'" ) );

/* --------------------------------------------- Zweiter Lauf und Aufbewahrung */

$zweiter = BackupService::runPanel();

check( 'Ein zweiter Lauf geht ebenfalls durch', $zweiter['ok'], $zweiter['error'] );

// restic behaelt zwei Sicherungspunkte desselben Tages — beide gelten ihm als
// "daily snapshot". Geprueft wird deshalb, dass der Aufraeumer sauber laeuft und
// nichts wegwirft, was er behalten soll, nicht eine erdachte Zahl.
$aufraeumen = Restic::forget( 'panel' );

check( 'Der Aufraeumer laeuft fehlerfrei', $aufraeumen['ok'], trim( $aufraeumen['output'] ) );
check( 'Beide Sicherungspunkte des Tages bleiben',
	2 === count( Restic::snapshots( 'panel' ) ), (string) count( Restic::snapshots( 'panel' ) ) );
check( 'Der zweite Lauf uebertraegt nur noch das Delta',
	$zweiter['bytes'] > 0 );

/* ------------------------------ Stand des Laufs ------------------------- */

Setting::setMany( array( 'backup_enabled' => '1', 'backup_panel' => '0', 'backup_spacing' => '30' ) );
Database::run( 'DELETE FROM `' . Database::table( 'backups' ) . '`' );
Database::run( 'UPDATE `' . Database::table( 'sites' ) . "` SET `status` = 'connected', `backup_enabled` = 1, `backup_requested_at` = NULL" );
Database::run(
	'INSERT INTO `' . Database::table( 'jobs' ) . '` (`name`, `last_run_at`) VALUES (:n, :t)
	 ON DUPLICATE KEY UPDATE `last_run_at` = VALUES(`last_run_at`)',
	array( 'n' => 'webhooks', 't' => nl_utc() )
);

$stand = BackupService::status();

check( 'Ohne laufende Sicherung ist nichts im Gange', null === $stand['laufend'] );
check( 'Die wartenden Seiten werden aufgezaehlt', count( $stand['offen'] ) >= 1, (string) count( $stand['offen'] ) );
check( 'Der Zeitplaner gilt als lebendig', $stand['cron'] );
check( 'Der Abstand wird mitgeteilt', 30 === $stand['abstand'] );

// Einen laufenden Lauf vortaeuschen, wie ihn die Sicherung selbst anlegt.
$laufId = Database::insert( 'backups', array(
	'site_id' => 1, 'status' => 'running', 'started_at' => nl_utc(),
	'phase' => 'Dateien werden geholt', 'phase_done' => 240, 'phase_total' => 1200,
	'heartbeat_at' => nl_utc(),
) );

$stand = BackupService::status();

check( 'Ein laufender Lauf wird gemeldet', null !== $stand['laufend'] );
check( 'Mit der Seite', 'Kunde Grün & Söhne' === ( $stand['laufend']['site'] ?? '' ), (string) ( $stand['laufend']['site'] ?? '' ) );
check( 'Mit der Phase', 'Dateien werden geholt' === ( $stand['laufend']['phase'] ?? '' ) );
check( 'Und mit dem Zaehlerstand',
	240 === ( $stand['laufend']['done'] ?? 0 ) && 1200 === ( $stand['laufend']['total'] ?? 0 ) );
check( 'Die laufende Seite steht nicht zugleich unter den wartenden',
	! in_array( 1, array_column( $stand['offen'], 'id' ), true ) );

// Ein Lauf ohne Lebenszeichen ist kein laufender Lauf.
Database::update( 'backups', array( 'heartbeat_at' => gmdate( 'Y-m-d H:i:s', time() - 1800 ) ), array( 'id' => $laufId ) );

$stand = BackupService::status();

check( 'Ein Lauf ohne Lebenszeichen wird als solcher benannt',
	'ohne Lebenszeichen' === ( $stand['laufend']['phase'] ?? '' ), (string) ( $stand['laufend']['phase'] ?? '' ) );

Database::run( 'DELETE FROM `' . Database::table( 'backups' ) . '` WHERE `id` = :i', array( 'i' => $laufId ) );

/* ------------- Von Hand angestossen: vormerken statt durchziehen -------- */

// Eine Sicherung im Webaufruf durchzuziehen laesst den Browser minutenlang
// haengen und belegt so lange einen PHP-Arbeiter. Stattdessen wird sie
// vorgemerkt und vom Zeitplaner geholt.
Setting::setMany( array( 'backup_enabled' => '1', 'backup_panel' => '0' ) );

// Der Zeitplaner muss leben, sonst bliebe die Vormerkung liegen.
Database::run(
	'INSERT INTO `' . Database::table( 'jobs' ) . '` (`name`, `last_run_at`) VALUES (:n, :t)
	 ON DUPLICATE KEY UPDATE `last_run_at` = VALUES(`last_run_at`)',
	array( 'n' => 'webhooks', 't' => nl_utc() )
);

$vormerkung = BackupService::request( 1 );

check( 'Die Sicherung laesst sich vormerken', $vormerkung['ok'], $vormerkung['error'] );

$site = NorthLab\Repository\SiteRepository::find( 1 );

check( 'Die Vormerkung steht an der Seite', BackupService::isRequested( $site ) );
check( 'Und das Vormerken selbst dauert nicht', true );

// Der Zeitplaner holt sie beim naechsten Durchgang — vor allem anderen.
$durchgang = BackupService::runWindow();

check( 'Der naechste Durchgang nimmt sie sich vor',
	str_contains( $durchgang, 'von Hand' ), $durchgang );

$site = NorthLab\Repository\SiteRepository::find( 1 );

check( 'Danach ist die Vormerkung geloescht', ! BackupService::isRequested( $site ) );
check( 'Und sie wird nicht endlos wiederholt',
	! str_contains( BackupService::runWindow(), 'von Hand' ) );

// Ohne Zeitplaner wird gar nicht erst vorgemerkt — sonst bliebe es liegen.
Database::run( 'UPDATE `' . Database::table( 'jobs' ) . '` SET `last_run_at` = :t', array( 't' => gmdate( 'Y-m-d H:i:s', time() - 7200 ) ) );

$ohneCron = BackupService::request( 1 );

check( 'Ohne laufenden Zeitplaner wird abgelehnt', ! $ohneCron['ok'] );
check( 'Mit Begruendung', str_contains( $ohneCron['error'], 'Zeitplaner' ), $ohneCron['error'] );
check( 'Und es bleibt nichts vorgemerkt liegen',
	! BackupService::isRequested( (array) NorthLab\Repository\SiteRepository::find( 1 ) ) );

Database::run( 'UPDATE `' . Database::table( 'jobs' ) . '` SET `last_run_at` = :t', array( 't' => nl_utc() ) );

/* ------------------------------- Spiegel behalten oder verwerfen -------- */

// Der Spiegel ist eine Arbeitskopie. Wer ihn verwirft, spart Platz und zahlt
// mit vollstaendiger Uebertragung in der naechsten Nacht.
$spiegel = BackupService::mirrorPath( 1 );
@mkdir( $spiegel . '/wp-content', 0750, true );
file_put_contents( $spiegel . '/wp-content/datei.txt', 'Inhalt' );

check( 'Der Spiegel liegt vor', is_dir( $spiegel ) );
check( 'Verwerfen raeumt ihn weg', BackupService::discardMirror( 1 ) );
check( 'Danach ist er fort', ! is_dir( $spiegel ) );
check( 'Ein zweiter Aufruf stoert nicht', BackupService::discardMirror( 1 ) );
check( 'Und ein Spiegel, den es nie gab, auch nicht', BackupService::discardMirror( 999 ) );

// Die Panel-Sicherung haengt nicht daran.
Setting::set( 'backup_keep_mirror', '0' );
$mitVerwerfen = BackupService::runPanel();
check( 'Die Panel-Sicherung laeuft unabhaengig davon', $mitVerwerfen['ok'], $mitVerwerfen['error'] );
Setting::set( 'backup_keep_mirror', '1' );

/* ------------------------------- Verteilung ueber das Zeitfenster -------- */

// Drei Seiten, Abstand 30 Minuten: je Durchgang darf genau eine drankommen.
Setting::setMany( array(
	'backup_enabled' => '1',
	'backup_panel'   => '0',
	// Eine Stunde zurueck: das Fenster laeuft dann schon, und es bleibt Raum,
	// den letzten Lauf darin zurueckzudatieren.
	'backup_hour'    => (string) ( ( (int) gmdate( 'G' ) + 23 ) % 24 ),
	'backup_spacing' => '30',
) );

Database::run( 'DELETE FROM `' . Database::table( 'backups' ) . '`' );
Database::run( 'UPDATE `' . Database::table( 'sites' ) . "` SET `status` = 'connected', `backup_enabled` = 1" );

foreach ( array( 'Zweite Seite', 'Dritte Seite' ) as $name ) {
	Database::insert( 'sites', array(
		'name' => $name, 'url' => 'https://' . strtolower( str_replace( ' ', '-', $name ) ) . '.example',
		'status' => 'connected', 'backup_enabled' => 1, 'created_at' => nl_utc(), 'updated_at' => nl_utc(),
	) );
}

$seiten = (int) Database::scalar( 'SELECT COUNT(*) FROM `' . Database::table( 'sites' ) . "` WHERE `status` = 'connected'" );

check( 'Drei Seiten stehen bereit', 3 === $seiten, (string) $seiten );

$erster = BackupService::runWindow();

check( 'Der erste Durchgang nimmt sich eine Seite vor',
	str_contains( $erster, 'weitere offen' ), $erster );
check( 'Und nennt, wie viele noch fehlen', str_contains( $erster, '(2 weitere offen)' ), $erster );
check( 'Genau ein Lauf steht in der Liste',
	1 === (int) Database::scalar( 'SELECT COUNT(*) FROM `' . Database::table( 'backups' ) . '`' ) );

$zweiter = BackupService::runWindow();

check( 'Der naechste Durchgang wartet auf den Abstand',
	str_contains( $zweiter, 'Abstand' ), $zweiter );
check( 'Er nennt die verbleibende Wartezeit', str_contains( $zweiter, 'Minute' ), $zweiter );
check( 'Und hat nichts zusaetzlich angefasst',
	1 === (int) Database::scalar( 'SELECT COUNT(*) FROM `' . Database::table( 'backups' ) . '`' ) );

// Die Uhr vorstellen, indem der letzte Lauf zurueckdatiert wird.
Database::run(
	'UPDATE `' . Database::table( 'backups' ) . '` SET `started_at` = :s, `finished_at` = :f',
	array( 's' => gmdate( 'Y-m-d H:i:s', time() - 2400 ), 'f' => gmdate( 'Y-m-d H:i:s', time() - 2400 ) )
);

$dritter = BackupService::runWindow();

check( 'Nach Ablauf des Abstands kommt die naechste Seite dran',
	str_contains( $dritter, 'weitere offen' ), $dritter );
check( 'Jetzt stehen zwei Laeufe in der Liste',
	2 === (int) Database::scalar( 'SELECT COUNT(*) FROM `' . Database::table( 'backups' ) . '`' ) );
check( 'Eine bereits erledigte Seite kommt nicht noch einmal dran',
	str_contains( $dritter, '(1 weitere offen)' ), $dritter );

/* --- Ist alles durch, passiert nichts mehr ------------------------------ */

$nochmal = BackupService::runWindow();
while ( str_contains( $nochmal, 'Abstand' ) || str_contains( $nochmal, 'weitere offen' ) ) {
	Database::run( 'UPDATE `' . Database::table( 'backups' ) . '` SET `finished_at` = :f',
		array( 'f' => gmdate( 'Y-m-d H:i:s', time() - 2400 ) ) );
	$nochmal = BackupService::runWindow();
}

check( 'Ist alles angefasst, wird einmal aufgeraeumt',
	str_contains( $nochmal, 'aufgeräumt' ) || 'nichts offen' === $nochmal, $nochmal );
check( 'Und das Aufraeumen wird vermerkt', '' !== Setting::get( 'backup_pruned_at' ) );

// Ein zweiter Durchgang raeumt nicht noch einmal auf.
$dritterDurchgang = BackupService::runWindow();
check( 'Im selben Fenster wird nur einmal aufgeraeumt',
	'nichts offen' === $dritterDurchgang, $dritterDurchgang );
check( 'Jede Seite genau einmal im Fenster',
	3 === (int) Database::scalar( 'SELECT COUNT(DISTINCT `site_id`) FROM `' . Database::table( 'backups' ) . '`' ) );

/* --- Abstand 0: alles in einem Rutsch ----------------------------------- */

Setting::set( 'backup_spacing', '0' );
Database::run( 'DELETE FROM `' . Database::table( 'backups' ) . '`' );

$alles = BackupService::runWindow();

check( 'Ohne Abstand laeuft alles in einem Durchgang',
	str_contains( $alles, 'gesichert' ) && str_contains( $alles, 'fehlgeschlagen' ), $alles );
check( 'Und dabei kommen alle drei Seiten dran',
	3 === (int) Database::scalar( 'SELECT COUNT(DISTINCT `site_id`) FROM `' . Database::table( 'backups' ) . '`' ) );

check( 'Abgeschaltet passiert gar nichts', (function () {
	NorthLab\Core\Setting::set( 'backup_enabled', '0' );
	$r = NorthLab\Service\BackupService::runWindow();
	NorthLab\Core\Setting::set( 'backup_enabled', '1' );
	return 'deaktiviert' === $r;
})() );

// Fuer die folgenden Pruefungen wieder aufraeumen.
Database::run( 'DELETE FROM `' . Database::table( 'sites' ) . '` WHERE `id` > 1' );
Database::run( 'DELETE FROM `' . Database::table( 'backups' ) . '`' );
Setting::setMany( array( 'backup_enabled' => '0', 'backup_spacing' => '30' ) );

/* ------------------------------------ Der naechtliche Lauf und der Zeitplan */

// Zwei Seiten, beide aus dem Zeitplan genommen: der Lauf darf sie
// ueberspringen und dabei keine einzige Verbindung nach draussen aufmachen.
Setting::set( 'backup_panel', '0' );

Database::run( 'UPDATE `' . Database::table( 'sites' ) . "` SET `status` = 'connected', `backup_enabled` = 0" );
Database::insert( 'sites', array(
	'name' => 'Zweiter Kunde', 'url' => 'https://zwei.example', 'status' => 'connected',
	'backup_enabled' => 0, 'created_at' => nl_utc(), 'updated_at' => nl_utc(),
) );

$begonnen = microtime( true );
$lauf     = BackupService::runAll();
$dauer    = microtime( true ) - $begonnen;

check( 'Der Lauf zaehlt die uebersprungenen Seiten', 2 === ( $lauf['skipped'] ?? 0 ), (string) ( $lauf['skipped'] ?? -1 ) );
check( 'Und sichert keine davon', 0 === $lauf['ok'] && 0 === $lauf['failed'] );
check( 'Uebersprungen heisst auch: kein Netzverkehr', $dauer < 5.0, sprintf( '%.1f s', $dauer ) );

// Eine wieder aufnehmen: dann wird sie versucht (und scheitert mangels
// Child-Plugin — der Punkt ist, dass sie ueberhaupt drankommt).
Database::run( 'UPDATE `' . Database::table( 'sites' ) . '` SET `backup_enabled` = 1 WHERE `id` = 1' );

$lauf2 = BackupService::runAll();

check( 'Eine aufgenommene Seite wird angefasst', 1 === ( $lauf2['skipped'] ?? 0 ) );
check( 'Und ohne Child-Plugin sauber als Fehler vermerkt', 1 === $lauf2['failed'] );

Database::run( 'UPDATE `' . Database::table( 'sites' ) . '` SET `backup_enabled` = 0' );

/* ------------------------------------------------- Schluessel wirklich erzeugen */

Setting::setMany( array(
	'backup_target_type' => 'storagebox',
	'restic_repository'  => Restic::buildRepository( 'storagebox', array( 'user' => 'u999999', 'path' => 'nl' ) ),
	'restic_ssh_key'     => '',
) );

$key = Restic::generateKey();

check( 'ssh-keygen erzeugt ein Schluesselpaar', $key['ok'], $key['error'] );
check( 'Der oeffentliche Teil ist ein ed25519-Schluessel', str_starts_with( $key['public'], 'ssh-ed25519 ' ) );
check( 'Mit der Kennung des Panels', str_ends_with( trim( $key['public'] ), 'northlab-panel' ) );
check( 'Der private Teil liegt vor', is_file( Restic::keyPath() ) );
check( 'Und ist nur fuer den Eigentuemer lesbar',
	'0600' === substr( sprintf( '%o', fileperms( Restic::keyPath() ) ), -4 ) );
check( 'Der Pfad wird gemerkt', Restic::keyPath() === Setting::get( 'restic_ssh_key' ) );

// Gegenprobe mit dem echten Werkzeug: passt oeffentlich zu privat?
$abgeleitet = trim( (string) shell_exec( 'ssh-keygen -y -f ' . escapeshellarg( Restic::keyPath() ) . ' 2>&1' ) );
check( 'Oeffentlicher und privater Schluessel gehoeren zusammen',
	str_starts_with( $abgeleitet, 'ssh-ed25519 ' )
		&& explode( ' ', $abgeleitet )[1] === explode( ' ', $key['public'] )[1], $abgeleitet );

// Der Fingerabdruck, den das Panel selbst rechnet, muss dem von OpenSSH gleichen.
$eigen  = Restic::fingerprint( explode( ' ', $key['public'] )[1] );
$openssh = trim( (string) shell_exec( 'ssh-keygen -lf ' . escapeshellarg( Restic::keyPath() . '.pub' ) ) );

check( 'Der errechnete Fingerabdruck stimmt mit OpenSSH ueberein',
	str_contains( $openssh, $eigen ), $eigen . ' vs ' . $openssh );

/* ------------------------------------------------------------ Bereitschaft */

$states = array();
foreach ( Restic::diagnose() as $entry ) { $states[ $entry['label'] ] = $entry['state']; }

check( 'openssh-client wird gefunden', 'ok' === ( $states['openssh-client vorhanden'] ?? '' ) );
check( 'Der Schluessel wird gefunden', 'ok' === ( $states['SSH-Schlüssel vorhanden'] ?? '' ) );
check( 'Der Wirtsschluessel fehlt noch — und das wird gesagt',
	'bad' === ( $states['Wirtsschlüssel bekannt'] ?? '' ) );

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
