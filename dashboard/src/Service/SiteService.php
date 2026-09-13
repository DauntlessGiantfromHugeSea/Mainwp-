<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Config;
use NorthLab\Core\Crypto;
use NorthLab\Core\Http;
use NorthLab\Repository\SiteRepository;

/**
 * Anlegen, Verbinden und Entfernen von Kundenseiten.
 */
final class SiteService {

	/**
	 * Legt eine Seite an und verbindet sie sofort mit dem Child-Plugin.
	 *
	 * @param array<string,mixed> $data name, url, connect_code, client_id, tags, notes, verify_ssl, http_user, http_pass
	 * @return array{0:int,1:string|null} Seiten-ID und Fehlermeldung.
	 */
	public static function createAndConnect( array $data ): array {
		$url = SiteRepository::normalizeUrl( (string) ( $data['url'] ?? '' ) );

		if ( '' === $url || ! Http::isValidUrl( $url ) ) {
			return array( 0, 'Bitte eine gültige, öffentlich erreichbare URL angeben.' );
		}
		if ( null !== SiteRepository::findByUrl( $url ) ) {
			return array( 0, 'Diese Seite ist bereits im Panel registriert.' );
		}

		$code = trim( (string) ( $data['connect_code'] ?? '' ) );
		if ( '' === $code ) {
			return array( 0, 'Bitte den Verbindungscode aus dem Child-Plugin eintragen.' );
		}

		$keys = Crypto::generateKeypair();
		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			$name = (string) ( parse_url( $url, PHP_URL_HOST ) ?: $url );
		}

		$siteId = SiteRepository::insert(
			array(
				'client_id'          => $data['client_id'] ?? null,
				'name'               => $name,
				'url'                => $url,
				'connection_id'      => Crypto::connectionId(),
				'private_key'        => Crypto::encrypt( $keys['private'] ),
				'public_key'         => $keys['public'],
				'auto_update_policy' => (string) ( $data['auto_update_policy'] ?? 'inherit' ),
				'tags'               => (string) ( $data['tags'] ?? '' ),
				'notes'              => (string) ( $data['notes'] ?? '' ),
				'verify_ssl'         => ! isset( $data['verify_ssl'] ) || $data['verify_ssl'],
				'http_user'          => (string) ( $data['http_user'] ?? '' ),
				'http_pass'          => ! empty( $data['http_pass'] ) ? Crypto::encrypt( (string) $data['http_pass'] ) : '',
			)
		);

		$site     = SiteRepository::find( $siteId );
		$response = ChildClient::connect( (array) $site, $code, $keys['public'] );

		if ( ! $response['ok'] ) {
			// Fehlgeschlagene Erstverbindung hinterlässt keine Karteileiche.
			SiteRepository::delete( $siteId );
			return array( 0, $response['error'] );
		}

		SiteRepository::update( $siteId, array( 'status' => 'connected', 'last_error' => null ) );

		if ( ! empty( $response['data']['child'] ) && is_array( $response['data']['child'] ) ) {
			SyncService::applyPayload( (array) SiteRepository::find( $siteId ), $response['data']['child'] );
		}

		EventBus::dispatch(
			'site.connected',
			array( 'url' => $url ),
			array( 'site_id' => $siteId, 'message' => sprintf( 'Seite "%s" verbunden.', $name ) )
		);

		return array( $siteId, null );
	}

	/**
	 * Verbindung mit neuem Code und frischem Schlüsselpaar erneuern.
	 */
	public static function reconnect( int $siteId, string $code ): ?string {
		$site = SiteRepository::find( $siteId );
		if ( null === $site ) {
			return 'Seite nicht gefunden.';
		}

		$code = trim( $code );
		if ( '' === $code ) {
			return 'Bitte einen Verbindungscode eintragen.';
		}

		$keys          = Crypto::generateKeypair();
		$connectionId  = Crypto::connectionId();
		$site['connection_id'] = $connectionId;

		$response = ChildClient::connect( $site, $code, $keys['public'] );

		if ( ! $response['ok'] ) {
			return $response['error'];
		}

		SiteRepository::update(
			$siteId,
			array(
				'connection_id' => $connectionId,
				'private_key'   => Crypto::encrypt( $keys['private'] ),
				'public_key'    => $keys['public'],
				'status'        => 'connected',
				'last_error'    => null,
			)
		);

		if ( ! empty( $response['data']['child'] ) && is_array( $response['data']['child'] ) ) {
			SyncService::applyPayload( (array) SiteRepository::find( $siteId ), $response['data']['child'] );
		}

		EventBus::dispatch(
			'site.connected',
			array( 'url' => $site['url'], 'reconnect' => true ),
			array( 'site_id' => $siteId, 'message' => sprintf( 'Verbindung zu "%s" erneuert.', $site['name'] ) )
		);

		return null;
	}

	/**
	 * Entfernt eine Seite und meldet sie – wenn möglich – beim Child ab.
	 */
	public static function remove( int $siteId ): bool {
		$site = SiteRepository::find( $siteId );
		if ( null === $site ) {
			return false;
		}

		if ( 'connected' === $site['status'] ) {
			// Ein Fehler ist hier unkritisch: lokal wird die Seite in jedem Fall entfernt.
			ChildClient::post( $site, '/disconnect', array(), 20 );
		}

		SiteRepository::delete( $siteId );

		EventBus::dispatch(
			'site.disconnected',
			array( 'url' => $site['url'] ),
			array( 'message' => sprintf( 'Seite "%s" entfernt.', $site['name'] ) )
		);

		return true;
	}

	/**
	 * Vom Benutzer bearbeitbare Felder aktualisieren.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function updateSettings( int $siteId, array $data ): ?string {
		if ( null === SiteRepository::find( $siteId ) ) {
			return 'Seite nicht gefunden.';
		}

		$fields = array();

		if ( isset( $data['name'] ) ) {
			$name = trim( (string) $data['name'] );
			if ( '' === $name ) {
				return 'Der Seitenname darf nicht leer sein.';
			}
			$fields['name'] = $name;
		}
		if ( array_key_exists( 'client_id', $data ) ) {
			$fields['client_id'] = ! empty( $data['client_id'] ) ? (int) $data['client_id'] : null;
		}
		if ( isset( $data['tags'] ) ) {
			$tags           = array_filter( array_map( 'trim', explode( ',', (string) $data['tags'] ) ) );
			$fields['tags'] = implode( ', ', $tags );
		}
		if ( isset( $data['notes'] ) ) {
			$fields['notes'] = trim( (string) $data['notes'] );
		}
		if ( isset( $data['auto_update_policy'] ) ) {
			$allowed                      = array( 'inherit', 'off', 'security', 'minor', 'all' );
			$policy                       = (string) $data['auto_update_policy'];
			$fields['auto_update_policy'] = in_array( $policy, $allowed, true ) ? $policy : 'inherit';
		}
		if ( array_key_exists( 'is_paused', $data ) ) {
			$fields['is_paused'] = empty( $data['is_paused'] ) ? 0 : 1;
		}
		if ( array_key_exists( 'verify_ssl', $data ) ) {
			$fields['verify_ssl'] = empty( $data['verify_ssl'] ) ? 0 : 1;
		}
		if ( isset( $data['http_user'] ) ) {
			$fields['http_user'] = trim( (string) $data['http_user'] );
		}
		if ( ! empty( $data['http_pass'] ) ) {
			$fields['http_pass'] = Crypto::encrypt( (string) $data['http_pass'] );
		}

		if ( $fields ) {
			SiteRepository::update( $siteId, $fields );
		}

		return null;
	}

	/**
	 * Webhook-URL, die im externen Monitoring hinterlegt wird.
	 *
	 * @param array<string,mixed> $site
	 */
	public static function monitorUrl( array $site ): string {
		return rtrim( (string) Config::get( 'app.url', '' ), '/' ) . '/api/uptime/' . (string) $site['monitor_token'];
	}
}
