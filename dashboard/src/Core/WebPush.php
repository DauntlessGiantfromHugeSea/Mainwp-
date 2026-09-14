<?php

declare( strict_types = 1 );

namespace NorthLab\Core;

use RuntimeException;

/**
 * Web Push ohne Fremdbibliothek.
 *
 * Zwei Verfahren greifen ineinander:
 *
 *  - VAPID (RFC 8292): Das Panel weist sich beim Push-Dienst des Browsers mit
 *    einem signierten Token aus. Der Schlüssel gehört dem Panel, nicht dem Gerät.
 *  - Nachrichtenverschlüsselung (RFC 8291 auf Basis von RFC 8188): Der Inhalt
 *    wird für genau ein Gerät verschlüsselt. Der Push-Dienst leitet nur weiter
 *    und kann nicht mitlesen.
 *
 * Beides steckt in OpenSSL, das PHP ohnehin mitbringt — es braucht kein Composer.
 */
final class WebPush {

	/** Fester Kopf einer DER-kodierten P-256-Schlüsselangabe. */
	private const P256_SPKI_HEADER = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

	/** Länge der Ableitung für Inhaltsschlüssel und Nonce. */
    private const KEY_LENGTH   = 16;
	private const NONCE_LENGTH = 12;

	/* ------------------------------------------------------------ Schlüssel */

	/**
	 * Erzeugt ein VAPID-Schlüsselpaar.
	 *
	 * @return array{public:string,private:string} Beide base64url, wie der Browser sie erwartet.
	 */
	public static function generateKeys(): array {
		$key = openssl_pkey_new(
			array(
				'curve_name'       => 'prime256v1',
				'private_key_type' => OPENSSL_KEYTYPE_EC,
			)
		);

		if ( false === $key ) {
			throw new RuntimeException( 'VAPID-Schlüsselpaar konnte nicht erzeugt werden.' );
		}

		$details = openssl_pkey_get_details( $key );

		if ( ! is_array( $details ) || empty( $details['ec'] ) ) {
			throw new RuntimeException( 'Schlüsseldetails konnten nicht gelesen werden.' );
		}

		return array(
			'public'  => self::b64( self::point( $details['ec']['x'], $details['ec']['y'] ) ),
			'private' => self::b64( str_pad( (string) $details['ec']['d'], 32, "\0", STR_PAD_LEFT ) ),
		);
	}

	/* ------------------------------------------------------------- Versand */

	/**
	 * Verschlüsselte Nachricht an einen Endpunkt schicken.
	 *
	 * @param array{endpoint:string,p256dh:string,auth:string} $subscription
	 * @param array<string,mixed>                              $payload
	 * @param array{public:string,private:string,subject:string} $vapid
	 * @return array{status:int,error:string}
	 */
	public static function send( array $subscription, array $payload, array $vapid, int $ttl = 86400, int $timeout = 10 ): array {
		$body = (string) json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		try {
			$encrypted = self::encrypt( $body, (string) $subscription['p256dh'], (string) $subscription['auth'] );
			$headers   = self::headers( (string) $subscription['endpoint'], $vapid, strlen( $encrypted ), $ttl );
		} catch ( RuntimeException $e ) {
			return array( 'status' => 0, 'error' => $e->getMessage() );
		}

		$response = Http::request(
			'POST',
			(string) $subscription['endpoint'],
			$encrypted,
			$headers,
			$timeout
		);

		return array(
			'status' => (int) $response['status'],
			'error'  => (string) $response['error'],
		);
	}

	/**
	 * Kopfzeilen inklusive VAPID-Nachweis.
	 *
	 * @param array{public:string,private:string,subject:string} $vapid
	 * @return array<string,string>
	 */
	private static function headers( string $endpoint, array $vapid, int $length, int $ttl ): array {
		$parts = parse_url( $endpoint );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			throw new RuntimeException( 'Push-Endpunkt ist keine gültige Adresse.' );
		}

		// Der Empfaenger ist der Ursprung, und dazu gehoert ein abweichender Port.
		$audience = $parts['scheme'] . '://' . $parts['host'];

		if ( ! empty( $parts['port'] ) ) {
			$default = 'https' === $parts['scheme'] ? 443 : 80;

			if ( (int) $parts['port'] !== $default ) {
				$audience .= ':' . (int) $parts['port'];
			}
		}
		$token    = self::vapidToken( $audience, $vapid );

		return array(
			'Authorization'    => 'vapid t=' . $token . ', k=' . $vapid['public'],
			'Content-Type'     => 'application/octet-stream',
			'Content-Encoding' => 'aes128gcm',
			'Content-Length'   => (string) $length,
			'TTL'              => (string) max( 0, $ttl ),
			'Urgency'          => 'normal',
		);
	}

	/**
	 * Signiertes Token nach RFC 8292.
	 *
	 * @param array{public:string,private:string,subject:string} $vapid
	 */
	public static function vapidToken( string $audience, array $vapid ): string {
		$header = self::b64( (string) json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) );

		$claims = self::b64(
			(string) json_encode(
				array(
					'aud' => $audience,
					// Zwoelf Stunden; laenger als 24 lehnen die Dienste ab.
					'exp' => time() + 43200,
					'sub' => $vapid['subject'],
				),
				JSON_UNESCAPED_SLASHES
			)
		);

		$input   = $header . '.' . $claims;
		$private = self::privateKeyFrom( (string) $vapid['private'], (string) $vapid['public'] );

		$der = '';
		if ( ! openssl_sign( $input, $der, $private, OPENSSL_ALGO_SHA256 ) ) {
			throw new RuntimeException( 'VAPID-Token konnte nicht signiert werden.' );
		}

		return $input . '.' . self::b64( self::derToRaw( $der ) );
	}

	/* -------------------------------------------------- Verschlüsselung */

	/**
	 * Nachricht für genau ein Gerät verschlüsseln (RFC 8291, aes128gcm).
	 *
	 * @param string $plaintext Klartext.
	 * @param string $p256dh    Öffentlicher Schlüssel des Geräts, base64url.
	 * @param string $authB64   Geteiltes Geheimnis des Geräts, base64url.
	 */
	public static function encrypt( string $plaintext, string $p256dh, string $authB64, ?string $forcedSalt = null, ?array $forcedServerKey = null ): string {
		$userPublic = self::unb64( $p256dh );
		$auth       = self::unb64( $authB64 );

		if ( 65 !== strlen( $userPublic ) || "\x04" !== $userPublic[0] ) {
			throw new RuntimeException( 'Der öffentliche Schlüssel des Geräts hat ein unerwartetes Format.' );
		}
		if ( 16 !== strlen( $auth ) ) {
			throw new RuntimeException( 'Das Geheimnis des Geräts hat eine unerwartete Länge.' );
		}

		// Für jede Nachricht ein frisches Schlüsselpaar — so lässt sich aus zwei
		// Nachrichten an dasselbe Gerät nichts miteinander verrechnen.
		if ( null !== $forcedServerKey ) {
			$serverPublic  = $forcedServerKey['public'];
			$serverPrivate = $forcedServerKey['private'];
		} else {
			$pair          = self::ephemeralPair();
			$serverPublic  = $pair['public'];
			$serverPrivate = $pair['private'];
		}

		$shared = openssl_pkey_derive( self::publicKeyFrom( $userPublic ), $serverPrivate, 32 );

		if ( false === $shared ) {
			throw new RuntimeException( 'Gemeinsames Geheimnis konnte nicht abgeleitet werden: ' . self::opensslErrors() );
		}

		$salt = $forcedSalt ?? random_bytes( 16 );

		// Erst mit dem Geraetegeheimnis: bindet den Schluessel an dieses Abonnement.
		$prk = hash_hkdf(
			'sha256',
			$shared,
			32,
			"WebPush: info\0" . $userPublic . $serverPublic,
			$auth
		);

		$contentKey = hash_hkdf( 'sha256', $prk, self::KEY_LENGTH, "Content-Encoding: aes128gcm\0", $salt );
		$nonce      = hash_hkdf( 'sha256', $prk, self::NONCE_LENGTH, "Content-Encoding: nonce\0", $salt );

		// Ein einzelner Datensatz, deshalb das Ende-Zeichen 0x02.
		$padded = $plaintext . "\x02";

		$tag        = '';
		$ciphertext = openssl_encrypt( $padded, 'aes-128-gcm', $contentKey, OPENSSL_RAW_DATA, $nonce, $tag );

		if ( false === $ciphertext ) {
			throw new RuntimeException( 'Nachricht konnte nicht verschlüsselt werden: ' . self::opensslErrors() );
		}

		// Kopf nach RFC 8188: Salz, Datensatzgroesse, Laenge und Schluessel des Absenders.
		return $salt
			. pack( 'N', 4096 )
			. chr( strlen( $serverPublic ) )
			. $serverPublic
			. $ciphertext
			. $tag;
	}

	/* ------------------------------------------------------------ Bausteine */

	/**
	 * @return array{public:string,private:\OpenSSLAsymmetricKey}
	 */
	private static function ephemeralPair(): array {
		$key = openssl_pkey_new(
			array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC )
		);

		if ( false === $key ) {
			throw new RuntimeException( 'Flüchtiges Schlüsselpaar konnte nicht erzeugt werden.' );
		}

		$details = openssl_pkey_get_details( $key );

		return array(
			'public'  => self::point( $details['ec']['x'], $details['ec']['y'] ),
			'private' => $key,
		);
	}

	/**
	 * Unkomprimierter Punkt: 0x04 || X || Y, beide auf 32 Byte aufgefüllt.
	 */
	private static function point( string $x, string $y ): string {
		return "\x04" . str_pad( $x, 32, "\0", STR_PAD_LEFT ) . str_pad( $y, 32, "\0", STR_PAD_LEFT );
	}

	/**
	 * Rohen Punkt in einen Schlüssel verwandeln, mit dem OpenSSL rechnen kann.
	 *
	 * @return \OpenSSLAsymmetricKey
	 */
	private static function publicKeyFrom( string $point ) {
		$der = hex2bin( self::P256_SPKI_HEADER ) . $point;
		$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";

		$key = openssl_pkey_get_public( $pem );

		if ( false === $key ) {
			throw new RuntimeException( 'Öffentlicher Schlüssel des Geräts ist ungültig.' );
		}

		return $key;
	}

	/**
	 * Privaten VAPID-Schlüssel aus den gespeicherten Rohwerten aufbauen.
	 *
	 * @return \OpenSSLAsymmetricKey
	 */
	private static function privateKeyFrom( string $privateB64, string $publicB64 ) {
		$d     = self::unb64( $privateB64 );
		$point = self::unb64( $publicB64 );

		if ( 32 !== strlen( $d ) || 65 !== strlen( $point ) ) {
			throw new RuntimeException( 'Das VAPID-Schlüsselpaar hat ein unerwartetes Format.' );
		}

		// SEC1-Struktur: Version, privater Wert, Kurve, öffentlicher Punkt.
		$der = self::seq(
			self::int( "\x01" )
			. self::tag( 0x04, $d )
			. self::tag( 0xa0, hex2bin( '06082a8648ce3d030107' ) )
			. self::tag( 0xa1, self::tag( 0x03, "\x00" . $point ) )
		);

		$pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END EC PRIVATE KEY-----\n";

		$key = openssl_pkey_get_private( $pem );

		if ( false === $key ) {
			throw new RuntimeException( 'Privater VAPID-Schlüssel ist ungültig: ' . self::opensslErrors() );
		}

		return $key;
	}

	/** DER: SEQUENCE. */
	private static function seq( string $content ): string {
		return self::tag( 0x30, $content );
	}

	/** DER: INTEGER. */
	private static function int( string $content ): string {
		return self::tag( 0x02, $content );
	}

	/** DER: beliebiges Element mit Längenangabe. */
	private static function tag( int $type, string $content ): string {
		$length = strlen( $content );

		if ( $length < 128 ) {
			$prefix = chr( $length );
		} else {
			$bytes  = ltrim( pack( 'N', $length ), "\0" );
			$prefix = chr( 0x80 | strlen( $bytes ) ) . $bytes;
		}

		return chr( $type ) . $prefix . $content;
	}

	/**
	 * OpenSSL liefert die Signatur als DER-Sequenz zweier Zahlen; JWT erwartet
	 * beide roh hintereinander, je 32 Byte.
	 */
	private static function derToRaw( string $der ): string {
		$offset = 0;

		$read = static function ( string $data, int &$offset ): string {
			if ( "\x02" !== $data[ $offset ] ) {
				throw new RuntimeException( 'Signatur hat ein unerwartetes Format.' );
			}
			$length  = ord( $data[ $offset + 1 ] );
			$value   = substr( $data, $offset + 2, $length );
			$offset += 2 + $length;

			// Fuehrende Null steht nur da, damit die Zahl nicht negativ wirkt.
			return str_pad( ltrim( $value, "\0" ), 32, "\0", STR_PAD_LEFT );
		};

		if ( "\x30" !== $der[0] ) {
			throw new RuntimeException( 'Signatur hat ein unerwartetes Format.' );
		}

		// Laengenfeld ueberspringen, ein- oder zweistufig.
		$offset = ord( $der[1] ) < 128 ? 2 : 2 + ( ord( $der[1] ) & 0x7f );

		$r = $read( $der, $offset );
		$s = $read( $der, $offset );

		return $r . $s;
	}

	public static function b64( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	public static function unb64( string $encoded ): string {
		$encoded = strtr( trim( $encoded ), '-_', '+/' );
		$padded  = str_pad( $encoded, (int) ( ceil( strlen( $encoded ) / 4 ) * 4 ), '=', STR_PAD_RIGHT );

		return (string) base64_decode( $padded, true );
	}

	private static function opensslErrors(): string {
		$messages = array();

		while ( $error = openssl_error_string() ) {
			$messages[] = $error;
		}

		return $messages ? implode( '; ', $messages ) : 'unbekannter Fehler';
	}
}
