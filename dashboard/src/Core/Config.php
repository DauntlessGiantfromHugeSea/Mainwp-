<?php

declare( strict_types = 1 );

namespace NorthLab\Core;

/**
 * Zugriff auf config.php.
 */
final class Config {

	/** @var array<string,mixed> */
	private static array $data = array();

	private static bool $installed = false;

	public static function load( string $file ): void {
		if ( is_file( $file ) ) {
			$loaded = require $file;
			if ( is_array( $loaded ) ) {
				self::$data      = $loaded;
				self::$installed = '' !== (string) self::get( 'db.name', '' );
			}
		}
	}

	public static function isInstalled(): bool {
		return self::$installed;
	}

	/**
	 * Punktnotation: Config::get('db.host').
	 */
	public static function get( string $key, mixed $default = null ): mixed {
		$segments = explode( '.', $key );
		$cursor   = self::$data;

		foreach ( $segments as $segment ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
				return $default;
			}
			$cursor = $cursor[ $segment ];
		}

		return $cursor;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		return self::$data;
	}

	/**
	 * Schreibt eine neue config.php. Wird vom Installer und den Einstellungen genutzt.
	 *
	 * @param array<string,mixed> $config
	 */
	public static function write( string $file, array $config ): bool {
		$php = "<?php\n/**\n * NorthLab Control Panel — automatisch erzeugte Konfiguration.\n"
			. " * Erstellt am " . gmdate( 'c' ) . "\n */\n\nreturn " . self::export( $config ) . ";\n";

		$written = file_put_contents( $file, $php, LOCK_EX );
		if ( false === $written ) {
			return false;
		}

		@chmod( $file, 0640 );

		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $file, true );
		}

		return true;
	}

	/**
	 * var_export mit lesbarer Einrückung.
	 *
	 * @param mixed $value
	 */
	private static function export( mixed $value, int $depth = 0 ): string {
		$pad = str_repeat( "\t", $depth + 1 );

		if ( is_array( $value ) ) {
			$isList = array_is_list( $value );
			$lines  = array();

			foreach ( $value as $key => $item ) {
				$lines[] = $isList
					? $pad . self::export( $item, $depth + 1 )
					: $pad . var_export( (string) $key, true ) . ' => ' . self::export( $item, $depth + 1 );
			}

			return "array(\n" . implode( ",\n", $lines ) . ",\n" . str_repeat( "\t", $depth ) . ')';
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( null === $value ) {
			return 'null';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}

		return var_export( (string) $value, true );
	}

	/**
	 * Basis-URL aus der aktuellen Anfrage ableiten (Fallback, wenn app.url leer ist).
	 */
	public static function guessBaseUrl(): string {
		$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] )
			|| ( ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) === 'https' )
			|| ( (int) ( $_SERVER['SERVER_PORT'] ?? 80 ) === 443 );

		$host = (string) ( $_SERVER['HTTP_HOST'] ?? 'localhost' );
		$dir  = rtrim( str_replace( '\\', '/', dirname( (string) ( $_SERVER['SCRIPT_NAME'] ?? '/index.php' ) ) ), '/' );

		return ( $https ? 'https://' : 'http://' ) . $host . $dir;
	}
}
