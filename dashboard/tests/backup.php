<?php
/**
 * Restic-Huelle: Adressbau, ssh-Konfiguration, Aufruf und Umgebung.
 *
 * Ohne echten Speicher. Statt restic laeuft ein Skript, das protokolliert,
 * womit es aufgerufen wurde — genau das war naemlich kaputt.
 */
declare( strict_types = 1 );

namespace NorthLab\Core {
	class Setting {
		/** @var array<string,string> */
		public static array $values = array();

		public static function get( string $name, string $default = '' ): string {
			return array_key_exists( $name, self::$values ) ? self::$values[ $name ] : $default;
		}
		public static function getInt( string $name, int $default = 0 ): int {
			$v = self::get( $name, (string) $default );
			return '' === $v ? $default : (int) $v;
		}
		public static function getBool( string $name, bool $default = false ): bool {
			return in_array( self::get( $name, $default ? '1' : '0' ), array( '1', 'true', 'yes', 'on' ), true );
		}
		public static function getArray( string $name, array $default = array() ): array {
			$raw = self::get( $name, '' );
			$d   = json_decode( $raw, true );
			return is_array( $d ) ? $d : $default;
		}
		public static function set( string $name, $value ): void {
			self::$values[ $name ] = is_array( $value ) ? (string) json_encode( $value ) : (string) $value;
		}
		public static function setMany( array $values ): void {
			foreach ( $values as $k => $v ) { self::set( (string) $k, $v ); }
		}
	}

	class Config {
		public static function get( string $name, $default = null ) {
			return 'app.key' === $name ? base64_encode( str_repeat( 'k', 32 ) ) : $default;
		}
	}

	class Logger {
		/** @var array<int,string> */
		public static array $lines = array();
		public static function error( string $message, array $context = array() ): void {
			self::$lines[] = $message;
		}
		public static function exception( \Throwable $e ): void {
			self::$lines[] = $e->getMessage();
		}
	}
}

namespace {

$root = realpath( dirname( __DIR__ ) );
define( 'NL_ROOT', $root );
define( 'NL_SRC', $root . '/src' );
define( 'NL_STORAGE', sys_get_temp_dir() . '/nlc-backup-test' );

spl_autoload_register( static function ( string $c ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = NL_SRC . '/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );

use NorthLab\Core\Setting;
use NorthLab\Service\Restic;

$fails = 0;
$n     = 0;
function check( string $label, bool $ok, string $extra = '' ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label" . ( '' !== $extra ? " ($extra)" : '' ) . "\n"; }
}

// Aufraeumen und eine Attrappe fuer restic hinlegen.
exec( 'rm -rf ' . escapeshellarg( NL_STORAGE ) );
mkdir( NL_STORAGE, 0700, true );

$fakeBin = NL_STORAGE . '/fake-restic';
$logFile = NL_STORAGE . '/aufruf.log';

file_put_contents(
	$fakeBin,
	"#!/bin/sh\n"
	. "{\n"
	. "  echo \"ARGS:\$*\"\n"
	. "  echo \"HOME=\$HOME\"\n"
	. "  echo \"RESTIC_REPOSITORY=\$RESTIC_REPOSITORY\"\n"
	. "  echo \"RESTIC_PASSWORD=\$RESTIC_PASSWORD\"\n"
	. "  echo \"RESTIC_CACHE_DIR=\$RESTIC_CACHE_DIR\"\n"
	. "  echo \"AWS_ACCESS_KEY_ID=\$AWS_ACCESS_KEY_ID\"\n"
	. "  echo \"AWS_SECRET_ACCESS_KEY=\$AWS_SECRET_ACCESS_KEY\"\n"
	. "} > " . escapeshellarg( $logFile ) . "\n"
	. "echo 'restic 0.17.3 compiled with go1.23'\n"
	. "exit 0\n"
);
chmod( $fakeBin, 0755 );

function aufruf(): string {
	$path = NL_STORAGE . '/aufruf.log';
	return is_file( $path ) ? (string) file_get_contents( $path ) : '';
}

/* ------------------------------------------------- Adresse zusammensetzen */

$sb = Restic::buildRepository( 'storagebox', array( 'user' => 'u123456', 'path' => 'northlab' ) );

check( 'Storage Box: Benutzer ist zugleich der Wirt',
	'sftp://u123456@u123456.your-storagebox.de:23/northlab' === $sb, $sb );
check( 'Storage Box: Port 23, nicht 22', str_contains( $sb, ':23/' ) );
check( 'Storage Box: Grossschreibung stoert nicht',
	str_starts_with( Restic::buildRepository( 'storagebox', array( 'user' => 'U123456' ) ), 'sftp://u123456@' ) );
check( 'Storage Box: ohne Unterordner ein Vorgabename',
	str_ends_with( Restic::buildRepository( 'storagebox', array( 'user' => 'u1' ) ), '/northlab' ) );
check( 'Storage Box: fuehrende Schraegstriche fliegen raus',
	str_ends_with( Restic::buildRepository( 'storagebox', array( 'user' => 'u1', 'path' => '/kunden/' ) ), ':23/kunden' ) );
check( 'Storage Box: ohne Benutzer keine Adresse',
	'' === Restic::buildRepository( 'storagebox', array( 'user' => '' ) ) );

$s3 = Restic::buildRepository( 's3', array(
	'endpoint' => 'https://fsn1.your-objectstorage.com',
	'bucket'   => 'nl-sicherung',
	'path'     => 'panel',
) );

check( 'S3: Endpunkt, Bucket und Unterordner',
	's3:https://fsn1.your-objectstorage.com/nl-sicherung/panel' === $s3, $s3 );
check( 'S3: Schraegstrich am Endpunkt stoert nicht',
	's3:https://fsn1.your-objectstorage.com/b' === Restic::buildRepository( 's3', array(
		'endpoint' => 'https://fsn1.your-objectstorage.com/', 'bucket' => 'b' ) ) );
check( 'S3: ohne Bucket keine Adresse',
	'' === Restic::buildRepository( 's3', array( 'endpoint' => 'https://x', 'bucket' => '' ) ) );
check( 'Alle drei Hetzner-Standorte sind hinterlegt', 3 === count( Restic::S3_ENDPOINTS ) );

check( 'Eigener SFTP-Server wird durchgereicht',
	'sftp://a@b:2222//srv/repo' === Restic::buildRepository( 'sftp', array( 'repository' => 'sftp://a@b:2222//srv/repo' ) ) );
check( 'Lokaler Pfad ohne Schraegstrich am Ende',
	'/mnt/box' === Restic::buildRepository( 'local', array( 'repository' => '/mnt/box/' ) ) );

/* ------------------------------------------------------ Adresse zerlegen */

$p = Restic::parseSftp( 'sftp://u123456@u123456.your-storagebox.de:23/northlab' );
check( 'Zerlegen: Benutzer', 'u123456' === ( $p['user'] ?? '' ) );
check( 'Zerlegen: Wirt', 'u123456.your-storagebox.de' === ( $p['host'] ?? '' ) );
check( 'Zerlegen: Port', 23 === ( $p['port'] ?? 0 ) );

$p2 = Restic::parseSftp( 'sftp:benutzer@wirt.de:/srv/repo' );
check( 'Zerlegen: alte Schreibweise ohne //', 'wirt.de' === ( $p2['host'] ?? '' ) );
check( 'Zerlegen: ohne Portangabe gilt 22', 22 === ( $p2['port'] ?? 0 ) );

$p3 = Restic::parseSftp( 'sftp://a@b//absolut/pfad' );
check( 'Zerlegen: doppelter Schraegstrich als Pfadbeginn', 'b' === ( $p3['host'] ?? '' ) );

check( 'Zerlegen: S3 ist kein sftp', null === Restic::parseSftp( 's3:https://x/y' ) );
check( 'Zerlegen: ohne Benutzer nicht verwertbar', null === Restic::parseSftp( 'sftp://wirt.de/pfad' ) );

/* --------------------------------------------------- Aufruf und Umgebung */

Setting::$values = array(
	'backup_target_type' => 'storagebox',
	'restic_repository'  => $sb,
	'restic_binary'      => $fakeBin,
	'restic_password'    => \NorthLab\Core\Crypto::encrypt( 'geheim-123' ),
);

check( 'Passwort wird verschluesselt abgelegt',
	str_starts_with( Setting::get( 'restic_password' ), 'v2:' ) || str_starts_with( Setting::get( 'restic_password' ), 'v1:' ) );
check( 'Und kommt im Klartext zurueck', 'geheim-123' === Restic::password() );
check( 'Klartext aus alten Installationen wird noch gelesen', (function () {
	$keep = Setting::$values['restic_password'];
	Setting::$values['restic_password'] = 'alt-im-klartext';
	$read = Restic::password();
	Setting::$values['restic_password'] = $keep;
	return 'alt-im-klartext' === $read;
})() );

$result = Restic::run( array( 'snapshots' ) );

check( 'restic wird ueberhaupt ausgefuehrt', $result['ok'], trim( $result['output'] ) );
check( 'Der Unterbefehl kommt an', str_contains( aufruf(), ' snapshots' ) );
check( 'Die Adresse steht in der Umgebung', str_contains( aufruf(), 'RESTIC_REPOSITORY=' . $sb ) );
check( 'Das Passwort steht in der Umgebung', str_contains( aufruf(), 'RESTIC_PASSWORD=geheim-123' ) );
check( 'HOME zeigt ins Arbeitsverzeichnis', str_contains( aufruf(), 'HOME=' . Restic::workDir() ) );
check( 'Der Cache liegt daneben', str_contains( aufruf(), 'RESTIC_CACHE_DIR=' . Restic::workDir() . '/cache' ) );
check( 'Ohne S3 keine AWS-Schluessel', str_contains( aufruf(), 'AWS_ACCESS_KEY_ID=' . "\n" ) );

/* --- Und dasselbe mit hinterlegtem SSH-Schluessel: daran ist es gescheitert */

$keyPath = NL_STORAGE . '/restic/.ssh/id_ed25519';
@mkdir( dirname( $keyPath ), 0700, true );
file_put_contents( $keyPath, "-- kein echter Schluessel --\n" );
file_put_contents( $keyPath . '.pub', "ssh-ed25519 AAAAC3Nz northlab-panel\n" );
Setting::$values['restic_ssh_key'] = $keyPath;

@unlink( $logFile );
$withKey = Restic::run( array( 'snapshots' ) );

check( 'Mit SSH-Schluessel laeuft restic trotzdem', $withKey['ok'], trim( $withKey['output'] ) );
check( 'Auch dann kommt der Unterbefehl an', str_contains( aufruf(), ' snapshots' ) );

$config = Restic::sshDir() . '/config';

check( 'Eine ssh-Konfiguration wird geschrieben', is_file( $config ) );

$conf = is_file( $config ) ? (string) file_get_contents( $config ) : '';

check( 'ssh-Konfiguration: gilt fuer die uebergebene Verbindung', str_contains( $conf, 'Host *' ) );
check( 'ssh-Konfiguration: Benutzer', str_contains( $conf, 'User u123456' ) );
check( 'ssh-Konfiguration: Port 23', str_contains( $conf, 'Port 23' ) );
check( 'ssh-Konfiguration: eigener Schluessel', str_contains( $conf, 'IdentityFile "' . $keyPath . '"' ) );
check( 'ssh-Konfiguration: nur dieser Schluessel', str_contains( $conf, 'IdentitiesOnly yes' ) );
check( 'ssh-Konfiguration: eigene known_hosts',
	str_contains( $conf, 'UserKnownHostsFile "' . Restic::knownHostsPath() . '"' ) );
check( 'ssh-Konfiguration: Wirtspruefung bleibt an', str_contains( $conf, 'StrictHostKeyChecking yes' ) );
check( 'ssh-Konfiguration: keine Passwortabfrage', str_contains( $conf, 'BatchMode yes' ) );
check( 'ssh-Konfiguration ist nur fuer den Eigentuemer lesbar',
	is_file( $config ) && '0600' === substr( sprintf( '%o', fileperms( $config ) ), -4 ) );

// Pfade gehoeren in Anfuehrungszeichen: ssh zerlegt den Wert sonst am Leerzeichen.
check( 'ssh-Konfiguration: Pfade sind eingefasst',
	1 === preg_match( '/UserKnownHostsFile "[^"]+"/', $conf ) );

/* --- Und der Weg, auf dem restic ueberhaupt erst zu dieser Datei kommt ---- */

// ssh nimmt sein Heimatverzeichnis aus der Passwortdatenbank, nicht aus HOME.
// Eine Konfiguration, die nur dort laege, wuerde nie gelesen.
check( 'restic bekommt einen eigenen Verbindungsbefehl',
	str_contains( aufruf(), 'ARGS:-o sftp.command=' ) );
check( 'Der Befehl reicht die Konfiguration ausdruecklich weiter',
	str_contains( aufruf(), "-F '" . Restic::sshConfigPath() . "'" ) );
check( 'Und nennt den Wirt', str_contains( aufruf(), 'u123456.your-storagebox.de -s sftp' ) );
check( 'Der Unterbefehl steht dahinter', str_contains( aufruf(), 'sftp snapshots' ) );

$lokal = Restic::sftpCommand( array( 'user' => 'u1', 'host' => 'wirt.de', 'port' => 23 ) );
check( 'Der Verbindungsbefehl faengt mit ssh an', str_starts_with( $lokal, 'ssh -F ' ) );
check( 'Und hoert mit dem sftp-Dienst auf', str_ends_with( $lokal, 'wirt.de -s sftp' ) );

/* ------------------------------------------------------------ S3-Zugang */

Setting::$values = array(
	'backup_target_type' => 's3',
	'restic_repository'  => $s3,
	'restic_binary'      => $fakeBin,
	'restic_password'    => \NorthLab\Core\Crypto::encrypt( 'geheim-123' ),
	's3_access_key'      => 'AKIAPANEL',
	's3_secret_key'      => \NorthLab\Core\Crypto::encrypt( 'sehr-geheim' ),
);

@unlink( $logFile );
Restic::run( array( 'snapshots' ) );

check( 'S3: Zugangsschluessel geht an restic', str_contains( aufruf(), 'AWS_ACCESS_KEY_ID=AKIAPANEL' ) );
check( 'S3: Geheimnis wird entschluesselt uebergeben', str_contains( aufruf(), 'AWS_SECRET_ACCESS_KEY=sehr-geheim' ) );
check( 'S3: gilt als eingerichtet', Restic::configured() );
check( 'S3: ohne Geheimnis nicht eingerichtet', (function () {
	$keep = Setting::$values['s3_secret_key'];
	Setting::$values['s3_secret_key'] = '';
	$ok = Restic::configured();
	Setting::$values['s3_secret_key'] = $keep;
	return ! $ok;
})() );
check( 'S3: kein ssh noetig, also keine Konfiguration', null === Restic::parseSftp( $s3 ) );
check( 'S3: und kein Verbindungsbefehl im Aufruf', ! str_contains( aufruf(), 'sftp.command' ) );

/* --------------------------------------------------------- Wirtsschluessel */

Setting::$values = array(
	'backup_target_type' => 'storagebox',
	'restic_repository'  => $sb,
	'restic_binary'      => $fakeBin,
);

$blob = base64_encode( 'so-tun-als-waere-das-ein-schluessel' );

check( 'Fingerabdruck in der Form, die OpenSSH zeigt',
	'SHA256:' . rtrim( base64_encode( hash( 'sha256', base64_decode( $blob ), true ) ), '=' ) === Restic::fingerprint( $blob ) );
check( 'Fingerabdruck traegt kein Fuellzeichen', ! str_contains( Restic::fingerprint( $blob ), '=' ) );

check( 'Vor der Uebernahme ist der Wirt unbekannt', ! Restic::hostKnown() );

$line = '[u123456.your-storagebox.de]:23 ssh-ed25519 ' . $blob;

check( 'Uebernahme laeuft durch', null === Restic::trustHostKeys( array( $line ) ) );
check( 'Danach ist der Wirt bekannt', Restic::hostKnown() );
check( 'known_hosts ist nur fuer den Eigentuemer lesbar',
	'0600' === substr( sprintf( '%o', fileperms( Restic::knownHostsPath() ) ), -4 ) );

// Ein zweiter Durchgang darf nicht zwei widersprechende Zeilen hinterlassen.
$neu = '[u123456.your-storagebox.de]:23 ssh-ed25519 ' . base64_encode( 'ein-anderer-schluessel' );
Restic::trustHostKeys( array( $neu ) );
$known = (string) file_get_contents( Restic::knownHostsPath() );

check( 'Der alte Eintrag desselben Wirts weicht dem neuen', ! str_contains( $known, $blob ) );
check( 'Der neue steht drin', str_contains( $known, base64_encode( 'ein-anderer-schluessel' ) ) );
check( 'Genau eine Zeile fuer diesen Wirt',
	1 === count( array_filter( explode( "\n", $known ), static fn( $l ) => str_contains( $l, 'your-storagebox.de' ) ) ) );

// Ein fremder Wirt bleibt unangetastet.
file_put_contents( Restic::knownHostsPath(), "fremder.host.de ssh-rsa AAAA\n", FILE_APPEND );
Restic::trustHostKeys( array( $line ) );
check( 'Ein fremder Wirt bleibt stehen',
	str_contains( (string) file_get_contents( Restic::knownHostsPath() ), 'fremder.host.de' ) );

check( 'Ein Eintrag ohne Port passt nicht auf Port 23', (function () use ( $blob ) {
	file_put_contents( \NorthLab\Service\Restic::knownHostsPath(), 'u123456.your-storagebox.de ssh-ed25519 ' . $blob . "\n" );
	return ! \NorthLab\Service\Restic::hostKnown();
})() );

/* ----------------------------------------------------------- Bereitschaft */

Setting::$values = array( 'restic_binary' => $fakeBin );

$states = array();
foreach ( Restic::diagnose() as $entry ) { $states[ $entry['label'] ] = $entry['state']; }

check( 'Prueflauf meldet das gefundene restic', 'ok' === ( $states['restic installiert'] ?? '' ) );
check( 'Prueflauf vermisst das Ziel', 'bad' === ( $states['Ziel eingetragen'] ?? '' ) );
check( 'Prueflauf vermisst das Passwort', 'bad' === ( $states['Repository-Passwort gesetzt'] ?? '' ) );
check( 'Ohne Ziel keine Netzpruefung', ! array_key_exists( 'Port 23 erreichbar', $states ) );

// Ein Passwort, das mit einem anderen app.key verschluesselt wurde: gesetzt sieht
// es aus, brauchbar ist es nicht.
Setting::$values = array(
	'restic_binary'     => $fakeBin,
	'restic_repository' => $sb,
	'restic_password'   => 'v2:' . base64_encode( random_bytes( 60 ) ),
);

$kaputt = array();
foreach ( Restic::diagnose() as $entry ) { $kaputt[ $entry['label'] ] = $entry; }

check( 'Ein unlesbares Passwort gilt nicht als gesetzt',
	'bad' === ( $kaputt['Repository-Passwort gesetzt']['state'] ?? '' ) );
check( 'Und der Grund wird genannt',
	str_contains( (string) ( $kaputt['Repository-Passwort gesetzt']['detail'] ?? '' ), 'app.key' ) );
check( 'Damit gilt das Ziel als nicht eingerichtet', ! Restic::configured() );

Setting::$values = array(
	'restic_binary'     => $fakeBin,
	'restic_repository' => $sb,
	'restic_password'   => \NorthLab\Core\Crypto::encrypt( 'x' ),
	'restic_ssh_key'    => $keyPath,
);

$states = array();
foreach ( Restic::diagnose() as $entry ) { $states[ $entry['label'] ] = $entry['state']; }

check( 'Mit Ziel wird der Schluessel geprueft', array_key_exists( 'SSH-Schlüssel vorhanden', $states ) );
check( 'Der hinterlegte Schluessel wird gefunden', 'ok' === ( $states['SSH-Schlüssel vorhanden'] ?? '' ) );
check( 'Der Wirtsschluessel wird geprueft', array_key_exists( 'Wirtsschlüssel bekannt', $states ) );
check( 'Die Netzpruefung bleibt aussen vor', ! array_key_exists( 'Port 23 erreichbar', $states ) );

$netz = array();
foreach ( Restic::diagnose( true ) as $entry ) { $netz[ $entry['label'] ] = $entry['state']; }
check( 'Auf Wunsch wird auch der Port geprueft', array_key_exists( 'Port 23 erreichbar', $netz ) );

/* -------------------------------------------- Wer gehoert in den Zeitplan */

use NorthLab\Service\BackupService;

check( 'Eine Seite mit 1 ist dabei', BackupService::scheduled( array( 'backup_enabled' => 1 ) ) );
check( 'Eine Seite mit 0 nicht', ! BackupService::scheduled( array( 'backup_enabled' => 0 ) ) );
check( 'Auch "0" als Text zaehlt als aus', ! BackupService::scheduled( array( 'backup_enabled' => '0' ) ) );
// Vor Schema 9 gibt es die Spalte nicht — dann gilt die Vorgabe.
check( 'Fehlt die Spalte, wird gesichert', BackupService::scheduled( array( 'id' => 7 ) ) );

/* ------------------------------------------------------------- Anzeige */

check( 'Ein Passwort in der Adresse wird verdeckt',
	str_contains( Restic::mask( 'sftp://u1:passwort@wirt/p' ), '•••' ) );
check( 'Und steht nicht mehr drin',
	! str_contains( Restic::mask( 'sftp://u1:passwort@wirt/p' ), 'passwort' ) );
check( 'Der Benutzer bleibt aber lesbar',
	str_contains( Restic::mask( 'sftp://u1:passwort@wirt/p' ), 'u1@' ) === false
	&& str_contains( Restic::mask( 'sftp://u1:passwort@wirt/p' ), 'u1:•••@' ) );

// Ohne Passwort gibt es nichts zu verdecken — und der Benutzer der Storage
// Box ist die Angabe, an der man die eigene Box ueberhaupt erkennt.
$sbAdresse = Restic::buildRepository( 'storagebox', array( 'user' => 'u123456', 'path' => 'northlab' ) );

check( 'Ohne Passwort bleibt die Adresse unveraendert', $sbAdresse === Restic::mask( $sbAdresse ) );
check( 'Der Benutzer der Storage Box bleibt sichtbar',
	str_contains( Restic::mask( $sbAdresse ), 'u123456@u123456.your-storagebox.de' ), Restic::mask( $sbAdresse ) );
check( 'Und der Port auch', str_contains( Restic::mask( $sbAdresse ), ':23/northlab' ) );
check( 'Eine S3-Adresse bleibt unangetastet',
	's3:https://fsn1.your-objectstorage.com/eimer' === Restic::mask( 's3:https://fsn1.your-objectstorage.com/eimer' ) );

/* ------------------------------------------------------ Art des Ziels */

Setting::$values = array( 'restic_repository' => $s3 );
check( 'Die Art wird aus einer alten Adresse abgelesen: S3', 's3' === Restic::type() );
Setting::$values = array( 'restic_repository' => $sb );
check( 'Die Art wird abgelesen: Storage Box', 'storagebox' === Restic::type() );
Setting::$values = array( 'restic_repository' => 'sftp://a@b/c' );
check( 'Die Art wird abgelesen: eigener SFTP', 'sftp' === Restic::type() );
Setting::$values = array( 'restic_repository' => '/mnt/box' );
check( 'Die Art wird abgelesen: lokal', 'local' === Restic::type() );
Setting::$values = array( 'backup_target_type' => 's3', 'restic_repository' => $sb );
check( 'Die gespeicherte Art gilt vor der Ablesung', 's3' === Restic::type() );

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );

}
