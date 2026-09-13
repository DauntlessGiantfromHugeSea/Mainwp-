<?php

declare( strict_types = 1 );

namespace NorthLab\Core;

use RuntimeException;

/**
 * Schlüsselpaare, Signaturen und verschlüsselte Ablage von Geheimnissen.
 */
final class Crypto {

	/**
	 * Erzeugt ein RSA-2048-Schlüsselpaar für eine neue Seitenverbindung.
	 *
	 * @return array{private:string,public:string}
	 */
	public static function generateKeypair(): array {
		$resource = openssl_pkey_new(
			array(
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
				'digest_alg'       => 'sha256',
			)
		);

		if ( false === $resource ) {
			throw new RuntimeException( 'Schlüsselpaar konnte nicht erzeugt werden: ' . self::opensslErrors() );
		}

		$private = '';
		if ( ! openssl_pkey_export( $resource, $private ) ) {
			throw new RuntimeException( 'Privater Schlüssel konnte nicht exportiert werden: ' . self::opensslErrors() );
		}

		$details = openssl_pkey_get_details( $resource );
		if ( ! $details || empty( $details['key'] ) ) {
			throw new RuntimeException( 'Öffentlicher Schlüssel konnte nicht gelesen werden.' );
		}

		return array( 'private' => $private, 'public' => (string) $details['key'] );
	}

	/**
	 * Signiert die kanonische Request-Zeichenkette (RSA-SHA256, base64).
	 */
	public static function sign( string $payload, string $privateKeyPem ): string {
		$key = openssl_pkey_get_private( $privateKeyPem );
		if ( false === $key ) {
			throw new RuntimeException( 'Privater Schlüssel ist ungültig.' );
		}

		$signature = '';
		if ( ! openssl_sign( $payload, $signature, $key, OPENSSL_ALGO_SHA256 ) ) {
			throw new RuntimeException( 'Signatur konnte nicht erzeugt werden: ' . self::opensslErrors() );
		}

		return base64_encode( $signature );
	}

	public static function connectionId(): string {
		return 'nl_' . bin2hex( random_bytes( 16 ) );
	}

	public static function monitorToken(): string {
		return bin2hex( random_bytes( 20 ) );
	}

	public static function secret( int $bytes = 24 ): string {
		return bin2hex( random_bytes( $bytes ) );
	}

	/**
	 * Neuer App-Schlüssel für config.php.
	 */
	public static function newAppKey(): string {
		return base64_encode( random_bytes( 32 ) );
	}

	/* --------------------------------------------------------- Symmetrisch */

	/**
	 * 32-Byte-Schlüssel aus der Konfiguration.
	 */
	private static function key(): string {
		$configured = (string) Config::get( 'app.key', '' );
		if ( '' === $configured ) {
			throw new RuntimeException( 'app.key ist nicht gesetzt. Bitte den Installer ausführen.' );
		}

		$raw = base64_decode( $configured, true );

		return ( false !== $raw && 32 === strlen( $raw ) ) ? $raw : hash( 'sha256', $configured, true );
	}

	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		$key = self::key();

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return 'v2:' . base64_encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, $key ) );
		}

		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			throw new RuntimeException( 'Verschlüsselung fehlgeschlagen.' );
		}
		$mac = hash_hmac( 'sha256', $iv . $cipher, $key, true );

		return 'v1:' . base64_encode( $iv . $mac . $cipher );
	}

	public static function decrypt( string $stored ): string {
		if ( '' === $stored ) {
			return '';
		}

		$key = self::key();

		if ( str_starts_with( $stored, 'v2:' ) ) {
			if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
				return '';
			}
			$raw = base64_decode( substr( $stored, 3 ), true );
			if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
				return '';
			}
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $key );
			return false === $plain ? '' : $plain;
		}

		if ( str_starts_with( $stored, 'v1:' ) ) {
			$raw = base64_decode( substr( $stored, 3 ), true );
			if ( false === $raw || strlen( $raw ) <= 48 ) {
				return '';
			}
			$iv     = substr( $raw, 0, 16 );
			$mac    = substr( $raw, 16, 32 );
			$cipher = substr( $raw, 48 );
			if ( ! hash_equals( hash_hmac( 'sha256', $iv . $cipher, $key, true ), $mac ) ) {
				return '';
			}
			$plain = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			return false === $plain ? '' : $plain;
		}

		return '';
	}

	private static function opensslErrors(): string {
		$messages = array();
		while ( $error = openssl_error_string() ) {
			$messages[] = $error;
		}
		return $messages ? implode( '; ', $messages ) : 'unbekannter Fehler';
	}
}
