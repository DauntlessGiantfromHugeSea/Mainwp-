<?php

declare( strict_types = 1 );

namespace NorthLab\Core;

/**
 * cURL-Wrapper mit einheitlicher Antwortstruktur.
 */
final class Http {

	/**
	 * @param array<string,string> $headers
	 * @return array{ok:bool,status:int,body:string,error:string,ms:int,headers:array<string,string>}
	 */
	public static function request(
		string $method,
		string $url,
		?string $body = null,
		array $headers = array(),
		int $timeout = 60,
		bool $verifySsl = true,
		?string $basicAuth = null
	): array {
		$started = microtime( true );

		$handle = curl_init();

		$headerLines = array();
		foreach ( $headers as $name => $value ) {
			$headerLines[] = $name . ': ' . $value;
		}

		$responseHeaders = array();

		curl_setopt_array(
			$handle,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_CUSTOMREQUEST  => strtoupper( $method ),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 3,
				CURLOPT_TIMEOUT        => $timeout,
				CURLOPT_CONNECTTIMEOUT => min( 20, $timeout ),
				CURLOPT_SSL_VERIFYPEER => $verifySsl,
				CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
				CURLOPT_HTTPHEADER     => $headerLines,
				CURLOPT_USERAGENT      => 'NorthLabPanel/' . NL_VERSION,
				CURLOPT_ENCODING       => '',
				CURLOPT_HEADERFUNCTION => static function ( $ch, string $line ) use ( &$responseHeaders ): int {
					$length = strlen( $line );
					$parts  = explode( ':', $line, 2 );
					if ( count( $parts ) === 2 ) {
						$responseHeaders[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
					}
					return $length;
				},
			)
		);

		if ( null !== $body ) {
			curl_setopt( $handle, CURLOPT_POSTFIELDS, $body );
		}
		if ( null !== $basicAuth && '' !== $basicAuth ) {
			curl_setopt( $handle, CURLOPT_USERPWD, $basicAuth );
		}

		$responseBody = curl_exec( $handle );
		$status       = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		$error        = (string) curl_error( $handle );

		curl_close( $handle );

		return array(
			'ok'      => '' === $error && $status >= 200 && $status < 300,
			'status'  => $status,
			'body'    => is_string( $responseBody ) ? $responseBody : '',
			'error'   => $error,
			'ms'      => (int) round( ( microtime( true ) - $started ) * 1000 ),
			'headers' => $responseHeaders,
		);
	}

	/**
	 * @param array<string,string> $headers
	 * @return array{ok:bool,status:int,body:string,error:string,ms:int,headers:array<string,string>}
	 */
	public static function get( string $url, array $headers = array(), int $timeout = 60, bool $verifySsl = true, ?string $basicAuth = null ): array {
		return self::request( 'GET', $url, null, $headers, $timeout, $verifySsl, $basicAuth );
	}

	/**
	 * @param array<string,string> $headers
	 * @return array{ok:bool,status:int,body:string,error:string,ms:int,headers:array<string,string>}
	 */
	public static function postJson( string $url, string $json, array $headers = array(), int $timeout = 60, bool $verifySsl = true, ?string $basicAuth = null ): array {
		$headers['Content-Type'] = 'application/json';
		$headers['Accept']       = 'application/json';
		return self::request( 'POST', $url, $json, $headers, $timeout, $verifySsl, $basicAuth );
	}

	/**
	 * Prüft, ob eine URL syntaktisch gültig und öffentlich adressierbar aussieht.
	 */
	public static function isValidUrl( string $url ): bool {
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		$host   = (string) parse_url( $url, PHP_URL_HOST );

		return in_array( $scheme, array( 'http', 'https' ), true ) && '' !== $host;
	}
}
