<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\SiteRepository;

/**
 * Das Child-Plugin auf den Kundenseiten aktuell halten.
 *
 * Der Normalfall braucht diesen Dienst gar nicht: die Kundenseite fragt das
 * Panel selbst nach neuen Versionen und meldet sie in der Update-Zentrale wie
 * jedes andere Plugin. Hier liegt der direkte Weg für den Fall, dass es sofort
 * passieren soll — ohne auf den Zwischenspeicher der Kundenseite zu warten.
 */
final class ChildPluginService {

	/**
	 * Version, die das Panel ausliefert.
	 */
	public static function shipped(): string {
		return ChildPackager::version();
	}

	/**
	 * @param array<string,mixed> $site
	 */
	public static function isOutdated( array $site ): bool {
		if ( ! SiteRepository::isManaged( $site ) ) {
			return false;
		}

		$installed = trim( (string) ( $site['child_version'] ?? '' ) );

		// Ohne bekannte Version lässt sich nichts vergleichen — erst synchronisieren.
		if ( '' === $installed ) {
			return false;
		}

		return version_compare( $installed, self::shipped(), '<' );
	}

	/**
	 * Kundenseite nachsehen lassen, ohne zu installieren.
	 *
	 * @return array{ok:bool,message:string,installed:string,available:?string}
	 */
	public static function check( int $siteId ): array {
		return self::call( $siteId, 'check' );
	}

	/**
	 * Update anstoßen.
	 *
	 * @return array{ok:bool,message:string,installed:string,available:?string}
	 */
	public static function update( int $siteId ): array {
		return self::call( $siteId, 'run' );
	}

	/**
	 * Alle Seiten mit veralteter Version nachziehen.
	 *
	 * @param array<int,int>|null $siteIds Sichtbarkeitsfilter.
	 * @return array<int,array{site:string,success:bool,message:string}>
	 */
	public static function updateAll( ?array $siteIds = null ): array {
		$results = array();

		foreach ( SiteRepository::active() as $site ) {
			if ( null !== $siteIds && ! in_array( (int) $site['id'], $siteIds, true ) ) {
				continue;
			}
			if ( ! self::isOutdated( $site ) ) {
				continue;
			}

			$result    = self::update( (int) $site['id'] );
			$results[] = array(
				'site'    => (string) $site['name'],
				'success' => $result['ok'],
				'message' => $result['message'],
			);
		}

		return $results;
	}

	/**
	 * @return array{ok:bool,message:string,installed:string,available:?string}
	 */
	private static function call( int $siteId, string $action ): array {
		$site = SiteRepository::find( $siteId );

		if ( null === $site ) {
			return self::failure( 'Seite nicht gefunden.' );
		}
		if ( ! SiteRepository::isManaged( $site ) ) {
			return self::failure( 'Diese Seite wird nur überwacht — dort läuft kein Child-Plugin.' );
		}

		// Grosszuegig: die Kundenseite laedt das Paket und packt es aus.
		$response = ChildClient::post( $site, '/self-update', array( 'action' => $action ), 180 );

		if ( ! $response['ok'] ) {
			// Vor 1.3.0 gab es die Route nicht — das ist kein Fehler des Panels.
			$hint = str_contains( $response['error'], '404' )
				? ' Auf dieser Seite läuft noch eine Child-Version ohne Selbst-Update. '
					. 'Einmal von Hand aktualisieren, danach geht es aus der Ferne.'
				: '';

			return self::failure( $response['error'] . $hint );
		}

		$result = (array) ( $response['data']['result'] ?? array() );

		$installed = (string) ( $result['version'] ?? ( $site['child_version'] ?? '' ) );
		$available = isset( $result['available'] ) ? (string) $result['available'] : null;

		if ( ! empty( $result['updated'] ) ) {
			SiteRepository::update( $siteId, array( 'child_version' => $installed ) );

			ActivityRepository::log(
				'plugin.child_updated',
				sprintf(
					'Child-Plugin von %s auf %s aktualisiert.',
					(string) ( $result['previous'] ?? '?' ),
					$installed
				),
				array( 'site_id' => $siteId )
			);

			EventBus::dispatch(
				'child.updated',
				array( 'from' => (string) ( $result['previous'] ?? '' ), 'to' => $installed ),
				array(
					'site_id' => $siteId,
					'message' => sprintf( 'Child-Plugin auf "%s" ist jetzt %s.', (string) $site['name'], $installed ),
				)
			);
		}

		return array(
			'ok'        => ! empty( $result['ok'] ),
			'message'   => (string) ( $result['message'] ?? 'Keine Rückmeldung.' ),
			'installed' => $installed,
			'available' => $available,
		);
	}

	/**
	 * @return array{ok:bool,message:string,installed:string,available:?string}
	 */
	private static function failure( string $message ): array {
		return array( 'ok' => false, 'message' => $message, 'installed' => '', 'available' => null );
	}
}
