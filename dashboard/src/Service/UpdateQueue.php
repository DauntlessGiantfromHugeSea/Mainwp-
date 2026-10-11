<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Auth;
use NorthLab\Core\Database;
use NorthLab\Core\Logger;
use NorthLab\Repository\SiteRepository;
use Throwable;

/**
 * Warteschlange für Updates.
 *
 * Updates liefen früher im Webaufruf. Das Panel schickte den Befehl an die
 * Kundenseite und wartete, bis WordPress dort Plugin für Plugin
 * heruntergeladen, entpackt und eingespielt hatte — bei einem grossen Plugin
 * Minuten, bei "alles einspielen" über neun Seiten entsprechend länger. So
 * lange hing der Browser, und ein PHP-Arbeiter war die ganze Zeit belegt;
 * zwei gleichzeitige Läufe genügen auf einem kleinen Server, um das Panel
 * unbenutzbar zu machen. Irgendwann griff ein Zeitlimit — und niemand wusste,
 * ob das Update nun durch war oder nicht.
 *
 * Deshalb derselbe Weg wie bei den Sicherungen: der Webaufruf merkt nur vor,
 * der Zeitplaner arbeitet ab, die Oberfläche fragt den Stand nach.
 */
final class UpdateQueue {

	/** Länger als das soll ein Durchgang des Zeitplaners nicht dauern. */
	public const BUDGET = 120;

	/** Fertige Einträge werden nach so vielen Tagen aufgeräumt. */
	public const KEEP_DAYS = 14;

	/**
	 * Ein hängengebliebener Eintrag gilt nach dieser Zeit als tot.
	 *
	 * Das passiert, wenn der Arbeiter mitten im Lauf wegstirbt — Zeitlimit,
	 * OOM-Killer, Neustart. Ohne diese Grenze stünde der Eintrag für immer
	 * auf "läuft" und die Warteschlange käme nie wieder in Gang.
	 */
	public const STUCK_AFTER = 3600;

	/* ------------------------------------------------------------ Vormerken */

	/**
	 * Updates für eine Seite vormerken.
	 *
	 * @param array<int,array{type:string,slug:string}> $items Leer = alle offenen.
	 * @return array{ok:bool,error:string,id:int}
	 */
	public static function request( int $siteId, array $items = array() ): array {
		$site = SiteRepository::find( $siteId );

		if ( null === $site ) {
			return self::nein( 'Seite nicht gefunden.' );
		}
		if ( ! empty( $site['is_paused'] ) ) {
			return self::nein( 'Die Seite ist pausiert.' );
		}
		if ( ! SiteRepository::isManaged( $site ) ) {
			return self::nein( 'Diese Seite wird nur überwacht — Updates laufen dort nicht über das Panel.' );
		}
		if ( ! Scheduler::isCronHealthy() ) {
			return self::nein( 'Der Zeitplaner läuft nicht — ein vorgemerktes Update würde liegen bleiben.' );
		}

		// Schon etwas offen für diese Seite? Dann die Auswahl dazulegen,
		// statt einen zweiten Eintrag anzulegen: zwei Updateläufe gleichzeitig
		// auf derselben Installation sind genau der Weg in ein halb
		// entpacktes Plugin.
		$offen = self::openFor( $siteId );

		if ( null !== $offen ) {
			return self::merge( $offen, $items );
		}

		$id = Database::insert(
			'update_jobs',
			array(
				'site_id'      => $siteId,
				'items'        => self::encode( $items ),
				'status'       => 'wartet',
				'requested_at' => nl_utc(),
				'requested_by' => Auth::id() ?: null,
				'total'        => count( $items ),
			)
		);

		return array( 'ok' => true, 'error' => '', 'id' => $id );
	}

	/**
	 * Dieselbe Vormerkung für mehrere Seiten.
	 *
	 * @param array<int,int> $siteIds
	 * @return array<int,array{site_id:int,site:string,success:bool,message:string}>
	 */
	public static function requestMany( array $siteIds ): array {
		$out = array();

		foreach ( array_unique( array_map( 'intval', $siteIds ) ) as $siteId ) {
			$site = SiteRepository::find( $siteId );

			if ( null === $site ) {
				continue;
			}

			$ergebnis = self::request( $siteId );

			$out[] = array(
				'site_id' => $siteId,
				'site'    => (string) $site['name'],
				'success' => $ergebnis['ok'],
				'message' => $ergebnis['ok'] ? 'vorgemerkt' : $ergebnis['error'],
			);
		}

		return $out;
	}

	/* ------------------------------------------------------------ Abarbeiten */

	/**
	 * Vorgemerkte Updates abarbeiten, bis das Zeitbudget aufgebraucht ist.
	 *
	 * Der Zeitplaner ruft das jede Minute auf. Ein Durchgang nimmt so viele
	 * Einträge, wie in das Budget passen — und bricht nicht mitten in einem
	 * ab, sondern prüft nur vor dem nächsten.
	 */
	public static function runDue(): string {
		self::reviveStuck();

		$start    = microtime( true );
		$erledigt = 0;
		$fehler   = 0;

		while ( microtime( true ) - $start < self::BUDGET ) {
			$job = self::nextWaiting();

			if ( null === $job ) {
				break;
			}

			$ergebnis = self::runJob( $job );
			$erledigt++;

			if ( ! $ergebnis ) {
				$fehler++;
			}
		}

		if ( 0 === $erledigt ) {
			return 'nichts vorgemerkt';
		}

		return sprintf( '%d Seite(n) bearbeitet, %d mit Fehler', $erledigt, $fehler );
	}

	/**
	 * Einen einzelnen Eintrag abarbeiten.
	 *
	 * @param array<string,mixed> $job
	 */
	private static function runJob( array $job ): bool {
		$id = (int) $job['id'];

		// Erst auf "läuft" setzen, dann arbeiten. Stirbt der Arbeiter mitten
		// im Lauf, steht der Eintrag wenigstens nicht mehr als "wartet" da
		// und wird nicht sofort ein zweites Mal angefasst.
		Database::update(
			'update_jobs',
			array( 'status' => 'laeuft', 'started_at' => nl_utc() ),
			array( 'id' => $id )
		);

		try {
			$ergebnis = UpdateService::apply( (int) $job['site_id'], self::decode( (string) ( $job['items'] ?? '' ) ) );
		} catch ( Throwable $e ) {
			Logger::exception( $e );
			$ergebnis = array( 'ok' => false, 'error' => $e->getMessage(), 'succeeded' => 0, 'failed' => 0 );
		}

		Database::update(
			'update_jobs',
			array(
				'status'      => $ergebnis['ok'] ? 'fertig' : 'fehler',
				'finished_at' => nl_utc(),
				'succeeded'   => (int) ( $ergebnis['succeeded'] ?? 0 ),
				'failed'      => (int) ( $ergebnis['failed'] ?? 0 ),
				'note'        => $ergebnis['ok']
					? sprintf( '%d eingespielt, %d fehlgeschlagen', (int) $ergebnis['succeeded'], (int) $ergebnis['failed'] )
					: (string) $ergebnis['error'],
			),
			array( 'id' => $id )
		);

		return (bool) $ergebnis['ok'];
	}

	/**
	 * Einträge, die als "läuft" steckengeblieben sind, wieder freigeben.
	 */
	public static function reviveStuck(): int {
		$tote = Database::select(
			'SELECT `id` FROM `' . Database::table( 'update_jobs' ) . '`
			 WHERE `status` = :s AND `started_at` < :grenze',
			array( 's' => 'laeuft', 'grenze' => gmdate( 'Y-m-d H:i:s', time() - self::STUCK_AFTER ) )
		);

		foreach ( $tote as $zeile ) {
			Database::update(
				'update_jobs',
				array(
					'status'      => 'fehler',
					'finished_at' => nl_utc(),
					// Ehrlich bleiben: wir wissen nicht, ob das Update auf der
					// Kundenseite durchgelaufen ist oder nicht.
					'note'        => 'Abgebrochen — der Lauf hat sich nicht mehr gemeldet. Bitte den Stand der Seite prüfen.',
				),
				array( 'id' => (int) $zeile['id'] )
			);
		}

		return count( $tote );
	}

	/* ---------------------------------------------------------------- Stand */

	/**
	 * Wartet oder läuft gerade etwas für diese Seite?
	 *
	 * @return array<string,mixed>|null
	 */
	public static function openFor( int $siteId ): ?array {
		return Database::selectOne(
			'SELECT * FROM `' . Database::table( 'update_jobs' ) . '`
			 WHERE `site_id` = :id AND `status` IN (:a, :b)
			 ORDER BY `id` ASC LIMIT 1',
			array( 'id' => $siteId, 'a' => 'wartet', 'b' => 'laeuft' )
		);
	}

	public static function isQueued( int $siteId ): bool {
		return null !== self::openFor( $siteId );
	}

	/**
	 * Stand der Warteschlange für die Anzeige.
	 *
	 * @param array<int,int>|null $siteIds Sichtbare Seiten; null = alle.
	 * @return array<string,mixed>
	 */
	public static function status( ?array $siteIds = null ): array {
		$wo     = '';
		$params = array();

		if ( null !== $siteIds ) {
			[ $in, $params ] = Database::inClause( $siteIds, 'sid' );
			$wo              = ' AND j.`site_id` IN ' . $in;
		}

		$zeilen = Database::select(
			'SELECT j.*, s.`name` AS site_name FROM `' . Database::table( 'update_jobs' ) . '` j
			 INNER JOIN `' . Database::table( 'sites' ) . '` s ON s.id = j.site_id
			 WHERE j.`status` IN (\'wartet\', \'laeuft\')' . $wo . '
			 ORDER BY j.`id` ASC LIMIT 50',
			$params
		);

		$laufend = null;
		$offen   = array();

		foreach ( $zeilen as $zeile ) {
			$eintrag = array(
				'site_id' => (int) $zeile['site_id'],
				'name'    => (string) $zeile['site_name'],
				'total'   => (int) $zeile['total'],
			);

			if ( 'laeuft' === $zeile['status'] ) {
				$laufend = $eintrag;
				continue;
			}

			$offen[] = $eintrag;
		}

		$fertig = Database::select(
			'SELECT j.*, s.`name` AS site_name FROM `' . Database::table( 'update_jobs' ) . '` j
			 INNER JOIN `' . Database::table( 'sites' ) . '` s ON s.id = j.site_id
			 WHERE j.`status` IN (\'fertig\', \'fehler\')' . $wo . '
			 ORDER BY j.`id` DESC LIMIT 8',
			$params
		);

		return array(
			'ok'      => true,
			'cron'    => Scheduler::isCronHealthy(),
			'laufend' => $laufend,
			'offen'   => $offen,
			'letzte'  => array_map(
				static fn( array $z ): array => array(
					'name'      => (string) $z['site_name'],
					'ok'        => 'fertig' === $z['status'],
					'note'      => (string) ( $z['note'] ?? '' ),
					'wann'      => (string) ( $z['finished_at'] ?? '' ),
					'succeeded' => (int) $z['succeeded'],
					'failed'    => (int) $z['failed'],
				),
				$fertig
			),
		);
	}

	/**
	 * Alte, abgeschlossene Einträge wegräumen.
	 */
	public static function prune(): int {
		return Database::pdo()->exec(
			sprintf(
				'DELETE FROM `%s` WHERE `status` IN (\'fertig\', \'fehler\') AND `finished_at` < %s',
				Database::table( 'update_jobs' ),
				Database::pdo()->quote( gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400 ) )
			)
		) ?: 0;
	}

	/* ---------------------------------------------------------------- Intern */

	/**
	 * @return array<string,mixed>|null
	 */
	private static function nextWaiting(): ?array {
		return Database::selectOne(
			'SELECT * FROM `' . Database::table( 'update_jobs' ) . '`
			 WHERE `status` = :s ORDER BY `requested_at` ASC, `id` ASC LIMIT 1',
			array( 's' => 'wartet' )
		);
	}

	/**
	 * Eine Auswahl zu einem schon vorgemerkten Eintrag dazulegen.
	 *
	 * @param array<string,mixed>                       $job
	 * @param array<int,array{type:string,slug:string}> $items
	 * @return array{ok:bool,error:string,id:int}
	 */
	private static function merge( array $job, array $items ): array {
		$id  = (int) $job['id'];
		$alt = self::decode( (string) ( $job['items'] ?? '' ) );

		// Ein Eintrag ohne Auswahl heisst "alles offene". Der schluckt jede
		// weitere Auswahl ohnehin — da gibt es nichts dazuzulegen.
		if ( ! $alt || ! $items ) {
			return array( 'ok' => true, 'error' => '', 'id' => $id );
		}

		// Nur was noch nicht drin ist. Dasselbe Plugin zweimal in einer Liste
		// liesse WordPress es zweimal herunterladen.
		$zusammen = $alt;

		foreach ( $items as $neu ) {
			foreach ( $zusammen as $vorhanden ) {
				if ( $vorhanden['type'] === $neu['type'] && $vorhanden['slug'] === $neu['slug'] ) {
					continue 2;
				}
			}
			$zusammen[] = $neu;
		}

		// Ein Eintrag, der schon laeuft, wird nicht mehr angefasst — die
		// Kundenseite arbeitet die alte Liste gerade ab.
		if ( 'wartet' === (string) $job['status'] ) {
			Database::update(
				'update_jobs',
				array( 'items' => self::encode( $zusammen ), 'total' => count( $zusammen ) ),
				array( 'id' => $id )
			);
		}

		return array( 'ok' => true, 'error' => '', 'id' => $id );
	}

	/**
	 * @param array<int,array{type:string,slug:string}> $items
	 */
	private static function encode( array $items ): ?string {
		if ( ! $items ) {
			return null;
		}

		return (string) json_encode( array_values( $items ) );
	}

	/**
	 * @return array<int,array{type:string,slug:string}>
	 */
	private static function decode( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return array();
		}

		$daten = json_decode( $raw, true );

		if ( ! is_array( $daten ) ) {
			return array();
		}

		$out = array();

		foreach ( $daten as $eintrag ) {
			if ( ! is_array( $eintrag ) || ! isset( $eintrag['type'], $eintrag['slug'] ) ) {
				continue;
			}
			$out[] = array( 'type' => (string) $eintrag['type'], 'slug' => (string) $eintrag['slug'] );
		}

		return $out;
	}

	/**
	 * @return array{ok:bool,error:string,id:int}
	 */
	private static function nein( string $grund ): array {
		return array( 'ok' => false, 'error' => $grund, 'id' => 0 );
	}
}
