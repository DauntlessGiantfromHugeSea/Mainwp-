<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\SiteRepository;

/**
 * Die Link-Pruefung der Kundenseiten anstossen und abfragen.
 *
 * Gepruft wird auf der Kundenseite selbst, in kleinen Etappen ueber deren
 * WP-Cron. Das Panel stoesst nur an und liest das Ergebnis — sonst laege die
 * Last fuer alle Seiten auf dem einen Server, auf dem ohnehin schon die
 * Sicherungen laufen.
 */
final class LinkService {

	/**
	 * Stand und letztes Ergebnis einer Seite holen.
	 *
	 * @return array{ok:bool,error:string,progress:array<string,mixed>,result:array<string,mixed>|null,config:array<string,mixed>}
	 */
	public static function fetch( int $siteId ): array {
		$site = self::managed( $siteId );

		if ( is_string( $site ) ) {
			return self::answer( false, $site );
		}

		$response = ChildClient::post( $site, '/links', array( 'action' => 'status' ) );

		if ( ! $response['ok'] ) {
			return self::answer( false, $response['error'] );
		}

		return array(
			'ok'       => true,
			'error'    => '',
			'progress' => (array) ( $response['data']['progress'] ?? array() ),
			'result'   => is_array( $response['data']['result'] ?? null ) ? (array) $response['data']['result'] : null,
			'config'   => (array) ( $response['data']['config'] ?? array() ),
		);
	}

	/**
	 * Einen Durchgang anstossen.
	 */
	public static function scan( int $siteId ): ?string {
		$site = self::managed( $siteId );

		if ( is_string( $site ) ) {
			return $site;
		}

		$response = ChildClient::post( $site, '/links', array( 'action' => 'scan' ) );

		if ( ! $response['ok'] ) {
			return $response['error'];
		}

		ActivityRepository::log(
			'site.links',
			'Link-Prüfung gestartet — sie läuft auf der Kundenseite in Etappen weiter.',
			array( 'site_id' => $siteId )
		);

		return null;
	}

	/**
	 * Eine Etappe von aussen antreiben.
	 *
	 * Nur fuer Seiten ohne laufenden WP-Cron noetig. Dort bliebe ein
	 * angestossener Durchgang sonst fuer immer in der ersten Etappe stehen.
	 */
	public static function step( int $siteId ): ?string {
		$site = self::managed( $siteId );

		if ( is_string( $site ) ) {
			return $site;
		}

		$response = ChildClient::post( $site, '/links', array( 'action' => 'step' ), 120 );

		return $response['ok'] ? null : $response['error'];
	}

	/**
	 * Einstellungen der Pruefung setzen.
	 *
	 * @param array<string,mixed> $settings
	 */
	public static function configure( int $siteId, array $settings ): ?string {
		$site = self::managed( $siteId );

		if ( is_string( $site ) ) {
			return $site;
		}

		$response = ChildClient::post( $site, '/links', array( 'action' => 'config', 'settings' => $settings ) );

		return $response['ok'] ? null : $response['error'];
	}

	/**
	 * @return array{ok:bool,error:string,progress:array<string,mixed>,result:null,config:array<string,mixed>}
	 */
	private static function answer( bool $ok, string $error ): array {
		return array( 'ok' => $ok, 'error' => $error, 'progress' => array(), 'result' => null, 'config' => array() );
	}

	/**
	 * @return array<string,mixed>|string
	 */
	private static function managed( int $siteId ) {
		$site = SiteRepository::find( $siteId );

		if ( null === $site ) {
			return 'Seite nicht gefunden.';
		}

		return ChildFeature::unavailable( $site, 'links' ) ?? $site;
	}
}
