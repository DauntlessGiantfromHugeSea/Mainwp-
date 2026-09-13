<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Repository\SiteRepository;
use NorthLab\Repository\UptimeRepository;

/**
 * Verarbeitung von Verfügbarkeitsmeldungen.
 */
final class UptimeService {

	/**
	 * Ereignis speichern und bei Statuswechsel ein Event auslösen.
	 *
	 * @param array<string,mixed> $meta source, http_code, response_ms, message, raw, occurred_at
	 * @return bool True bei Statuswechsel.
	 */
	public static function record( int $siteId, string $status, array $meta = array() ): bool {
		$site = SiteRepository::find( $siteId );
		if ( null === $site ) {
			return false;
		}

		$status = 'up' === $status ? 'up' : 'down';
		$when   = (string) ( $meta['occurred_at'] ?? nl_utc() );

		UptimeRepository::insertEvent( $siteId, $status, $meta + array( 'occurred_at' => $when ) );

		$previous = (string) $site['uptime_status'];
		if ( $previous === $status ) {
			return false;
		}

		SiteRepository::update( $siteId, array( 'uptime_status' => $status, 'uptime_since' => $when ) );

		if ( 'down' === $status ) {
			EventBus::dispatch(
				'site.offline',
				array(
					'source'    => (string) ( $meta['source'] ?? 'webhook' ),
					'http_code' => (int) ( $meta['http_code'] ?? 0 ),
					'reason'    => (string) ( $meta['message'] ?? '' ),
				),
				array(
					'site_id' => $siteId,
					'message' => sprintf( '"%s" ist nicht erreichbar.', $site['name'] ),
				)
			);

			return true;
		}

		$downtime = ( 'down' === $previous && ! empty( $site['uptime_since'] ) )
			? max( 0, (int) strtotime( $when . ' UTC' ) - (int) strtotime( (string) $site['uptime_since'] . ' UTC' ) )
			: 0;

		EventBus::dispatch(
			'site.online',
			array(
				'source'           => (string) ( $meta['source'] ?? 'webhook' ),
				'downtime_seconds' => $downtime,
			),
			array(
				'site_id' => $siteId,
				'message' => $downtime > 0
					? sprintf( '"%s" ist wieder online (Ausfall: %s).', $site['name'], nl_duration( $downtime ) )
					: sprintf( '"%s" ist erreichbar.', $site['name'] ),
			)
		);

		return true;
	}

	/**
	 * Erfolgreiche Kommunikation als "erreichbar" verbuchen, ohne die Tabelle zu fluten.
	 */
	public static function markReachable( int $siteId ): void {
		$site = SiteRepository::find( $siteId );
		if ( null === $site ) {
			return;
		}

		if ( 'unknown' === $site['uptime_status'] ) {
			SiteRepository::update( $siteId, array( 'uptime_status' => 'up', 'uptime_since' => nl_utc() ) );
			return;
		}

		if ( 'up' !== $site['uptime_status'] ) {
			self::record( $siteId, 'up', array( 'source' => 'sync' ) );
		}
	}

	/**
	 * Erkennt das Format gängiger Monitoring-Dienste und normalisiert es.
	 *
	 * Unterstützt: generisches JSON, UptimeRobot, Better Stack, Uptime Kuma,
	 * Pingdom, StatusCake und HetrixTools.
	 *
	 * @param array<string,mixed> $body
	 * @return array{status:string,source:string,http_code:int,response_ms:int,message:string}|null
	 */
	public static function normalize( array $body ): ?array {
		// Generisch: {"status":"up"} — hat Vorrang, wenn eindeutig.
		if ( isset( $body['status'] ) && is_scalar( $body['status'] ) && ! isset( $body['heartbeat'] ) ) {
			$status = self::interpret( (string) $body['status'] );
			if ( null !== $status ) {
				return self::result(
					$status,
					(string) ( $body['source'] ?? 'generic' ),
					(int) ( $body['http_code'] ?? $body['status_code'] ?? 0 ),
					(int) ( $body['response_ms'] ?? $body['response_time'] ?? 0 ),
					(string) ( $body['message'] ?? $body['reason'] ?? '' )
				);
			}
		}

		// UptimeRobot: alertType 1 = down, 2 = up.
		if ( isset( $body['alertType'] ) || isset( $body['alerttype'] ) ) {
			$type = (int) ( $body['alertType'] ?? $body['alerttype'] );
			return self::result(
				2 === $type ? 'up' : 'down',
				'uptimerobot',
				0,
				0,
				(string) ( $body['alertDetails'] ?? $body['alertTypeFriendlyName'] ?? '' )
			);
		}

		// Better Stack.
		if ( isset( $body['data']['attributes']['status'] ) ) {
			return self::result(
				self::interpret( (string) $body['data']['attributes']['status'] ) ?? 'down',
				'betterstack',
				(int) ( $body['data']['attributes']['response_code'] ?? 0 ),
				0,
				(string) ( $body['data']['attributes']['cause'] ?? '' )
			);
		}

		// Uptime Kuma.
		if ( isset( $body['heartbeat']['status'] ) ) {
			return self::result(
				1 === (int) $body['heartbeat']['status'] ? 'up' : 'down',
				'uptimekuma',
				0,
				(int) ( $body['heartbeat']['ping'] ?? 0 ),
				(string) ( $body['heartbeat']['msg'] ?? '' )
			);
		}

		// Pingdom.
		if ( isset( $body['current_state'] ) ) {
			return self::result(
				self::interpret( (string) $body['current_state'] ) ?? 'down',
				'pingdom',
				0,
				0,
				(string) ( $body['long_description'] ?? $body['description'] ?? '' )
			);
		}

		// StatusCake.
		if ( isset( $body['Status'] ) ) {
			return self::result(
				self::interpret( (string) $body['Status'] ) ?? 'down',
				'statuscake',
				(int) ( $body['StatusCode'] ?? 0 ),
				0,
				(string) ( $body['Name'] ?? '' )
			);
		}

		// HetrixTools.
		if ( isset( $body['monitor_status'] ) ) {
			return self::result(
				self::interpret( (string) $body['monitor_status'] ) ?? 'down',
				'hetrixtools',
				0,
				0,
				is_scalar( $body['monitor_errors'] ?? null ) ? (string) $body['monitor_errors'] : ''
			);
		}

		return null;
	}

	private static function interpret( string $raw ): ?string {
		$value = strtolower( trim( $raw ) );

		$up   = array( 'up', 'online', 'ok', 'operational', 'resolved', 'recovered', 'available', 'success', '1', 'true' );
		$down = array( 'down', 'offline', 'error', 'critical', 'failed', 'failing', 'triggered', 'unavailable', 'paused', '0', 'false' );

		if ( in_array( $value, $up, true ) ) {
			return 'up';
		}
		if ( in_array( $value, $down, true ) ) {
			return 'down';
		}

		return null;
	}

	/**
	 * @return array{status:string,source:string,http_code:int,response_ms:int,message:string}
	 */
	private static function result( string $status, string $source, int $httpCode, int $responseMs, string $message ): array {
		return array(
			'status'      => $status,
			'source'      => substr( $source, 0, 40 ),
			'http_code'   => $httpCode,
			'response_ms' => $responseMs,
			'message'     => $message,
		);
	}
}
