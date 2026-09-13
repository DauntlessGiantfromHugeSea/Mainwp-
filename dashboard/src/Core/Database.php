<?php

declare( strict_types = 1 );

namespace NorthLab\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Dünne PDO-Schicht mit Tabellenpräfix.
 */
final class Database {

	private static ?PDO $pdo = null;

	private static string $prefix = 'nl_';

	/**
	 * @param array<string,mixed> $config
	 */
	public static function boot( array $config ): void {
		self::$prefix = (string) ( $config['prefix'] ?? 'nl_' );

		$dsn = sprintf(
			'mysql:host=%s;port=%d;dbname=%s;charset=%s',
			(string) ( $config['host'] ?? 'localhost' ),
			(int) ( $config['port'] ?? 3306 ),
			(string) ( $config['name'] ?? '' ),
			(string) ( $config['charset'] ?? 'utf8mb4' )
		);

		try {
			self::$pdo = new PDO(
				$dsn,
				(string) ( $config['user'] ?? '' ),
				(string) ( $config['pass'] ?? '' ),
				array(
					PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
					PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
					PDO::ATTR_EMULATE_PREPARES   => false,
					PDO::ATTR_STRINGIFY_FETCHES  => false,
				)
			);
			self::$pdo->exec( "SET time_zone = '+00:00'" );
		} catch ( PDOException $e ) {
			throw new RuntimeException( 'Datenbankverbindung fehlgeschlagen: ' . $e->getMessage(), 0, $e );
		}
	}

	/**
	 * Verbindungstest für den Installer.
	 *
	 * @param array<string,mixed> $config
	 */
	public static function test( array $config ): ?string {
		try {
			$dsn = sprintf(
				'mysql:host=%s;port=%d;dbname=%s;charset=%s',
				(string) ( $config['host'] ?? 'localhost' ),
				(int) ( $config['port'] ?? 3306 ),
				(string) ( $config['name'] ?? '' ),
				(string) ( $config['charset'] ?? 'utf8mb4' )
			);
			new PDO( $dsn, (string) ( $config['user'] ?? '' ), (string) ( $config['pass'] ?? '' ), array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
			return null;
		} catch ( PDOException $e ) {
			return $e->getMessage();
		}
	}

	public static function pdo(): PDO {
		if ( null === self::$pdo ) {
			throw new RuntimeException( 'Es besteht keine Datenbankverbindung.' );
		}
		return self::$pdo;
	}

	public static function isConnected(): bool {
		return null !== self::$pdo;
	}

	/**
	 * Präfigierter Tabellenname.
	 */
	public static function table( string $name ): string {
		return self::$prefix . $name;
	}

	public static function prefix(): string {
		return self::$prefix;
	}

	/**
	 * @param array<int|string,mixed> $params
	 */
	public static function run( string $sql, array $params = array() ): PDOStatement {
		$statement = self::pdo()->prepare( $sql );
		$statement->execute( $params );
		return $statement;
	}

	/**
	 * @param array<int|string,mixed> $params
	 * @return array<int,array<string,mixed>>
	 */
	public static function select( string $sql, array $params = array() ): array {
		return self::run( $sql, $params )->fetchAll();
	}

	/**
	 * @param array<int|string,mixed> $params
	 * @return array<string,mixed>|null
	 */
	public static function selectOne( string $sql, array $params = array() ): ?array {
		$row = self::run( $sql, $params )->fetch();
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<int|string,mixed> $params
	 */
	public static function scalar( string $sql, array $params = array() ): mixed {
		return self::run( $sql, $params )->fetchColumn();
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function insert( string $table, array $data ): int {
		$columns      = array_keys( $data );
		$placeholders = array_map( static fn( string $c ): string => ':' . $c, $columns );

		$sql = sprintf(
			'INSERT INTO `%s` (%s) VALUES (%s)',
			self::table( $table ),
			'`' . implode( '`, `', $columns ) . '`',
			implode( ', ', $placeholders )
		);

		self::run( $sql, self::bindable( $data ) );

		return (int) self::pdo()->lastInsertId();
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $where
	 */
	public static function update( string $table, array $data, array $where ): int {
		if ( ! $data ) {
			return 0;
		}

		$set = array();
		foreach ( array_keys( $data ) as $column ) {
			$set[] = sprintf( '`%s` = :set_%s', $column, $column );
		}

		$conditions = array();
		foreach ( array_keys( $where ) as $column ) {
			$conditions[] = sprintf( '`%s` = :where_%s', $column, $column );
		}

		$params = array();
		foreach ( self::bindable( $data ) as $key => $value ) {
			$params[ 'set_' . ltrim( (string) $key, ':' ) ] = $value;
		}
		foreach ( self::bindable( $where ) as $key => $value ) {
			$params[ 'where_' . ltrim( (string) $key, ':' ) ] = $value;
		}

		$sql = sprintf(
			'UPDATE `%s` SET %s WHERE %s',
			self::table( $table ),
			implode( ', ', $set ),
			implode( ' AND ', $conditions )
		);

		return self::run( $sql, $params )->rowCount();
	}

	/**
	 * @param array<string,mixed> $where
	 */
	public static function delete( string $table, array $where ): int {
		$conditions = array();
		foreach ( array_keys( $where ) as $column ) {
			$conditions[] = sprintf( '`%s` = :%s', $column, $column );
		}

		$sql = sprintf( 'DELETE FROM `%s` WHERE %s', self::table( $table ), implode( ' AND ', $conditions ) );

		return self::run( $sql, self::bindable( $where ) )->rowCount();
	}

	/**
	 * Booleans und Arrays in bindbare Skalare überführen.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private static function bindable( array $data ): array {
		$out = array();

		foreach ( $data as $key => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? 1 : 0;
			} elseif ( is_array( $value ) ) {
				$value = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
			$out[ (string) $key ] = $value;
		}

		return $out;
	}

	public static function beginTransaction(): void {
		self::pdo()->beginTransaction();
	}

	public static function commit(): void {
		self::pdo()->commit();
	}

	public static function rollBack(): void {
		if ( self::pdo()->inTransaction() ) {
			self::pdo()->rollBack();
		}
	}

	public static function tableExists( string $table ): bool {
		$name = self::table( $table );
		try {
			self::run( 'SELECT 1 FROM `' . $name . '` LIMIT 1' );
			return true;
		} catch ( PDOException $e ) {
			return false;
		}
	}
}
