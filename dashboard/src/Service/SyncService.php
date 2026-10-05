<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Logger;
use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\SiteRepository;
use NorthLab\Repository\UpdateRepository;
use Throwable;

/**
 * Holt den Zustand der Kundenseiten ab und schreibt ihn in die lokalen Tabellen.
 */
final class SyncService {

	/**
	 * Eine Seite synchronisieren.
	 *
	 * @param array<string,mixed>|int $site
	 * @return array{ok:bool,error:string,payload:array<string,mixed>}
	 */
	public static function site( array|int $site, bool $refresh = true ): array {
		$site = is_array( $site ) ? $site : SiteRepository::find( $site );

		if ( null === $site ) {
			return array( 'ok' => false, 'error' => 'Seite nicht gefunden.', 'payload' => array() );
		}

		// Seiten ohne Plugin können nur auf Erreichbarkeit geprüft werden.
		if ( ! SiteRepository::isManaged( $site ) ) {
			$up = SiteService::checkReachable( $site );

			return array(
				'ok'      => $up,
				'error'   => $up ? '' : (string) ( SiteRepository::find( (int) $site['id'] )['last_error'] ?? 'nicht erreichbar' ),
				'payload' => array(),
			);
		}

		$response = ChildClient::get( $site, '/status', array( 'refresh' => $refresh ? '1' : '0' ) );

		if ( ! $response['ok'] ) {
			self::markFailed( $site, $response['error'] );
			return array( 'ok' => false, 'error' => $response['error'], 'payload' => array() );
		}

		self::applyPayload( $site, $response['data'] );

		return array( 'ok' => true, 'error' => '', 'payload' => $response['data'] );
	}

	/**
	 * Übernimmt einen Statusbericht des Childs in die Datenbank.
	 *
	 * @param array<string,mixed> $site
	 * @param array<string,mixed> $payload
	 */
	public static function applyPayload( array $site, array $payload ): void {
		$siteId           = (int) $site['id'];
		$previousUpdates  = (int) $site['pending_updates'];
		$previousSecurity = (int) $site['security_score'];

		$updates = is_array( $payload['updates'] ?? null )
			? $payload['updates']
			: array( 'core' => array(), 'plugins' => array(), 'themes' => array(), 'translations' => 0 );

		$flat          = UpdateRepository::flatten( $updates );
		$count         = count( $flat );
		$securityScore = (int) ( $payload['security']['score'] ?? 0 );

		SiteRepository::storePayload( $siteId, $payload );
		UpdateRepository::replaceForSite( $siteId, $updates );

		SiteRepository::update(
			$siteId,
			array(
				'status'          => 'connected',
				'last_sync_at'    => nl_utc(),
				'last_seen_at'    => nl_utc(),
				'last_error'      => null,
				'wp_version'      => (string) ( $payload['environment']['wp_version'] ?? '' ),
				'php_version'     => (string) ( $payload['environment']['php_version'] ?? '' ),
				'child_version'   => (string) ( $payload['child_version'] ?? '' ),
				'admin_url'       => (string) ( $payload['site']['admin_url'] ?? '' ),
				'pending_updates' => $count,
				'security_score'  => $securityScore,
			)
		);

		// Ein erfolgreicher Sync beweist Erreichbarkeit.
		UptimeService::markReachable( $siteId );

		if ( $count > $previousUpdates ) {
			EventBus::dispatch(
				'updates.available',
				array( 'pending' => $count, 'previous' => $previousUpdates, 'items' => $flat ),
				array(
					'site_id' => $siteId,
					'message' => sprintf( '%d Update(s) verfügbar für "%s".', $count, $site['name'] ),
				)
			);
		}

		if ( $previousSecurity > 0 && $securityScore < $previousSecurity - 5 ) {
			EventBus::dispatch(
				'security.changed',
				array( 'score' => $securityScore, 'previous' => $previousSecurity ),
				array(
					'site_id' => $siteId,
					'message' => sprintf(
						'Sicherheitsbewertung von "%s" gefallen: %d → %d.',
						$site['name'],
						$previousSecurity,
						$securityScore
					),
				)
			);
		}

		// Die Kundenseite zeigt dem Kunden die letzten Sicherungen. Sie kennt
		// aber nur, was ihr gemeldet wurde — Laeufe von vor dem Einbau fehlen
		// dort. Weicht ihr Stand ab, wird die Vorgeschichte nachgereicht.
		// Nur dann: sonst ginge bei jedem Sync jeder Seite eine zweite
		// Anfrage raus, fuer nichts.
		if ( SiteRepository::isManaged( $site ) && BackupService::historyStale( $siteId, $payload ) ) {
			try {
				BackupService::pushHistory( $site );
			} catch ( Throwable $e ) {
				// Ein Sync darf daran nicht scheitern — die Liste auf der
				// Kundenseite ist Beiwerk, der Statusbericht ist die Aufgabe.
				Logger::exception( $e );
			}
		}
	}

	/**
	 * Alle aktiven Seiten synchronisieren.
	 *
	 * @return array{ok:int,failed:int,errors:array<int,string>}
	 */
	public static function all(): array {
		$ok     = 0;
		$failed = 0;
		$errors = array();

		foreach ( SiteRepository::active() as $site ) {
			$result = self::site( $site, true );

			if ( $result['ok'] ) {
				$ok++;
			} else {
				$failed++;
				$errors[ (int) $site['id'] ] = $result['error'];
			}
		}

		ActivityRepository::log(
			'sync.batch',
			sprintf( 'Sammel-Sync: %d erfolgreich, %d fehlgeschlagen.', $ok, $failed ),
			array( 'level' => $failed > 0 ? 'warning' : 'info', 'context' => array( 'errors' => $errors ) )
		);

		return array( 'ok' => $ok, 'failed' => $failed, 'errors' => $errors );
	}

	/**
	 * Leichter Erreichbarkeits-Check ohne vollen Statusbericht.
	 *
	 * @param array<string,mixed> $site
	 */
	public static function heartbeat( array $site ): bool {
		if ( ! SiteRepository::isManaged( $site ) ) {
			return SiteService::checkReachable( $site );
		}

		$response = ChildClient::get( $site, '/ping', array(), 20 );

		if ( ! $response['ok'] ) {
			UptimeService::record(
				(int) $site['id'],
				'down',
				array( 'source' => 'heartbeat', 'message' => $response['error'] )
			);
			return false;
		}

		UptimeService::record(
			(int) $site['id'],
			'up',
			array( 'source' => 'heartbeat', 'response_ms' => $response['ms'] )
		);

		return true;
	}

	/**
	 * @return array{up:int,down:int}
	 */
	public static function heartbeatAll(): array {
		$up   = 0;
		$down = 0;

		foreach ( SiteRepository::monitored() as $site ) {
			if ( self::heartbeat( $site ) ) {
				$up++;
			} else {
				$down++;
			}
		}

		return array( 'up' => $up, 'down' => $down );
	}

	/**
	 * @param array<string,mixed> $site
	 */
	private static function markFailed( array $site, string $error ): void {
		SiteRepository::update( (int) $site['id'], array( 'status' => 'error', 'last_error' => $error ) );

		EventBus::dispatch(
			'sync.failed',
			array( 'error' => $error ),
			array(
				'site_id' => (int) $site['id'],
				'message' => sprintf( 'Sync für "%s" fehlgeschlagen: %s', $site['name'], $error ),
			)
		);

		UptimeService::record( (int) $site['id'], 'down', array( 'source' => 'sync', 'message' => $error ) );
	}
}
