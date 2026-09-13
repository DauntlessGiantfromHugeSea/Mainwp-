<?php

declare( strict_types = 1 );

namespace NorthLab\Repository;

use NorthLab\Core\Database;

final class ReportRepository {

	/**
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		return Database::selectOne( 'SELECT * FROM `' . Database::table( 'reports' ) . '` WHERE `id` = :id', array( 'id' => $id ) );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function insert( array $data ): int {
		return Database::insert( 'reports', $data );
	}

	/**
	 * Ohne HTML/Payload — für die Listenansicht.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( int $limit = 50, int $clientId = 0 ): array {
		$sql = 'SELECT r.id, r.client_id, r.title, r.period_start, r.period_end, r.status, r.created_at, c.name AS client_name
			FROM `' . Database::table( 'reports' ) . '` r
			LEFT JOIN `' . Database::table( 'clients' ) . '` c ON c.id = r.client_id';

		$params = array();
		if ( $clientId > 0 ) {
			$sql              .= ' WHERE r.client_id = :cid';
			$params['cid'] = $clientId;
		}

		$sql .= ' ORDER BY r.id DESC LIMIT ' . max( 1, min( 200, $limit ) );

		return Database::select( $sql, $params );
	}

	public static function delete( int $id ): void {
		Database::delete( 'reports', array( 'id' => $id ) );
	}
}
