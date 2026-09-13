<?php
/**
 * Globale Kurzfunktionen für Templates.
 */

declare( strict_types = 1 );

use NorthLab\Core\Config;
use NorthLab\Core\Csrf;

if ( ! function_exists( 'e' ) ) {
	/**
	 * HTML-sicher ausgeben.
	 */
	function e( mixed $value ): string {
		return htmlspecialchars( (string) ( $value ?? '' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'url' ) ) {
	/**
	 * Absolute URL innerhalb der Anwendung.
	 */
	function url( string $path = '' ): string {
		$base = rtrim( (string) Config::get( 'app.url', '' ), '/' );
		if ( '' === $base ) {
			$base = Config::guessBaseUrl();
		}
		return $base . '/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'csrf_field' ) ) {
	function csrf_field(): string {
		return '<input type="hidden" name="_token" value="' . e( Csrf::token() ) . '">';
	}
}

if ( ! function_exists( 'nl_number' ) ) {
	function nl_number( int|float $value, int $decimals = 0 ): string {
		return number_format( (float) $value, $decimals, ',', '.' );
	}
}

if ( ! function_exists( 'nl_date' ) ) {
	/**
	 * UTC-Zeitstempel aus der Datenbank in lokaler Zeit anzeigen.
	 */
	function nl_date( ?string $utc, string $format = 'd.m.Y H:i' ): string {
		if ( null === $utc || '' === $utc || str_starts_with( $utc, '0000' ) ) {
			return '—';
		}
		try {
			$dt = new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) );
			return $dt->setTimezone( new DateTimeZone( date_default_timezone_get() ) )->format( $format );
		} catch ( Exception $e ) {
			return '—';
		}
	}
}

if ( ! function_exists( 'nl_ago' ) ) {
	function nl_ago( ?string $utc ): string {
		if ( null === $utc || '' === $utc ) {
			return 'nie';
		}
		$ts = strtotime( $utc . ' UTC' );
		if ( ! $ts ) {
			return 'nie';
		}

		$diff = time() - $ts;
		if ( $diff < 0 ) {
			return 'gleich';
		}
		if ( $diff < 60 ) {
			return 'gerade eben';
		}
		if ( $diff < 3600 ) {
			return (int) round( $diff / 60 ) . ' Min.';
		}
		if ( $diff < 86400 ) {
			return (int) round( $diff / 3600 ) . ' Std.';
		}
		if ( $diff < 2592000 ) {
			return (int) round( $diff / 86400 ) . ' Tg.';
		}
		return nl_date( $utc, 'd.m.Y' );
	}
}

if ( ! function_exists( 'nl_duration' ) ) {
	function nl_duration( int $seconds ): string {
		$seconds = max( 0, $seconds );
		if ( $seconds < 60 ) {
			return $seconds . ' Sek.';
		}
		if ( $seconds < 3600 ) {
			return (int) round( $seconds / 60 ) . ' Min.';
		}
		if ( $seconds < 86400 ) {
			return nl_number( $seconds / 3600, 1 ) . ' Std.';
		}
		return nl_number( $seconds / 86400, 1 ) . ' Tage';
	}
}

if ( ! function_exists( 'nl_bytes' ) ) {
	function nl_bytes( float $megabytes ): string {
		if ( $megabytes >= 1024 ) {
			return nl_number( $megabytes / 1024, 2 ) . ' GB';
		}
		return nl_number( $megabytes, 1 ) . ' MB';
	}
}

if ( ! function_exists( 'size_format_de' ) ) {
	/**
	 * Bytes lesbar machen, mit deutschem Dezimalkomma.
	 */
	function size_format_de( int|float $bytes, int $decimals = 1 ): string {
		$bytes = max( 0, (float) $bytes );

		foreach ( array( 'TB' => 1099511627776, 'GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024 ) as $einheit => $faktor ) {
			if ( $bytes >= $faktor ) {
				return nl_number( $bytes / $faktor, $decimals ) . ' ' . $einheit;
			}
		}

		return nl_number( $bytes ) . ' B';
	}
}

if ( ! function_exists( 'nl_utc' ) ) {
	/**
	 * Aktueller Zeitstempel im Datenbankformat (UTC).
	 */
	function nl_utc( int $offsetSeconds = 0 ): string {
		return gmdate( 'Y-m-d H:i:s', time() + $offsetSeconds );
	}
}
