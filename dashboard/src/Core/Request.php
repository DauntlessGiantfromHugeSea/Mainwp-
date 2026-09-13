<?php

declare( strict_types = 1 );

namespace NorthLab\Core;

/**
 * Gekapselter Zugriff auf die eingehende Anfrage.
 */
final class Request {

	/** @var array<string,mixed> */
	private array $query;

	/** @var array<string,mixed> */
	private array $post;

	private string $rawBody;

	/** @var array<string,mixed>|null */
	private ?array $json = null;

	/** @var array<string,string> */
	public array $params = array();

	public function __construct() {
		$this->query   = $_GET;
		$this->post    = $_POST;
		$this->rawBody = (string) file_get_contents( 'php://input' );
	}

	public function method(): string {
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );

		// Formulare können via _method PUT/DELETE simulieren.
		if ( 'POST' === $method && isset( $this->post['_method'] ) ) {
			$override = strtoupper( (string) $this->post['_method'] );
			if ( in_array( $override, array( 'PUT', 'PATCH', 'DELETE' ), true ) ) {
				return $override;
			}
		}

		return $method;
	}

	public function path(): string {
		$uri  = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
		$path = (string) parse_url( $uri, PHP_URL_PATH );

		// Unterverzeichnis-Installationen: Skriptpfad abschneiden.
		$scriptDir = rtrim( str_replace( '\\', '/', dirname( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ) ) ), '/' );
		if ( '' !== $scriptDir && str_starts_with( $path, $scriptDir ) ) {
			$path = substr( $path, strlen( $scriptDir ) );
		}

		$path = '/' . ltrim( $path, '/' );

		return '/' !== $path ? rtrim( $path, '/' ) : '/';
	}

	public function get( string $key, mixed $default = null ): mixed {
		return $this->query[ $key ] ?? $default;
	}

	public function post( string $key, mixed $default = null ): mixed {
		return $this->post[ $key ] ?? $default;
	}

	/**
	 * Erst POST, dann GET.
	 */
	public function input( string $key, mixed $default = null ): mixed {
		return $this->post[ $key ] ?? $this->query[ $key ] ?? $default;
	}

	public function string( string $key, string $default = '' ): string {
		$value = $this->input( $key, $default );
		return is_scalar( $value ) ? trim( (string) $value ) : $default;
	}

	public function int( string $key, int $default = 0 ): int {
		$value = $this->input( $key, $default );
		return is_scalar( $value ) ? (int) $value : $default;
	}

	public function bool( string $key ): bool {
		$value = $this->input( $key );
		return in_array( (string) $value, array( '1', 'true', 'on', 'yes' ), true );
	}

	/**
	 * @return array<int,string>
	 */
	public function arrayOfStrings( string $key ): array {
		$value = $this->input( $key, array() );
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				$out[] = (string) $item;
			}
		}
		return $out;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function all(): array {
		return $this->post + $this->query;
	}

	public function rawBody(): string {
		return $this->rawBody;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function json(): array {
		if ( null === $this->json ) {
			$decoded    = json_decode( $this->rawBody, true );
			$this->json = is_array( $decoded ) ? $decoded : array();
		}
		return $this->json;
	}

	public function header( string $name ): string {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
		return (string) ( $_SERVER[ $key ] ?? '' );
	}

	public function ip(): string {
		$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );

		foreach ( $candidates as $key ) {
			$value = (string) ( $_SERVER[ $key ] ?? '' );
			if ( '' === $value ) {
				continue;
			}
			$first = trim( explode( ',', $value )[0] );
			if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
				return $first;
			}
		}

		return '';
	}

	public function userAgent(): string {
		return substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 );
	}

	public function isAjax(): bool {
		return 'xmlhttprequest' === strtolower( $this->header( 'X-Requested-With' ) );
	}

	public function wantsJson(): bool {
		return $this->isAjax() || str_contains( strtolower( $this->header( 'Accept' ) ), 'application/json' );
	}
}
