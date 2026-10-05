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
		if ( ! SiteRepository::isManaged( $site ) ) {
			return self::fail( $siteId, 'Diese Seite wird nur überwacht — ohne Child-Plugin gibt es nichts zu sichern.' );
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

		$begonnen = microtime( true );

		// Der Kundenseite sagen, dass es losgeht — sie blendet dann ihren
		// Hinweis ein. Mit Ablaufzeit, nicht als Dauerzustand: stirbt dieser
		// Prozess, verschwindet der Hinweis von selbst.
		self::$bannerSite = $site;
		self::$bannerAt   = 0;
		self::announce( 'Wird vorbereitet' );

		try {
			$mirror = self::mirrorPath( $siteId );

			if ( ! is_dir( $mirror ) && ! mkdir( $mirror, 0750, true ) && ! is_dir( $mirror ) ) {
				throw new \RuntimeException( 'Spiegelverzeichnis nicht anlegbar: ' . $mirror );
			}

			self::progress( $runId, 'Datenbank wird exportiert', 0, 0, true );
			$stats['db_bytes'] = self::pullDatabase( $site, $mirror );

			self::progress( $runId, 'Dateiliste wird geholt', 0, 0, true );
			$fileStats = self::pullFiles( $site, $mirror, $runId );

			$stats = array_merge( $stats, $fileStats );

			// Zwischenstände auf der Kundenseite wieder entfernen.
			self::progress( $runId, 'Kundenseite aufräumen', 0, 0, true );
			ChildClient::post( $site, '/backup', array( 'action' => 'cleanup' ), 60 );

			self::progress( $runId, 'Übertragung zum Speicher', 0, 0, true );

			$backup = Restic::backup(
				$mirror,
				self::hostFor( $site ),
				array( 'northlab', 'site-' . $siteId )
			);

			if ( ! $backup['ok'] ) {
				throw new \RuntimeException( self::resticFehler( $backup['output'] ) );
			}

			// Nur die Sicherungspunkte ausbuchen. Das Umschreiben der Pakete
			// kommt einmal am Ende des Laufs — siehe runWindow().
			Restic::forget( self::hostFor( $site ) );

			// Der Spiegel ist eine Arbeitskopie, keine zweite Sicherung. Wer
			// den Platz nicht hat, kann ihn verwerfen — dann holt der naechste
			// Lauf die Seite allerdings wieder vollstaendig.
			if ( ! Setting::getBool( 'backup_keep_mirror', true ) ) {
				self::discardMirror( $siteId );
			}

			Database::update(
				'backups',
				array(
					'status'        => 'success',
					'finished_at'   => nl_utc(),
					'files_total'   => $stats['files_total'],
					'files_changed' => $stats['files_changed'],
					'bytes'         => $stats['bytes'],
					'db_bytes'      => $stats['db_bytes'],
					'phase'         => '',
					'phase_done'    => 0,
					'phase_total'   => 0,
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

			self::reportToSite(
				$site,
				array(
					'status'   => 'ok',
					'bytes'    => $stats['bytes'] + $stats['db_bytes'],
					'files'    => $stats['files_total'],
					'seconds'  => (int) round( microtime( true ) - $begonnen ),
					'snapshot' => (string) $backup['snapshot'],
					'message'  => sprintf( '%d Datei(en) gesichert.', $stats['files_total'] ),
				)
			);

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
					'phase'       => '',
					'message'     => substr( $e->getMessage(), 0, 1000 ),
				),
				array( 'id' => $runId )
			);

			self::reportToSite(
				$site,
				array(
					'status'  => 'failed',
					'seconds' => (int) round( microtime( true ) - $begonnen ),
					'message' => substr( $e->getMessage(), 0, 200 ),
				)
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

	/** Seite, deren Hinweis gerade laufen soll — fuer das Lebenszeichen. */
	private static ?array $bannerSite = null;

	/** Wann zuletzt ein Lebenszeichen rausging. */
	private static int $bannerAt = 0;

	/** Voreingestellter Wortlaut des Hinweises auf der Kundenseite. */
	public const BANNER_TEXT = 'Es läuft gerade eine Sicherung dieser Website. Sie kann kurzzeitig etwas langsamer reagieren.';

	/** So lange gilt eine Ankuendigung ohne neues Lebenszeichen. */
	private const BANNER_LEASE = 900;

	/**
	 * Und so oft wird eines geschickt.
	 *
	 * Deutlich haeufiger als die Frist — und haeufig genug, dass der
	 * Fortschritt auf der Kundenseite sich sichtbar bewegt. Eine Anfrage je
	 * Minute faellt neben dem, was eine Sicherung ohnehin an Verkehr macht,
	 * nicht ins Gewicht.
	 */
	private const BANNER_EVERY = 60;

	/**
	 * Der Kundenseite sagen, dass gerade gesichert wird.
	 *
	 * Mit Frist statt als Dauerzustand: bricht dieser Prozess ab, laeuft die
	 * Ankuendigung von selbst aus. Ein Hinweis, der nur durch ein "fertig"
	 * verschwindet, haengt sonst fuer immer auf der Kundenseite.
	 */
	private static function announce( string $phase = '', int $done = 0, int $total = 0 ): void {
		if ( null === self::$bannerSite || ( time() - self::$bannerAt ) < self::BANNER_EVERY ) {
			return;
		}

		self::$bannerAt = time();

		// Die Einstellungen reisen mit. So gilt auf jeder Kundenseite das, was
		// hier eingestellt ist, ohne einen zweiten Weg, der aus dem Takt
		// geraten kann.
		self::tellSite(
			self::$bannerSite,
			array(
				'action'   => 'running',
				'seconds'  => self::BANNER_LEASE,
				'label'    => 'Sicherung',
				'progress' => array( 'phase' => $phase, 'done' => $done, 'total' => $total ),
				'banner'  => array(
					'enabled'  => Setting::getBool( 'backup_banner', true ),
					'audience' => (string) Setting::get( 'backup_banner_audience', 'loggedin' ),
					'text'     => (string) Setting::get( 'backup_banner_text', self::BANNER_TEXT ),
				),
			)
		);
	}

	/**
	 * Den Lauf auf der Kundenseite vermerken und den Hinweis beenden.
	 *
	 * @param array<string,mixed> $site
	 * @param array<string,mixed> $daten
	 */
	private static function reportToSite( array $site, array $daten ): void {
		self::$bannerSite = null;

		self::tellSite( $site, array( 'action' => 'report', 'data' => $daten ) );
	}

	/**
	 * Eine Nebensache an die Kundenseite schicken.
	 *
	 * Schlaegt das fehl, ist das kein Grund, die Sicherung scheitern zu lassen —
	 * der Hinweis ist Beiwerk, die Sicherung ist die Aufgabe.
	 *
	 * @param array<string,mixed> $site
	 * @param array<string,mixed> $body
	 */
	private static function tellSite( array $site, array $body ): void {
		try {
			ChildClient::post( $site, '/backup', $body, 15 );
		} catch ( Throwable $e ) {
			Logger::exception( $e );
		}
	}

	/**
	 * Woran der Lauf gerade ist — in die Zeile schreiben, die gerade läuft.
	 *
	 * Der Lauf geschieht im Hintergrund; die Oberfläche sieht nur, was hier
	 * landet. Geschrieben wird gedrosselt, sonst erzeugt das Zählen von
	 * Dateien mehr Datenbankverkehr als die Sicherung selbst.
	 */
	private static function progress( int $runId, string $phase, int $done = 0, int $total = 0, bool $force = false ): void {
		static $zuletzt = 0.0;

		$jetzt = microtime( true );

		if ( ! $force && $jetzt - $zuletzt < 2.0 ) {
			return;
		}

		$zuletzt = $jetzt;

		Database::update(
			'backups',
			array(
				'phase'        => $phase,
				'phase_done'   => $done,
				'phase_total'  => $total,
				'heartbeat_at' => nl_utc(),
			),
			array( 'id' => $runId )
		);

		// Lebenszeichen an die Kundenseite, mit demselben Stand, der gerade in
		// die Datenbank ging. announce() drosselt selbst; eine lange
		// Dateiuebertragung soll den Hinweis nicht mittendrin auslaufen lassen,
		// und der Kunde soll sehen, wie weit es ist.
		self::announce( $phase, $done, $total );
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
	private static function pullFiles( array $site, string $mirror, int $runId = 0 ): array {
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

				if ( $runId > 0 ) {
					self::progress( $runId, 'Dateien werden geholt', $total, (int) ( $response['data']['total'] ?? 0 ) );
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
		$ok      = 0;
		$failed  = 0;
		$skipped = 0;

		foreach ( SiteRepository::active() as $site ) {
			// Wer eine Seite bewusst aus dem Zeitplan genommen hat, will sie
			// nachts nicht sehen — von Hand laesst sie sich weiter sichern.
			if ( ! self::scheduled( $site ) ) {
				$skipped++;
				continue;
			}

			$result = self::run( $site );

			if ( $result['ok'] ) {
				$ok++;
			} else {
				$failed++;
			}
		}

		// Das Panel zuletzt: dann steht in seiner Datenbank schon, wie der Lauf
		// fuer die Seiten ausgegangen ist.
		if ( Setting::getBool( 'backup_panel', true ) ) {
			$panel = self::runPanel();

			if ( $panel['ok'] ) {
				$ok++;
			} else {
				$failed++;
			}
		}

		if ( $ok + $failed > 0 ) {
			ActivityRepository::log(
				'backup.batch',
				sprintf(
					'Sicherungslauf: %d erfolgreich, %d fehlgeschlagen%s.',
					$ok,
					$failed,
					$skipped > 0 ? sprintf( ', %d nicht im Zeitplan', $skipped ) : ''
				),
				array( 'level' => $failed > 0 ? 'warning' : 'info' )
			);
		}

		return array( 'ok' => $ok, 'failed' => $failed, 'skipped' => $skipped );
	}

	/**
	 * Ein Durchgang im nächtlichen Zeitfenster.
	 *
	 * Statt alle Seiten in einem Rutsch zu sichern, kommt je Aufruf genau eine
	 * dran — mit dem eingestellten Abstand dazwischen. Ein Dutzend Seiten
	 * gleichzeitig zu ziehen bringt weder den Panel-Server noch die
	 * Kundenseiten in einen guten Zustand.
	 *
	 * Der Zeitplaner ruft das alle paar Minuten auf; die Methode entscheidet
	 * selbst, ob gerade etwas zu tun ist.
	 */
	public static function runWindow(): string {
		if ( ! Setting::getBool( 'backup_enabled', false ) ) {
			return 'deaktiviert';
		}

		// Von Hand angestossene Sicherungen zuerst, und ohne auf den Abstand zu
		// warten — wer den Knopf drueckt, wartet nicht gern eine halbe Stunde.
		$verlangt = self::requested();

		if ( null !== $verlangt ) {
			SiteRepository::update( (int) $verlangt['id'], array( 'backup_requested_at' => null ) );

			$result = self::run( $verlangt );

			return sprintf(
				'%s (von Hand): %s',
				$verlangt['name'],
				$result['ok'] ? 'gesichert' : 'fehlgeschlagen'
			);
		}

		if ( Setting::getBool( 'panel_backup_requested', false ) ) {
			Setting::set( 'panel_backup_requested', '0' );

			$panel = self::runPanel();

			return $panel['ok'] ? 'Panel gesichert (von Hand)' : 'Panel fehlgeschlagen: ' . $panel['error'];
		}

		$start   = self::windowStart();
		$abstand = self::spacing();

		// Ohne Abstand das alte Verhalten: alles hintereinander in einem Lauf.
		if ( 0 === $abstand ) {
			$stats = self::runAll();

			return sprintf( '%d gesichert, %d fehlgeschlagen', $stats['ok'], $stats['failed'] );
		}

		$erledigt = self::handledSince( $start );
		$offen    = array();

		foreach ( SiteRepository::active() as $site ) {
			if ( self::scheduled( $site ) && ! isset( $erledigt[ (int) $site['id'] ] ) ) {
				$offen[] = $site;
			}
		}

		if ( ! $offen ) {
			// Die Seiten sind durch — dann noch das Panel selbst.
			if ( Setting::getBool( 'backup_panel', true ) && ! self::panelHandledSince( $start ) ) {
				$panel = self::runPanel();

				return $panel['ok'] ? 'Panel gesichert' : 'Panel fehlgeschlagen: ' . $panel['error'];
			}

			return self::pruneOnce( $start );
		}

		$wartet = self::waitFor( $start, $abstand );

		if ( $wartet > 0 ) {
			return sprintf( 'Abstand: noch %d Minute(n), %d Seite(n) offen', (int) ceil( $wartet / 60 ), count( $offen ) );
		}

		$site   = $offen[0];
		$result = self::run( $site );

		return sprintf(
			'%s: %s (%d weitere offen)',
			$site['name'],
			$result['ok'] ? 'gesichert' : 'fehlgeschlagen',
			count( $offen ) - 1
		);
	}

	/**
	 * Stand des Sicherungslaufs, wie ihn die Oberfläche anzeigt.
	 *
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		$laufend = Database::selectOne(
			'SELECT b.*, s.name AS site_name FROM `' . Database::table( 'backups' ) . '` b
			 INNER JOIN `' . Database::table( 'sites' ) . "` s ON s.id = b.site_id
			 WHERE b.status = 'running' ORDER BY b.id DESC LIMIT 1"
		);

		// Ein Lauf, von dem seit zehn Minuten kein Lebenszeichen kam, laeuft
		// nicht mehr — der Prozess ist gestorben, ohne die Zeile zu schliessen.
		if ( null !== $laufend ) {
			$puls = $laufend['heartbeat_at'] ?? $laufend['started_at'];

			if ( time() - (int) strtotime( (string) $puls . ' UTC' ) > 600 ) {
				$laufend['phase'] = 'ohne Lebenszeichen';
			}
		}

		$start    = self::windowStart();
		$erledigt = self::handledSince( $start );
		$offen    = array();

		foreach ( SiteRepository::active() as $site ) {
			if ( ! self::scheduled( $site ) || isset( $erledigt[ (int) $site['id'] ] ) ) {
				continue;
			}
			if ( null !== $laufend && (int) $laufend['site_id'] === (int) $site['id'] ) {
				continue;
			}

			$offen[] = array(
				'id'         => (int) $site['id'],
				'name'       => (string) $site['name'],
				'vorgemerkt' => self::isRequested( $site ),
			);
		}

		return array(
			'enabled'   => Setting::getBool( 'backup_enabled', false ),
			'cron'      => Scheduler::isCronHealthy(),
			'laufend'   => null === $laufend ? null : array(
				'site'    => (string) $laufend['site_name'],
				'seit'    => (string) $laufend['started_at'],
				'phase'   => (string) ( $laufend['phase'] ?? '' ),
				'done'    => (int) ( $laufend['phase_done'] ?? 0 ),
				'total'   => (int) ( $laufend['phase_total'] ?? 0 ),
			),
			'offen'     => $offen,
			'wartet'    => self::waitFor( $start, self::spacing() ),
			'abstand'   => (int) ( self::spacing() / 60 ),
			'fenster'   => $start,
		);
	}

	/**
	 * Die am längsten wartende, von Hand angestossene Sicherung.
	 *
	 * @return array<string,mixed>|null
	 */
	private static function requested(): ?array {
		return Database::selectOne(
			'SELECT * FROM `' . Database::table( 'sites' ) . '`
			 WHERE `backup_requested_at` IS NOT NULL
			 ORDER BY `backup_requested_at` ASC LIMIT 1'
		);
	}

	/**
	 * Eine Sicherung vormerken, statt sie im Webaufruf durchzuziehen.
	 *
	 * Eine Sicherung dauert Minuten bis Stunden. Lief sie im Aufruf, wartete
	 * der Browser die ganze Zeit, und ein PHP-Arbeiter war so lange belegt —
	 * zwei davon genügen auf einem kleinen Server, um das Panel lahmzulegen.
	 *
	 * @return array{ok:bool,error:string}
	 */
	public static function request( int $siteId ): array {
		$site = SiteRepository::find( $siteId );

		if ( null === $site ) {
			return array( 'ok' => false, 'error' => 'Seite nicht gefunden.' );
		}
		if ( ! SiteRepository::isManaged( $site ) ) {
			return array( 'ok' => false, 'error' => 'Diese Seite wird nur überwacht — ohne Child-Plugin gibt es nichts zu sichern.' );
		}
		if ( ! Restic::configured() ) {
			return array( 'ok' => false, 'error' => 'Es ist kein Sicherungsziel eingerichtet.' );
		}
		if ( ! Scheduler::isCronHealthy() ) {
			return array(
				'ok'    => false,
				'error' => 'Der Zeitplaner läuft nicht — eine vorgemerkte Sicherung würde liegen bleiben.',
			);
		}

		SiteRepository::update( $siteId, array( 'backup_requested_at' => nl_utc() ) );

		return array( 'ok' => true, 'error' => '' );
	}

	/**
	 * Wartet diese Seite auf eine von Hand angestossene Sicherung?
	 *
	 * @param array<string,mixed> $site
	 */
	public static function isRequested( array $site ): bool {
		return ! empty( $site['backup_requested_at'] );
	}

	/**
	 * Einmal je Zeitfenster aufräumen.
	 *
	 * Vorher lief das hinter jeder einzelnen Seite. Bei einem Dutzend Seiten
	 * war das ein Dutzend Mal die schwerste Operation, die restic kennt —
	 * genau deshalb war der Server während der Sicherung nicht zu gebrauchen.
	 */
	private static function pruneOnce( string $start ): string {
		$letztes = Setting::get( 'backup_pruned_at', '' );

		if ( '' !== $letztes && $letztes >= $start ) {
			return 'nichts offen';
		}

		$ergebnis = Restic::prune();

		Setting::set( 'backup_pruned_at', nl_utc() );

		if ( ! $ergebnis['ok'] ) {
			Logger::error( 'Aufräumen des Repositories fehlgeschlagen', array( 'output' => substr( $ergebnis['output'], -500 ) ) );

			return 'alles gesichert, Aufräumen fehlgeschlagen';
		}

		return 'alles gesichert und aufgeräumt';
	}

	/**
	 * Abstand zwischen zwei Sicherungen, in Sekunden. 0 heisst: alle auf einmal.
	 */
	public static function spacing(): int {
		return max( 0, min( 240, Setting::getInt( 'backup_spacing', 30 ) ) ) * 60;
	}

	/**
	 * Beginn des laufenden Zeitfensters.
	 *
	 * Das Fenster beginnt zur eingestellten Stunde und laeuft bis zur selben
	 * Stunde am naechsten Tag. Bei vielen Seiten und grossem Abstand reicht
	 * eine Stunde nicht — und ein Fenster, das zumacht, bevor alle durch sind,
	 * liesse die letzten Seiten stillschweigend aus.
	 *
	 * Ein "ausserhalb" gibt es damit nicht: ist alles gesichert, meldet der
	 * Durchgang schlicht, dass nichts offen ist.
	 */
	private static function windowStart(): string {
		$stunde = max( 0, min( 23, Setting::getInt( 'backup_hour', 3 ) ) );
		$jetzt  = time();

		$heute = (int) strtotime( gmdate( 'Y-m-d', $jetzt ) . sprintf( ' %02d:00:00 UTC', $stunde ) );

		// Vor der Stunde gehoert der Zeitpunkt noch zum Fenster von gestern.
		$beginn = $jetzt >= $heute ? $heute : $heute - 86400;

		return gmdate( 'Y-m-d H:i:s', $beginn );
	}

	/**
	 * Welche Seiten wurden in diesem Fenster schon angefasst?
	 *
	 * Auch Fehlversuche zaehlen — sonst blockierte eine Seite, die nicht
	 * erreichbar ist, bei jedem Durchgang alle anderen.
	 *
	 * @return array<int,true>
	 */
	private static function handledSince( string $start ): array {
		$ids = array();

		foreach ( Database::select(
			'SELECT DISTINCT `site_id` FROM `' . Database::table( 'backups' ) . '` WHERE `started_at` >= :s',
			array( 's' => $start )
		) as $row ) {
			$ids[ (int) $row['site_id'] ] = true;
		}

		return $ids;
	}

	private static function panelHandledSince( string $start ): bool {
		$letzte = Setting::get( 'panel_backup_at', '' );

		return '' !== $letzte && $letzte >= $start;
	}

	/**
	 * Wie viele Sekunden sind bis zum nächsten Durchgang noch zu warten?
	 */
	private static function waitFor( string $start, int $abstand ): int {
		$letzte = Database::scalar(
			'SELECT MAX(COALESCE(`finished_at`, `started_at`)) FROM `' . Database::table( 'backups' ) . '`
			 WHERE `started_at` >= :s',
			array( 's' => $start )
		);

		if ( ! is_string( $letzte ) ) {
			// Noch nichts gelaufen in diesem Fenster: sofort loslegen.
			return 0;
		}

		return max( 0, (int) strtotime( $letzte . ' UTC' ) + $abstand - time() );
	}

	/**
	 * Läuft die nächtliche Sicherung wirklich?
	 *
	 * Eine Sicherung, die stillschweigend nicht stattfindet, ist schlimmer als
	 * gar keine — man verlässt sich darauf. Deshalb wird nicht nur geprüft, ob
	 * der Zeitplan eingeschaltet ist, sondern ob tatsächlich etwas passiert.
	 *
	 * @return string|null Warnung oder null, wenn alles seinen Gang geht.
	 */
	public static function scheduleWarning(): ?string {
		if ( ! Setting::getBool( 'backup_enabled', false ) ) {
			return null;
		}

		if ( ! Scheduler::isCronHealthy() ) {
			return 'Der Zeitplaner läuft nicht. Der nächtliche Lauf findet damit nicht statt — '
				. 'auch wenn er hier eingeschaltet ist. Auf dem Panel-Server den Cron-Eintrag prüfen: '
				. 'crontab -u www-data -l';
		}

		$letzte = Database::scalar(
			'SELECT MAX(`started_at`) FROM `' . Database::table( 'backups' ) . "` WHERE `status` = 'success'"
		);

		if ( ! is_string( $letzte ) ) {
			// Noch nie gelaufen ist kein Fehler, solange der Zeitplan jung ist.
			return null;
		}

		$alter = time() - (int) strtotime( $letzte . ' UTC' );

		// 26 Stunden: ein Tag plus Luft fuer einen laenger laufenden Lauf.
		if ( $alter > 93600 ) {
			return sprintf(
				'Die letzte erfolgreiche Sicherung liegt %s zurück, obwohl der Zeitplan an ist. '
				. 'Unten im Verlauf steht, woran der letzte Lauf gescheitert ist.',
				nl_ago( $letzte )
			);
		}

		return null;
	}

	/**
	 * Gehört die Seite in den nächtlichen Lauf?
	 *
	 * @param array<string,mixed> $site
	 */
	public static function scheduled( array $site ): bool {
		// Fehlt die Spalte noch (altes Schema), gilt die Vorgabe: mitsichern.
		return ! array_key_exists( 'backup_enabled', $site ) || (bool) $site['backup_enabled'];
	}

	/**
	 * Sichert das Panel selbst: Datenbank und Konfiguration.
	 *
	 * In der Datenbank stehen die privaten Schlüssel aller Verbindungen, in der
	 * config.php der Schlüssel, mit dem sie verschlüsselt sind. Beides zusammen
	 * ist die Voraussetzung dafür, das Panel nach einem Ausfall wieder
	 * hinzustellen, ohne jede Seite neu zu verbinden.
	 *
	 * @return array{ok:bool,error:string,bytes:int}
	 */
	public static function runPanel(): array {
		if ( ! Restic::configured() ) {
			return array( 'ok' => false, 'error' => 'Es ist kein Sicherungsziel eingerichtet.', 'bytes' => 0 );
		}

		$mirror = self::mirrorBase() . '/_panel';

		try {
			if ( ! is_dir( $mirror ) && ! mkdir( $mirror, 0750, true ) && ! is_dir( $mirror ) ) {
				throw new \RuntimeException( 'Spiegelverzeichnis nicht anlegbar: ' . $mirror );
			}

			$bytes = PanelBackup::dump( $mirror . '/datenbank.sql.gz' );

			// Ohne die config.php liesse sich die Datenbank zwar einspielen, aber
			// nichts darin entschluesseln.
			$config = NL_ROOT . '/config.php';

			if ( is_file( $config ) ) {
				copy( $config, $mirror . '/config.php' );
			}

			$backup = Restic::backup( $mirror, 'panel', array( 'northlab', 'panel' ) );

			if ( ! $backup['ok'] ) {
				throw new \RuntimeException( self::resticFehler( $backup['output'] ) );
			}

			Restic::forget( 'panel' );

			Setting::setMany(
				array(
					'panel_backup_at'      => nl_utc(),
					'panel_backup_status'  => 'success',
					'panel_backup_message' => sprintf( 'Datenbank %s gesichert.', size_format_de( $bytes ) ),
				)
			);

			return array( 'ok' => true, 'error' => '', 'bytes' => $bytes );

		} catch ( Throwable $e ) {
			Logger::exception( $e );

			Setting::setMany(
				array(
					'panel_backup_at'      => nl_utc(),
					'panel_backup_status'  => 'failed',
					'panel_backup_message' => substr( $e->getMessage(), 0, 500 ),
				)
			);

			EventBus::dispatch(
				'backup.failed',
				array( 'error' => $e->getMessage() ),
				array( 'message' => 'Sicherung des Panels fehlgeschlagen: ' . $e->getMessage() )
			);

			return array( 'ok' => false, 'error' => $e->getMessage(), 'bytes' => 0 );
		}
	}

	/* ----------------------------------------------------------- Werkzeuge */

	/**
	 * Spiegel einer Seite wegräumen.
	 *
	 * Der Datenbank-Export gehört dazu — er liegt im selben Baum.
	 */
	public static function discardMirror( int $siteId ): bool {
		$pfad = self::mirrorPath( $siteId );

		if ( ! is_dir( $pfad ) ) {
			return true;
		}

		$eintraege = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $pfad, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $eintraege as $eintrag ) {
			if ( $eintrag->isDir() ) {
				@rmdir( $eintrag->getPathname() );
			} else {
				@unlink( $eintrag->getPathname() );
			}
		}

		return @rmdir( $pfad );
	}

	public static function mirrorBase(): string {
		$base = trim( Setting::get( 'backup_mirror_dir', '' ) );

		return rtrim( '' === $base ? NL_STORAGE . '/backups' : $base, '/' );
	}

	public static function mirrorPath( int $siteId ): string {
		return self::mirrorBase() . '/site-' . $siteId;
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

	/**
	 * Aus einem restic-Fehlschlag eine Meldung machen, mit der man etwas
	 * anfangen kann. Die rohe Ausgabe bleibt dahinter stehen.
	 */
	private static function resticFehler( string $output ): string {
		$hinweis = Restic::hinweis( $output );

		return ( null === $hinweis ? '' : $hinweis . ' — ' ) . 'restic: ' . self::lastLines( $output );
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
