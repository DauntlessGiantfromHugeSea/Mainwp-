<?php

declare( strict_types = 1 );

namespace NorthLab\Repository;

use NorthLab\Core\Database;

/**
 * Inventar der auf allen Seiten verfügbaren Updates.
 */
final class UpdateRepository {

	/**
	 * Ersetzt das Inventar einer Seite. Ignoriert-Markierungen bleiben erhalten.
	 *
	 * @param array<string,mixed> $updates Rohantwort des Child-Plugins.
	 */
	public static function replaceForSite( int $siteId, array $updates ): void {
		$ignored = self::ignoredKeys( $siteId );

		Database::delete( 'updates', array( 'site_id' => $siteId ) );

		$now = nl_utc();

		foreach ( self::flatten( $updates ) as $item ) {
			if ( '' === $item['slug'] ) {
				continue;
			}

			Database::insert(
				'updates',
				array(
					'site_id'         => $siteId,
					'type'            => $item['type'],
					'slug'            => $item['slug'],
					'name'            => $item['name'],
					'current_version' => $item['current_version'],
					'new_version'     => $item['new_version'],
					'is_ignored'      => isset( $ignored[ $item['type'] . '|' . $item['slug'] ] ) ? 1 : 0,
					'detected_at'     => $now,
				)
			);
		}
	}

	/**
	 * Normalisiert die verschachtelte Child-Antwort in eine flache Liste.
	 *
	 * @param array<string,mixed> $updates
	 * @return array<int,array{type:string,slug:string,name:string,current_version:string,new_version:string}>
	 */
	public static function flatten( array $updates ): array {
		$out = array();

		foreach ( array( 'core' => 'core', 'plugins' => 'plugin', 'themes' => 'theme' ) as $group => $type ) {
			foreach ( (array) ( $updates[ $group ] ?? array() ) as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$out[] = array(
					'type'            => (string) ( $entry['type'] ?? $type ),
					'slug'            => (string) ( $entry['slug'] ?? '' ),
					'name'            => (string) ( $entry['name'] ?? $entry['slug'] ?? '' ),
					'current_version' => (string) ( $entry['current_version'] ?? '' ),
					'new_version'     => (string) ( $entry['new_version'] ?? '' ),
				);
			}
		}

		return $out;
	}

	/**
	 * @return array<string,bool>
	 */
	private static function ignoredKeys( int $siteId ): array {
		$rows = Database::select(
			'SELECT `type`, `slug` FROM `' . Database::table( 'updates' ) . '` WHERE `site_id` = :id AND `is_ignored` = 1',
			array( 'id' => $siteId )
		);

		$keys = array();
		foreach ( $rows as $row ) {
			$keys[ $row['type'] . '|' . $row['slug'] ] = true;
		}

		return $keys;
	}

	/**
	 * @param array<string,mixed> $args site_id, client_id, type, include_ignored, search
	 * @return array<int,array<string,mixed>>
	 */
	public static function query( array $args = array() ): array {
		$where  = array( 's.is_paused = 0' );
		$params = array();

		if ( ! empty( $args['site_id'] ) ) {
			$where[]           = 'u.site_id = :site_id';
			$params['site_id'] = (int) $args['site_id'];
		}
		if ( ! empty( $args['client_id'] ) ) {
			$where[]             = 's.client_id = :client_id';
			$params['client_id'] = (int) $args['client_id'];
		}
		if ( ! empty( $args['type'] ) ) {
			$where[]        = 'u.type = :type';
			$params['type'] = (string) $args['type'];
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]          = '(u.name LIKE :search OR u.slug LIKE :search)';
			$params['search'] = '%' . (string) $args['search'] . '%';
		}
		if ( empty( $args['include_ignored'] ) ) {
			$where[] = 'u.is_ignored = 0';
		}

		return Database::select(
			'SELECT u.*, s.name AS site_name, s.url AS site_url, s.client_id
			 FROM `' . Database::table( 'updates' ) . '` u
			 INNER JOIN `' . Database::table( 'sites' ) . '` s ON s.id = u.site_id
			 WHERE ' . implode( ' AND ', $where ) . '
			 ORDER BY u.type ASC, u.name ASC, s.name ASC',
			$params
		);
	}

	/**
	 * Nach Plugin/Theme gruppiert — Grundlage für "auf allen Seiten aktualisieren".
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,array{type:string,slug:string,name:string,new_version:string,sites:array<int,array<string,mixed>>}>
	 */
	public static function grouped( array $args = array() ): array {
		$groups = array();

		foreach ( self::query( $args ) as $row ) {
			$key = $row['type'] . '|' . $row['slug'];

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'type'        => (string) $row['type'],
					'slug'        => (string) $row['slug'],
					'name'        => (string) $row['name'],
					'new_version' => (string) $row['new_version'],
					'sites'       => array(),
				);
			}

			if ( version_compare( (string) $row['new_version'], $groups[ $key ]['new_version'], '>' ) ) {
				$groups[ $key ]['new_version'] = (string) $row['new_version'];
			}

			$groups[ $key ]['sites'][] = array(
				'id'              => (int) $row['site_id'],
				'name'            => (string) $row['site_name'],
				'url'             => (string) $row['site_url'],
				'current_version' => (string) $row['current_version'],
				'new_version'     => (string) $row['new_version'],
			);
		}

		uasort(
			$groups,
			static function ( array $a, array $b ): int {
				$diff = count( $b['sites'] ) <=> count( $a['sites'] );
				return 0 !== $diff ? $diff : strcasecmp( $a['name'], $b['name'] );
			}
		);

		return $groups;
	}

	/**
	 * @return array<string,int>
	 */
	public static function countsByType(): array {
		$rows = Database::select(
			'SELECT u.type, COUNT(*) AS total
			 FROM `' . Database::table( 'updates' ) . '` u
			 INNER JOIN `' . Database::table( 'sites' ) . '` s ON s.id = u.site_id
			 WHERE u.is_ignored = 0 AND s.is_paused = 0
			 GROUP BY u.type'
		);

		$counts = array( 'core' => 0, 'plugin' => 0, 'theme' => 0 );
		foreach ( $rows as $row ) {
			$counts[ (string) $row['type'] ] = (int) $row['total'];
		}

		return $counts;
	}

	public static function setIgnored( int $siteId, string $type, string $slug, bool $ignore ): void {
		Database::update(
			'updates',
			array( 'is_ignored' => $ignore ? 1 : 0 ),
			array( 'site_id' => $siteId, 'type' => $type, 'slug' => $slug )
		);
	}

	public static function countForSite( int $siteId, bool $includeIgnored = false ): int {
		$sql = 'SELECT COUNT(*) FROM `' . Database::table( 'updates' ) . '` WHERE `site_id` = :id';
		if ( ! $includeIgnored ) {
			$sql .= ' AND `is_ignored` = 0';
		}

		return (int) Database::scalar( $sql, array( 'id' => $siteId ) );
	}
}
