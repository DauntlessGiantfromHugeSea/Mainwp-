<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Setting;
use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\SiteRepository;

/**
 * Wartungsmodus aus der Ferne schalten.
 *
 * Der Zustand liegt maßgeblich auf der Kundenseite; das Panel spiegelt ihn nur,
 * damit die Seitenliste ihn ohne Rückfrage bei jeder Seite anzeigen kann.
 */
final class MaintenanceModeService {

	/** Vorschläge für die Dauer, in Minuten. 0 heißt "bis ich es abschalte". */
	public const DURATIONS = array(
		15   => '15 Minuten',
		30   => '30 Minuten',
		60   => '1 Stunde',
		120  => '2 Stunden',
		480  => '8 Stunden',
		1440 => '24 Stunden',
		0    => 'Bis auf Widerruf',
	);

	/**
	 * Gestaltung der Wartungsseite, wie sie in den Einstellungen hinterlegt ist.
	 *
	 * @return array<string,mixed>
	 */
	public static function design(): array {
		return array(
			'headline'    => Setting::get( 'mmode_headline', 'Wartungsmodus' ),
			'message'     => Setting::get( 'mmode_message', 'Wir sind gleich wieder da.' ),
			'color'       => Setting::get( 'mmode_color', Setting::get( 'agency_color', '#f9907a' ) ),
			'logo'        => Setting::get( 'mmode_logo', Setting::get( 'agency_logo_url', '' ) ),
			'retry_after' => (int) Setting::get( 'mmode_retry_after', '3600' ),
		);
	}

	/**
	 * Zustand von der Kundenseite holen.
	 *
	 * @return array{ok:bool,state:array<string,mixed>,error:string}
	 */
	public static function fetch( int $siteId ): array {
		$site = self::managed( $siteId );

		if ( is_string( $site ) ) {
			return array( 'ok' => false, 'state' => array(), 'error' => $site );
		}

		$response = ChildClient::post( $site, '/mmode', array( 'action' => 'get' ) );

		if ( ! $response['ok'] ) {
			return array( 'ok' => false, 'state' => array(), 'error' => $response['error'] );
		}

		$state = (array) ( $response['data']['mmode'] ?? array() );
		self::mirror( $siteId, $state );

		return array( 'ok' => true, 'state' => $state, 'error' => '' );
	}

	/**
	 * Wartungsmodus einschalten.
	 *
	 * @param int $minutes Dauer in Minuten; 0 bedeutet bis auf Widerruf.
	 */
	public static function enable( int $siteId, int $minutes = 0, array $overrides = array() ): ?string {
		$site = self::managed( $siteId );

		if ( is_string( $site ) ) {
			return $site;
		}

		$minutes  = max( 0, min( 43200, $minutes ) );
		$until    = $minutes > 0 ? time() + $minutes * 60 : 0;
		$settings = array_merge( self::design(), $overrides, array( 'enabled' => true, 'until' => $until ) );

		$response = ChildClient::post( $site, '/mmode', array( 'action' => 'set', 'settings' => $settings ) );

		if ( ! $response['ok'] ) {
			return $response['error'];
		}

		self::mirror( $siteId, (array) ( $response['data']['mmode'] ?? array() ) );

		$note = $until > 0
			? sprintf( 'Wartungsmodus eingeschaltet, endet automatisch um %s UTC.', gmdate( 'd.m.Y H:i', $until ) )
			: 'Wartungsmodus eingeschaltet, bis auf Widerruf.';

		ActivityRepository::log( 'site.mmode', $note, array( 'site_id' => $siteId, 'level' => 'warning' ) );

		EventBus::dispatch(
			'mmode.on',
			array( 'until' => $until > 0 ? gmdate( 'c', $until ) : null, 'minutes' => $minutes ),
			array(
				'site_id' => $siteId,
				'message' => sprintf( '"%s" ist im Wartungsmodus. %s', (string) $site['name'], $note ),
				'level'   => 'warning',
			)
		);

		return null;
	}

	public static function disable( int $siteId ): ?string {
		$site = self::managed( $siteId );

		if ( is_string( $site ) ) {
			return $site;
		}

		$response = ChildClient::post( $site, '/mmode', array( 'action' => 'set', 'settings' => array( 'enabled' => false, 'until' => 0 ) ) );

		if ( ! $response['ok'] ) {
			return $response['error'];
		}

		self::mirror( $siteId, (array) ( $response['data']['mmode'] ?? array() ) );

		ActivityRepository::log( 'site.mmode', 'Wartungsmodus beendet.', array( 'site_id' => $siteId ) );

		EventBus::dispatch(
			'mmode.off',
			array(),
			array(
				'site_id' => $siteId,
				'message' => sprintf( '"%s" ist wieder öffentlich erreichbar.', (string) $site['name'] ),
			)
		);

		return null;
	}

	/**
	 * Vorschau-HTML von der Kundenseite rendern lassen.
	 *
	 * @return array{ok:bool,html:string,error:string}
	 */
	public static function preview( int $siteId, array $overrides = array() ): array {
		$site = self::managed( $siteId );

		if ( is_string( $site ) ) {
			return array( 'ok' => false, 'html' => '', 'error' => $site );
		}

		$response = ChildClient::post(
			$site,
			'/mmode',
			array( 'action' => 'preview', 'settings' => array_merge( self::design(), $overrides ) )
		);

		if ( ! $response['ok'] ) {
			return array( 'ok' => false, 'html' => '', 'error' => $response['error'] );
		}

		return array( 'ok' => true, 'html' => (string) ( $response['data']['html'] ?? '' ), 'error' => '' );
	}

	/**
	 * Seiten aufräumen, deren Wartungsfenster abgelaufen ist.
	 *
	 * Die Kundenseite beendet den Modus selbst, sobald das Fenster vorbei ist.
	 * Hier wird nur der gespiegelte Stand nachgezogen, damit die Liste stimmt.
	 *
	 * @return int Anzahl der nachgezogenen Seiten.
	 */
	public static function reconcile(): int {
		$expired = SiteRepository::withExpiredMaintenance();
		$count   = 0;

		foreach ( $expired as $site ) {
			SiteRepository::update(
				(int) $site['id'],
				array( 'maintenance_mode' => 0, 'maintenance_until' => null )
			);

			ActivityRepository::log(
				'site.mmode',
				'Wartungsfenster abgelaufen — die Seite ist wieder öffentlich.',
				array( 'site_id' => (int) $site['id'] )
			);

			EventBus::dispatch(
				'mmode.off',
				array( 'reason' => 'expired' ),
				array(
					'site_id' => (int) $site['id'],
					'message' => sprintf( '"%s" ist wieder öffentlich erreichbar (Wartungsfenster abgelaufen).', (string) $site['name'] ),
				)
			);

			$count++;
		}

		return $count;
	}

	/* ------------------------------------------------------------- Intern */

	/**
	 * @param array<string,mixed> $state
	 */
	private static function mirror( int $siteId, array $state ): void {
		$until = (int) ( $state['until'] ?? 0 );

		SiteRepository::update(
			$siteId,
			array(
				'maintenance_mode'  => ! empty( $state['enabled'] ) ? 1 : 0,
				'maintenance_until' => $until > 0 ? gmdate( 'Y-m-d H:i:s', $until ) : null,
			)
		);
	}

	/**
	 * @return array<string,mixed>|string
	 */
	private static function managed( int $siteId ) {
		$site = SiteRepository::find( $siteId );

		if ( null === $site ) {
			return 'Seite nicht gefunden.';
		}
		if ( ! SiteRepository::isManaged( $site ) ) {
			return 'Diese Seite wird nur überwacht — einen Wartungsmodus kann das Panel dort nicht schalten.';
		}

		return $site;
	}
}
