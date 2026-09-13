<?php

declare( strict_types = 1 );

namespace NorthLab\Core;

use Throwable;

/**
 * Einfaches Datei-Log unter storage/logs.
 */
final class Logger {

	public static function write( string $level, string $message, array $context = array() ): void {
		$dir = NL_STORAGE . '/logs';
		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0750, true );
		}

		$line = sprintf(
			"[%s] %s: %s%s\n",
			gmdate( 'Y-m-d H:i:s' ),
			strtoupper( $level ),
			$message,
			$context ? ' ' . json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : ''
		);

		@file_put_contents( $dir . '/app.log', $line, FILE_APPEND | LOCK_EX );
	}

	public static function info( string $message, array $context = array() ): void {
		self::write( 'info', $message, $context );
	}

	public static function error( string $message, array $context = array() ): void {
		self::write( 'error', $message, $context );
	}

	public static function exception( Throwable $e ): void {
		self::write(
			'error',
			get_class( $e ) . ': ' . $e->getMessage(),
			array(
				'file'  => $e->getFile() . ':' . $e->getLine(),
				'trace' => explode( "\n", $e->getTraceAsString() ),
			)
		);
	}
}
