<?php

declare( strict_types = 1 );

namespace NorthLab\Core;

/**
 * Zeitbasierte Einmalpasswörter nach RFC 6238 (HMAC-SHA1, 30 Sekunden, 6 Stellen).
 *
 * Bewusst ohne Abhängigkeiten — das ist genau der Umfang, den Google Authenticator,
 * Aegis, 1Password, Bitwarden und Co. erwarten.
 */
final class Totp {

	private const PERIOD = 30;

	private const DIGITS = 6;

	private const ALGO = 'sha1';

	private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	/**
	 * Neues Geheimnis: 20 Byte, base32-kodiert.
	 */
	public static function generateSecret(): string {
		return self::base32Encode( random_bytes( 20 ) );
	}

	/**
	 * otpauth-URI für QR-Code und manuelle Eingabe.
	 */
	public static function uri( string $secret, string $account, string $issuer ): string {
		$label = rawurlencode( $issuer ) . ':' . rawurlencode( $account );

		return 'otpauth://totp/' . $label . '?' . http_build_query(
			array(
				'secret'    => $secret,
				'issuer'    => $issuer,
				'algorithm' => strtoupper( self::ALGO ),
				'digits'    => self::DIGITS,
				'period'    => self::PERIOD,
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
	}

	/**
	 * Code für einen Zeitpunkt berechnen.
	 */
	public static function codeAt( string $secret, ?int $timestamp = null ): string {
		$counter = intdiv( $timestamp ?? time(), self::PERIOD );

		return self::hotp( $secret, $counter );
	}

	/**
	 * Prüft einen eingegebenen Code.
	 *
	 * Das Fenster von einem Schritt vor und zurück fängt Uhren ab, die leicht
	 * auseinanderlaufen — mehr wäre eine unnötige Vergrösserung der Angriffsfläche.
	 */
	public static function verify( string $secret, string $code, int $window = 1, ?int $timestamp = null ): bool {
		$code = preg_replace( '/\D/', '', $code ) ?? '';

		if ( strlen( $code ) !== self::DIGITS ) {
			return false;
		}

		$counter = intdiv( $timestamp ?? time(), self::PERIOD );
		$valid   = false;

		// Alle Kandidaten durchlaufen, damit die Laufzeit nicht verrät, welcher passte.
		for ( $offset = -$window; $offset <= $window; $offset++ ) {
			if ( hash_equals( self::hotp( $secret, $counter + $offset ), $code ) ) {
				$valid = true;
			}
		}

		return $valid;
	}

	/**
	 * Sekunden bis zum nächsten Code — für die Anzeige im Einrichtungsdialog.
	 */
	public static function secondsRemaining( ?int $timestamp = null ): int {
		return self::PERIOD - ( ( $timestamp ?? time() ) % self::PERIOD );
	}

	/**
	 * Geheimnis in Vierergruppen — so lässt es sich abtippen.
	 */
	public static function formatSecret( string $secret ): string {
		return trim( chunk_split( $secret, 4, ' ' ) );
	}

	private static function hotp( string $secret, int $counter ): string {
		$key = self::base32Decode( $secret );

		if ( '' === $key ) {
			return str_repeat( '0', self::DIGITS );
		}

		$binary = hash_hmac( self::ALGO, pack( 'J', $counter ), $key, true );
		$offset = ord( $binary[ strlen( $binary ) - 1 ] ) & 0x0F;

		$value = ( ( ord( $binary[ $offset ] ) & 0x7F ) << 24 )
			| ( ( ord( $binary[ $offset + 1 ] ) & 0xFF ) << 16 )
			| ( ( ord( $binary[ $offset + 2 ] ) & 0xFF ) << 8 )
			| ( ord( $binary[ $offset + 3 ] ) & 0xFF );

		return str_pad( (string) ( $value % ( 10 ** self::DIGITS ) ), self::DIGITS, '0', STR_PAD_LEFT );
	}

	/* ------------------------------------------------------------- Base32 */

	public static function base32Encode( string $bytes ): string {
		if ( '' === $bytes ) {
			return '';
		}

		$bits = '';
		foreach ( str_split( $bytes ) as $byte ) {
			$bits .= str_pad( decbin( ord( $byte ) ), 8, '0', STR_PAD_LEFT );
		}

		$out = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= self::ALPHABET[ bindec( str_pad( $chunk, 5, '0', STR_PAD_RIGHT ) ) ];
		}

		return $out;
	}

	public static function base32Decode( string $secret ): string {
		$secret = strtoupper( preg_replace( '/[^A-Z2-7]/i', '', $secret ) ?? '' );

		if ( '' === $secret ) {
			return '';
		}

		$bits = '';
		foreach ( str_split( $secret ) as $char ) {
			$index = strpos( self::ALPHABET, $char );
			if ( false === $index ) {
				return '';
			}
			$bits .= str_pad( decbin( $index ), 5, '0', STR_PAD_LEFT );
		}

		$out = '';
		foreach ( str_split( $bits, 8 ) as $chunk ) {
			if ( 8 === strlen( $chunk ) ) {
				$out .= chr( bindec( $chunk ) );
			}
		}

		return $out;
	}

	/* -------------------------------------------------- Wiederherstellung */

	/**
	 * Acht Ersatzcodes im Format XXXX-XXXX.
	 *
	 * @return array<int,string>
	 */
	public static function generateRecoveryCodes( int $count = 8 ): array {
		$codes    = array();
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // ohne I, O, 0, 1

		for ( $i = 0; $i < $count; $i++ ) {
			$code = '';
			for ( $c = 0; $c < 8; $c++ ) {
				$code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
			}
			$codes[] = substr( $code, 0, 4 ) . '-' . substr( $code, 4 );
		}

		return $codes;
	}

	/**
	 * @param array<int,string> $codes
	 * @return array<int,string>
	 */
	public static function hashRecoveryCodes( array $codes ): array {
		return array_map(
			static fn( string $code ): string => password_hash( self::normalizeRecoveryCode( $code ), PASSWORD_DEFAULT ),
			$codes
		);
	}

	public static function normalizeRecoveryCode( string $code ): string {
		return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $code ) ?? '' );
	}
}
