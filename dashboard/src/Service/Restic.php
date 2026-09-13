<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Logger;
use NorthLab\Core\Setting;

/**
 * Dünne Hülle um das restic-Kommando.
 *
 * restic übernimmt Deduplizierung, Verschlüsselung und Aufbewahrung. Ohne das
 * wäre eine nächtliche Vollsicherung von einem Dutzend WordPress-Seiten weder
 * bezahlbar noch in einer Nacht fertig.
 */
final class Restic {

	/**
	 * Ist restic vorhanden und einsatzbereit?
	 */
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
		$result = self::run( array( 'version' ), false );

		if ( ! $result['ok'] ) {
			return null;
		}

		return trim( explode( "\n", $result['output'] )[0] ?? '' );
	}

	public static function configured(): bool {
		return '' !== trim( Setting::get( 'restic_repository', '' ) )
			&& '' !== trim( Setting::get( 'restic_password', '' ) );
	}

	/**
	 * Legt das Repository an, falls es noch keins gibt.
	 *
	 * @return array{ok:bool,output:string}
	 */
	public static function initRepository(): array {
		$check = self::run( array( 'cat', 'config' ) );

		if ( $check['ok'] ) {
			return array( 'ok' => true, 'output' => 'Repository besteht bereits.' );
		}

		return self::run( array( 'init' ) );
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

		$result = self::run( $args );

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

		$command = escapeshellcmd( $binary );

		foreach ( $args as $arg ) {
			$command .= ' ' . escapeshellarg( $arg );
		}

		$env = array(
			'HOME' => Setting::get( 'restic_home', '/root' ),
			'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
		);

		if ( $withRepository ) {
			$env['RESTIC_REPOSITORY'] = Setting::get( 'restic_repository', '' );
			$env['RESTIC_PASSWORD']   = Setting::get( 'restic_password', '' );

			$sshKey = trim( Setting::get( 'restic_ssh_key', '' ) );

			if ( '' !== $sshKey ) {
				// StrictHostKeyChecking bleibt an; der Wirt muss einmalig in
				// known_hosts stehen, sonst wäre die Verbindung angreifbar.
				$env['RESTIC_PROGRESS_FPS'] = '0';
				$command                    = 'RESTIC_SFTP_COMMAND=' . escapeshellarg(
					'ssh -i ' . $sshKey . ' -o BatchMode=yes -s sftp'
				) . ' ' . $command;
			}
		}

		$descriptors = array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		$process = proc_open( 'timeout ' . (int) $timeout . ' ' . $command . ' 2>&1', $descriptors, $pipes, null, $env );

		if ( ! is_resource( $process ) ) {
			return array( 'ok' => false, 'output' => 'restic konnte nicht gestartet werden.', 'code' => 1 );
		}

		$output = (string) stream_get_contents( $pipes[1] );

		fclose( $pipes[1] );
		fclose( $pipes[2] );

		$code = proc_close( $process );

		if ( 0 !== $code ) {
			Logger::error( 'restic ' . ( $args[0] ?? '' ) . ' fehlgeschlagen', array( 'code' => $code, 'output' => substr( $output, -1500 ) ) );
		}

		return array( 'ok' => 0 === $code, 'output' => $output, 'code' => $code );
	}
}
