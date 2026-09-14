<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Config;
use NorthLab\Repository\SiteRepository;

/**
 * Wartung, Sicherheit und Erweiterungsverwaltung auf den Kundenseiten.
 */
final class MaintenanceService {

	/**
	 * Auswahl der Wartungsaufgaben (Spiegel der Child-Implementierung).
	 *
	 * @return array<string,string>
	 */
	public static function tasks(): array {
		return array(
			'revisions'          => 'Beitragsrevisionen löschen',
			'auto_drafts'        => 'Automatische Entwürfe löschen',
			'trash_posts'        => 'Papierkorb (Beiträge) leeren',
			'spam_comments'      => 'Spam-Kommentare löschen',
			'trash_comments'     => 'Papierkorb (Kommentare) leeren',
			'expired_transients' => 'Abgelaufene Transients entfernen',
			'orphan_postmeta'    => 'Verwaiste Postmeta entfernen',
			'orphan_commentmeta' => 'Verwaiste Commentmeta entfernen',
			'optimize_tables'    => 'Datenbanktabellen optimieren',
			'flush_cache'        => 'Object-Cache leeren',
			'flush_rewrites'     => 'Permalinks neu schreiben',
		);
	}

	/**
	 * Wartungsaufgaben auf einer Seite ausführen.
	 *
	 * @param array<int,string> $tasks
	 * @return array{ok:bool,error:string,results:array<int,array<string,mixed>>}
	 */
	public static function run( int $siteId, array $tasks ): array {
		$site = SiteRepository::find( $siteId );

		if ( null === $site ) {
			return array( 'ok' => false, 'error' => 'Seite nicht gefunden.', 'results' => array() );
		}
		if ( ! SiteRepository::isManaged( $site ) ) {
			return array( 'ok' => false, 'error' => 'Diese Seite wird nur überwacht — Wartung ist dort nicht möglich.', 'results' => array() );
		}

		$allowed = array_keys( self::tasks() );
		$tasks   = array_values( array_intersect( $tasks, $allowed ) );

		if ( ! $tasks ) {
			return array( 'ok' => false, 'error' => 'Bitte mindestens eine Aufgabe auswählen.', 'results' => array() );
		}

		$response = ChildClient::post(
			$site,
			'/maintenance',
			array( 'tasks' => $tasks ),
			(int) Config::get( 'http.update_timeout', 300 )
		);

		if ( ! $response['ok'] ) {
			return array( 'ok' => false, 'error' => $response['error'], 'results' => array() );
		}

		$results = (array) ( $response['data']['results'] ?? array() );

		$affected = 0;
		foreach ( $results as $result ) {
			$affected += (int) ( $result['affected'] ?? 0 );
		}

		EventBus::dispatch(
			'maintenance.done',
			array( 'results' => $results, 'tasks' => $tasks ),
			array(
				'site_id' => $siteId,
				'message' => sprintf(
					'Wartung auf "%s" ausgeführt (%d Aufgabe(n), %d Datensätze bereinigt).',
					$site['name'],
					count( $results ),
					$affected
				),
			)
		);

		return array( 'ok' => true, 'error' => '', 'results' => $results );
	}

	/**
	 * Wartung auf mehreren Seiten.
	 *
	 * @param array<int,int>    $siteIds
	 * @param array<int,string> $tasks
	 * @return array<int,array{site_id:int,site:string,success:bool,message:string}>
	 */
	public static function runBulk( array $siteIds, array $tasks ): array {
		$out = array();

		foreach ( $siteIds as $siteId ) {
			$site = SiteRepository::find( (int) $siteId );
			if ( null === $site ) {
				continue;
			}

			$result = self::run( (int) $siteId, $tasks );

			$out[] = array(
				'site_id' => (int) $siteId,
				'site'    => (string) $site['name'],
				'success' => $result['ok'],
				'message' => $result['ok'] ? sprintf( '%d Aufgabe(n) ausgeführt.', count( $result['results'] ) ) : $result['error'],
			);
		}

		return $out;
	}

	/**
	 * Sicherheits-Scan neu ausführen.
	 *
	 * @return array{ok:bool,error:string,scan:array<string,mixed>}
	 */
	public static function scanSecurity( int $siteId ): array {
		$site = SiteRepository::find( $siteId );

		if ( null === $site ) {
			return array( 'ok' => false, 'error' => 'Seite nicht gefunden.', 'scan' => array() );
		}

		$response = ChildClient::post( $site, '/security', array( 'action' => 'scan' ) );

		if ( ! $response['ok'] ) {
			return array( 'ok' => false, 'error' => $response['error'], 'scan' => array() );
		}

		$scan = (array) ( $response['data']['scan'] ?? array() );

		self::mergeIntoPayload( $siteId, 'security', $scan );
		SiteRepository::update( $siteId, array( 'security_score' => (int) ( $scan['score'] ?? 0 ) ) );

		return array( 'ok' => true, 'error' => '', 'scan' => $scan );
	}

	/**
	 * Automatisch behebbare Sicherheitspunkte anwenden.
	 *
	 * @param array<int,string> $checks
	 * @return array{ok:bool,error:string,results:array<int,array<string,mixed>>,scan:array<string,mixed>}
	 */
	public static function harden( int $siteId, array $checks ): array {
		$site = SiteRepository::find( $siteId );

		if ( null === $site ) {
			return array( 'ok' => false, 'error' => 'Seite nicht gefunden.', 'results' => array(), 'scan' => array() );
		}

		$response = ChildClient::post( $site, '/security', array( 'action' => 'harden', 'checks' => array_values( $checks ) ) );

		if ( ! $response['ok'] ) {
			return array( 'ok' => false, 'error' => $response['error'], 'results' => array(), 'scan' => array() );
		}

		$scan = (array) ( $response['data']['scan'] ?? array() );

		self::mergeIntoPayload( $siteId, 'security', $scan );
		SiteRepository::update( $siteId, array( 'security_score' => (int) ( $scan['score'] ?? 0 ) ) );

		return array(
			'ok'      => true,
			'error'   => '',
			'results' => (array) ( $response['data']['results'] ?? array() ),
			'scan'    => $scan,
		);
	}

	/**
	 * Plugin-Aktion auf einer Seite (activate, deactivate, delete, install).
	 *
	 * @param array<int,string> $targets
	 * @return array{ok:bool,error:string,results:array<int,array<string,mixed>>}
	 */
	public static function pluginAction( int $siteId, string $action, array $targets ): array {
		return self::extensionAction( $siteId, '/plugins', $action, $targets );
	}

	/**
	 * Theme-Aktion auf einer Seite (activate, delete, install).
	 *
	 * @param array<int,string> $targets
	 * @return array{ok:bool,error:string,results:array<int,array<string,mixed>>}
	 */
	public static function themeAction( int $siteId, string $action, array $targets ): array {
		return self::extensionAction( $siteId, '/themes', $action, $targets );
	}

	/**
	 * @param array<int,string> $targets
	 * @return array{ok:bool,error:string,results:array<int,array<string,mixed>>}
	 */
	private static function extensionAction( int $siteId, string $route, string $action, array $targets ): array {
		$site = SiteRepository::find( $siteId );

		if ( null === $site ) {
			return array( 'ok' => false, 'error' => 'Seite nicht gefunden.', 'results' => array() );
		}

		$allowed = array( 'activate', 'deactivate', 'delete', 'install' );
		if ( ! in_array( $action, $allowed, true ) ) {
			return array( 'ok' => false, 'error' => 'Unbekannte Aktion.', 'results' => array() );
		}
		if ( ! $targets ) {
			return array( 'ok' => false, 'error' => 'Keine Auswahl getroffen.', 'results' => array() );
		}

		$response = ChildClient::post(
			$site,
			$route,
			array( 'action' => $action, 'targets' => array_values( $targets ) ),
			(int) Config::get( 'http.update_timeout', 300 )
		);

		if ( ! $response['ok'] ) {
			return array( 'ok' => false, 'error' => $response['error'], 'results' => array() );
		}

		// Nach strukturellen Änderungen lohnt sich ein frischer Statusbericht.
		SyncService::site( $site, false );

		return array( 'ok' => true, 'error' => '', 'results' => (array) ( $response['data']['results'] ?? array() ) );
	}

	/**
	 * Teilbereich des zwischengespeicherten Statusberichts ersetzen.
	 *
	 * @param array<string,mixed> $value
	 */
	private static function mergeIntoPayload( int $siteId, string $key, array $value ): void {
		$payload = SiteRepository::payload( $siteId );

		if ( null === $payload ) {
			return;
		}

		$payload[ $key ] = $value;

		SiteRepository::storePayload( $siteId, $payload );
	}
}
