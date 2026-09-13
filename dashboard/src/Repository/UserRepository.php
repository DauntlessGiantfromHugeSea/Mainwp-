<?php

declare( strict_types = 1 );

namespace NorthLab\Repository;

use NorthLab\Core\Database;

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
}
