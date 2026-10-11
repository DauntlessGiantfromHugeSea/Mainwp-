<?php
/**
 * Updates werden vorgemerkt, nicht im Webaufruf eingespielt.
 *
 * Das ist der Punkt des Ganzen: der Browser soll nicht minutenlang haengen,
 * waehrend eine Kundenseite Plugin fuer Plugin auspackt. Geprueft wird gegen
 * eine echte MariaDB — die Warteschlange ist eine Tabelle, und ob ein Eintrag
 * wirklich genau einmal genommen wird, beantwortet nur sie.
 *
 *   php dashboard/tests/warteschlange.php
 */
declare( strict_types = 1 );

$root = realpath( dirname( __DIR__ ) );
define( 'NL_ROOT', $root );
define( 'NL_SRC', $root . '/src' );
define( 'NL_RESOURCES', $root . '/resources' );
define( 'NL_STORAGE', sys_get_temp_dir() . '/nlc-queue-test' );
define( 'NL_VERSION', '1.0.0' );

spl_autoload_register( static function ( string $c ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = NL_SRC . '/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );

require_once NL_SRC . '/helpers.php';

use NorthLab\Core\Database;
use NorthLab\Service\Scheduler;
use NorthLab\Service\UpdateQueue;

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
$pdo->exec( 'CREATE DATABASE IF NOT EXISTS `nl_queue` CHARACTER SET utf8mb4' );

Database::boot(
	array(
		'host' => '127.0.0.1', 'port' => 3306, 'user' => $user, 'pass' => $pass,
		'name' => 'nl_queue', 'prefix' => 'nl_',
	)
);

foreach ( array( 'update_jobs', 'sites', 'jobs', 'settings' ) as $t ) {
	Database::pdo()->exec( 'DROP TABLE IF EXISTS `nl_' . $t . '`' );
}

\NorthLab\Core\Migrator::migrate();

/** Den Zeitplaner als lebendig ausweisen — sonst nimmt die Warteschlange nichts an. */
function cron_lebt( bool $ja ): void {
	Database::pdo()->exec( 'DELETE FROM `nl_jobs`' );
	Database::insert(
		'jobs',
		array(
			'name'        => 'webhooks',
			'last_run_at' => gmdate( 'Y-m-d H:i:s', $ja ? time() - 30 : time() - 7200 ),
			'last_result' => 'ok',
			'last_duration_ms' => 1,
			'is_running'  => 0,
		)
	);
}

function seite( string $name, string $url, string $typ = 'wordpress', int $pausiert = 0 ): int {
	return Database::insert(
		'sites',
		array(
			'name' => $name, 'url' => $url, 'site_type' => $typ, 'is_paused' => $pausiert,
			'created_at' => nl_utc(), 'updated_at' => nl_utc(),
		)
	);
}

cron_lebt( true );

$a        = seite( 'Shop', 'https://a.example' );
$b        = seite( 'Blog', 'https://b.example' );
$nurSchau = seite( 'Nur beobachtet', 'https://c.example', 'monitor' );
$pause    = seite( 'Pausiert', 'https://d.example', 'wordpress', 1 );

/* ------------------------------------------------------------ Vormerken */

echo "Vormerken\n";

$r = UpdateQueue::request( $a );
check( 'Eine Seite laesst sich vormerken', $r['ok'] && $r['id'] > 0, $r['error'] );
check( 'Und gilt danach als vorgemerkt', UpdateQueue::isQueued( $a ) );
check( 'Eine andere nicht', ! UpdateQueue::isQueued( $b ) );

// Der Webaufruf darf nichts tun ausser eintragen.
$zeile = Database::selectOne( 'SELECT * FROM `nl_update_jobs` WHERE `id` = :i', array( 'i' => $r['id'] ) );
check( 'Der Eintrag wartet, er laeuft nicht', 'wartet' === ( $zeile['status'] ?? '' ) );
check( 'Es ist noch nichts gestartet', array_key_exists( 'started_at', (array) $zeile ) && null === $zeile['started_at'] );

check( 'Eine nur beobachtete Seite wird abgelehnt', ! UpdateQueue::request( $nurSchau )['ok'] );
check( 'Eine pausierte auch', ! UpdateQueue::request( $pause )['ok'] );
check( 'Eine, die es nicht gibt, ebenso', ! UpdateQueue::request( 99999 )['ok'] );

/* -------------------------------------------------- Kein zweiter Eintrag */

echo "Zusammenlegen\n";

UpdateQueue::request( $b, array( array( 'type' => 'plugin', 'slug' => 'eins' ) ) );
UpdateQueue::request( $b, array( array( 'type' => 'plugin', 'slug' => 'zwei' ) ) );

$fuerB = Database::select( 'SELECT * FROM `nl_update_jobs` WHERE `site_id` = :s', array( 's' => $b ) );
check( 'Zweimal vormerken ergibt einen Eintrag, nicht zwei', 1 === count( $fuerB ), (string) count( $fuerB ) );

// Zwei Updatelaeufe gleichzeitig auf derselben Installation sind der Weg in
// ein halb entpacktes Plugin — deshalb zusammenlegen statt danebenlegen.
$items = json_decode( (string) $fuerB[0]['items'], true );
check( 'Beide Auswahlen stehen darin', 2 === count( (array) $items ), (string) count( (array) $items ) );
check( 'Und die Anzahl stimmt', 2 === (int) $fuerB[0]['total'] );

UpdateQueue::request( $b, array( array( 'type' => 'plugin', 'slug' => 'eins' ) ) );
$fuerB2 = Database::selectOne( 'SELECT * FROM `nl_update_jobs` WHERE `site_id` = :s', array( 's' => $b ) );
check( 'Dasselbe zweimal wird nicht doppelt eingetragen', 2 === (int) $fuerB2['total'], (string) $fuerB2['total'] );

/* ------------------------------------------------- Ohne Zeitplaner nichts */

echo "Ohne Zeitplaner\n";

cron_lebt( false );
$c = seite( 'Spaet', 'https://e.example' );
$ohne = UpdateQueue::request( $c );
check( 'Ohne laufenden Zeitplaner wird nichts angenommen', ! $ohne['ok'] );
check( 'Und der Grund sagt, warum', str_contains( $ohne['error'], 'Zeitplaner' ), $ohne['error'] );
check( 'Es liegt auch nichts herum', ! UpdateQueue::isQueued( $c ) );
cron_lebt( true );

/* --------------------------------------------------------------- Stand */

echo "Stand\n";

$stand = UpdateQueue::status();
check( 'Der Stand nennt beide offenen Seiten', 2 === count( $stand['offen'] ), (string) count( $stand['offen'] ) );
check( 'Es laeuft noch nichts', null === $stand['laufend'] );
check( 'Der Zeitplaner gilt als gesund', true === $stand['cron'] );

$nurA = UpdateQueue::status( array( $a ) );
check( 'Ein eingeschraenktes Konto sieht nur die eigene Seite', 1 === count( $nurA['offen'] ), (string) count( $nurA['offen'] ) );
check( 'Und zwar die richtige', 'Shop' === ( $nurA['offen'][0]['name'] ?? '' ) );
check( 'Ohne zugeordnete Seite bleibt die Liste leer', array() === UpdateQueue::status( array() )['offen'] );

/* ----------------------------------------------- Haengengebliebener Lauf */

echo "Haengengeblieben\n";

// Stirbt der Arbeiter mitten im Lauf — Zeitlimit, OOM, Neustart —, stand der
// Eintrag frueher fuer immer auf "laeuft" und die Warteschlange kam nie
// wieder in Gang.
Database::update(
	'update_jobs',
	array( 'status' => 'laeuft', 'started_at' => gmdate( 'Y-m-d H:i:s', time() - UpdateQueue::STUCK_AFTER - 60 ) ),
	array( 'site_id' => $a )
);

check( 'Vorher gilt er als offen', UpdateQueue::isQueued( $a ) );
check( 'Er wird eingesammelt', 1 === UpdateQueue::reviveStuck() );

$tot = Database::selectOne( 'SELECT * FROM `nl_update_jobs` WHERE `site_id` = :s', array( 's' => $a ) );
check( 'Danach steht er auf Fehler', 'fehler' === $tot['status'] );
check(
	'Und sagt ehrlich, dass der Stand der Seite unklar ist',
	str_contains( (string) $tot['note'], 'prüfen' ),
	(string) $tot['note']
);
check( 'Die Seite ist wieder frei', ! UpdateQueue::isQueued( $a ) );

// Ein Lauf, der gerade erst begonnen hat, wird nicht abgeraeumt.
Database::update(
	'update_jobs',
	array( 'status' => 'laeuft', 'started_at' => nl_utc() ),
	array( 'site_id' => $b )
);
check( 'Ein frischer Lauf bleibt unangetastet', 0 === UpdateQueue::reviveStuck() );
check( 'Und erscheint im Stand als laufend', 'Blog' === ( UpdateQueue::status()['laufend']['name'] ?? '' ) );

/* ------------------------------------------------------------ Aufraeumen */

echo "Aufraeumen\n";

Database::update(
	'update_jobs',
	array(
		'status'      => 'fertig',
		'finished_at' => gmdate( 'Y-m-d H:i:s', time() - ( UpdateQueue::KEEP_DAYS + 1 ) * 86400 ),
	),
	array( 'site_id' => $a )
);

$vorher = count( Database::select( 'SELECT `id` FROM `nl_update_jobs`' ) );
$weg    = UpdateQueue::prune();
check( 'Alte, erledigte Eintraege verschwinden', 1 === $weg, (string) $weg );
check( 'Die uebrigen bleiben', count( Database::select( 'SELECT `id` FROM `nl_update_jobs`' ) ) === $vorher - 1 );

/* ------------------------------------------------------------ Zeitplaner */

echo "Zeitplaner\n";

$aufgaben = array_column( Scheduler::overview(), 'interval', 'name' );

check( 'Die Aufgabe steht im Zeitplan', array_key_exists( 'updates', $aufgaben ), implode( ', ', array_keys( $aufgaben ) ) );
// Jede Minute nachsehen, wie bei den Sicherungen: ein von Hand angestossenes
// Update soll gleich loslaufen, nicht erst in fuenf Minuten.
check( 'Und sieht jede Minute nach', 60 === ( $aufgaben['updates'] ?? 0 ), (string) ( $aufgaben['updates'] ?? 0 ) );

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
