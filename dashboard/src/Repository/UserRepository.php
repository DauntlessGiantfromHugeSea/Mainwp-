<?php

declare( strict_types = 1 );

namespace NorthLab\Repository;

use NorthLab\Core\Crypto;
use NorthLab\Core\Database;
use NorthLab\Core\Totp;

final class UserRepository {

	public const ROLES = array(
		'admin'    => 'Administrator',
		'member'   => 'Mitarbeiter',
		'readonly' => 'Nur Lesen',
	);

	/**
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		return Database::selectOne( 'SELECT * FROM `' . Database::table( 'users' ) . '` WHERE `id` = :id', array( 'id' => $id ) );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function findByEmail( string $email ): ?array {
		return Database::selectOne(
			'SELECT * FROM `' . Database::table( 'users' ) . '` WHERE `email` = :email',
			array( 'email' => strtolower( trim( $email ) ) )
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		return Database::select( 'SELECT * FROM `' . Database::table( 'users' ) . '` ORDER BY `name` ASC, `email` ASC' );
	}

	public static function count(): int {
		return (int) Database::scalar( 'SELECT COUNT(*) FROM `' . Database::table( 'users' ) . '`' );
	}

	/**
	 * @return array{0:int,1:string|null} ID und Fehlermeldung.
	 */
	public static function create( string $email, string $name, string $password, string $role = 'member' ): array {
		$email = strtolower( trim( $email ) );

		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return array( 0, 'Bitte eine gültige E-Mail-Adresse angeben.' );
		}
		if ( self::findByEmail( $email ) ) {
			return array( 0, 'Diese E-Mail-Adresse wird bereits verwendet.' );
		}

		$error = self::validatePassword( $password );
		if ( null !== $error ) {
			return array( 0, $error );
		}

		$id = Database::insert(
			'users',
			array(
				'email'         => $email,
				'name'          => trim( $name ),
				'password_hash' => password_hash( $password, PASSWORD_DEFAULT ),
				'role'          => array_key_exists( $role, self::ROLES ) ? $role : 'member',
				'is_active'     => 1,
				'created_at'    => nl_utc(),
			)
		);

		return array( $id, null );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function update( int $id, array $data ): ?string {
		$fields = array();

		if ( isset( $data['name'] ) ) {
			$fields['name'] = trim( (string) $data['name'] );
		}
		if ( isset( $data['email'] ) ) {
			$email = strtolower( trim( (string) $data['email'] ) );
			if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				return 'Bitte eine gültige E-Mail-Adresse angeben.';
			}
			$existing = self::findByEmail( $email );
			if ( $existing && (int) $existing['id'] !== $id ) {
				return 'Diese E-Mail-Adresse wird bereits verwendet.';
			}
			$fields['email'] = $email;
		}
		if ( isset( $data['role'] ) && array_key_exists( (string) $data['role'], self::ROLES ) ) {
			$fields['role'] = (string) $data['role'];
		}
		if ( array_key_exists( 'is_active', $data ) ) {
			$fields['is_active'] = ! empty( $data['is_active'] ) ? 1 : 0;
		}

		if ( $fields ) {
			Database::update( 'users', $fields, array( 'id' => $id ) );
		}

		return null;
	}

	public static function updatePassword( int $id, string $password ): ?string {
		$error = self::validatePassword( $password );
		if ( null !== $error ) {
			return $error;
		}

		Database::update(
			'users',
			array(
				'password_hash'   => password_hash( $password, PASSWORD_DEFAULT ),
				'failed_attempts' => 0,
				'locked_until'    => null,
			),
			array( 'id' => $id )
		);

		return null;
	}

	public static function delete( int $id ): bool {
		Database::delete( 'sessions', array( 'user_id' => $id ) );
		return Database::delete( 'users', array( 'id' => $id ) ) > 0;
	}

	public static function adminCount(): int {
		return (int) Database::scalar(
			'SELECT COUNT(*) FROM `' . Database::table( 'users' ) . "` WHERE `role` = 'admin' AND `is_active` = 1"
		);
	}

	public static function validatePassword( string $password ): ?string {
		if ( strlen( $password ) < 10 ) {
			return 'Das Passwort muss mindestens 10 Zeichen lang sein.';
		}
		if ( ! preg_match( '/[A-Za-z]/', $password ) || ! preg_match( '/[0-9]/', $password ) ) {
			return 'Das Passwort muss Buchstaben und Ziffern enthalten.';
		}
		return null;
	}

	public static function roleLabel( string $role ): string {
		return self::ROLES[ $role ] ?? $role;
	}

	/* ------------------------------------------------ Zwei-Faktor-Anmeldung */

	/**
	 * Hinterlegt ein noch unbestätigtes Geheimnis. Scharf wird es erst,
	 * wenn der Benutzer einen gültigen Code eingegeben hat.
	 */
	public static function startTotpSetup( int $id, string $secret ): void {
		Database::update(
			'users',
			array(
				'totp_secret'       => Crypto::encrypt( $secret ),
				'totp_enabled'      => 0,
				'totp_confirmed_at' => null,
			),
			array( 'id' => $id )
		);
	}

	/**
	 * @param array<string,mixed> $user
	 */
	public static function totpSecret( array $user ): string {
		return Crypto::decrypt( (string) ( $user['totp_secret'] ?? '' ) );
	}

	/**
	 * Schaltet 2FA scharf und gibt die Ersatzcodes im Klartext zurück —
	 * einmalig, gespeichert werden nur deren Hashes.
	 *
	 * @return array<int,string>
	 */
	public static function confirmTotp( int $id ): array {
		$codes = Totp::generateRecoveryCodes();

		Database::update(
			'users',
			array(
				'totp_enabled'      => 1,
				'totp_confirmed_at' => nl_utc(),
				'recovery_codes'    => json_encode( Totp::hashRecoveryCodes( $codes ) ),
			),
			array( 'id' => $id )
		);

		return $codes;
	}

	public static function disableTotp( int $id ): void {
		Database::update(
			'users',
			array(
				'totp_secret'       => '',
				'totp_enabled'      => 0,
				'totp_confirmed_at' => null,
				'recovery_codes'    => null,
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Ersatzcode prüfen und dabei verbrauchen.
	 *
	 * @param array<string,mixed> $user
	 */
	public static function consumeRecoveryCode( array $user, string $code ): bool {
		$stored = json_decode( (string) ( $user['recovery_codes'] ?? '[]' ), true );

		if ( ! is_array( $stored ) || ! $stored ) {
			return false;
		}

		$candidate = Totp::normalizeRecoveryCode( $code );

		if ( '' === $candidate ) {
			return false;
		}

		foreach ( $stored as $index => $hash ) {
			if ( is_string( $hash ) && password_verify( $candidate, $hash ) ) {
				unset( $stored[ $index ] );

				Database::update(
					'users',
					array( 'recovery_codes' => json_encode( array_values( $stored ) ) ),
					array( 'id' => (int) $user['id'] )
				);

				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $user
	 */
	public static function recoveryCodesLeft( array $user ): int {
		$stored = json_decode( (string) ( $user['recovery_codes'] ?? '[]' ), true );

		return is_array( $stored ) ? count( $stored ) : 0;
	}

	/**
	 * @return array<int,string> Ersatzcodes im Klartext.
	 */
	public static function regenerateRecoveryCodes( int $id ): array {
		$codes = Totp::generateRecoveryCodes();

		Database::update(
			'users',
			array( 'recovery_codes' => json_encode( Totp::hashRecoveryCodes( $codes ) ) ),
			array( 'id' => $id )
		);

		return $codes;
	}

	/* ---------------------------------------------------- Seitenzuordnung */

	/**
	 * IDs der Seiten, die diesem Konto ausdrücklich zugewiesen sind.
	 *
	 * @return array<int,int>
	 */
	public static function siteIds( int $id ): array {
		$rows = Database::select(
			'SELECT `site_id` FROM `' . Database::table( 'user_sites' ) . '` WHERE `user_id` = :id',
			array( 'id' => $id )
		);

		return array_map( static fn( array $row ): int => (int) $row['site_id'], $rows );
	}

	/**
	 * @param array<int,int> $siteIds
	 */
	public static function setSites( int $id, array $siteIds ): void {
		Database::delete( 'user_sites', array( 'user_id' => $id ) );

		foreach ( array_unique( array_map( 'intval', $siteIds ) ) as $siteId ) {
			if ( $siteId > 0 ) {
				Database::insert( 'user_sites', array( 'user_id' => $id, 'site_id' => $siteId ) );
			}
		}
	}

	public static function setSiteAccess( int $id, string $access ): void {
		Database::update(
			'users',
			array( 'site_access' => 'assigned' === $access ? 'assigned' : 'all' ),
			array( 'id' => $id )
		);
	}

	/**
	 * Zuordnungen einer gelöschten Seite aufräumen.
	 */
	public static function forgetSite( int $siteId ): void {
		Database::delete( 'user_sites', array( 'site_id' => $siteId ) );
	}
}
