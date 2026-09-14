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

	/** Einmal je Anfrage gelesen — die Seitenliste fragt sonst je Zeile die Datei. */
	private static ?string $shipped = null;

	/**
	 * Version, die das Panel ausliefert.
	 */
	public static function shipped(): string {
		return self::$shipped ??= ChildPackager::version();
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

		$blocked = ChildFeature::unavailable( $site, 'selfupdate' );

		if ( null !== $blocked ) {
			return self::failure( $blocked );
		}

		// Grosszuegig: die Kundenseite laedt das Paket und packt es aus.
		$response = ChildClient::post( $site, '/self-update', array( 'action' => $action ), 180 );

		if ( ! $response['ok'] ) {
			return self::failure( $response['error'] );
		}

		$result = (array) ( $response['data']['result'] ?? array() );

		$installed = (string) ( $result['version'] ?? ( $site['child_version'] ?? '' ) );
		$available = isset( $result['available'] ) ? (string) $result['available'] : null;

		// Die Seite hat gerade gesagt, welche Version dort laeuft. Das gilt, ob
		// dabei ein Update stattfand oder nicht — sonst behauptet das Panel
		// weiter "veraltet", obwohl es die Wahrheit soeben erfahren hat.
		self::remember( $siteId, $site, $installed );

		if ( ! empty( $result['updated'] ) ) {
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
	 * Gemeldete Version uebernehmen, wenn sie plausibel ist und sich geaendert hat.
	 *
	 * @param array<string,mixed> $site
	 */
	private static function remember( int $siteId, array $site, string $reported ): void {
		$store = self::versionToStore( $reported, (string) ( $site['child_version'] ?? '' ) );

		if ( null === $store ) {
			return;
		}

		SiteRepository::update( $siteId, array( 'child_version' => $store ) );
	}

	/**
	 * Was von einer gemeldeten Version zu übernehmen ist.
	 *
	 * @param string $reported Was die Kundenseite gesagt hat.
	 * @param string $current  Was das Panel bisher gespeichert hat.
	 * @return string|null Zu speichernder Wert, oder null wenn nichts zu tun ist.
	 */
	public static function versionToStore( string $reported, string $current ): ?string {
		$reported = trim( $reported );

		// Nur etwas übernehmen, das wie eine Versionsnummer aussieht — sonst
		// landet eine Fehlermeldung in der Spalte und alle Vergleiche kippen.
		if ( '' === $reported || ! preg_match( '/^\d+(\.\d+){0,3}(-[0-9A-Za-z.]+)?$/', $reported ) ) {
			return null;
		}

		return $reported === trim( $current ) ? null : $reported;
	}

	/**
	 * @return array{ok:bool,message:string,installed:string,available:?string}
	 */
	private static function failure( string $message ): array {
		return array( 'ok' => false, 'message' => $message, 'installed' => '', 'available' => null );
	}
}
