<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Database;
use PDO;

/**
 * Export der Panel-Datenbank.
 *
 * Ohne diese Sicherung wäre nach einem Serverausfall alles weg, was das Panel
 * über die betreuten Seiten weiss — und vor allem die privaten Schlüssel der
 * Verbindungen. Jede Seite müsste von Hand neu verbunden werden.
 *
 * Geschrieben wird mit PDO statt mit mysqldump: das Werkzeug gehört nicht zu
 * den Voraussetzungen dieser Installation und fehlt auf manchem Webhosting.
 */
final class PanelBackup {

	/** Zeilen je INSERT — gross genug für Tempo, klein genug für max_allowed_packet. */
	private const ROWS_PER_INSERT = 200;

	/**
	 * Schreibt einen SQL-Export aller Panel-Tabellen.
	 *
	 * @return int Grösse der erzeugten Datei.
	 */
	public static function dump( string $target ): int {
		$directory = dirname( $target );

		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0750, true ) && ! is_dir( $directory ) ) {
			throw new \RuntimeException( 'Verzeichnis nicht anlegbar: ' . $directory );
		}

		$gzip   = function_exists( 'gzopen' );
		$handle = $gzip ? gzopen( $target, 'wb6' ) : fopen( $target, 'wb' );

		if ( ! $handle ) {
			throw new \RuntimeException( 'Export nicht schreibbar: ' . $target );
		}

		$write = static function ( string $text ) use ( $handle, $gzip ): void {
			if ( $gzip ) {
				gzwrite( $handle, $text );
			} else {
				fwrite( $handle, $text );
			}
		};

		$pdo = Database::pdo();

		$write( "-- NorthLab Control Panel — Datenbankexport\n" );
		$write( '-- ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n" );
		$write( "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n" );

		foreach ( self::tables( $pdo ) as $table ) {
			self::dumpTable( $pdo, $table, $write );
		}

		$write( "SET FOREIGN_KEY_CHECKS=1;\n" );

		if ( $gzip ) {
			gzclose( $handle );
		} else {
			fclose( $handle );
		}

		clearstatcache( true, $target );

		return (int) ( is_file( $target ) ? filesize( $target ) : 0 );
	}

	/**
	 * Tabellen dieser Installation.
	 *
	 * @return array<int,string>
	 */
	private static function tables( PDO $pdo ): array {
		$prefix = Database::prefix();
		$tables = array();

		foreach ( $pdo->query( 'SHOW TABLES' )->fetchAll( PDO::FETCH_NUM ) as $row ) {
			$name = (string) $row[0];

			// Teilt sich das Panel die Datenbank mit etwas anderem, bleibt das
			// Fremde aussen vor.
			if ( '' === $prefix || str_starts_with( $name, $prefix ) ) {
				$tables[] = $name;
			}
		}

		sort( $tables );

		return $tables;
	}

	/**
	 * @param callable(string):void $write
	 */
	private static function dumpTable( PDO $pdo, string $table, callable $write ): void {
		$quoted = '`' . str_replace( '`', '``', $table ) . '`';
		$create = $pdo->query( 'SHOW CREATE TABLE ' . $quoted )->fetch( PDO::FETCH_NUM );

		$write( "\n-- Tabelle " . $table . "\n" );
		$write( 'DROP TABLE IF EXISTS ' . $quoted . ";\n" );
		$write( (string) ( $create[1] ?? '' ) . ";\n" );

		$statement = $pdo->query( 'SELECT * FROM ' . $quoted );
		$buffer    = array();

		while ( $row = $statement->fetch( PDO::FETCH_ASSOC ) ) {
			$values = array();

			foreach ( $row as $value ) {
				if ( null === $value ) {
					$values[] = 'NULL';
				} elseif ( is_int( $value ) || is_float( $value ) ) {
					$values[] = (string) $value;
				} else {
					$values[] = $pdo->quote( (string) $value );
				}
			}

			$buffer[] = '(' . implode( ',', $values ) . ')';

			if ( count( $buffer ) >= self::ROWS_PER_INSERT ) {
				$write( 'INSERT INTO ' . $quoted . ' VALUES ' . implode( ',', $buffer ) . ";\n" );
				$buffer = array();
			}
		}

		if ( $buffer ) {
			$write( 'INSERT INTO ' . $quoted . ' VALUES ' . implode( ',', $buffer ) . ";\n" );
		}
	}
}
