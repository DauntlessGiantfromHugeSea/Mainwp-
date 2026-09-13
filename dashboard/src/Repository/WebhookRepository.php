<?php

declare( strict_types = 1 );

namespace NorthLab\Repository;

use NorthLab\Core\Crypto;
use NorthLab\Core\Database;
use NorthLab\Core\Http;
use NorthLab\Service\EventBus;

final class WebhookRepository {

	/**
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		return Database::selectOne( 'SELECT * FROM `' . Database::table( 'webhooks' ) . '` WHERE `id` = :id', array( 'id' => $id ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		return Database::select(
			'SELECT w.*, c.name AS client_name
			 FROM `' . Database::table( 'webhooks' ) . '` w
			 LEFT JOIN `' . Database::table( 'clients' ) . '` c ON c.id = w.client_id
			 ORDER BY w.name ASC'
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function active(): array {
		return Database::select( 'SELECT * FROM `' . Database::table( 'webhooks' ) . '` WHERE `is_active` = 1' );
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array{0:int,1:string|null}
	 */
	public static function create( array $data ): array {
		$url = trim( (string) ( $data['target_url'] ?? '' ) );
		if ( ! Http::isValidUrl( $url ) ) {
			return array( 0, 'Bitte eine gültige Ziel-URL (http/https) angeben.' );
		}

		$events = self::sanitizeEvents( (array) ( $data['events'] ?? array() ) );
		if ( ! $events ) {
			return array( 0, 'Bitte mindestens ein Ereignis auswählen.' );
		}

		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			$name = (string) ( parse_url( $url, PHP_URL_HOST ) ?: 'Webhook' );
		}

		$id = Database::insert(
			'webhooks',
			array(
				'name'       => $name,
				'target_url' => $url,
				'secret'     => trim( (string) ( $data['secret'] ?? '' ) ) ?: Crypto::secret( 24 ),
				'events'     => json_encode( $events ),
				'client_id'  => ! empty( $data['client_id'] ) ? (int) $data['client_id'] : null,
				'is_active'  => empty( $data['is_active'] ) ? 0 : 1,
				'created_at' => nl_utc(),
			)
		);

		return array( $id, null );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function update( int $id, array $data ): ?string {
		if ( null === self::find( $id ) ) {
			return 'Webhook nicht gefunden.';
		}

		$fields = array();

		if ( isset( $data['name'] ) ) {
			$fields['name'] = trim( (string) $data['name'] );
		}
		if ( isset( $data['target_url'] ) ) {
			$url = trim( (string) $data['target_url'] );
			if ( ! Http::isValidUrl( $url ) ) {
				return 'Bitte eine gültige Ziel-URL (http/https) angeben.';
			}
			$fields['target_url'] = $url;
		}
		if ( ! empty( $data['secret'] ) ) {
			$fields['secret'] = trim( (string) $data['secret'] );
		}
		if ( isset( $data['events'] ) ) {
			$events = self::sanitizeEvents( (array) $data['events'] );
			if ( ! $events ) {
				return 'Bitte mindestens ein Ereignis auswählen.';
			}
			$fields['events'] = json_encode( $events );
		}
		if ( array_key_exists( 'client_id', $data ) ) {
			$fields['client_id'] = ! empty( $data['client_id'] ) ? (int) $data['client_id'] : null;
		}
		if ( array_key_exists( 'is_active', $data ) ) {
			$fields['is_active'] = empty( $data['is_active'] ) ? 0 : 1;
		}

		if ( $fields ) {
			Database::update( 'webhooks', $fields, array( 'id' => $id ) );
		}

		return null;
	}

	public static function delete( int $id ): void {
		Database::delete( 'webhook_deliveries', array( 'webhook_id' => $id ) );
		Database::delete( 'webhooks', array( 'id' => $id ) );
	}

	/**
	 * @param array<string,mixed> $webhook
	 * @return array<int,string>
	 */
	public static function events( array $webhook ): array {
		$decoded = json_decode( (string) ( $webhook['events'] ?? '[]' ), true );
		return is_array( $decoded ) ? array_map( 'strval', $decoded ) : array();
	}

	/**
	 * @param array<int,mixed> $events
	 * @return array<int,string>
	 */
	private static function sanitizeEvents( array $events ): array {
		$catalog = array_keys( EventBus::catalog() );
		$clean   = array();

		foreach ( $events as $event ) {
			$event = (string) $event;
			if ( in_array( $event, $catalog, true ) && ! in_array( $event, $clean, true ) ) {
				$clean[] = $event;
			}
		}

		return $clean;
	}

	/* ------------------------------------------------------ Zustellungen */

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function deliveries( int $webhookId = 0, int $limit = 50 ): array {
		$limit = max( 1, min( 200, $limit ) );

		if ( $webhookId > 0 ) {
			return Database::select(
				'SELECT d.*, w.name AS webhook_name FROM `' . Database::table( 'webhook_deliveries' ) . '` d
				 LEFT JOIN `' . Database::table( 'webhooks' ) . '` w ON w.id = d.webhook_id
				 WHERE d.webhook_id = :id ORDER BY d.id DESC LIMIT ' . $limit,
				array( 'id' => $webhookId )
			);
		}

		return Database::select(
			'SELECT d.*, w.name AS webhook_name FROM `' . Database::table( 'webhook_deliveries' ) . '` d
			 LEFT JOIN `' . Database::table( 'webhooks' ) . '` w ON w.id = d.webhook_id
			 ORDER BY d.id DESC LIMIT ' . $limit
		);
	}

	/**
	 * @return array<string,int>
	 */
	public static function queueStats(): array {
		$rows = Database::select(
			'SELECT `status`, COUNT(*) AS total FROM `' . Database::table( 'webhook_deliveries' ) . '` GROUP BY `status`'
		);

		$stats = array( 'pending' => 0, 'success' => 0, 'failed' => 0 );
		foreach ( $rows as $row ) {
			$stats[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $stats;
	}

	public static function retry( int $deliveryId ): void {
		Database::update(
			'webhook_deliveries',
			array(
				'status'          => 'pending',
				'attempts'        => 0,
				'next_attempt_at' => nl_utc(),
				'updated_at'      => nl_utc(),
			),
			array( 'id' => $deliveryId )
		);
	}

	public static function prune( int $days = 30 ): int {
		return Database::run(
			'DELETE FROM `' . Database::table( 'webhook_deliveries' ) . "`
			 WHERE `status` <> 'pending' AND `updated_at` < :cutoff",
			array( 'cutoff' => gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * 86400 ) )
		)->rowCount();
	}
}
