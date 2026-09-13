<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Config;
use NorthLab\Core\Crypto;
use NorthLab\Core\Http;
use NorthLab\Repository\SiteRepository;

/**
 * Signierter Client zum NorthLab-Child-Plugin einer Kundenseite.
 *
 * Kanonische Signaturzeichenkette (identisch im Child implementiert):
 *   connection_id \n timestamp \n nonce \n METHOD \n /northlab-child/v1<route> \n sha256(body)
 */
final class ChildClient {

	public const NAMESPACE_PATH = '/northlab-child/v1';

	/**
	 * Erstverbindung mit Einmal-Code (noch ohne Signatur).
	 *
	 * @param array<string,mixed> $site
	 * @return array{ok:bool,data:array<string,mixed>,error:string}
	 */
	public static function connect( array $site, string $code, string $publicKey ): array {
		$body = json_encode(
			array(
				'code'           => $code,
				'public_key'     => $publicKey,
				'connection_id'  => (string) $site['connection_id'],
				'dashboard_url'  => rtrim( (string) Config::get( 'app.url', '' ), '/' ),
				'dashboard_name' => \NorthLab\Core\Setting::get( 'agency_name', 'NorthLab' ),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		$response = array();

		foreach ( array( 'pretty', 'query' ) as $index => $style ) {
			$response = Http::postJson(
				self::endpoint( $site, '/connect', $style ),
				(string) $body,
				array(),
				(int) Config::get( 'http.timeout', 60 ),
				self::verifySsl( $site ),
				self::basicAuth( $site )
			);

			if ( 404 !== $response['status'] ) {
				self::rememberStyle( $site, $style );
				break;
			}
		}

		return self::interpret( $response );
	}

	/**
	 * @param array<string,mixed> $site
	 * @param array<string,mixed> $query
	 * @return array{ok:bool,data:array<string,mixed>,error:string,ms:int}
	 */
	public static function get( array $site, string $route, array $query = array(), ?int $timeout = null ): array {
		return self::request( $site, 'GET', $route, $query, null, $timeout );
	}

	/**
	 * @param array<string,mixed> $site
	 * @param array<string,mixed> $body
	 * @return array{ok:bool,data:array<string,mixed>,error:string,ms:int}
	 */
	public static function post( array $site, string $route, array $body = array(), ?int $timeout = null ): array {
		return self::request( $site, 'POST', $route, array(), $body, $timeout );
	}

	/**
	 * @param array<string,mixed>      $site
	 * @param array<string,mixed>      $query
	 * @param array<string,mixed>|null $body
	 * @return array{ok:bool,data:array<string,mixed>,error:string,ms:int}
	 */
	private static function request( array $site, string $method, string $route, array $query, ?array $body, ?int $timeout ): array {
		$encrypted = (string) ( $site['private_key'] ?? '' );
		if ( '' === $encrypted ) {
			return self::failure( 'Für diese Seite ist kein privater Schlüssel hinterlegt. Bitte neu verbinden.' );
		}

		$privateKey = Crypto::decrypt( $encrypted );
		if ( '' === $privateKey ) {
			return self::failure(
				'Der private Schlüssel konnte nicht entschlüsselt werden. Wurde app.key in der config.php geändert? Die Seite muss neu verbunden werden.'
			);
		}

		$json      = null === $body ? '' : (string) json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$timestamp = (string) time();
		$nonce     = bin2hex( random_bytes( 12 ) );

		$payload = implode(
			"\n",
			array(
				(string) $site['connection_id'],
				$timestamp,
				$nonce,
				strtoupper( $method ),
				self::NAMESPACE_PATH . $route,
				hash( 'sha256', $json ),
			)
		);

		$headers = array(
			'X-NL-Connection' => (string) $site['connection_id'],
			'X-NL-Timestamp'  => $timestamp,
			'X-NL-Nonce'      => $nonce,
			'X-NL-Signature'  => Crypto::sign( $payload, $privateKey ),
			'Accept'          => 'application/json',
		);

		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}

		$timeout  = $timeout ?? (int) Config::get( 'http.timeout', 60 );
		$primary  = (string) ( $site['rest_style'] ?? 'pretty' );
		$styles   = 'pretty' === $primary ? array( 'pretty', 'query' ) : array( 'query', 'pretty' );
		$response = array();

		foreach ( $styles as $index => $style ) {
			$url = self::endpoint( $site, $route, $style );
			if ( $query ) {
				$url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $query );
			}

			$response = Http::request(
				$method,
				$url,
				null === $body ? null : $json,
				$headers,
				$timeout,
				self::verifySsl( $site ),
				self::basicAuth( $site )
			);

			// Ein 404 heisst hier meist: diese Adressform gibt es auf der Seite nicht.
			// Alles andere — auch Fehler — ist eine echte Antwort und wird ausgewertet.
			if ( 404 !== $response['status'] || $index === count( $styles ) - 1 ) {
				if ( 404 !== $response['status'] ) {
					self::rememberStyle( $site, $style );
				}
				break;
			}
		}

		$result = self::interpret( $response );

		if ( $result['ok'] ) {
			SiteRepository::update( (int) $site['id'], array( 'last_seen_at' => nl_utc() ) );
		}

		return $result;
	}

	/**
	 * Wandelt die HTTP-Antwort in ein einheitliches Ergebnis mit sprechendem Fehlertext.
	 *
	 * @param array{ok:bool,status:int,body:string,error:string,ms:int,headers:array<string,string>} $response
	 * @return array{ok:bool,data:array<string,mixed>,error:string,ms:int}
	 */
	private static function interpret( array $response ): array {
		if ( '' !== $response['error'] ) {
			return self::failure( 'Seite nicht erreichbar: ' . $response['error'], $response['ms'] );
		}

		$decoded = json_decode( $response['body'], true );
		$data    = is_array( $decoded ) ? $decoded : array();

		if ( $response['status'] < 200 || $response['status'] >= 300 ) {
			$code    = (string) ( $data['code'] ?? '' );
			$message = (string) ( $data['message'] ?? sprintf( 'HTTP %d', $response['status'] ) );

			if ( 404 === $response['status'] ) {
				$message = 'Endpunkt nicht gefunden (HTTP 404). Das Panel hat beide Adressformen probiert '
					. '(/wp-json/… und ?rest_route=…). Prüfe: Ist das NorthLab-Child-Plugin aktiv? '
					. 'Blockiert ein Sicherheits-Plugin oder eine Firewall die REST-API? '
					. 'Zeigt die eingetragene URL auf die WordPress-Installation selbst (nicht auf eine Weiterleitung)?';
			} elseif ( 'nlc_not_connected' === $code ) {
				$message = 'Das Child-Plugin kennt diese Verbindung nicht mehr. Bitte die Seite neu verbinden.';
			} elseif ( 401 === $response['status'] ) {
				$message = 'Signatur abgelehnt (HTTP 401). Stimmen die Serverzeiten von Panel und Kundenseite überein?';
			} elseif ( 403 === $response['status'] && '' !== $message ) {
				$message = 'Zugriff verweigert: ' . $message;
			}

			return self::failure( $message, $response['ms'] );
		}

		if ( ! is_array( $decoded ) ) {
			$excerpt = trim( strip_tags( substr( $response['body'], 0, 200 ) ) );
			return self::failure( 'Antwort war kein gültiges JSON' . ( '' !== $excerpt ? ': ' . $excerpt : '.' ), $response['ms'] );
		}

		return array( 'ok' => true, 'data' => $data, 'error' => '', 'ms' => $response['ms'] );
	}

	/**
	 * @return array{ok:bool,data:array<string,mixed>,error:string,ms:int}
	 */
	private static function failure( string $message, int $ms = 0 ): array {
		return array( 'ok' => false, 'data' => array(), 'error' => $message, 'ms' => $ms );
	}

	/**
	 * Adresse des Endpunkts.
	 *
	 * WordPress bietet die REST-API in zwei Formen an: unter /wp-json/… nur dann,
	 * wenn sprechende Permalinks aktiv sind, sonst ausschliesslich über den
	 * Parameter ?rest_route=. Welche Form eine Seite versteht, merkt sich das Panel.
	 *
	 * @param array<string,mixed> $site
	 */
	private static function endpoint( array $site, string $route, ?string $style = null ): string {
		$base  = rtrim( (string) $site['url'], '/' );
		$style = $style ?? (string) ( $site['rest_style'] ?? 'pretty' );

		if ( 'query' === $style ) {
			return $base . '/?rest_route=' . rawurlencode( self::NAMESPACE_PATH . $route );
		}

		return $base . '/wp-json' . self::NAMESPACE_PATH . $route;
	}

	/**
	 * Hält fest, über welche Form die Seite antwortet — der Rückfall soll
	 * nicht bei jeder Anfrage erneut durchlaufen werden.
	 *
	 * @param array<string,mixed> $site
	 */
	private static function rememberStyle( array $site, string $style ): void {
		if ( (string) ( $site['rest_style'] ?? 'pretty' ) !== $style && ! empty( $site['id'] ) ) {
			SiteRepository::update( (int) $site['id'], array( 'rest_style' => $style ) );
		}
	}

	/**
	 * @param array<string,mixed> $site
	 */
	private static function verifySsl( array $site ): bool {
		if ( ! Config::get( 'http.verify_ssl', true ) ) {
			return false;
		}
		return ! empty( $site['verify_ssl'] );
	}

	/**
	 * @param array<string,mixed> $site
	 */
	private static function basicAuth( array $site ): ?string {
		$user = (string) ( $site['http_user'] ?? '' );
		if ( '' === $user ) {
			return null;
		}

		return $user . ':' . Crypto::decrypt( (string) ( $site['http_pass'] ?? '' ) );
	}
}
