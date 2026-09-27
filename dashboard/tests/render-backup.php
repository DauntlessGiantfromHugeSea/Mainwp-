<?php
/**
 * Die Sicherungsseite rendern — in jeder Speicherart und in jedem Zustand,
 * den die Einrichtung durchlaeuft.
 */
declare( strict_types = 1 );

namespace NorthLab\Core {
	class Setting {
		/** @var array<string,string> */
		public static array $values = array();
		public static function all(): array { return self::$values; }
		public static function get( string $n, string $d = '' ): string {
			return array_key_exists( $n, self::$values ) ? self::$values[ $n ] : $d;
		}
		public static function getInt( string $n, int $d = 0 ): int {
			$v = self::get( $n, (string) $d ); return '' === $v ? $d : (int) $v;
		}
		public static function getBool( string $n, bool $d = false ): bool {
			return in_array( self::get( $n, $d ? '1' : '0' ), array( '1', 'true', 'yes', 'on' ), true );
		}
		public static function getArray( string $n, array $d = array() ): array {
			$x = json_decode( self::get( $n, '' ), true ); return is_array( $x ) ? $x : $d;
		}
		public static function set( string $n, $v ): void {
			self::$values[ $n ] = is_array( $v ) ? (string) json_encode( $v ) : (string) $v;
		}
		public static function setMany( array $v ): void { foreach ( $v as $k => $x ) { self::set( (string) $k, $x ); } }
	}

	class Config {
		public static function get( string $n, $d = null ) {
			return 'app.key' === $n ? base64_encode( str_repeat( 'k', 32 ) ) : $d;
		}
	}

	class Logger {
		public static function error( string $m, array $c = array() ): void {}
		public static function exception( \Throwable $e ): void {}
	}
}

namespace {

$root = realpath( dirname( __DIR__ ) );
define( 'NL_ROOT', $root );
define( 'NL_SRC', $root . '/src' );
define( 'NL_VIEWS', $root . '/views' );
define( 'NL_STORAGE', sys_get_temp_dir() . '/nlc-renderbackup' );
define( 'NL_VERSION', '1.0.0' );

spl_autoload_register( static function ( string $c ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = NL_SRC . '/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );

function url( string $path = '' ): string { return 'https://panel.test' . $path; }
function csrf_field(): string { return '<input type="hidden" name="_token" value="t">'; }

require_once NL_SRC . '/helpers.php';
require_once NL_SRC . '/view-helpers.php';

use NorthLab\Core\Auth;
use NorthLab\Core\Setting;
use NorthLab\Core\View;
use NorthLab\Service\Restic;

$auth = new ReflectionClass( Auth::class );
$auth->setStaticPropertyValue( 'user', array( 'id' => 1, 'role' => 'admin', 'name' => 'Test' ) );
$auth->setStaticPropertyValue( 'resolved', true );

$fails = 0;
$n     = 0;
function check( string $label, bool $ok ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label\n"; }
}

exec( 'rm -rf ' . escapeshellarg( NL_STORAGE ) );
mkdir( NL_STORAGE, 0700, true );

/**
 * @param array<string,mixed> $data
 */
function render( array $data ): string {
	$shared = new ReflectionProperty( View::class, 'shared' );
	$shared->setAccessible( true );
	$shared->setValue( null, array() );

	$defaults = array(
		'rows'        => array(),
		'history'     => array(),
		'enabled'     => false,
		'configured'  => false,
		'available'   => true,
		'resticInfo'  => 'restic 0.17.3',
		'resticProblem' => null,
		'cronHealthy'     => true,
		'cronLast'        => gmdate( 'Y-m-d H:i:s' ),
		'scheduleWarning' => null,
		'repository'  => Restic::repository(),
		'targetType'  => Restic::type(),
		'checks'      => Restic::diagnose(),
		'publicKey'   => Restic::publicKey(),
		'sftp'        => Restic::parseSftp( Restic::repository() ),
		'pendingKeys' => Setting::getArray( 'restic_hostkey_pending', array() ),
		'setupSteps'  => Setting::getArray( 'restic_setup_steps', array() ),
		'setupAt'     => Setting::get( 'restic_setup_at', '' ),
		'hostKnown'   => Restic::hostKnown(),
		'panelOn'     => true,
		'panelAt'     => '',
		'panelState'  => '',
		'panelNote'   => '',
		'hour'        => 3,
		'diskFree'    => 12345678,
		'settings'    => Setting::all(),
	);

	extract( array_replace( $defaults, $data ), EXTR_SKIP );
	ob_start();
	include NL_VIEWS . '/pages/backups/index.php';
	return (string) ob_get_clean();
}

/* ------------------------------------------------- Frisch, nichts gesetzt */

Setting::$values = array();

$html = render( array() );

check( 'Frisch: die Seite rendert', str_contains( $html, 'Sicherungsziel' ) );
check( 'Frisch: Storage Box ist vorausgewaehlt',
	preg_match( '/value="storagebox"[^>]*checked/', $html ) === 1 );
check( 'Frisch: die Felder der Storage Box sind sichtbar',
	preg_match( '/data-choice-when="target:storagebox"(?![^>]*hidden)/', $html ) === 1 );
check( 'Frisch: die S3-Felder sind ausgeblendet',
	preg_match( '/data-choice-when="target:s3"[^>]*hidden/', $html ) === 1 );
check( 'Frisch: es wird zum Schluesselerzeugen aufgefordert',
	str_contains( $html, 'Schlüsselpaar erzeugen' ) );
check( 'Frisch: ohne Schluessel verweist der Kasten aufs Erzeugen',
	str_contains( $html, 'erst unten unter „SSH-Zugang“ ein Schlüsselpaar erzeugen' ) );
check( 'Frisch: die Bereitschaft meldet offene Punkte', str_contains( $html, 'offen' ) );
check( 'Frisch: kein Wirtsschluessel-Block ohne Ziel', ! str_contains( $html, 'Wirtsschlüssel abrufen' ) );
check( 'Frisch: das Panel selbst wird angeboten', str_contains( $html, 'Das Panel selbst' ) );
check( 'Frisch: die Einrichtung in einem Rutsch wird angeboten',
	str_contains( $html, 'Jetzt einrichten' ) );
check( 'Frisch: dafuer wird das Passwort des Speichers erfragt',
	str_contains( $html, 'name="box_password"' ) );
check( 'Frisch: mit dem Hinweis, dass es nicht gespeichert wird',
	str_contains( $html, 'nirgends gespeichert' ) );
check( 'Frisch: Port 23 wird erklaert', str_contains( $html, 'Port 23' ) );
// Neue Storage Boxen haben SSH ab Werk aus — das ist die haeufigste Huerde.
check( 'Frisch: auf SSH-Support und externe Erreichbarkeit wird hingewiesen',
	str_contains( $html, 'SSH-Support' ) && str_contains( $html, 'Externe Erreichbarkeit' ) );
check( 'Frisch: Unterkonten werden erwaehnt', str_contains( $html, 'u123456-sub1' ) );
check( 'Frisch: der Port laesst sich waehlen', str_contains( $html, 'name="sb_port"' ) );
check( 'Frisch: mit 23 als Vorgabe', 1 === preg_match( '/value="23"[^>]*selected/', $html ) );
check( 'Frisch: und 22 als Ausweg', str_contains( $html, '22 — nur SFTP' ) );
check( 'Frisch: der Weg ohne Passwort wird gezeigt',
	str_contains( $html, 'Kein Passwort zur Hand?' ) );
check( 'Frisch: mit dem Hinweis aufs eigene Box-Passwort',
	str_contains( $html, 'nicht das deines' ) );
check( 'Frisch: und der Anweisung, das Feld leer zu lassen',
	str_contains( $html, 'leer lassen' ) );

// Ausgeblendete Felder werden trotzdem mitgeschickt. Traegt ein leeres Feld
// denselben Namen wie ein ausgefuelltes, gewinnt das letzte — und die Eingabe
// waere weg.
$form = '';
if ( preg_match( '#<input type="hidden" name="section" value="target">(.*?)</form>#s', $html, $m ) ) {
	$form = $m[1];
}

preg_match_all( '/\bname="([a-z0-9_]+)"/', $form, $names );

$mehrfach = array();
foreach ( array_count_values( $names[1] ) as $name => $anzahl ) {
	// Nur die Auswahlknoepfe duerfen sich einen Namen teilen.
	if ( $anzahl > 1 && 'backup_target_type' !== $name ) {
		$mehrfach[] = $name;
	}
}

check( 'Das Zielformular ist nicht leer', '' !== $form );
check( 'Kein Feldname kommt zweimal vor: ' . implode( ', ', $mehrfach ), ! $mehrfach );
check( 'Die vier Auswahlknoepfe teilen sich einen Namen',
	4 === count( array_filter( $names[1], static fn( $x ) => 'backup_target_type' === $x ) ) );

/* ------------------------------------------- Storage Box mit Schluessel */

$repo = Restic::buildRepository( 'storagebox', array( 'user' => 'u123456', 'path' => 'northlab' ) );

Setting::$values = array(
	'backup_target_type' => 'storagebox',
	'restic_repository'  => $repo,
	'sb_user'            => 'u123456',
	'sb_path'            => 'northlab',
	'restic_password'    => 'v2:abc',
);

$keyDir = Restic::sshDir();
@mkdir( $keyDir, 0700, true );
file_put_contents( Restic::keyPath(), "-----BEGIN OPENSSH PRIVATE KEY-----\nGEHEIMER-TEIL-XYZ\n" );
file_put_contents( Restic::keyPath() . '.pub', 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5 northlab-panel' );

$html = render( array( 'configured' => true, 'enabled' => true ) );

check( 'Mit Schluessel: der oeffentliche Teil steht da',
	str_contains( $html, 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5 northlab-panel' ) );
// Auch im Kasten "Kein Passwort zur Hand" — dort wird er gebraucht.
check( 'Mit Schluessel: er steht auch zum Einfuegen ins Hetzner-Konto bereit',
	2 === substr_count( $html, 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5 northlab-panel' ) );
check( 'Mit Schluessel: mit der Anleitung dazu', str_contains( $html, 'SSH-Keys' ) );
check( 'Mit Schluessel: der Einspielbefehl nennt Port 23',
	str_contains( $html, 'ssh-copy-id -s -p 23' ) );
check( 'Mit Schluessel: der Befehl nennt Benutzer und Wirt',
	str_contains( $html, 'u123456@u123456.your-storagebox.de' ) );
// An der Kachel oben erkennt man, welche Box gemeint ist. Der Benutzer
// gehoert dort hin, ein Passwort nicht.
check( 'Die Kachel zeigt den Benutzer der Storage Box',
	str_contains( $html, 'u123456@u123456.your-storagebox.de' ) );
check( 'Und verdeckt ihn nicht', ! str_contains( $html, 'sftp:•••@' ) );

check( 'Mit Schluessel: die Wiederherstellung nennt den Verbindungsbefehl',
	str_contains( $html, 'sftp.command=ssh -F' ) );
check( 'Mit Schluessel: und erklaert, warum HOME dafuer nicht reicht',
	str_contains( $html, 'Passwortdatenbank' ) );
check( 'Mit Schluessel: der Wirtsschluessel laesst sich abrufen',
	str_contains( $html, 'Wirtsschlüssel abrufen' ) );
check( 'Mit Schluessel: er gilt noch als nicht hinterlegt',
	str_contains( $html, 'noch nicht hinterlegt' ) );
check( 'Mit Schluessel: die eingetragenen Werte stehen in den Feldern',
	str_contains( $html, 'value="u123456"' ) && str_contains( $html, 'value="northlab"' ) );
check( 'Mit Schluessel: das Passwortfeld bleibt leer und sagt das',
	str_contains( $html, 'gesetzt — leer lassen für unverändert' ) );
check( 'Der private Schluessel taucht nirgends auf', ! str_contains( $html, 'GEHEIMER-TEIL-XYZ' ) );

/* --------------------------------------------- Abgerufener Wirtsschluessel */

Setting::set( 'restic_hostkey_pending', array(
	array( 'type' => 'ssh-ed25519', 'line' => '[u123456.your-storagebox.de]:23 ssh-ed25519 AAAA', 'fingerprint' => 'SHA256:abc123' ),
) );

$html = render( array( 'configured' => true ) );

check( 'Abgerufen: der Fingerabdruck wird gezeigt', str_contains( $html, 'SHA256:abc123' ) );
check( 'Abgerufen: zum Vergleichen aufgefordert', str_contains( $html, 'Vergleiche den Fingerabdruck' ) );
check( 'Abgerufen: uebernehmen ist ein eigener Schritt', str_contains( $html, 'Fingerabdruck stimmt' ) );
check( 'Abgerufen: nicht gleichzeitig erneut abrufen', ! str_contains( $html, 'Wirtsschlüssel abrufen' ) );

/* ------------------------------------------------------------------- S3 */

Setting::$values = array(
	'backup_target_type' => 's3',
	'restic_repository'  => 's3:https://hel1.your-objectstorage.com/eimer/panel',
	's3_endpoint'        => 'https://hel1.your-objectstorage.com',
	's3_bucket'          => 'eimer',
	's3_prefix'          => 'panel',
	's3_access_key'      => 'AKIAPANEL',
	's3_secret_key'      => 'v2:xyz',
	'restic_password'    => 'v2:abc',
);

$html = render( array( 'configured' => true ) );

check( 'S3: ist vorausgewaehlt', preg_match( '/value="s3"[^>]*checked/', $html ) === 1 );
check( 'S3: die Felder sind sichtbar',
	preg_match( '/data-choice-when="target:s3"(?![^>]*hidden)/', $html ) === 1 );
check( 'S3: alle drei Hetzner-Standorte stehen zur Wahl',
	str_contains( $html, 'fsn1.your-objectstorage.com' )
	&& str_contains( $html, 'nbg1.your-objectstorage.com' )
	&& str_contains( $html, 'hel1.your-objectstorage.com' ) );
check( 'S3: kein SSH-Block', ! str_contains( $html, 'SSH-Zugang' ) );
check( 'S3: die Wiederherstellung nennt die AWS-Schluessel',
	str_contains( $html, 'AWS_SECRET_ACCESS_KEY' ) );
check( 'S3: das Geheimnis selbst steht nicht in der Seite', ! str_contains( $html, 'v2:xyz' ) );
check( 'S3: kein Verbindungsbefehl, der dort nichts zu suchen hat',
	! str_contains( $html, 'sftp.command' ) );
check( 'S3: auch keine Einrichtungskarte', ! str_contains( $html, 'Passwort des Speichers' ) );

/* ------------------------------------------------ Lauf mit Vorgeschichte */

Setting::$values = array( 'backup_target_type' => 'local', 'restic_repository' => '/mnt/box' );

$html = render( array(
	'configured' => true,
	'enabled'    => true,
	'panelAt'    => gmdate( 'Y-m-d H:i:s', time() - 3600 ),
	'panelState' => 'success',
	'panelNote'  => 'Datenbank 2,1 MB gesichert.',
	'history'    => array(
		array(
			'started_at' => gmdate( 'Y-m-d H:i:s' ), 'site_name' => 'Kundenshop', 'status' => 'success',
			'files_total' => 1200, 'bytes' => 4096, 'db_bytes' => 2048, 'message' => 'alles gut',
		),
	),
) );

check( 'Lokal: der SSH-Block entfaellt', ! str_contains( $html, 'SSH-Zugang' ) );
check( 'Lokal: die Warnung vor derselben Platte steht da',
	str_contains( $html, 'keine Sicherung' ) );
check( 'Verlauf: der Eintrag erscheint', str_contains( $html, 'Kundenshop' ) );
check( 'Panel: der letzte Lauf wird gemeldet', str_contains( $html, 'Datenbank 2,1 MB gesichert.' ) );
check( 'Panel: als erfolgreich markiert', str_contains( $html, 'gesichert</span>' ) );

/* --------------------------------------- Ergebnis der Einrichtung zeigen */

Setting::$values = array(
	'backup_target_type' => 'storagebox',
	'restic_repository'  => $repo,
	'restic_setup_steps' => (string) json_encode( array(
		array( 'label' => 'restic gefunden', 'ok' => true, 'detail' => 'restic 0.17.3' ),
		array( 'label' => 'Wirtsschlüssel übernommen', 'ok' => true, 'detail' => 'SHA256:abcXYZ' ),
		array( 'label' => 'Schlüssel auf dem Speicher abgelegt', 'ok' => false, 'detail' => 'Das Passwort wurde abgelehnt.' ),
	) ),
	'restic_setup_at'    => gmdate( 'Y-m-d H:i:s', time() - 120 ),
);

$html = render( array() );

check( 'Ergebnis: die Schritte werden aufgelistet', str_contains( $html, 'Wirtsschlüssel übernommen' ) );
check( 'Ergebnis: der Fingerabdruck steht dabei', str_contains( $html, 'SHA256:abcXYZ' ) );
check( 'Ergebnis: der gescheiterte Schritt nennt den Grund',
	str_contains( $html, 'Das Passwort wurde abgelehnt.' ) );
check( 'Ergebnis: und ist als Fehler gekennzeichnet',
	substr_count( $html, 'badge bad' ) >= 1 );

/* ------------------------------ Laeuft der Zeitplaner ueberhaupt? -------- */

Setting::$values = array( 'backup_target_type' => 'local', 'restic_repository' => '/mnt/box' );

$stiller = render( array(
	'configured'      => true,
	'enabled'         => true,
	'cronHealthy'     => false,
	'cronLast'        => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
	'scheduleWarning' => 'Der Zeitplaner läuft nicht. Der nächtliche Lauf findet damit nicht statt.',
) );

check( 'Ein stiller Zeitplaner wird ganz oben gemeldet',
	str_contains( $stiller, 'Die nächtliche Sicherung läuft nicht' ) );
check( 'Mit dem Grund', str_contains( $stiller, 'Der Zeitplaner läuft nicht' ) );
check( 'Die Kachel wird rot statt gruen', str_contains( $stiller, 'class="stat bad"' ) );
check( 'Und sagt, seit wann es still ist', str_contains( $stiller, 'Zeitplaner still seit' ) );

$nie = render( array(
	'configured'  => true,
	'enabled'     => true,
	'cronHealthy' => false,
	'cronLast'    => null,
) );

check( 'Ein nie gelaufener Zeitplaner wird als solcher benannt',
	str_contains( $nie, 'nie gelaufen' ) );

$laeuft = render( array( 'configured' => true, 'enabled' => true ) );

check( 'Bei laufendem Zeitplaner keine Warnung',
	! str_contains( $laeuft, 'Die nächtliche Sicherung läuft nicht' ) );
check( 'Und die Kachel bleibt gruen', str_contains( $laeuft, 'class="stat ok"' ) );

/* --------------------------------------------- restic nicht einsatzbereit */

Setting::$values = array();
$html = render( array( 'available' => false, 'resticInfo' => null, 'resticProblem' => null ) );

check( 'Fehlt restic, steht es ganz oben', str_contains( $html, 'nicht einsatzbereit' ) );
check( 'Mit dem Installationsbefehl', str_contains( $html, 'apt install restic' ) );

// Steht dort eine Adresse statt eines Programms, ist die Empfehlung "apt
// install" falsch — restic ist ja da.
Setting::$values = array( 'restic_binary' => 'sftp://u669261@u669261.your-storagebox.de:23/northlab' );
$html = render( array(
	'available'     => false,
	'resticInfo'    => null,
	'resticProblem' => Restic::binaryProblem(),
) );

check( 'Ein falscher Pfad wird als solcher benannt', str_contains( $html, 'Pfad zu restic' ) );
check( 'Und nicht zur Installation geraten', ! str_contains( $html, 'apt install restic' ) );
check( 'Der falsche Wert wird gezeigt', str_contains( $html, 'u669261.your-storagebox.de' ) );

// Das Feld selbst liegt jetzt hinter "Erweitert" und heisst deutlicher.
check( 'Das Programmfeld steckt unter "Erweitert"', str_contains( $html, '<summary class="small muted">Erweitert' ) );
check( 'Es ist aufgeklappt, wenn etwas drinsteht', str_contains( $html, '<details open>' ) );
check( 'Und warnt davor, die Speicheradresse einzutragen',
	str_contains( $html, 'Nicht</strong> die Adresse des' ) );

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );

}
