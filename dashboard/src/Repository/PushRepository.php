<?php

declare( strict_types = 1 );

namespace NorthLab\Repository;

use NorthLab\Core\Database;

/**
 * Geräte, die Push-Meldungen bekommen.
 *
 * Ein Abonnement gehört immer zu einem Panel-Konto: Meldungen über Seiten, die
 * jemand gar nicht sehen darf, gehen ihn auch auf dem Handy nichts an.
 */
final class PushRepository {

	/** So viele erfolglose Zustellungen, dann fliegt das Gerät raus. */
	public const MAX_FAILURES = 5;

	/**
	 * Legt ein Abonnement an oder frischt ein bestehendes auf.
	 *
	 * Der Endpunkt ist bis zu 500 Zeichen lang; verglichen wird über seinen
	 * Hash, denn so lange Spalten lassen sich nicht sinnvoll indizieren.
	 */
	public static function save( int $userId, string $endpoint, string $p256dh, string $auth, string $label = '' ): int {
		$hash = hash( 'sha256', $endpoint );

		$existing = Database::selectOne(
			'SELECT * FROM `' . Database::table( 'push_subscriptions' ) . '` WHERE `endpoint_hash` = :hash',
			array( 'hash' => $hash )
		);

		if ( null !== $existing ) {
			Database::update(
				'push_subscriptions',
				array(
					// Ein Gerät kann den Besitzer wechseln, wenn sich dort jemand anderes anmeldet.
					'user_id'  => $userId,
					'p256dh'   => $p256dh,
					'auth'     => $auth,
					'label'    => $label,
					'failures' => 0,
				),
				array( 'id' => (int) $existing['id'] )
			);

			return (int) $existing['id'];
		}

		return Database::insert(
			'push_subscriptions',
			array(
				'user_id'       => $userId,
				'endpoint'      => $endpoint,
				'endpoint_hash' => $hash,
				'p256dh'        => $p256dh,
				'auth'          => $auth,
				'label'         => $label,
				'created_at'    => nl_utc(),
			)
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function forUser( int $userId ): array {
		return Database::select(
			'SELECT * FROM `' . Database::table( 'push_subscriptions' ) . '` WHERE `user_id` = :id ORDER BY `created_at` DESC',
			array( 'id' => $userId )
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		return Database::select( 'SELECT * FROM `' . Database::table( 'push_subscriptions' ) . '`' );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function findByEndpoint( string $endpoint ): ?array {
		return Database::selectOne(
			'SELECT * FROM `' . Database::table( 'push_subscriptions' ) . '` WHERE `endpoint_hash` = :hash',
			array( 'hash' => hash( 'sha256', $endpoint ) )
		);
	}

	public static function deleteByEndpoint( string $endpoint ): void {
		Database::delete( 'push_subscriptions', array( 'endpoint_hash' => hash( 'sha256', $endpoint ) ) );
	}

	public static function delete( int $id ): void {
		Database::delete( 'push_subscriptions', array( 'id' => $id ) );
	}

	public static function markSent( int $id ): void {
		Database::update(
			'push_subscriptions',
			array( 'failures' => 0, 'last_sent_at' => nl_utc() ),
			array( 'id' => $id )
		);
	}

	/**
	 * Zählt einen Fehlschlag und entfernt das Gerät, wenn es zu oft war.
	 *
	 * @return bool true, wenn das Abonnement entfernt wurde.
	 */
	public static function markFailed( int $id, bool $gone = false ): bool {
		if ( $gone ) {
			self::delete( $id );
			return true;
		}

		$row      = Database::selectOne(
			'SELECT `failures` FROM `' . Database::table( 'push_subscriptions' ) . '` WHERE `id` = :id',
			array( 'id' => $id )
		);
		$failures = (int) ( $row['failures'] ?? 0 ) + 1;

		if ( $failures >= self::MAX_FAILURES ) {
			self::delete( $id );
			return true;
		}

		Database::update( 'push_subscriptions', array( 'failures' => $failures ), array( 'id' => $id ) );

		return false;
	}

	public static function countForUser( int $userId ): int {
		return (int) Database::scalar(
			'SELECT COUNT(*) FROM `' . Database::table( 'push_subscriptions' ) . '` WHERE `user_id` = :id',
			array( 'id' => $userId )
		);
	}
}
