<?php

declare( strict_types = 1 );

namespace NorthLab\Repository;

use NorthLab\Core\Database;
use NorthLab\Core\Setting;

/**
 * Agenturkunden: gruppieren Seiten und bestimmen Berichtsempfänger.
 */
final class ClientRepository {

	public const FREQUENCIES = array(
		'off'       => 'Keine Berichte',
		'weekly'    => 'Wöchentlich',
		'monthly'   => 'Monatlich',
		'quarterly' => 'Quartalsweise',
	);

	/**
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		return Database::selectOne( 'SELECT * FROM `' . Database::table( 'clients' ) . '` WHERE `id` = :id', array( 'id' => $id ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function all( ?array $siteIds = null ): array {
		// Bei eingeschränktem Konto zählen nur die freigegebenen Seiten, und
		// Kunden ganz ohne solche Seite tauchen gar nicht erst auf.
		if ( null !== $siteIds ) {
			[ $in, $params ] = Database::inClause( $siteIds, 'vis' );

			return Database::select(
				'SELECT c.*, COUNT(s.id) AS site_count, COALESCE(SUM(s.pending_updates), 0) AS pending_updates
				 FROM `' . Database::table( 'clients' ) . '` c
				 INNER JOIN `' . Database::table( 'sites' ) . '` s ON s.client_id = c.id AND s.id IN ' . $in . '
				 GROUP BY c.id
				 ORDER BY c.name ASC',
				$params
			);
		}

		return Database::select(
			'SELECT c.*, COUNT(s.id) AS site_count, COALESCE(SUM(s.pending_updates), 0) AS pending_updates
			 FROM `' . Database::table( 'clients' ) . '` c
			 LEFT JOIN `' . Database::table( 'sites' ) . '` s ON s.client_id = c.id
			 GROUP BY c.id
			 ORDER BY c.name ASC'
		);
	}

	/**
	 * Nur die Stammdaten, ohne Aggregation — für Auswahlfelder.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function options(): array {
		return Database::select( 'SELECT `id`, `name` FROM `' . Database::table( 'clients' ) . '` ORDER BY `name` ASC' );
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array{0:int,1:string|null}
	 */
	public static function create( array $data ): array {
		$name = trim( (string) ( $data['name'] ?? '' ) );
		if ( '' === $name ) {
			return array( 0, 'Der Kundenname darf nicht leer sein.' );
		}

		$email = trim( (string) ( $data['email'] ?? '' ) );
		if ( '' !== $email && ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return array( 0, 'Die E-Mail-Adresse ist ungültig.' );
		}

		$id = Database::insert(
			'clients',
			array(
				'name'                  => $name,
				'contact_name'          => trim( (string) ( $data['contact_name'] ?? '' ) ),
				'email'                 => $email,
				'report_frequency'      => self::frequency( (string) ( $data['report_frequency'] ?? '' ) ),
				'report_webhook_url'    => trim( (string) ( $data['report_webhook_url'] ?? '' ) ),
				'report_webhook_secret' => trim( (string) ( $data['report_webhook_secret'] ?? '' ) ),
				'report_email_enabled'  => empty( $data['report_email_enabled'] ) ? 0 : 1,
				'notes'                 => trim( (string) ( $data['notes'] ?? '' ) ),
				'created_at'            => nl_utc(),
			)
		);

		return array( $id, null );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function update( int $id, array $data ): ?string {
		if ( null === self::find( $id ) ) {
			return 'Kunde nicht gefunden.';
		}

		$fields = array();

		if ( isset( $data['name'] ) ) {
			$name = trim( (string) $data['name'] );
			if ( '' === $name ) {
				return 'Der Kundenname darf nicht leer sein.';
			}
			$fields['name'] = $name;
		}
		if ( isset( $data['contact_name'] ) ) {
			$fields['contact_name'] = trim( (string) $data['contact_name'] );
		}
		if ( isset( $data['email'] ) ) {
			$email = trim( (string) $data['email'] );
			if ( '' !== $email && ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				return 'Die E-Mail-Adresse ist ungültig.';
			}
			$fields['email'] = $email;
		}
		if ( isset( $data['report_frequency'] ) ) {
			$fields['report_frequency'] = self::frequency( (string) $data['report_frequency'] );
		}
		if ( isset( $data['report_webhook_url'] ) ) {
			$fields['report_webhook_url'] = trim( (string) $data['report_webhook_url'] );
		}
		if ( isset( $data['report_webhook_secret'] ) ) {
			$fields['report_webhook_secret'] = trim( (string) $data['report_webhook_secret'] );
		}
		if ( array_key_exists( 'report_email_enabled', $data ) ) {
			$fields['report_email_enabled'] = empty( $data['report_email_enabled'] ) ? 0 : 1;
		}
		if ( isset( $data['notes'] ) ) {
			$fields['notes'] = trim( (string) $data['notes'] );
		}

		if ( $fields ) {
			Database::update( 'clients', $fields, array( 'id' => $id ) );
		}

		return null;
	}

	/**
	 * Löscht den Kunden; zugeordnete Seiten bleiben bestehen und werden entkoppelt.
	 */
	public static function delete( int $id ): void {
		Database::run(
			'UPDATE `' . Database::table( 'sites' ) . '` SET `client_id` = NULL WHERE `client_id` = :id',
			array( 'id' => $id )
		);
		Database::delete( 'clients', array( 'id' => $id ) );
	}

	public static function markReported( int $id ): void {
		Database::update( 'clients', array( 'last_report_at' => nl_utc() ), array( 'id' => $id ) );
	}

	/**
	 * Kunden, deren Bericht fällig ist.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function dueForReport(): array {
		$due = array();

		foreach ( self::all() as $client ) {
			if ( 'off' === $client['report_frequency'] ) {
				continue;
			}
			if ( self::isDue( $client ) ) {
				$due[] = $client;
			}
		}

		return $due;
	}

	/**
	 * @param array<string,mixed> $client
	 */
	public static function isDue( array $client ): bool {
		$last = ! empty( $client['last_report_at'] ) ? (int) strtotime( (string) $client['last_report_at'] . ' UTC' ) : 0;

		$interval = match ( (string) $client['report_frequency'] ) {
			'weekly'    => 7 * 86400,
			'quarterly' => 90 * 86400,
			default     => 30 * 86400,
		};

		return ( time() - $last ) >= $interval;
	}

	public static function periodDays( string $frequency ): int {
		return match ( $frequency ) {
			'weekly'    => 7,
			'quarterly' => 90,
			default     => 30,
		};
	}

	private static function frequency( string $value ): string {
		return array_key_exists( $value, self::FREQUENCIES )
			? $value
			: Setting::get( 'report_default_freq', 'monthly' );
	}
}
