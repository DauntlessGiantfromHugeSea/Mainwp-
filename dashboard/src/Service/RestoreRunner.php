<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Database;
use NorthLab\Core\Logger;
use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\SiteRepository;
use Throwable;

/**
 * Das Zurückspielen selbst.
 *
 * Läuft im Zeitplaner, nie im Webaufruf — es dauert Minuten und überschreibt
 * dabei eine laufende Website. Die Reihenfolge ist bewusst so und nicht
 * anders:
 *
 *   1. Sichern. Immer, ohne Ausnahme. Wer einen Stand von gestern einspielt,
 *      verliert sonst alles von heute, und zwar endgültig.
 *   2. Wartungsmodus an. Während halb alte, halb neue Dateien nebeneinander
 *      liegen, soll kein Besucher die Seite sehen.
 *   3. Dateien, dann Datenbank. Andersherum liefe eine alte Datenbank kurz
 *      gegen neuen Code — das ist der Zustand, in dem WordPress Tabellen
 *      migriert und es danach endgültig kaputt ist.
 *   4. Aufräumen, Cache leeren, Wartungsmodus aus.
 *
 * Bricht etwas in der Mitte ab, bleibt der Wartungsmodus an. Das ist
 * Absicht: eine halb zurückgespielte Seite gehört nicht ans Netz, und
 * jemand muss hinsehen.
 */
final class RestoreRunner {

	/** So viel geht je Übertragung an die Kundenseite. */
	public const CHUNK = 524288;

	/** Länger als das soll ein Durchgang des Zeitplaners nicht dauern. */
	public const BUDGET = 240;

	/**
	 * Freigegebene Vorhaben abarbeiten.
	 */
	public static function runDue(): string {
		RestoreRunService::expireCodes();
		self::reviveStuck();

		$job = Database::selectOne(
			'SELECT * FROM `' . Database::table( 'restore_jobs' ) . "`
			 WHERE `status` = 'vorgemerkt' ORDER BY `released_at` ASC, `id` ASC LIMIT 1"
		);

		if ( null === $job ) {
			return 'nichts freigegeben';
		}

		return self::run( (int) $job['id'] );
	}

	/**
	 * Ein Vorhaben durchziehen.
	 */
	public static function run( int $id ): string {
		$job  = Database::selectOne( 'SELECT * FROM `' . Database::table( 'restore_jobs' ) . '` WHERE `id` = :i', array( 'i' => $id ) );
		$site = null === $job ? null : SiteRepository::find( (int) $job['site_id'] );

		if ( null === $job || null === $site ) {
			return 'Vorhaben nicht gefunden';
		}

		self::phase( $id, 'Wird vorbereitet' );
		Database::update( 'restore_jobs', array( 'status' => 'laeuft', 'started_at' => nl_utc() ), array( 'id' => $id ) );

		try {
			$ergebnis = self::durchfuehren( $id, $site, (string) $job['snapshot'], (string) $job['scope'] );
		} catch ( Throwable $e ) {
			Logger::exception( $e );
			$ergebnis = 'Abgebrochen: ' . $e->getMessage();
		}

		$gelungen = '' === $ergebnis;

		Database::update(
			'restore_jobs',
			array(
				'status'      => $gelungen ? 'fertig' : 'fehler',
				'finished_at' => nl_utc(),
				'phase'       => '',
				'note'        => $gelungen ? 'Zurückgespielt.' : $ergebnis,
			),
			array( 'id' => $id )
		);

		if ( $gelungen ) {
			// Der Wartungsmodus geht nur bei Erfolg wieder aus. Eine halb
			// zurueckgespielte Seite gehoert nicht ans Netz.
			MaintenanceModeService::disable( (int) $site['id'] );
			SyncService::site( (int) $site['id'], true );
		}

		ActivityRepository::log(
			$gelungen ? 'restore.done' : 'restore.failed',
			$gelungen
				? sprintf( 'Sicherung zurückgespielt (%s).', RestoreRunService::SCOPES[ (string) $job['scope'] ] ?? '' )
				: 'Zurückspielen fehlgeschlagen: ' . $ergebnis,
			array( 'site_id' => (int) $site['id'], 'level' => 'warning' )
		);

		EventBus::dispatch(
			$gelungen ? 'restore.done' : 'restore.failed',
			array( 'scope' => (string) $job['scope'], 'snapshot' => substr( (string) $job['snapshot'], 0, 8 ) ),
			array(
				'site_id' => (int) $site['id'],
				'message' => $gelungen
					? sprintf( 'Auf "%s" wurde eine Sicherung zurückgespielt.', (string) $site['name'] )
					: sprintf( 'Zurückspielen auf "%s" fehlgeschlagen: %s', (string) $site['name'], $ergebnis ),
			)
		);

		return $gelungen ? 'zurückgespielt' : $ergebnis;
	}

	/**
	 * @param array<string,mixed> $site
	 * @return string Leer bei Erfolg, sonst der Grund.
	 */
	private static function durchfuehren( int $id, array $site, string $snapshot, string $scope ): string {
		$siteId = (int) $site['id'];

		// --- 1. Sichern, bevor etwas ueberschrieben wird ------------------
		self::phase( $id, 'Sicherung des aktuellen Stands' );

		$schutz = BackupService::run( $siteId );

		if ( ! $schutz['ok'] ) {
			return 'Der aktuelle Stand liess sich nicht sichern, darum wurde nichts überschrieben: ' . $schutz['error'];
		}

		Database::update(
			'restore_jobs',
			array( 'safety_snapshot' => (string) ( $schutz['snapshot'] ?? '' ) ),
			array( 'id' => $id )
		);

		// --- 2. Wartungsmodus --------------------------------------------
		self::phase( $id, 'Wartungsmodus' );
		MaintenanceModeService::enable( $siteId, 120 );

		$wurzel = Restic::snapshotRoot( $snapshot );

		if ( '' === $wurzel ) {
			return 'Der Sicherungspunkt liess sich nicht lesen.';
		}

		// --- 3. Dateien ---------------------------------------------------
		if ( 'db' !== $scope ) {
			$fehler = self::dateien( $id, $site, $snapshot, $wurzel );

			if ( '' !== $fehler ) {
				return $fehler;
			}
		}

		// --- 4. Datenbank --------------------------------------------------
		if ( 'files' !== $scope ) {
			$fehler = self::datenbank( $id, $site, $snapshot, $wurzel );

			if ( '' !== $fehler ) {
				return $fehler;
			}
		}

		// --- 5. Aufraeumen --------------------------------------------------
		self::phase( $id, 'Aufräumen' );
		ChildClient::post( $site, '/restore', array( 'action' => 'cleanup' ), 60 );

		return '';
	}

	/**
	 * Die Dateien des Sicherungspunkts zurückspielen.
	 *
	 * @param array<string,mixed> $site
	 */
	private static function dateien( int $id, array $site, string $snapshot, string $wurzel ): string {
		self::phase( $id, 'Dateien werden gezählt' );

		$dateien = self::sammeln( $snapshot, $wurzel . '/wp-content', '' );

		if ( null === $dateien ) {
			return 'Die Dateiliste des Sicherungspunkts liess sich nicht lesen.';
		}

		$gesamt = count( $dateien );
		$fertig = 0;

		foreach ( $dateien as $relativ ) {
			self::phase( $id, 'Dateien werden zurückgespielt', $fertig, $gesamt );

			$fehler = self::eineDatei( $site, $snapshot, $wurzel . '/wp-content/' . $relativ, 'wp-content/' . $relativ );

			if ( '' !== $fehler ) {
				return $fehler;
			}

			$fertig++;
		}

		self::phase( $id, 'Dateien zurückgespielt', $gesamt, $gesamt );

		return '';
	}

	/**
	 * Eine einzelne Datei übertragen und an ihren Platz legen.
	 *
	 * @param array<string,mixed> $site
	 */
	private static function eineDatei( array $site, string $snapshot, string $imSnapshot, string $ziel ): string {
		$puffer  = '';
		$offset  = 0;
		$hash    = hash_init( 'sha256' );
		$problem = '';

		$senden = static function ( string $stueck ) use ( $site, $ziel, &$offset, &$problem ): void {
			if ( '' !== $problem ) {
				return;
			}

			$antwort = ChildClient::post(
				$site,
				'/restore',
				array( 'action' => 'file', 'root' => 'content', 'path' => $ziel, 'offset' => $offset, 'data' => base64_encode( $stueck ) ),
				120
			);

			if ( ! $antwort['ok'] ) {
				$problem = sprintf( '%s: %s', $ziel, $antwort['error'] );

				return;
			}

			$offset += strlen( $stueck );
		};

		$holen = Restic::dump(
			$snapshot,
			$imSnapshot,
			null,
			static function ( string $stueck ) use ( &$puffer, $senden, $hash ): void {
				hash_update( $hash, $stueck );
				$puffer .= $stueck;

				while ( strlen( $puffer ) >= self::CHUNK ) {
					$senden( substr( $puffer, 0, self::CHUNK ) );
					$puffer = substr( $puffer, self::CHUNK );
				}
			}
		);

		if ( ! $holen['ok'] ) {
			return sprintf( '%s liess sich nicht aus der Sicherung holen: %s', $ziel, $holen['error'] );
		}

		if ( '' !== $puffer ) {
			$senden( $puffer );
		}

		if ( '' !== $problem ) {
			return $problem;
		}

		// Erst jetzt wird ueberschrieben — und nur, wenn die Pruefsumme
		// stimmt. Eine abgebrochene Uebertragung ersetzt nie eine Datei,
		// die vorher in Ordnung war.
		$fest = ChildClient::post(
			$site,
			'/restore',
			array( 'action' => 'commit', 'root' => 'content', 'path' => $ziel, 'sha256' => hash_final( $hash ) ),
			60
		);

		return $fest['ok'] ? '' : sprintf( '%s liess sich nicht ablegen: %s', $ziel, $fest['error'] );
	}

	/**
	 * Den Datenbankexport übertragen und einspielen.
	 *
	 * @param array<string,mixed> $site
	 */
	private static function datenbank( int $id, array $site, string $snapshot, string $wurzel ): string {
		self::phase( $id, 'Datenbank wird übertragen' );

		$start = ChildClient::post( $site, '/restore', array( 'action' => 'db-start' ), 60 );

		if ( ! $start['ok'] ) {
			return 'Die Kundenseite war für den Datenbank-Import nicht bereit: ' . $start['error'];
		}

		$puffer  = '';
		$offset  = 0;
		$problem = '';

		$senden = static function ( string $stueck ) use ( $site, &$offset, &$problem ): void {
			if ( '' !== $problem ) {
				return;
			}

			$antwort = ChildClient::post(
				$site,
				'/restore',
				array( 'action' => 'db-receive', 'offset' => $offset, 'data' => base64_encode( $stueck ) ),
				180
			);

			if ( ! $antwort['ok'] ) {
				$problem = 'Der Export liess sich nicht übertragen: ' . $antwort['error'];

				return;
			}

			$offset += strlen( $stueck );
		};

		// Der Export liegt gepackt in der Sicherung — hier entpackt
		// uebertragen, weil die Kundenseite ihn zeilenweise einspielt.
		$roh  = '';
		$hole = Restic::dump(
			$snapshot,
			$wurzel . '/_datenbank/datenbank.sql.gz',
			null,
			static function ( string $stueck ) use ( &$roh ): void {
				$roh .= $stueck;
			}
		);

		if ( ! $hole['ok'] ) {
			return 'Der Datenbankexport liess sich nicht aus der Sicherung holen: ' . $hole['error'];
		}

		$sql = @gzdecode( $roh );

		if ( false === $sql || '' === $sql ) {
			return 'Der Datenbankexport in der Sicherung liess sich nicht entpacken.';
		}

		unset( $roh );

		foreach ( str_split( $sql, self::CHUNK ) as $stueck ) {
			$senden( $stueck );
		}

		if ( '' !== $problem ) {
			return $problem;
		}

		unset( $sql );

		// Einspielen in Schritten: ein grosser Bestand sprengt jedes
		// Zeitlimit, und ein Abbruch mittendrin waere ein halb
		// ueberschriebener Bestand.
		$schritte = 0;

		while ( $schritte < 10000 ) {
			$schritt = ChildClient::post( $site, '/restore', array( 'action' => 'db-step' ), 180 );

			if ( ! $schritt['ok'] ) {
				return 'Der Import brach ab: ' . $schritt['error'];
			}

			$daten = (array) $schritt['data'];

			self::phase(
				$id,
				'Datenbank wird eingespielt',
				(int) ( $daten['offset'] ?? 0 ),
				(int) ( $daten['total'] ?? 0 )
			);

			if ( ! empty( $daten['done'] ) ) {
				return '';
			}

			$schritte++;
		}

		return 'Der Import kam nicht zum Ende.';
	}

	/**
	 * Alle Dateien unterhalb eines Verzeichnisses im Sicherungspunkt.
	 *
	 * @return array<int,string>|null
	 */
	private static function sammeln( string $snapshot, string $verzeichnis, string $praefix, int $tiefe = 0 ): ?array {
		// Eine Mediathek ist tief, aber nicht unendlich. Die Grenze schuetzt
		// vor einer Schleife ueber einen Verweis auf sich selbst.
		if ( $tiefe > 12 ) {
			return array();
		}

		$liste = Restic::ls( $snapshot, $verzeichnis );

		if ( ! $liste['ok'] ) {
			return null;
		}

		$dateien = array();

		foreach ( $liste['entries'] as $eintrag ) {
			$name = basename( (string) ( $eintrag['path'] ?? $eintrag['name'] ?? '' ) );

			if ( '' === $name || '.' === $name || '..' === $name ) {
				continue;
			}

			$relativ = '' === $praefix ? $name : $praefix . '/' . $name;

			if ( 'dir' === ( $eintrag['type'] ?? '' ) ) {
				$tiefer = self::sammeln( $snapshot, $verzeichnis . '/' . $name, $relativ, $tiefe + 1 );

				if ( null === $tiefer ) {
					return null;
				}

				$dateien = array_merge( $dateien, $tiefer );
				continue;
			}

			$dateien[] = $relativ;
		}

		return $dateien;
	}

	/**
	 * Hängengebliebene Läufe schliessen.
	 */
	public static function reviveStuck(): int {
		$tote = Database::select(
			'SELECT `id`, `site_id` FROM `' . Database::table( 'restore_jobs' ) . "`
			 WHERE `status` = 'laeuft' AND COALESCE(`heartbeat_at`, `started_at`) < :grenze",
			array( 'grenze' => gmdate( 'Y-m-d H:i:s', time() - RestoreRunService::PULS_TOT ) )
		);

		foreach ( $tote as $zeile ) {
			Database::update(
				'restore_jobs',
				array(
					'status'      => 'fehler',
					'finished_at' => nl_utc(),
					'note'        => 'Abgebrochen — der Lauf hat sich nicht mehr gemeldet. '
						. 'Die Seite steht womöglich halb zurückgespielt da und bleibt im Wartungsmodus.',
				),
				array( 'id' => (int) $zeile['id'] )
			);

			ActivityRepository::log(
				'restore.stale',
				'Ein Zurückspielen wurde ohne Abschluss beendet. Bitte den Stand der Seite prüfen.',
				array( 'site_id' => (int) $zeile['site_id'], 'level' => 'warning' )
			);
		}

		return count( $tote );
	}

	private static function phase( int $id, string $text, int $done = 0, int $total = 0 ): void {
		Database::update(
			'restore_jobs',
			array( 'phase' => $text, 'phase_done' => $done, 'phase_total' => $total, 'heartbeat_at' => nl_utc() ),
			array( 'id' => $id )
		);
	}
}
