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
	public static function recent( int $limit = 50, int $clientId = 0, ?array $siteIds = null ): array {
		$sql = 'SELECT r.id, r.client_id, r.site_id, r.title, r.period_start, r.period_end, r.status, r.created_at,
				c.name AS client_name, s.name AS site_name
			FROM `' . Database::table( 'reports' ) . '` r
			LEFT JOIN `' . Database::table( 'clients' ) . '` c ON c.id = r.client_id
			LEFT JOIN `' . Database::table( 'sites' ) . '` s ON s.id = r.site_id';

		$where  = array();
		$params = array();

		if ( $clientId > 0 ) {
			$where[]       = 'r.client_id = :cid';
			$params['cid'] = $clientId;
		}

		// Ein eingeschraenktes Konto sieht nur Berichte zu einer ihm
		// zugeordneten Seite oder zu einem Kunden, von dem es mindestens eine
		// Seite betreut. Ein Bericht ueber *alle* Seiten gehoert niemandem
		// einzeln und bleibt den Konten mit voller Sicht vorbehalten.
		if ( null !== $siteIds ) {
			// Zweimal dieselbe Liste, aber mit eigenen Platzhaltern: ein
			// benannter Platzhalter darf in einer Abfrage nur einmal
			// vorkommen, sonst weist PDO sie zurueck.
			[ $inA, $paramsA ] = Database::inClause( $siteIds, 'visa' );
			[ $inB, $paramsB ] = Database::inClause( $siteIds, 'visb' );

			$where[] = '( r.site_id IN ' . $inA
				. ' OR r.client_id IN ( SELECT `client_id` FROM `' . Database::table( 'sites' ) . '`'
				. ' WHERE `id` IN ' . $inB . ' AND `client_id` IS NOT NULL ) )';

			$params = array_merge( $params, $paramsA, $paramsB );
		}

		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		$sql .= ' ORDER BY r.id DESC LIMIT ' . max( 1, min( 200, $limit ) );

		return Database::select( $sql, $params );
	}

	/**
	 * Darf dieses Konto den Bericht sehen?
	 *
	 * Die Erzeugung war schon immer eingeschraenkt — das Lesen nicht. Wer die
	 * fortlaufende Nummer hochzaehlte, bekam die Berichte fremder Kunden mit
	 * Seitenadressen, Versionsstaenden und offenen Sicherheitsbefunden.
	 *
	 * @param array<string,mixed> $report
	 * @param array<int,int>|null $siteIds null = sieht alles.
	 */
	public static function visibleTo( array $report, ?array $siteIds ): bool {
		if ( null === $siteIds ) {
			return true;
		}

		if ( ! empty( $report['site_id'] ) ) {
			return in_array( (int) $report['site_id'], $siteIds, true );
		}

		if ( ! empty( $report['client_id'] ) ) {
			[ $in, $params ] = Database::inClause( $siteIds, 'vis' );

			return null !== Database::selectOne(
				'SELECT `id` FROM `' . Database::table( 'sites' ) . '`
				 WHERE `client_id` = :cid AND `id` IN ' . $in . ' LIMIT 1',
				array_merge( array( 'cid' => (int) $report['client_id'] ), $params )
			);
		}

		// Weder Seite noch Kunde: ein Bericht ueber den gesamten Bestand.
		return false;
	}

	public static function delete( int $id ): void {
		Database::delete( 'reports', array( 'id' => $id ) );
	}
}
