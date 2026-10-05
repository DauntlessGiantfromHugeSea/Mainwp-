<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Config;
use NorthLab\Core\Database;
use NorthLab\Core\Setting;
use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\SiteRepository;
use NorthLab\Repository\UpdateRepository;

/**
 * Ausführung einzelner und massenhafter Updates.
 */
final class UpdateService {

	/**
	 * Updates auf einer Seite einspielen.
	 *
	 * @param array<string,mixed>|int                   $site
	 * @param array<int,array{type:string,slug:string}> $items Leer = alle verfügbaren.
	 * @return array{ok:bool,error:string,succeeded:int,failed:int,results:array<int,array<string,mixed>>}
	 */
	public static function apply( array|int $site, array $items = array() ): array {
		$site = is_array( $site ) ? $site : SiteRepository::find( $site );

		if ( null === $site ) {
			return self::failure( 'Seite nicht gefunden.' );
		}
		if ( ! empty( $site['is_paused'] ) ) {
			return self::failure( 'Die Seite ist pausiert.' );
		}
		if ( ! SiteRepository::isManaged( $site ) ) {
			return self::failure( 'Diese Seite wird nur überwacht — Updates laufen dort nicht über das Panel.' );
		}

		// Erst sichern, dann einspielen. Das ist der Moment, in dem man eine
		// Sicherung wirklich braucht — und der einzige, in dem man sicher
		// weiss, dass gleich etwas veraendert wird.
		$schutz = self::backupFirst( $site );

		if ( null !== $schutz ) {
			return self::failure( $schutz );
		}

		$body = $items ? array( 'items' => array_values( $items ) ) : array( 'all' => true );

		$response = ChildClient::post( $site, '/update', $body, (int) Config::get( 'http.update_timeout', 300 ) );

		if ( ! $response['ok'] ) {
			EventBus::dispatch(
				'update.failed',
				array( 'error' => $response['error'], 'items' => $items ),
				array(
					'site_id' => (int) $site['id'],
					'message' => sprintf( 'Update auf "%s" fehlgeschlagen: %s', $site['name'], $response['error'] ),
				)
			);

			return self::failure( $response['error'] );
		}

		$results   = is_array( $response['data']['results'] ?? null ) ? $response['data']['results'] : array();
		$succeeded = array_values( array_filter( $results, static fn( $r ): bool => ! empty( $r['success'] ) ) );
		$failed    = array_values( array_filter( $results, static fn( $r ): bool => empty( $r['success'] ) ) );

		if ( $succeeded ) {
			EventBus::dispatch(
				'update.applied',
				array( 'items' => $succeeded ),
				array(
					'site_id' => (int) $site['id'],
					'message' => sprintf( '%d Update(s) auf "%s" eingespielt.', count( $succeeded ), $site['name'] ),
				)
			);
		}

		if ( $failed ) {
			EventBus::dispatch(
				'update.failed',
				array( 'items' => $failed ),
				array(
					'site_id' => (int) $site['id'],
					'message' => sprintf( '%d Update(s) auf "%s" fehlgeschlagen.', count( $failed ), $site['name'] ),
				)
			);
		}

		// Inventar direkt aus der Antwort nachziehen — spart einen zweiten Roundtrip.
		if ( is_array( $response['data']['updates'] ?? null ) ) {
			UpdateRepository::replaceForSite( (int) $site['id'], $response['data']['updates'] );

			SiteRepository::update(
				(int) $site['id'],
				array(
					'pending_updates' => count( UpdateRepository::flatten( $response['data']['updates'] ) ),
					'last_sync_at'    => nl_utc(),
				)
			);
		}

		return array(
			'ok'        => true,
			'error'     => '',
			'succeeded' => count( $succeeded ),
			'failed'    => count( $failed ),
			'results'   => $results,
		);
	}

	/**
	 * Ein bestimmtes Plugin/Theme auf allen betroffenen Seiten aktualisieren.
	 *
	 * @param array<int,int>|null $siteIds Auf diese Seiten einschränken; null = alle.
	 * @return array<int,array{site_id:int,site:string,success:bool,message:string}>
	 */
	public static function applyAcrossSites( string $type, string $slug, ?array $siteIds = null ): array {
		$out = array();

		foreach ( UpdateRepository::query( array( 'type' => $type, 'site_ids' => $siteIds ) ) as $row ) {
			if ( (string) $row['slug'] !== $slug ) {
				continue;
			}

			$result = self::apply( (int) $row['site_id'], array( array( 'type' => $type, 'slug' => $slug ) ) );

			$out[] = array(
				'site_id' => (int) $row['site_id'],
				'site'    => (string) $row['site_name'],
				'success' => $result['ok'] && 0 === $result['failed'],
				'message' => $result['ok'] ? self::summarize( $result ) : $result['error'],
			);
		}

		return $out;
	}

	/**
	 * Alle offenen Updates auf allen Seiten einspielen.
	 *
	 * @param array<int,int>|null $siteIds Auf diese Seiten einschränken; null = alle.
	 * @return array<int,array{site_id:int,site:string,success:bool,message:string}>
	 */
	public static function applyEverything( ?array $siteIds = null ): array {
		$out = array();

		foreach ( SiteRepository::active() as $site ) {
			if ( null !== $siteIds && ! in_array( (int) $site['id'], $siteIds, true ) ) {
				continue;
			}
			if ( (int) $site['pending_updates'] < 1 ) {
				continue;
			}

			$items = self::openItems( (int) $site['id'] );
			if ( ! $items ) {
				continue;
			}

			$result = self::apply( $site, $items );

			$out[] = array(
				'site_id' => (int) $site['id'],
				'site'    => (string) $site['name'],
				'success' => $result['ok'] && 0 === $result['failed'],
				'message' => $result['ok'] ? self::summarize( $result ) : $result['error'],
			);
		}

		return $out;
	}

	/**
	 * Nicht ignorierte Updates einer Seite als Item-Liste.
	 *
	 * @return array<int,array{type:string,slug:string}>
	 */
	public static function openItems( int $siteId ): array {
		$items = array();

		foreach ( UpdateRepository::query( array( 'site_id' => $siteId ) ) as $row ) {
			$items[] = array( 'type' => (string) $row['type'], 'slug' => (string) $row['slug'] );
		}

		return $items;
	}

	/* ------------------------------------------------------ Automatik */

	/**
	 * Wendet die konfigurierte Auto-Update-Politik auf alle Seiten an.
	 *
	 * @return array{sites:int,applied:int}
	 */
	public static function runAutoUpdates(): array {
		if ( ! self::inWindow() ) {
			return array( 'sites' => 0, 'applied' => 0 );
		}

		$globalPolicy = Setting::get( 'auto_update_policy', 'off' );

		$touched = 0;
		$applied = 0;

		foreach ( SiteRepository::active() as $site ) {
			$policy = 'inherit' === $site['auto_update_policy'] ? $globalPolicy : (string) $site['auto_update_policy'];
			if ( 'off' === $policy ) {
				continue;
			}

			// Die Ausnahmen je Seite dazunehmen — darum erst hier und nicht
			// einmal vorab fuer alle.
			$items = self::itemsForPolicy( (int) $site['id'], $policy, self::excludes( $site ) );
			if ( ! $items ) {
				continue;
			}

			$result = self::apply( $site, $items );
			$touched++;

			if ( $result['ok'] ) {
				$applied += $result['succeeded'];
			}
		}

		if ( $touched > 0 ) {
			ActivityRepository::log(
				'auto_update.run',
				sprintf( 'Auto-Updates: %d Seite(n) bearbeitet, %d Update(s) eingespielt.', $touched, $applied )
			);
		}

		return array( 'sites' => $touched, 'applied' => $applied );
	}

	/**
	 * @param array<int,string> $excludes
	 * @return array<int,array{type:string,slug:string}>
	 */
	private static function itemsForPolicy( int $siteId, string $policy, array $excludes ): array {
		$items = array();

		foreach ( UpdateRepository::query( array( 'site_id' => $siteId ) ) as $row ) {
			if ( self::isExcluded( (string) $row['slug'], $excludes ) ) {
				continue;
			}

			// "security" und "minor" verhalten sich gleich: keine Major-Sprünge ohne Kontrolle.
			if ( 'all' !== $policy && ! self::isMinorStep( (string) $row['current_version'], (string) $row['new_version'] ) ) {
				continue;
			}

			$items[] = array( 'type' => (string) $row['type'], 'slug' => (string) $row['slug'] );
		}

		return $items;
	}

	/**
	 * @return array<int,string>
	 */
	/**
	 * Vor dem Update sichern.
	 *
	 * @param array<string,mixed> $site
	 * @return string|null Grund, warum nicht aktualisiert werden darf — oder null.
	 */
	public static function backupFirst( array $site ): ?string {
		if ( ! Setting::getBool( 'backup_before_update', true ) ) {
			return null;
		}

		// Ohne eingerichtetes Ziel gibt es nichts zu sichern. Deswegen alle
		// Updates zu blockieren hiesse, Sicherheitsluecken offen zu lassen,
		// weil ein Speicher fehlt — das waere der schlechtere Tausch.
		if ( ! Restic::configured() || ! Restic::available() ) {
			return null;
		}

		$siteId = (int) $site['id'];

		// Eine frische Sicherung reicht. Vor jedem einzelnen Plugin-Update
		// erneut zu sichern dauert laenger als die Updates selbst.
		$maxAlter = max( 5, min( 1440, Setting::getInt( 'backup_before_update_age', 180 ) ) );

		$letzte = Database::scalar(
			'SELECT MAX(`finished_at`) FROM `' . Database::table( 'backups' ) . '`
			 WHERE `site_id` = :id AND `status` = :ok',
			array( 'id' => $siteId, 'ok' => 'success' )
		);

		if ( null !== $letzte && '' !== (string) $letzte ) {
			$alter = time() - (int) strtotime( (string) $letzte . ' UTC' );

			if ( $alter >= 0 && $alter < $maxAlter * 60 ) {
				return null;
			}
		}

		ActivityRepository::log(
			'site.backup',
			'Sicherung vor dem Update.',
			array( 'site_id' => $siteId )
		);

		$ergebnis = BackupService::run( $site );

		if ( $ergebnis['ok'] ) {
			return null;
		}

		EventBus::dispatch(
			'update.blocked',
			array( 'error' => $ergebnis['error'] ),
			array(
				'site_id' => $siteId,
				'level'   => 'warning',
				'message' => sprintf(
					'Update auf "%s" nicht eingespielt: die Sicherung davor schlug fehl (%s).',
					(string) $site['name'],
					$ergebnis['error']
				),
			)
		);

		return sprintf(
			'Vor dem Update sollte gesichert werden, und das schlug fehl: %s — '
				. 'darum wurde nichts eingespielt. Abschaltbar unter Einstellungen → Updates.',
			$ergebnis['error']
		);
	}

	/**
	 * Was von den automatischen Updates ausgenommen ist.
	 *
	 * Die globale Liste gilt ueberall. Eine Seite kann weitere Punkte
	 * dazunehmen — fuer den Fall, dass genau ein Kunde ein angepasstes
	 * Plugin hat, das ein Update ueberschreiben wuerde.
	 *
	 * @param array<string,mixed>|null $site
	 * @return array<int,string>
	 */
	public static function excludes( ?array $site = null ): array {
		$out = self::parseExcludes( (string) Setting::get( 'auto_update_excludes', '' ) );

		if ( null !== $site && ! empty( $site['update_excludes'] ) ) {
			$out = array_merge( $out, self::parseExcludes( (string) $site['update_excludes'] ) );
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * @return array<int,string>
	 */
	public static function parseExcludes( string $raw ): array {
		$out = array();

		foreach ( preg_split( '/[\r\n,]+/', $raw ) ?: array() as $line ) {
			$line = trim( (string) $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}

		return $out;
	}

	/**
	 * @param array<int,string> $excludes
	 */
	private static function isExcluded( string $slug, array $excludes ): bool {
		foreach ( $excludes as $pattern ) {
			if ( $pattern === $slug ) {
				return true;
			}
			if ( str_contains( $pattern, '*' ) && fnmatch( $pattern, $slug ) ) {
				return true;
			}
			// Ordnername genügt: "woocommerce" trifft auch "woocommerce/woocommerce.php".
			if ( str_starts_with( $slug, $pattern . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	private static function isMinorStep( string $from, string $to ): bool {
		if ( '' === $from || '' === $to ) {
			return false;
		}

		$a = explode( '.', (string) preg_replace( '/[^0-9.].*$/', '', $from ) );
		$b = explode( '.', (string) preg_replace( '/[^0-9.].*$/', '', $to ) );

		return (int) ( $a[0] ?? 0 ) === (int) ( $b[0] ?? 0 );
	}

	/**
	 * Prüft, ob die aktuelle Uhrzeit im konfigurierten Wartungsfenster liegt.
	 */
	public static function inWindow(): bool {
		$window = trim( Setting::get( 'auto_update_window', '' ) );
		if ( '' === $window ) {
			return true;
		}
		if ( ! preg_match( '/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $window, $m ) ) {
			return true;
		}

		$now   = (int) date( 'Hi' );
		$start = (int) sprintf( '%02d%02d', (int) $m[1], (int) $m[2] );
		$end   = (int) sprintf( '%02d%02d', (int) $m[3], (int) $m[4] );

		return $start <= $end
			? ( $now >= $start && $now <= $end )
			: ( $now >= $start || $now <= $end ); // Fenster über Mitternacht
	}

	/**
	 * @param array{succeeded:int,failed:int} $result
	 */
	private static function summarize( array $result ): string {
		if ( 0 === $result['failed'] ) {
			return sprintf( '%d Update(s) eingespielt.', $result['succeeded'] );
		}

		return sprintf( '%d erfolgreich, %d fehlgeschlagen.', $result['succeeded'], $result['failed'] );
	}

	/**
	 * @return array{ok:bool,error:string,succeeded:int,failed:int,results:array<int,array<string,mixed>>}
	 */
	private static function failure( string $error ): array {
		return array( 'ok' => false, 'error' => $error, 'succeeded' => 0, 'failed' => 0, 'results' => array() );
	}
}
