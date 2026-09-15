<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Crypto;
use NorthLab\Core\Logger;
use NorthLab\Core\Setting;

/**
 * Hülle um das restic-Kommando.
 *
 * restic übernimmt Deduplizierung, Verschlüsselung und Aufbewahrung. Ohne das
 * wäre eine nächtliche Vollsicherung von einem Dutzend WordPress-Seiten weder
 * bezahlbar noch in einer Nacht fertig.
 *
 * Alles, was restic zum Arbeiten braucht — SSH-Schlüssel, known_hosts, die
 * ssh-Konfiguration und der Cache — liegt in einem eigenen Arbeitsverzeichnis
 * unter storage/. Damit läuft die Sicherung unter demselben Benutzer, egal ob
 * sie aus dem Browser angestossen wird oder nachts aus dem Cron. Ein Schlüssel
 * in /root/.ssh wäre für den Webserver-Benutzer nicht lesbar.
 */
final class Restic {

	/** Speicherarten, aus denen die Repository-Adresse gebaut wird. */
	public const TYPES = array( 'storagebox', 's3', 'sftp', 'local' );

	/** Standorte der Hetzner-Objektspeicher. */
	public const S3_ENDPOINTS = array(
		'https://fsn1.your-objectstorage.com' => 'Hetzner Falkenstein (fsn1)',
		'https://nbg1.your-objectstorage.com' => 'Hetzner Nürnberg (nbg1)',
		'https://hel1.your-objectstorage.com' => 'Hetzner Helsinki (hel1)',
	);

	/** SSH-Port einer Hetzner Storage Box. Port 22 ist dort nicht der Weg. */
	public const STORAGEBOX_PORT = 23;

	/* --------------------------------------------------------- Werkzeug */

	public static function available(): bool {
		return '' !== self::binary() && null !== self::version();
	}

	public static function binary(): string {
		$configured = trim( Setting::get( 'restic_binary', '' ) );

		if ( '' !== $configured ) {
			return is_executable( $configured ) ? $configured : '';
		}

		foreach ( array( '/usr/local/bin/restic', '/usr/bin/restic', '/opt/restic/restic' ) as $candidate ) {
			if ( is_executable( $candidate ) ) {
				return $candidate;
			}
		}

		$found = trim( (string) shell_exec( 'command -v restic 2>/dev/null' ) );

		return '' !== $found && is_executable( $found ) ? $found : '';
	}

	public static function version(): ?string {
		$result = self::run( array( 'version' ), false, 30 );

		if ( ! $result['ok'] ) {
			return null;
		}

		return trim( explode( "\n", $result['output'] )[0] ?? '' );
	}

	/** Liegt openssh-client vor? Ohne das gibt es keine sftp-Verbindung. */
	public static function sshBinary(): string {
		$found = trim( (string) shell_exec( 'command -v ssh 2>/dev/null' ) );

		return '' !== $found && is_executable( $found ) ? $found : '';
	}

	/* ------------------------------------------------------ Einstellungen */

	public static function repository(): string {
		return trim( Setting::get( 'restic_repository', '' ) );
	}

	public static function type(): string {
		$type = Setting::get( 'backup_target_type', '' );

		if ( in_array( $type, self::TYPES, true ) ) {
			return $type;
		}

		// Aeltere Installationen haben nur die Adresse. Die Art laesst sich
		// daraus ablesen.
		$repository = self::repository();

		if ( str_starts_with( $repository, 's3:' ) ) {
			return 's3';
		}
		if ( str_contains( $repository, 'your-storagebox.de' ) ) {
			return 'storagebox';
		}
		if ( str_starts_with( $repository, 'sftp:' ) ) {
			return 'sftp';
		}

		return '' === $repository ? 'storagebox' : 'local';
	}

	/**
	 * Das Repository-Passwort im Klartext.
	 *
	 * Gespeichert wird es verschlüsselt. Ältere Installationen haben es im
	 * Klartext stehen — die werden beim nächsten Speichern mitgezogen.
	 */
	public static function password(): string {
		$stored = Setting::get( 'restic_password', '' );

		if ( '' === $stored ) {
			return '';
		}

		if ( str_starts_with( $stored, 'v1:' ) || str_starts_with( $stored, 'v2:' ) ) {
			return Crypto::decrypt( $stored );
		}

		return $stored;
	}

	public static function hasPassword(): bool {
		return '' !== Setting::get( 'restic_password', '' );
	}

	public static function s3Secret(): string {
		$stored = Setting::get( 's3_secret_key', '' );

		if ( '' === $stored ) {
			return '';
		}

		if ( str_starts_with( $stored, 'v1:' ) || str_starts_with( $stored, 'v2:' ) ) {
			return Crypto::decrypt( $stored );
		}

		return $stored;
	}

	public static function configured(): bool {
		if ( '' === self::repository() || '' === self::password() ) {
			return false;
		}

		if ( 's3' === self::type() ) {
			return '' !== Setting::get( 's3_access_key', '' ) && '' !== self::s3Secret();
		}

		return true;
	}

	/**
	 * Baut die Repository-Adresse aus den Einzelfeldern.
	 *
	 * @param array<string,string> $values
	 */
	public static function buildRepository( string $type, array $values ): string {
		$path = trim( (string) ( $values['path'] ?? '' ), " \t/" );

		switch ( $type ) {
			case 'storagebox':
				$user = strtolower( trim( (string) ( $values['user'] ?? '' ) ) );

				if ( '' === $user ) {
					return '';
				}

				// Der Benutzer ist zugleich der Wirtsname. Ein einzelner
				// Schraegstrich heisst: relativ zum Anmeldeverzeichnis.
				return sprintf(
					'sftp://%s@%s.your-storagebox.de:%d/%s',
					$user,
					$user,
					self::STORAGEBOX_PORT,
					'' === $path ? 'northlab' : $path
				);

			case 's3':
				$endpoint = rtrim( trim( (string) ( $values['endpoint'] ?? '' ) ), '/' );
				$bucket   = trim( (string) ( $values['bucket'] ?? '' ), " \t/" );

				if ( '' === $endpoint || '' === $bucket ) {
					return '';
				}

				return 's3:' . $endpoint . '/' . $bucket . ( '' === $path ? '' : '/' . $path );

			case 'sftp':
				return trim( (string) ( $values['repository'] ?? '' ) );

			case 'local':
				$local = trim( (string) ( $values['repository'] ?? '' ) );

				return '' === $local ? '' : rtrim( $local, '/' );
		}

		return '';
	}

	/* ------------------------------------------------- Arbeitsverzeichnis */

	public static function workDir(): string {
		$configured = trim( Setting::get( 'restic_home', '' ) );

		return '' !== $configured ? rtrim( $configured, '/' ) : NL_STORAGE . '/restic';
	}

	public static function sshDir(): string {
		return self::workDir() . '/.ssh';
	}

	public static function keyPath(): string {
		$configured = trim( Setting::get( 'restic_ssh_key', '' ) );

		return '' !== $configured ? $configured : self::sshDir() . '/id_ed25519';
	}

	public static function knownHostsPath(): string {
		return self::sshDir() . '/known_hosts';
	}

	public static function hasKey(): bool {
		return is_file( self::keyPath() ) && is_readable( self::keyPath() );
	}

	public static function publicKey(): string {
		$path = self::keyPath() . '.pub';

		return is_file( $path ) ? trim( (string) file_get_contents( $path ) ) : '';
	}

	/**
	 * Ist das Arbeitsverzeichnis beschreibbar?
	 *
	 * Legt nichts an — Nachsehen darf nichts verändern.
	 */
	public static function workDirWritable(): bool {
		$dir = self::workDir();

		while ( ! is_dir( $dir ) ) {
			$parent = dirname( $dir );

			if ( $parent === $dir ) {
				return false;
			}

			$dir = $parent;
		}

		return is_writable( $dir );
	}

	/**
	 * Legt Arbeits- und Schlüsselverzeichnis an.
	 *
	 * @return string|null Fehlermeldung oder null.
	 */
	public static function prepareDirectories(): ?string {
		foreach ( array( self::workDir(), self::sshDir(), self::workDir() . '/cache' ) as $dir ) {
			if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
				return 'Verzeichnis nicht anlegbar: ' . $dir;
			}
		}

		return null;
	}

	/**
	 * Schreibt die ssh-Konfiguration, die restic beim Verbinden liest.
	 *
	 * restic ruft intern `ssh <wirt> -s sftp` auf. Port, Benutzer, Schlüssel
	 * und known_hosts kommen deshalb am zuverlässigsten aus einer eigenen
	 * ssh-Konfiguration — das ist auch der Weg, den restic selbst empfiehlt.
	 * Geschrieben wird sie vor jedem Lauf, damit sie nie von den Einstellungen
	 * abweicht.
	 */
	public static function writeSshConfig(): ?string {
		$sftp = self::parseSftp( self::repository() );

		if ( null === $sftp ) {
			return null;
		}

		$error = self::prepareDirectories();

		if ( null !== $error ) {
			return $error;
		}

		$lines = array(
			'# Von NorthLab erzeugt. Aenderungen werden ueberschrieben.',
			'Host ' . $sftp['host'],
			'    HostName ' . $sftp['host'],
			'    User ' . $sftp['user'],
			'    Port ' . $sftp['port'],
			'    BatchMode yes',
			// Der Wirt muss bekannt sein. Sonst koennte sich jemand
			// dazwischensetzen und die Sicherung mitlesen.
			'    StrictHostKeyChecking yes',
			'    UserKnownHostsFile ' . self::knownHostsPath(),
			'    ServerAliveInterval 30',
		);

		if ( self::hasKey() ) {
			$lines[] = '    IdentityFile ' . self::keyPath();
			// Sonst probiert ssh zuerst alle Schluessel des Benutzers durch und
			// die Storage Box trennt nach zu vielen Fehlversuchen.
			$lines[] = '    IdentitiesOnly yes';
		}

		$path = self::sshDir() . '/config';

		if ( false === file_put_contents( $path, implode( "\n", $lines ) . "\n", LOCK_EX ) ) {
			return 'ssh-Konfiguration nicht schreibbar: ' . $path;
		}

		// ssh weigert sich, eine Konfiguration zu lesen, die andere aendern koennen.
		@chmod( $path, 0600 );

		return null;
	}

	/**
	 * Zerlegt eine sftp-Repository-Adresse.
	 *
	 * @return array{user:string,host:string,port:int}|null
	 */
	public static function parseSftp( string $repository ): ?array {
		if ( ! str_starts_with( $repository, 'sftp:' ) ) {
			return null;
		}

		$rest = substr( $repository, 5 );
		$port = 22;

		// Zwei Schreibweisen: sftp://benutzer@wirt:port/pfad und sftp:benutzer@wirt:/pfad
		if ( str_starts_with( $rest, '//' ) ) {
			$rest      = substr( $rest, 2 );
			$authority = explode( '/', $rest, 2 )[0];
		} else {
			$authority = explode( ':', $rest, 2 )[0];
		}

		if ( ! str_contains( $authority, '@' ) ) {
			return null;
		}

		[ $user, $host ] = explode( '@', $authority, 2 );

		if ( str_contains( $host, ':' ) ) {
			[ $host, $rawPort ] = explode( ':', $host, 2 );
			$port               = (int) $rawPort;
		}

		if ( '' === $user || '' === $host ) {
			return null;
		}

		return array( 'user' => $user, 'host' => $host, 'port' => $port > 0 ? $port : 22 );
	}

	/* ------------------------------------------------------- Wirtsschlüssel */

	/**
	 * Holt die Wirtsschlüssel des Ziels, ohne sie zu übernehmen.
	 *
	 * @return array{ok:bool,error:string,keys:array<int,array{type:string,line:string,fingerprint:string}>}
	 */
	public static function scanHostKey(): array {
		$sftp = self::parseSftp( self::repository() );

		if ( null === $sftp ) {
			return array( 'ok' => false, 'error' => 'Das Ziel ist keine sftp-Adresse.', 'keys' => array() );
		}

		$binary = trim( (string) shell_exec( 'command -v ssh-keyscan 2>/dev/null' ) );

		if ( '' === $binary ) {
			return array( 'ok' => false, 'error' => 'ssh-keyscan fehlt (Paket openssh-client).', 'keys' => array() );
		}

		$command = sprintf(
			'timeout 20 %s -p %d %s 2>/dev/null',
			escapeshellcmd( $binary ),
			$sftp['port'],
			escapeshellarg( $sftp['host'] )
		);

		$output = (string) shell_exec( $command );
		$keys   = array();

		foreach ( explode( "\n", $output ) as $line ) {
			$line = trim( $line );

			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}

			$parts = preg_split( '/\s+/', $line );

			if ( ! is_array( $parts ) || count( $parts ) < 3 ) {
				continue;
			}

			$keys[] = array(
				'type'        => $parts[1],
				'line'        => $line,
				'fingerprint' => self::fingerprint( $parts[2] ),
			);
		}

		if ( ! $keys ) {
			return array(
				'ok'    => false,
				'error' => sprintf( 'Keine Antwort von %s auf Port %d.', $sftp['host'], $sftp['port'] ),
				'keys'  => array(),
			);
		}

		return array( 'ok' => true, 'error' => '', 'keys' => $keys );
	}

	/**
	 * Fingerabdruck wie ihn OpenSSH anzeigt.
	 */
	public static function fingerprint( string $base64Blob ): string {
		$raw = base64_decode( $base64Blob, true );

		if ( false === $raw ) {
			return '';
		}

		return 'SHA256:' . rtrim( base64_encode( hash( 'sha256', $raw, true ) ), '=' );
	}

	/**
	 * Übernimmt geprüfte Wirtsschlüssel in known_hosts.
	 *
	 * @param array<int,string> $lines
	 */
	public static function trustHostKeys( array $lines ): ?string {
		$error = self::prepareDirectories();

		if ( null !== $error ) {
			return $error;
		}

		$sftp = self::parseSftp( self::repository() );

		if ( null === $sftp ) {
			return 'Das Ziel ist keine sftp-Adresse.';
		}

		$existing = is_file( self::knownHostsPath() )
			? (string) file_get_contents( self::knownHostsPath() )
			: '';

		// Alte Eintraege desselben Wirts weichen den neuen, sonst stehen zwei
		// widersprechende Schluessel da und ssh nimmt den falschen.
		$kept = array();

		foreach ( explode( "\n", $existing ) as $line ) {
			$line = trim( $line );

			if ( '' === $line || self::matchesHost( $line, $sftp['host'], $sftp['port'] ) ) {
				continue;
			}

			$kept[] = $line;
		}

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( '' !== $line ) {
				$kept[] = $line;
			}
		}

		if ( false === file_put_contents( self::knownHostsPath(), implode( "\n", $kept ) . "\n", LOCK_EX ) ) {
			return 'known_hosts nicht schreibbar.';
		}

		@chmod( self::knownHostsPath(), 0600 );

		return null;
	}

	/**
	 * Steht der Wirt des Ziels schon in known_hosts?
	 */
	public static function hostKnown(): bool {
		$sftp = self::parseSftp( self::repository() );

		if ( null === $sftp || ! is_file( self::knownHostsPath() ) ) {
			return false;
		}

		foreach ( explode( "\n", (string) file_get_contents( self::knownHostsPath() ) ) as $line ) {
			if ( self::matchesHost( trim( $line ), $sftp['host'], $sftp['port'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Betrifft die known_hosts-Zeile diesen Wirt?
	 *
	 * Bei einem abweichenden Port schreibt OpenSSH [wirt]:port.
	 */
	private static function matchesHost( string $line, string $host, int $port ): bool {
		if ( '' === $line || str_starts_with( $line, '#' ) ) {
			return false;
		}

		$first = preg_split( '/\s+/', $line )[0] ?? '';
		$needle = 22 === $port ? $host : sprintf( '[%s]:%d', $host, $port );

		foreach ( explode( ',', $first ) as $entry ) {
			if ( $entry === $needle ) {
				return true;
			}
		}

		return false;
	}

	/* ------------------------------------------------------ Eigener Schlüssel */

	/**
	 * Erzeugt den SSH-Schlüssel, mit dem sich das Panel am Speicher anmeldet.
	 *
	 * @return array{ok:bool,error:string,public:string}
	 */
	public static function generateKey(): array {
		$binary = trim( (string) shell_exec( 'command -v ssh-keygen 2>/dev/null' ) );

		if ( '' === $binary ) {
			return array( 'ok' => false, 'error' => 'ssh-keygen fehlt (Paket openssh-client).', 'public' => '' );
		}

		$error = self::prepareDirectories();

		if ( null !== $error ) {
			return array( 'ok' => false, 'error' => $error, 'public' => '' );
		}

		$path = self::sshDir() . '/id_ed25519';

		foreach ( array( $path, $path . '.pub' ) as $old ) {
			if ( is_file( $old ) ) {
				unlink( $old );
			}
		}

		$command = sprintf(
			'%s -t ed25519 -N %s -C %s -f %s 2>&1',
			escapeshellcmd( $binary ),
			escapeshellarg( '' ),
			escapeshellarg( 'northlab-panel' ),
			escapeshellarg( $path )
		);

		$output = (string) shell_exec( $command );

		if ( ! is_file( $path . '.pub' ) ) {
			return array( 'ok' => false, 'error' => trim( $output ) ?: 'Schlüssel nicht erzeugt.', 'public' => '' );
		}

		@chmod( $path, 0600 );
		@chmod( $path . '.pub', 0644 );

		// Einen eigens erzeugten Schluessel traegt das Panel selbst ein.
		Setting::set( 'restic_ssh_key', $path );

		return array( 'ok' => true, 'error' => '', 'public' => self::publicKey() );
	}

	/* ------------------------------------------------------------ Befehle */

	/**
	 * Legt das Repository an, falls es noch keins gibt.
	 *
	 * @return array{ok:bool,output:string}
	 */
	public static function initRepository(): array {
		$check = self::run( array( 'cat', 'config' ), true, 120 );

		if ( $check['ok'] ) {
			return array( 'ok' => true, 'output' => 'Repository besteht bereits.' );
		}

		$init = self::run( array( 'init' ), true, 300 );

		if ( ! $init['ok'] ) {
			// Die Meldung des ersten Versuchs ist oft die aussagekraeftigere.
			$init['output'] = trim( $init['output'] ) . "\n" . trim( $check['output'] );
		}

		return $init;
	}

	/**
	 * Sichert ein Verzeichnis unter einem Namen.
	 *
	 * @param array<int,string> $tags
	 * @return array{ok:bool,output:string,snapshot:string}
	 */
	public static function backup( string $path, string $host, array $tags = array() ): array {
		$args = array( 'backup', $path, '--host', $host, '--json' );

		foreach ( $tags as $tag ) {
			$args[] = '--tag';
			$args[] = $tag;
		}

		$result   = self::run( $args, true, 7200 );
		$snapshot = '';

		// restic meldet im JSON-Modus zeilenweise; die letzte Zeile trägt die Zusammenfassung.
		foreach ( array_reverse( explode( "\n", $result['output'] ) ) as $line ) {
			$decoded = json_decode( trim( $line ), true );

			if ( is_array( $decoded ) && ( $decoded['message_type'] ?? '' ) === 'summary' ) {
				$snapshot = (string) ( $decoded['snapshot_id'] ?? '' );
				break;
			}
		}

		return array( 'ok' => $result['ok'], 'output' => $result['output'], 'snapshot' => $snapshot );
	}

	/**
	 * Alte Sicherungspunkte nach den eingestellten Regeln aufräumen.
	 *
	 * @return array{ok:bool,output:string}
	 */
	public static function forget( string $host ): array {
		return self::run(
			array(
				'forget',
				'--host', $host,
				'--keep-daily', (string) max( 1, Setting::getInt( 'backup_keep_daily', 7 ) ),
				'--keep-weekly', (string) max( 0, Setting::getInt( 'backup_keep_weekly', 4 ) ),
				'--keep-monthly', (string) max( 0, Setting::getInt( 'backup_keep_monthly', 6 ) ),
				'--prune',
			),
			true,
			3600
		);
	}

	/**
	 * Sicherungspunkte einer Seite.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function snapshots( string $host = '' ): array {
		$args = array( 'snapshots', '--json' );

		if ( '' !== $host ) {
			$args[] = '--host';
			$args[] = $host;
		}

		$result = self::run( $args, true, 120 );

		if ( ! $result['ok'] ) {
			return array();
		}

		$decoded = json_decode( $result['output'], true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Belegung des Repositories.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function stats(): ?array {
		$result = self::run( array( 'stats', '--mode', 'raw-data', '--json' ), true, 600 );

		if ( ! $result['ok'] ) {
			return null;
		}

		$decoded = json_decode( $result['output'], true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Führt restic aus.
	 *
	 * @param array<int,string> $args
	 * @return array{ok:bool,output:string,code:int}
	 */
	public static function run( array $args, bool $withRepository = true, int $timeout = 300 ): array {
		$binary = self::binary();

		if ( '' === $binary ) {
			return array( 'ok' => false, 'output' => 'restic ist auf diesem Server nicht installiert.', 'code' => 127 );
		}

		if ( $withRepository ) {
			$prepared = self::prepareDirectories() ?? self::writeSshConfig();

			if ( null !== $prepared ) {
				return array( 'ok' => false, 'output' => $prepared, 'code' => 1 );
			}
		}

		$command = escapeshellcmd( $binary );

		foreach ( $args as $arg ) {
			$command .= ' ' . escapeshellarg( $arg );
		}

		$descriptors = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = proc_open(
			'timeout ' . (int) $timeout . ' ' . $command . ' 2>&1',
			$descriptors,
			$pipes,
			null,
			self::environment( $withRepository )
		);

		if ( ! is_resource( $process ) ) {
			return array( 'ok' => false, 'output' => 'restic konnte nicht gestartet werden.', 'code' => 1 );
		}

		$output = (string) stream_get_contents( $pipes[1] );

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$code = proc_close( $process );

		if ( 124 === $code ) {
			$output .= "\nAbgebrochen: das Zeitlimit von " . $timeout . ' Sekunden war erreicht.';
		}

		if ( 0 !== $code ) {
			Logger::error(
				'restic ' . ( $args[0] ?? '' ) . ' fehlgeschlagen',
				array( 'code' => $code, 'output' => substr( $output, -1500 ) )
			);
		}

		return array( 'ok' => 0 === $code, 'output' => $output, 'code' => $code );
	}

	/**
	 * Umgebung für den restic-Aufruf.
	 *
	 * Bewusst eine feste Liste statt der Umgebung des Webservers: was restic
	 * sieht, steht hier und nirgendwo sonst.
	 *
	 * @return array<string,string>
	 */
	private static function environment( bool $withRepository ): array {
		$env = array(
			'HOME'             => self::workDir(),
			'PATH'             => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
			'RESTIC_CACHE_DIR' => self::workDir() . '/cache',
			// Fortschrittszeilen blaehen nur das Protokoll auf.
			'RESTIC_PROGRESS_FPS' => '0',
		);

		if ( ! $withRepository ) {
			return $env;
		}

		$env['RESTIC_REPOSITORY'] = self::repository();
		$env['RESTIC_PASSWORD']   = self::password();

		if ( 's3' === self::type() ) {
			$env['AWS_ACCESS_KEY_ID']     = Setting::get( 's3_access_key', '' );
			$env['AWS_SECRET_ACCESS_KEY'] = self::s3Secret();
			$env['AWS_DEFAULT_REGION']    = Setting::get( 's3_region', 'eu-central-1' );
		}

		return $env;
	}

	/* ------------------------------------------------------------- Prüfung */

	/**
	 * Was fehlt noch, damit eine Sicherung laufen kann?
	 *
	 * @return array<int,array{label:string,state:string,detail:string}>
	 */
	public static function diagnose( bool $withNetwork = false ): array {
		$checks = array();
		$add    = static function ( string $label, string $state, string $detail ) use ( &$checks ): void {
			$checks[] = array( 'label' => $label, 'state' => $state, 'detail' => $detail );
		};

		$version = self::available() ? self::version() : null;

		$add(
			'restic installiert',
			null !== $version ? 'ok' : 'bad',
			$version ?? 'Nicht gefunden. Auf dem Panel-Server: apt install restic'
		);

		$writable = self::workDirWritable();

		$add(
			'Arbeitsverzeichnis beschreibbar',
			$writable ? 'ok' : 'bad',
			$writable ? self::workDir() : 'Nicht beschreibbar: ' . self::workDir()
		);

		$repository = self::repository();

		$add(
			'Ziel eingetragen',
			'' !== $repository ? 'ok' : 'bad',
			'' !== $repository ? self::mask( $repository ) : 'Noch keine Adresse gesetzt.'
		);

		// Ein hinterlegtes, aber nicht mehr entschluesselbares Passwort ist der
		// verwirrendste Fall: gesetzt sieht es aus, brauchbar ist es nicht.
		$lesbar = '' !== self::password();

		$add(
			'Repository-Passwort gesetzt',
			self::hasPassword() && $lesbar ? 'ok' : 'bad',
			! self::hasPassword()
				? 'Ohne Passwort kann restic nichts schreiben.'
				: ( $lesbar
					? 'Verschlüsselt hinterlegt.'
					: 'Hinterlegt, aber nicht mehr lesbar — wurde app.key getauscht? Dann hier neu eintragen.' )
		);

		$type = self::type();

		if ( 's3' === $type ) {
			$hasKeys = '' !== Setting::get( 's3_access_key', '' ) && '' !== self::s3Secret();

			$add(
				'S3-Zugangsdaten gesetzt',
				$hasKeys ? 'ok' : 'bad',
				$hasKeys ? 'Schlüssel und Geheimnis hinterlegt.' : 'Access Key und Secret fehlen.'
			);
		}

		$sftp = self::parseSftp( $repository );

		if ( null !== $sftp ) {
			$ssh = self::sshBinary();

			$add(
				'openssh-client vorhanden',
				'' !== $ssh ? 'ok' : 'bad',
				'' !== $ssh ? $ssh : 'Fehlt. Auf dem Panel-Server: apt install openssh-client'
			);

			$add(
				'SSH-Schlüssel vorhanden',
				self::hasKey() ? 'ok' : 'bad',
				self::hasKey() ? self::keyPath() : 'Noch kein Schlüssel erzeugt.'
			);

			$add(
				'Wirtsschlüssel bekannt',
				self::hostKnown() ? 'ok' : 'bad',
				self::hostKnown()
					? $sftp['host'] . ' steht in known_hosts.'
					: 'Ohne Eintrag bricht ssh die Verbindung wortlos ab.'
			);

			// Nur auf Wunsch: eine tote Adresse liesse sonst jeden Seitenaufruf
			// in den Zeitablauf laufen.
			if ( $withNetwork ) {
				$reachable = self::portOpen( $sftp['host'], $sftp['port'] );

				$add(
					sprintf( 'Port %d erreichbar', $sftp['port'] ),
					$reachable ? 'ok' : 'bad',
					$reachable
						? $sftp['host'] . ' antwortet.'
						: sprintf( 'Keine Verbindung zu %s:%d — Firewall oder falscher Port.', $sftp['host'], $sftp['port'] )
				);
			}
		}

		return $checks;
	}

	/**
	 * Nimmt der Wirt auf diesem Port Verbindungen an?
	 */
	public static function portOpen( string $host, int $port, int $timeout = 6 ): bool {
		$handle = @fsockopen( $host, $port, $code, $message, $timeout );

		if ( ! is_resource( $handle ) ) {
			return false;
		}

		fclose( $handle );

		return true;
	}

	/**
	 * Adresse ohne Passwortanteil, für die Anzeige.
	 */
	public static function mask( string $repository ): string {
		return (string) preg_replace( '/:[^:@\/]*@/', ':•••@', $repository );
	}
}
