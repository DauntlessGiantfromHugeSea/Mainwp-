<?php

declare( strict_types = 1 );

namespace NorthLab\Repository;

use NorthLab\Core\Auth;
use NorthLab\Core\Database;

/**
 * Aktivitätsprotokoll — Grundlage für Reports und Fehlersuche.
 */
final class ActivityRepository {

	/**
	 * @param array<string,mixed> $args site_id, user_id, level, context
	 */
	public static function log( string $action, string $message, array $args = array() ): int {
		$userId = array_key_exists( 'user_id', $args ) ? (int) $args['user_id'] : Auth::id();

		return Database::insert(
			'activity',
			array(
				'site_id'    => ! empty( $args['site_id'] ) ? (int) $args['site_id'] : null,
				'user_id'    => $userId > 0 ? $userId : null,
				'action'     => substr( $action, 0, 60 ),
				'level'      => (string) ( $args['level'] ?? 'info' ),
				'message'    => $message,
				'context'    => isset( $args['context'] ) ? json_encode( $args['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : null,
				'created_at' => nl_utc(),
			)
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<int,array<string,mixed>>
	 */
	public static function query( array $args = array() ): array {
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['site_id'] ) ) {
			$where[]           = 'a.site_id = :site_id';
			$params['site_id'] = (int) $args['site_id'];
		}
		if ( ! empty( $args['action'] ) ) {
			$where[]          = 'a.action = :action';
			$params['action'] = (string) $args['action'];
		}
		if ( ! empty( $args['action_prefix'] ) ) {
			$where[]          = 'a.action LIKE :prefix';
			$params['prefix'] = (string) $args['action_prefix'] . '%';
		}
		if ( ! empty( $args['level'] ) ) {
			$where[]         = 'a.level = :level';
			$params['level'] = (string) $args['level'];
		}
		if ( ! empty( $args['since'] ) ) {
			$where[]         = 'a.created_at >= :since';
			$params['since'] = (string) $args['since'];
		}
		if ( ! empty( $args['until'] ) ) {
			$where[]         = 'a.created_at <= :until';
			$params['until'] = (string) $args['until'];
		}
		if ( ! empty( $args['search'] ) ) {
			$where[]          = '(a.message LIKE :search OR a.action LIKE :search)';
			$params['search'] = '%' . (string) $args['search'] . '%';
		}
		if ( isset( $args['site_ids'] ) && is_array( $args['site_ids'] ) ) {
			// Einträge ohne Seitenbezug (Anmeldungen, Systemmeldungen) bleiben ausgeblendet,
			// wenn ein Konto nur bestimmte Seiten sehen darf.
			[ $in, $inParams ] = Database::inClause( $args['site_ids'], 'vis' );
			$where[]           = 'a.site_id IN ' . $in;
			$params            = array_merge( $params, $inParams );
		}

		$limit  = max( 1, min( 1000, (int) ( $args['limit'] ?? 100 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$sql = 'SELECT a.*, s.name AS site_name, u.email AS user_email
			FROM `' . Database::table( 'activity' ) . '` a
			LEFT JOIN `' . Database::table( 'sites' ) . '` s ON s.id = a.site_id
			LEFT JOIN `' . Database::table( 'users' ) . '` u ON u.id = a.user_id
			WHERE ' . implode( ' AND ', $where ) . '
			ORDER BY a.created_at DESC, a.id DESC
			LIMIT ' . $limit . ' OFFSET ' . $offset;

		return Database::select( $sql, $params );
	}

	/**
	 * @param array<string,mixed> $args
	 */
	public static function count( array $args = array() ): int {
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['site_id'] ) ) {
			$where[]           = 'site_id = :site_id';
			$params['site_id'] = (int) $args['site_id'];
		}
		if ( ! empty( $args['action'] ) ) {
			$where[]          = 'action = :action';
			$params['action'] = (string) $args['action'];
		}
		if ( ! empty( $args['since'] ) ) {
			$where[]         = 'created_at >= :since';
			$params['since'] = (string) $args['since'];
		}

		return (int) Database::scalar(
			'SELECT COUNT(*) FROM `' . Database::table( 'activity' ) . '` WHERE ' . implode( ' AND ', $where ),
			$params
		);
	}

	public static function prune( int $days ): int {
		return Database::run(
			'DELETE FROM `' . Database::table( 'activity' ) . '` WHERE `created_at` < :cutoff',
			array( 'cutoff' => gmdate( 'Y-m-d H:i:s', time() - max( 7, $days ) * 86400 ) )
		)->rowCount();
	}
}
