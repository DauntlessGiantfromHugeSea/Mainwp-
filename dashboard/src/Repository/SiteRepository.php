<?php

declare( strict_types = 1 );

namespace NorthLab\Repository;

use NorthLab\Core\Crypto;
use NorthLab\Core\Database;

/**
 * Registry der verwalteten WordPress-Seiten.
 */
final class SiteRepository {

	/**
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		return Database::selectOne( 'SELECT * FROM `' . Database::table( 'sites' ) . '` WHERE `id` = :id', array( 'id' => $id ) );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function findByUrl( string $url ): ?array {
		return Database::selectOne(
			'SELECT * FROM `' . Database::table( 'sites' ) . '` WHERE `url` = :url',
			array( 'url' => self::normalizeUrl( $url ) )
		);
	}

	/**
	 * Findet eine Seite anhand einer beliebig geschriebenen URL.
	 *
	 * Externe Monitore melden die Adresse nicht zwingend so, wie sie hier hinterlegt ist:
	 * mal mit http statt https, mal mit "www.", mal mit Pfad oder abschliessendem Slash.
	 * Verglichen wird deshalb nur der Hostname.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function findByLooseUrl( string $url ): ?array {
		$needle = self::hostKey( $url );

		if ( '' === $needle ) {
			return null;
		}

		foreach ( Database::select( 'SELECT * FROM `' . Database::table( 'sites' ) . '`' ) as $site ) {
			if ( self::hostKey( (string) $site['url'] ) === $needle ) {
				return $site;
			}
		}

		return null;
	}

	/**
	 * Vergleichbarer Hostname: ohne Schema, ohne "www.", ohne Port, klein geschrieben.
	 */
	public static function hostKey( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) ) {
			$url = 'https://' . $url;
		}

		$host = (string) ( parse_url( $url, PHP_URL_HOST ) ?: '' );
		$host = strtolower( rtrim( $host, '.' ) );

		return preg_replace( '/^www\./', '', $host ) ?? $host;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function findByMonitorToken( string $token ): ?array {
		if ( '' === $token ) {
			return null;
		}
		return Database::selectOne(
			'SELECT * FROM `' . Database::table( 'sites' ) . '` WHERE `monitor_token` = :token',
			array( 'token' => $token )
		);
	}

	/**
	 * @param array<string,mixed> $args client_id, status, uptime_status, search, tag, orderby, order, limit, offset
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( array $args = array() ): array {
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['client_id'] ) ) {
			$where[]             = 's.client_id = :client_id';
			$params['client_id'] = (int) $args['client_id'];
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]          = 's.status = :status';
			$params['status'] = (string) $args['status'];
		}
		if ( ! empty( $args['uptime_status'] ) ) {
			$where[]                 = 's.uptime_status = :uptime_status';
			$params['uptime_status'] = (string) $args['uptime_status'];
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]          = '(s.name LIKE :search OR s.url LIKE :search)';
			$params['search'] = '%' . (string) $args['search'] . '%';
		}
		if ( ! empty( $args['tag'] ) ) {
			$where[]       = 'CONCAT(",", REPLACE(s.tags, ", ", ","), ",") LIKE :tag';
			$params['tag'] = '%,' . (string) $args['tag'] . ',%';
		}
		if ( ! empty( $args['has_updates'] ) ) {
			$where[] = 's.pending_updates > 0';
		}
		if ( isset( $args['include_paused'] ) && ! $args['include_paused'] ) {
			$where[] = 's.is_paused = 0';
		}

		$allowed = array( 'name', 'url', 'status', 'last_sync_at', 'pending_updates', 'security_score', 'created_at', 'uptime_status' );
		$orderBy = in_array( (string) ( $args['orderby'] ?? '' ), $allowed, true ) ? (string) $args['orderby'] : 'name';
		$order   = strtoupper( (string) ( $args['order'] ?? 'ASC' ) ) === 'DESC' ? 'DESC' : 'ASC';

		$limit = '';
		if ( ! empty( $args['limit'] ) ) {
			$limit = ' LIMIT ' . max( 1, (int) $args['limit'] ) . ' OFFSET ' . max( 0, (int) ( $args['offset'] ?? 0 ) );
		}

		$sql = 'SELECT s.*, c.name AS client_name
			FROM `' . Database::table( 'sites' ) . '` s
			LEFT JOIN `' . Database::table( 'clients' ) . '` c ON c.id = s.client_id
			WHERE ' . implode( ' AND ', $where ) . '
			ORDER BY s.' . $orderBy . ' ' . $order . $limit;

		return Database::select( $sql, $params );
	}

	/**
	 * Verbundene, nicht pausierte Seiten — Basis für alle Automatismen.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function active(): array {
		return Database::select(
			'SELECT * FROM `' . Database::table( 'sites' ) . "` WHERE `status` IN ('connected','error') AND `is_paused` = 0 ORDER BY `name` ASC"
		);
	}

	/**
	 * @return array<string,int>
	 */
	public static function stats(): array {
		$row = Database::selectOne(
			'SELECT
				COUNT(*) AS total,
				SUM(CASE WHEN `status` = "connected" THEN 1 ELSE 0 END) AS connected,
				SUM(CASE WHEN `status` = "error" THEN 1 ELSE 0 END) AS errored,
				SUM(CASE WHEN `uptime_status` = "down" THEN 1 ELSE 0 END) AS offline,
				SUM(`pending_updates`) AS updates,
				AVG(NULLIF(`security_score`, 0)) AS avg_security
			FROM `' . Database::table( 'sites' ) . '` WHERE `is_paused` = 0'
		) ?? array();

		return array(
			'total'        => (int) ( $row['total'] ?? 0 ),
			'connected'    => (int) ( $row['connected'] ?? 0 ),
			'errored'      => (int) ( $row['errored'] ?? 0 ),
			'offline'      => (int) ( $row['offline'] ?? 0 ),
			'updates'      => (int) ( $row['updates'] ?? 0 ),
			'avg_security' => (int) round( (float) ( $row['avg_security'] ?? 0 ) ),
		);
	}

	/**
	 * Legt eine Seite an. Die eigentliche Verbindung übernimmt der ConnectService.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function insert( array $data ): int {
		$now = nl_utc();

		return Database::insert(
			'sites',
			array(
				'client_id'          => ! empty( $data['client_id'] ) ? (int) $data['client_id'] : null,
				'name'               => (string) $data['name'],
				'url'                => (string) $data['url'],
				'connection_id'      => (string) $data['connection_id'],
				'private_key'        => (string) $data['private_key'],
				'public_key'         => (string) $data['public_key'],
				'status'             => 'pending',
				'monitor_token'      => Crypto::monitorToken(),
				'auto_update_policy' => (string) ( $data['auto_update_policy'] ?? 'inherit' ),
				'tags'               => (string) ( $data['tags'] ?? '' ),
				'notes'              => (string) ( $data['notes'] ?? '' ),
				'verify_ssl'         => empty( $data['verify_ssl'] ) ? 0 : 1,
				'http_user'          => (string) ( $data['http_user'] ?? '' ),
				'http_pass'          => (string) ( $data['http_pass'] ?? '' ),
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);
	}

	/**
	 * @param array<string,mixed> $fields
	 */
	public static function update( int $id, array $fields ): void {
		$fields['updated_at'] = nl_utc();
		Database::update( 'sites', $fields, array( 'id' => $id ) );
	}

	public static function delete( int $id ): void {
		Database::delete( 'site_data', array( 'site_id' => $id ) );
		Database::delete( 'updates', array( 'site_id' => $id ) );
		Database::delete( 'uptime_events', array( 'site_id' => $id ) );
		Database::delete( 'sites', array( 'id' => $id ) );
	}

	/* --------------------------------------------------------- Detaildaten */

	/**
	 * @return array<string,mixed>|null
	 */
	public static function payload( int $siteId ): ?array {
		$json = Database::scalar(
			'SELECT `payload` FROM `' . Database::table( 'site_data' ) . '` WHERE `site_id` = :id',
			array( 'id' => $siteId )
		);

		if ( ! is_string( $json ) || '' === $json ) {
			return null;
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public static function storePayload( int $siteId, array $payload ): void {
		Database::run(
			'INSERT INTO `' . Database::table( 'site_data' ) . '` (`site_id`, `payload`, `updated_at`)
			 VALUES (:id, :payload, :now)
			 ON DUPLICATE KEY UPDATE `payload` = VALUES(`payload`), `updated_at` = VALUES(`updated_at`)',
			array(
				'id'      => $siteId,
				'payload' => (string) json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'now'     => nl_utc(),
			)
		);
	}

	/* ----------------------------------------------------------- Werkzeuge */

	public static function rotateMonitorToken( int $id ): string {
		$token = Crypto::monitorToken();
		self::update( $id, array( 'monitor_token' => $token ) );
		return $token;
	}

	/**
	 * @param array<string,mixed> $site
	 * @return array<int,string>
	 */
	public static function tags( array $site ): array {
		$raw = (string) ( $site['tags'] ?? '' );

		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	/**
	 * @return array<int,string>
	 */
	public static function allTags(): array {
		$rows = Database::select( 'SELECT `tags` FROM `' . Database::table( 'sites' ) . "` WHERE `tags` <> ''" );

		$tags = array();
		foreach ( $rows as $row ) {
			foreach ( explode( ',', (string) $row['tags'] ) as $tag ) {
				$tag = trim( $tag );
				if ( '' !== $tag ) {
					$tags[ $tag ] = true;
				}
			}
		}

		$list = array_keys( $tags );
		sort( $list );

		return $list;
	}

	public static function normalizeUrl( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}

		return rtrim( $url, '/' );
	}
}
