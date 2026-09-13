<?php

declare( strict_types = 1 );

namespace NorthLab\Repository;

use NorthLab\Core\Database;

/**
 * Speicherung und Auswertung der Verfügbarkeitsdaten.
 */
final class UptimeRepository {

	/**
	 * @param array<string,mixed> $meta
	 */
	public static function insertEvent( int $siteId, string $status, array $meta = array() ): void {
		Database::insert(
			'uptime_events',
			array(
				'site_id'     => $siteId,
				'status'      => 'up' === $status ? 'up' : 'down',
				'source'      => substr( (string) ( $meta['source'] ?? 'webhook' ), 0, 40 ),
				'http_code'   => (int) ( $meta['http_code'] ?? 0 ),
				'response_ms' => (int) ( $meta['response_ms'] ?? 0 ),
				'message'     => substr( (string) ( $meta['message'] ?? '' ), 0, 255 ),
				'raw'         => isset( $meta['raw'] ) ? json_encode( $meta['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : null,
				'occurred_at' => (string) ( $meta['occurred_at'] ?? nl_utc() ),
			)
		);
	}

	/**
	 * Verfügbarkeit über die Statuswechsel eines Zeitraums.
	 *
	 * @return array{percent:float,downtime_seconds:int,incidents:int,window_seconds:int,avg_response_ms:int}
	 */
	public static function availability( int $siteId, string $from, string $to ): array {
		$fromTs = (int) strtotime( $from . ' UTC' );
		$toTs   = (int) strtotime( $to . ' UTC' );
		$window = max( 1, $toTs - $fromTs );

		$events = Database::select(
			'SELECT `status`, `occurred_at` FROM `' . Database::table( 'uptime_events' ) . '`
			 WHERE `site_id` = :id AND `occurred_at` BETWEEN :from AND :to
			 ORDER BY `occurred_at` ASC, `id` ASC',
			array( 'id' => $siteId, 'from' => $from, 'to' => $to )
		);

		// Zustand zu Fensterbeginn: letztes Ereignis davor.
		$initial = (string) ( Database::scalar(
			'SELECT `status` FROM `' . Database::table( 'uptime_events' ) . '`
			 WHERE `site_id` = :id AND `occurred_at` < :from
			 ORDER BY `occurred_at` DESC, `id` DESC LIMIT 1',
			array( 'id' => $siteId, 'from' => $from )
		) ?: 'up' );

		$state     = 'down' === $initial ? 'down' : 'up';
		$cursor    = $fromTs;
		$downtime  = 0;
		$incidents = 'down' === $state ? 1 : 0;

		foreach ( $events as $event ) {
			$at = (int) strtotime( (string) $event['occurred_at'] . ' UTC' );

			if ( 'down' === $state ) {
				$downtime += max( 0, $at - $cursor );
			}
			if ( (string) $event['status'] !== $state ) {
				if ( 'down' === $event['status'] ) {
					$incidents++;
				}
				$state = (string) $event['status'];
			}
			$cursor = $at;
		}

		if ( 'down' === $state ) {
			$downtime += max( 0, $toTs - $cursor );
		}

		$downtime = min( $downtime, $window );

		$avgResponse = (int) round( (float) Database::scalar(
			'SELECT AVG(NULLIF(`response_ms`, 0)) FROM `' . Database::table( 'uptime_events' ) . '`
			 WHERE `site_id` = :id AND `occurred_at` BETWEEN :from AND :to',
			array( 'id' => $siteId, 'from' => $from, 'to' => $to )
		) );

		return array(
			'percent'          => round( ( ( $window - $downtime ) / $window ) * 100, 3 ),
			'downtime_seconds' => $downtime,
			'incidents'        => $incidents,
			'window_seconds'   => $window,
			'avg_response_ms'  => $avgResponse,
		);
	}

	/**
	 * Störungen (down bis wieder up) eines Zeitraums.
	 *
	 * @return array<int,array{started_at:string,ended_at:string|null,seconds:int,reason:string,source:string}>
	 */
	public static function incidents( int $siteId, string $from, string $to ): array {
		$events = Database::select(
			'SELECT `status`, `message`, `source`, `occurred_at` FROM `' . Database::table( 'uptime_events' ) . '`
			 WHERE `site_id` = :id AND `occurred_at` BETWEEN :from AND :to
			 ORDER BY `occurred_at` ASC, `id` ASC',
			array( 'id' => $siteId, 'from' => $from, 'to' => $to )
		);

		$incidents = array();
		$open      = null;

		foreach ( $events as $event ) {
			if ( 'down' === $event['status'] && null === $open ) {
				$open = array(
					'started_at' => (string) $event['occurred_at'],
					'ended_at'   => null,
					'seconds'    => 0,
					'reason'     => (string) $event['message'],
					'source'     => (string) $event['source'],
				);
			} elseif ( 'up' === $event['status'] && null !== $open ) {
				$open['ended_at'] = (string) $event['occurred_at'];
				$open['seconds']  = max(
					0,
					(int) strtotime( (string) $event['occurred_at'] . ' UTC' ) - (int) strtotime( $open['started_at'] . ' UTC' )
				);
				$incidents[]      = $open;
				$open             = null;
			}
		}

		if ( null !== $open ) {
			$open['seconds'] = max( 0, time() - (int) strtotime( $open['started_at'] . ' UTC' ) );
			$incidents[]     = $open;
		}

		return array_reverse( $incidents );
	}

	/**
	 * Tagesweise Verfügbarkeit für die Sparkline-Darstellung.
	 *
	 * @return array<int,array{day:string,percent:float,downtime:int}>
	 */
	public static function dailySeries( int $siteId, int $days = 30 ): array {
		$series = array();

		for ( $offset = $days - 1; $offset >= 0; $offset-- ) {
			$dayStart = gmdate( 'Y-m-d 00:00:00', time() - $offset * 86400 );
			$dayEnd   = gmdate( 'Y-m-d 23:59:59', time() - $offset * 86400 );

			$stats = self::availability( $siteId, $dayStart, $dayEnd );

			$series[] = array(
				'day'      => gmdate( 'Y-m-d', time() - $offset * 86400 ),
				'percent'  => $stats['percent'],
				'downtime' => $stats['downtime_seconds'],
			);
		}

		return $series;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( int $siteId, int $limit = 50 ): array {
		return Database::select(
			'SELECT * FROM `' . Database::table( 'uptime_events' ) . '`
			 WHERE `site_id` = :id ORDER BY `occurred_at` DESC, `id` DESC LIMIT ' . max( 1, min( 500, $limit ) ),
			array( 'id' => $siteId )
		);
	}

	/**
	 * Letzte Ereignisse über alle Seiten.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function feed( int $limit = 100 ): array {
		return Database::select(
			'SELECT e.*, s.name AS site_name, s.url AS site_url
			 FROM `' . Database::table( 'uptime_events' ) . '` e
			 INNER JOIN `' . Database::table( 'sites' ) . '` s ON s.id = e.site_id
			 ORDER BY e.occurred_at DESC, e.id DESC LIMIT ' . max( 1, min( 500, $limit ) )
		);
	}

	public static function prune( int $days ): int {
		return Database::run(
			'DELETE FROM `' . Database::table( 'uptime_events' ) . '` WHERE `occurred_at` < :cutoff',
			array( 'cutoff' => gmdate( 'Y-m-d H:i:s', time() - max( 7, $days ) * 86400 ) )
		)->rowCount();
	}
}
