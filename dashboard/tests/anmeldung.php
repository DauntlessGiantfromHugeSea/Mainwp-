<?php
/**
 * Anmeldung: Passwort, zweiter Faktor, Sperre.
 *
 * Der am besten bewachte Weg ins Panel — und der einzige, der bisher gar
 * nicht geprueft wurde. Laeuft gegen eine echte MariaDB, weil die Sperre am
 * Konto haengt und nicht in der Sitzung.
 *
 *   php dashboard/tests/anmeldung.php
 */
declare( strict_types = 1 );

$root = realpath( dirname( __DIR__ ) );
define( 'NL_ROOT', $root );
define( 'NL_SRC', $root . '/src' );
define( 'NL_STORAGE', sys_get_temp_dir() . '/nlc-anmeldung-test' );
define( 'NL_VERSION', '1.0.0' );

spl_autoload_register( static function ( string $c ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = NL_SRC . '/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );

require_once NL_SRC . '/helpers.php';

use NorthLab\Core\Auth;
use NorthLab\Core\Config;
use NorthLab\Core\Database;
use NorthLab\Core\Request;
use NorthLab\Core\Session;
use NorthLab\Core\Totp;
use NorthLab\Repository\UserRepository;

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
$pdo->exec( 'CREATE DATABASE IF NOT EXISTS `nl_anmeldung` CHARACTER SET utf8mb4' );

// Verschluesselte Felder (das TOTP-Geheimnis) brauchen einen Hauptschluessel.
// Er gilt nur fuer diesen Lauf und steht in keiner Datei des Projekts.
$konfig = NL_STORAGE . '/config.php';
file_put_contents(
	$konfig,
	"<?php\nreturn array( 'app' => array( 'key' => " . var_export( base64_encode( random_bytes( 32 ) ), true ) . " ) );\n"
);
Config::load( $konfig );

Database::boot(
	array(
		'host' => '127.0.0.1', 'port' => 3306, 'user' => $user, 'pass' => $pass,
		'name' => 'nl_anmeldung', 'prefix' => 'nl_',
	)
);

foreach ( array( 'users', 'sessions', 'activity', 'settings', 'user_sites' ) as $t ) {
	Database::pdo()->exec( 'DROP TABLE IF EXISTS `nl_' . $t . '`' );
}

\NorthLab\Core\Migrator::migrate();

$_SESSION            = array();
$_SERVER['REQUEST_URI']    = '/login';
$_SERVER['REQUEST_METHOD'] = 'POST';

$anfrage = new Request();

/** Ein frisches Konto mit bekanntem Passwort. */
function konto( string $mail, string $passwort, bool $zweiterFaktor = false ): array {
	$id = Database::insert(
		'users',
		array(
			'email'         => $mail,
			'name'          => 'Prueferin',
			'password_hash' => password_hash( $passwort, PASSWORD_DEFAULT ),
			'role'          => 'admin',
			'is_active'     => 1,
			'created_at'    => nl_utc(),
		)
	);

	if ( $zweiterFaktor ) {
		$geheim = Totp::generateSecret();
		Database::update(
			'users',
			array(
				'totp_secret'       => \NorthLab\Core\Crypto::encrypt( $geheim ),
				'totp_enabled'      => 1,
				'totp_confirmed_at' => nl_utc(),
			),
			array( 'id' => $id )
		);

		return array( 'id' => $id, 'secret' => $geheim );
	}

	return array( 'id' => $id, 'secret' => '' );
}

/* --------------------------------------------------------- Ohne zweiten Faktor */

echo "Passwort\n";

$einfach = konto( 'einfach@beispiel.de', 'richtig-und-lang-genug' );

$_SESSION = array();
check( 'Ein falsches Passwort wird abgelehnt', null !== Auth::attempt( 'einfach@beispiel.de', 'daneben', $anfrage ) );
check( 'Ein unbekanntes Konto ebenso', null !== Auth::attempt( 'niemand@beispiel.de', 'egal', $anfrage ) );

$_SESSION = array();
check( 'Das richtige Passwort laesst durch', null === Auth::attempt( 'einfach@beispiel.de', 'richtig-und-lang-genug', $anfrage ) );

/* ------------------------------------------------- Sperre nach Fehlversuchen */

echo "Sperre\n";

$sperr = konto( 'sperr@beispiel.de', 'auch-ein-langes-passwort' );
$max   = max( 3, (int) Config::get( 'security.login_attempts', 5 ) );

for ( $i = 0; $i < $max; $i++ ) {
	$_SESSION = array();
	Auth::attempt( 'sperr@beispiel.de', 'falsch', $anfrage );
}

$gesperrt = Auth::attempt( 'sperr@beispiel.de', 'auch-ein-langes-passwort', $anfrage );
check( 'Nach genug Fehlversuchen ist das Konto gesperrt', null !== $gesperrt, (string) $gesperrt );
check( 'Und zwar auch fuer das richtige Passwort', is_string( $gesperrt ) && str_contains( $gesperrt, 'Fehlversuche' ), (string) $gesperrt );

/* --------------------------------------------------------- Mit zweitem Faktor */

echo "Zweiter Faktor\n";

$zwei = konto( 'zwei@beispiel.de', 'noch-ein-langes-passwort', true );

$_SESSION = array();
$stand    = Auth::attempt( 'zwei@beispiel.de', 'noch-ein-langes-passwort', $anfrage );

check( 'Das Passwort allein reicht nicht', Auth::CHALLENGE_REQUIRED === $stand, (string) $stand );
check( 'Es gibt noch keine Anmeldung', 0 === (int) ( $_SESSION['user_id'] ?? 0 ) );
check( 'Aber eine vorgemerkte Person', null !== Auth::pendingUser() );

check( 'Ein falscher Code wird abgelehnt', null !== Auth::completeChallenge( '000000', $anfrage ) );

$richtig = Totp::codeAt( $zwei["secret"] );
check( 'Der richtige Code schliesst ab', null === Auth::completeChallenge( $richtig, $anfrage ), $richtig );
check( 'Danach ist man angemeldet', (int) $zwei['id'] === (int) ( $_SESSION['user_id'] ?? 0 ) );

/* ------------------------- Der zweite Faktor laesst sich nicht durchprobieren */

echo "Durchprobieren\n";

// Das war die Luecke: die Fehlversuche am zweiten Faktor zaehlten nur in der
// Sitzung. Sechs Versuche, Sitzung wegwerfen, von vorn — beliebig oft, ohne
// dass am Konto irgendetwas mitlief. Wer das Passwort kannte, konnte den
// zweiten Faktor mit genug Geduld erraten.
$brute = konto( 'brute@beispiel.de', 'ein-sehr-langes-passwort', true );
$max   = max( 3, (int) Config::get( 'security.login_attempts', 5 ) );

for ( $i = 0; $i < $max; $i++ ) {
	// Jede Runde eine frische Sitzung — genau das, was der Angreifer tut.
	$_SESSION = array();
	Auth::attempt( 'brute@beispiel.de', 'ein-sehr-langes-passwort', $anfrage );
	Auth::completeChallenge( '000000', $anfrage );
}

$frisch = UserRepository::find( (int) $brute['id'] );
check( 'Falsche Codes zaehlen am Konto mit', (int) ( $frisch['failed_attempts'] ?? 0 ) >= $max, (string) ( $frisch['failed_attempts'] ?? 0 ) );
check( 'Und sperren es', ! empty( $frisch['locked_until'] ) );

$_SESSION = array();
$danach   = Auth::attempt( 'brute@beispiel.de', 'ein-sehr-langes-passwort', $anfrage );
check( 'Eine neue Sitzung hilft nicht mehr', Auth::CHALLENGE_REQUIRED !== $danach, (string) $danach );

/* ------------------------------------- Der richtige Code raeumt den Zaehler */

echo "Zaehler\n";

$teils = konto( 'teils@beispiel.de', 'wieder-ein-langes-passwort', true );

$_SESSION = array();
Auth::attempt( 'teils@beispiel.de', 'wieder-ein-langes-passwort', $anfrage );
Auth::completeChallenge( '000000', $anfrage );

$nachFehler = UserRepository::find( (int) $teils['id'] );
check( 'Ein Fehlversuch steht am Konto', 1 === (int) ( $nachFehler['failed_attempts'] ?? 0 ), (string) ( $nachFehler['failed_attempts'] ?? 0 ) );

check( 'Der richtige Code kommt trotzdem durch', null === Auth::completeChallenge( Totp::codeAt( $teils["secret"] ), $anfrage ) );

$nachErfolg = UserRepository::find( (int) $teils['id'] );
check( 'Und raeumt den Zaehler', 0 === (int) ( $nachErfolg['failed_attempts'] ?? 0 ), (string) ( $nachErfolg['failed_attempts'] ?? 0 ) );

/* -------------------------------- Passwortwechsel beendet fremde Sitzungen */

echo "Sitzungen\n";

$wechsel = konto( 'wechsel@beispiel.de', 'das-alte-lange-passwort' );

// In der Kommandozeile gibt es keine echte Sitzungskennung — die frueheren
// Anmeldungen haben darum alle dieselbe Zeile belegt. Fuer diesen Abschnitt
// ein sauberer Tisch.
Database::pdo()->exec( 'DELETE FROM `nl_sessions`' );

// Zwei Sitzungen desselben Kontos, eine davon die laufende.
Database::insert(
	'sessions',
	array(
		'id' => hash( 'sha256', 'fremde-sitzung' ), 'user_id' => (int) $wechsel['id'],
		'ip' => '198.51.100.7', 'user_agent' => 'Angreifer', 'created_at' => nl_utc(), 'expires_at' => nl_utc( 3600 ),
	)
);
Database::insert(
	'sessions',
	array(
		'id' => hash( 'sha256', Session::id() ), 'user_id' => (int) $wechsel['id'],
		'ip' => '203.0.113.1', 'user_agent' => 'Eigene', 'created_at' => nl_utc(), 'expires_at' => nl_utc( 3600 ),
	)
);

check( 'Vorher stehen zwei Sitzungen da',
	2 === count( Database::select( 'SELECT `id` FROM `nl_sessions` WHERE `user_id` = :u', array( 'u' => (int) $wechsel['id'] ) ) ) );

$beendet = Auth::logoutOthers( (int) $wechsel['id'] );

check( 'Eine wird beendet', 1 === $beendet, (string) $beendet );

$uebrig = Database::select( 'SELECT * FROM `nl_sessions` WHERE `user_id` = :u', array( 'u' => (int) $wechsel['id'] ) );
check( 'Die eigene bleibt', 1 === count( $uebrig ) );
check( 'Und zwar wirklich die eigene', hash( 'sha256', Session::id() ) === ( $uebrig[0]['id'] ?? '' ) );

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
