<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Config;
use NorthLab\Core\Database;
use NorthLab\Core\Logger;
use NorthLab\Core\Setting;
use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\SiteRepository;
use Throwable;

/**
 * Sicherung der Kundenseiten.
 *
 * Ablauf je Seite: Das Panel hält lokal ein Spiegelverzeichnis. Von der
 * Kundenseite kommt eine Dateiliste; geholt wird nur, was neu oder verändert
 * ist. Anschliessend macht restic aus dem Spiegel einen Sicherungspunkt auf
 * dem entfernten Speicher.
 *
 * Der Umweg über den Spiegel ist der Preis dafür, dass die Kundenseiten nur
 * über HTTP erreichbar sind. Dafür überträgt jede Nacht nur das Delta, und
 * restic dedupliziert obendrein.
 */
final class BackupService {

	/** Grösse eines Übertragungsblocks. */
	private const CHUNK_BYTES = 4194304;

	/** Dateien darüber werden stückweise geholt. */
	private const CHUNK_THRESHOLD = 8388608;

	/** Einträge je Abruf der Dateiliste. */
	private const MANIFEST_PAGE = 5000;

	/**
	 * Sichert eine Seite.
	 *
	 * @param array<string,mixed>|int $site
	 * @return array{ok:bool,error:string,stats:array<string,mixed>}
	 */
	public static function run( array|int $site ): array {
		$site = is_array( $site ) ? $site : SiteRepository::find( $site );

		if ( null === $site ) {
			return self::fail( 0, 'Seite nicht gefunden.' );
		}

		$siteId = (int) $site['id'];

		if ( ! empty( $site['is_paused'] ) ) {
			return self::fail( $siteId, 'Die Seite ist pausiert.' );
		}
		if ( ! Restic::configured() ) {
			return self::fail( $siteId, 'Es ist kein Sicherungsziel eingerichtet (Einstellungen → Sicherung).' );
		}
		if ( ! Restic::available() ) {
			return self::fail( $siteId, 'restic ist auf diesem Server nicht installiert.' );
		}

		$runId = Database::insert(
			'backups',
			array(
				'site_id'    => $siteId,
				'status'     => 'running',
				'started_at' => nl_utc(),
			)
		);

		$stats = array(
			'files_total'   => 0,
			'files_changed' => 0,
			'files_removed' => 0,
			'bytes'         => 0,
			'db_bytes'      => 0,
		);

		try {
			$mirror = self::mirrorPath( $siteId );

			if ( ! is_dir( $mirror ) && ! mkdir( $mirror, 0750, true ) && ! is_dir( $mirror ) ) {
				throw new \RuntimeException( 'Spiegelverzeichnis nicht anlegbar: ' . $mirror );
			}

			$stats['db_bytes'] = self::pullDatabase( $site, $mirror );
			$fileStats         = self::pullFiles( $site, $mirror );

			$stats = array_merge( $stats, $fileStats );

			// Zwischenstände auf der Kundenseite wieder entfernen.
			ChildClient::post( $site, '/backup', array( 'action' => 'cleanup' ), 60 );

			$backup = Restic::backup(
				$mirror,
				self::hostFor( $site ),
				array( 'northlab', 'site-' . $siteId )
			);

			if ( ! $backup['ok'] ) {
				throw new \RuntimeException( 'restic: ' . self::lastLines( $backup['output'] ) );
			}

			Restic::forget( self::hostFor( $site ) );

			Database::update(
				'backups',
				array(
					'status'        => 'success',
					'finished_at'   => nl_utc(),
					'files_total'   => $stats['files_total'],
					'files_changed' => $stats['files_changed'],
					'bytes'         => $stats['bytes'],
					'db_bytes'      => $stats['db_bytes'],
					'snapshot_id'   => $backup['snapshot'],
					'message'       => sprintf(
						'%d Datei(en), davon %d neu oder geändert, Datenbank %s.',
						$stats['files_total'],
						$stats['files_changed'],
						size_format_de( $stats['db_bytes'] )
					),
				),
				array( 'id' => $runId )
			);

			SiteRepository::update( $siteId, array( 'last_backup_at' => nl_utc() ) );

			EventBus::dispatch(
				'backup.completed',
				array( 'snapshot' => $backup['snapshot'] ) + $stats,
				array(
					'site_id' => $siteId,
					'message' => sprintf(
						'Sicherung von "%s" abgeschlossen: %d Datei(en) übertragen, Datenbank %s.',
						$site['name'],
						$stats['files_changed'],
						size_format_de( $stats['db_bytes'] )
					),
				)
			);

			return array( 'ok' => true, 'error' => '', 'stats' => $stats );

		} catch ( Throwable $e ) {
			Logger::exception( $e );

			Database::update(
				'backups',
				array(
					'status'      => 'failed',
					'finished_at' => nl_utc(),
					'message'     => substr( $e->getMessage(), 0, 1000 ),
				),
				array( 'id' => $runId )
			);

			EventBus::dispatch(
				'backup.failed',
				array( 'error' => $e->getMessage() ),
				array(
					'site_id' => $siteId,
					'message' => sprintf( 'Sicherung von "%s" fehlgeschlagen: %s', $site['name'], $e->getMessage() ),
				)
			);

			return array( 'ok' => false, 'error' => $e->getMessage(), 'stats' => $stats );
		}
	}

	/**
	 * Datenbank-Export anstossen, in Etappen durchlaufen lassen und abholen.
	 *
	 * @param array<string,mixed> $site
	 * @return int Grösse der Exportdatei.
	 */
	private static function pullDatabase( array $site, string $mirror ): int {
		$start = ChildClient::post( $site, '/backup', array( 'action' => 'db-start' ), 120 );

		if ( ! $start['ok'] ) {
			throw new \RuntimeException( 'Datenbank-Export nicht gestartet: ' . $start['error'] );
		}

		$file  = '';
		$size  = 0;
		$steps = 0;

		// Der Export läuft in Etappen, damit kein einzelner Request ins Zeitlimit läuft.
		while ( $steps < 600 ) {
			$steps++;

			$step = ChildClient::post( $site, '/backup', array( 'action' => 'db-step' ), 120 );

			if ( ! $step['ok'] ) {
				throw new \RuntimeException( 'Datenbank-Export abgebrochen: ' . $step['error'] );
			}
			if ( ! empty( $step['data']['done'] ) ) {
				$file = (string) ( $step['data']['file'] ?? '' );
				$size = (int) ( $step['data']['size'] ?? 0 );
				break;
			}
		}

		if ( '' === $file ) {
			throw new \RuntimeException( 'Datenbank-Export wurde nicht fertig.' );
		}

		$target = $mirror . '/_datenbank/datenbank.sql.gz';

		if ( ! self::fetchFile( $site, 'content', $file, $target, $size ) ) {
			throw new \RuntimeException( 'Datenbank-Export konnte nicht abgeholt werden.' );
		}

		return (int) ( is_file( $target ) ? filesize( $target ) : 0 );
	}

	/**
	 * Dateien abgleichen: holen, was fehlt oder sich geändert hat, und entfernen,
	 * was es auf der Kundenseite nicht mehr gibt.
	 *
	 * @param array<string,mixed> $site
	 * @return array<string,int>
	 */
	private static function pullFiles( array $site, string $mirror ): array {
		$root     = Setting::get( 'backup_root', 'content' );
		$excludes = self::excludes();

		$total   = 0;
		$changed = 0;
		$bytes   = 0;
		$offset  = 0;
		$seen    = array();

		do {
			$response = ChildClient::post(
				$site,
				'/backup',
				array(
					'action'   => 'manifest',
					'root'     => $root,
					'offset'   => $offset,
					'limit'    => self::MANIFEST_PAGE,
					'excludes' => $excludes,
				),
				180
			);

			if ( ! $response['ok'] ) {
				throw new \RuntimeException( 'Dateiliste nicht abrufbar: ' . $response['error'] );
			}

			$files = (array) ( $response['data']['files'] ?? array() );

			foreach ( $files as $entry ) {
				$path  = (string) ( $entry['path'] ?? '' );
				$size  = (int) ( $entry['size'] ?? 0 );
				$mtime = (int) ( $entry['mtime'] ?? 0 );

				if ( '' === $path || ! self::safeRelativePath( $path ) ) {
					continue;
				}

				$total++;
				$seen[ $path ] = true;

				$target = $mirror . '/' . $path;

				// Vergleich über Grösse und Änderungszeit — dasselbe Verfahren wie rsync.
				if ( is_file( $target ) && filesize( $target ) === $size && abs( filemtime( $target ) - $mtime ) < 2 ) {
					continue;
				}

				if ( self::fetchFile( $site, $root, $path, $target, $size ) ) {
					@touch( $target, $mtime );
					$changed++;
					$bytes += $size;
				}
			}

			$offset  += count( $files );
			$complete = ! empty( $response['data']['complete'] ) || ! $files;

		} while ( ! $complete && $offset < 500000 );

		$removed = self::removeVanished( $mirror, $seen );

		return array(
			'files_total'   => $total,
			'files_changed' => $changed,
			'files_removed' => $removed,
			'bytes'         => $bytes,
		);
	}

	/**
	 * Holt eine Datei, bei Bedarf in mehreren Blöcken.
	 *
	 * @param array<string,mixed> $site
	 */
	private static function fetchFile( array $site, string $root, string $path, string $target, int $size ): bool {
		$directory = dirname( $target );

		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0750, true ) && ! is_dir( $directory ) ) {
			return false;
		}

		if ( $size <= self::CHUNK_THRESHOLD ) {
			$result = ChildClient::downloadTo(
				$site,
				'/backup',
				array( 'action' => 'download', 'root' => $root, 'path' => $path ),
				$target,
				600
			);

			return $result['ok'];
		}

		// Grosse Dateien stückweise, damit kein Request ins Zeitlimit läuft.
		$temporary = $target . '.teil';
		@unlink( $temporary );

		$handle = fopen( $temporary, 'wb' );

		if ( ! $handle ) {
			return false;
		}

		for ( $offset = 0; $offset < $size; $offset += self::CHUNK_BYTES ) {
			$chunkFile = $temporary . '.blk';

			$result = ChildClient::downloadTo(
				$site,
				'/backup',
				array(
					'action' => 'download',
					'root'   => $root,
					'path'   => $path,
					'offset' => $offset,
					'length' => self::CHUNK_BYTES,
				),
				$chunkFile,
				600
			);

			if ( ! $result['ok'] ) {
				fclose( $handle );
				@unlink( $temporary );
				@unlink( $chunkFile );
				return false;
			}

			$chunk = fopen( $chunkFile, 'rb' );

			if ( $chunk ) {
				stream_copy_to_stream( $chunk, $handle );
				fclose( $chunk );
			}

			@unlink( $chunkFile );
		}

		fclose( $handle );

		return rename( $temporary, $target );
	}

	/**
	 * Entfernt aus dem Spiegel, was die Kundenseite nicht mehr kennt.
	 *
	 * @param array<string,bool> $seen
	 */
	private static function removeVanished( string $mirror, array $seen ): int {
		if ( ! $seen ) {
			// Ohne Dateiliste lieber nichts löschen.
			return 0;
		}

		$removed = 0;

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $mirror, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			$relative = ltrim( str_replace( '\\', '/', substr( $item->getPathname(), strlen( $mirror ) ) ), '/' );

			// Der Datenbank-Export gehört uns, nicht der Dateiliste.
			if ( str_starts_with( $relative, '_datenbank/' ) ) {
				continue;
			}

			if ( $item->isFile() && ! isset( $seen[ $relative ] ) ) {
				if ( @unlink( $item->getPathname() ) ) {
					$removed++;
				}
			} elseif ( $item->isDir() ) {
				@rmdir( $item->getPathname() );
			}
		}

		return $removed;
	}

	/**
	 * Alle fälligen Seiten sichern (nächtlicher Lauf).
	 *
	 * @return array{ok:int,failed:int}
	 */
	public static function runAll(): array {
		$ok     = 0;
		$failed = 0;

		foreach ( SiteRepository::active() as $site ) {
			$result = self::run( $site );

			if ( $result['ok'] ) {
				$ok++;
			} else {
				$failed++;
			}
		}

		if ( $ok + $failed > 0 ) {
			ActivityRepository::log(
				'backup.batch',
				sprintf( 'Sicherungslauf: %d erfolgreich, %d fehlgeschlagen.', $ok, $failed ),
				array( 'level' => $failed > 0 ? 'warning' : 'info' )
			);
		}

		return array( 'ok' => $ok, 'failed' => $failed );
	}

	/* ----------------------------------------------------------- Werkzeuge */

	public static function mirrorPath( int $siteId ): string {
		$base = trim( Setting::get( 'backup_mirror_dir', '' ) );

		if ( '' === $base ) {
			$base = NL_STORAGE . '/backups';
		}

		return rtrim( $base, '/' ) . '/site-' . $siteId;
	}

	/**
	 * @param array<string,mixed> $site
	 */
	public static function hostFor( array $site ): string {
		$host = (string) ( parse_url( (string) $site['url'], PHP_URL_HOST ) ?: 'site-' . $site['id'] );

		return preg_replace( '/[^a-z0-9.\-]/i', '-', $host ) ?? (string) $site['id'];
	}

	/**
	 * @return array<int,string>
	 */
	public static function excludes(): array {
		$raw = Setting::get( 'backup_excludes', '' );
		$out = array();

		foreach ( preg_split( '/[\r\n]+/', $raw ) ?: array() as $line ) {
			$line = trim( (string) $line );

			if ( '' !== $line ) {
				$out[] = $line;
			}
		}

		return $out;
	}

	/**
	 * Verhindert, dass ein manipulierter Pfad aus dem Spiegel ausbricht.
	 */
	private static function safeRelativePath( string $path ): bool {
		if ( str_contains( $path, "\0" ) || str_starts_with( $path, '/' ) ) {
			return false;
		}

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '..' === $segment ) {
				return false;
			}
		}

		return true;
	}

	private static function lastLines( string $text, int $lines = 4 ): string {
		$parts = array_filter( array_map( 'trim', explode( "\n", $text ) ) );

		return implode( ' | ', array_slice( $parts, -$lines ) );
	}

	/**
	 * @return array{ok:bool,error:string,stats:array<string,mixed>}
	 */
	private static function fail( int $siteId, string $error ): array {
		if ( $siteId > 0 ) {
			ActivityRepository::log( 'backup.failed', $error, array( 'site_id' => $siteId, 'level' => 'error' ) );
		}

		return array( 'ok' => false, 'error' => $error, 'stats' => array() );
	}

	/**
	 * Belegter Platz des Spiegels.
	 */
	public static function mirrorSize( int $siteId ): int {
		$path = self::mirrorPath( $siteId );

		if ( ! is_dir( $path ) ) {
			return 0;
		}

		$bytes = 0;

		try {
			foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
				if ( $file->isFile() ) {
					$bytes += $file->getSize();
				}
			}
		} catch ( Throwable $e ) {
			return 0;
		}

		return $bytes;
	}
}
