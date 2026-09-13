<?php

declare( strict_types = 1 );

namespace NorthLab\Core;

/**
 * Minimaler Router mit Platzhaltern: /sites/{id}, /uptime/{token}
 */
final class Router {

	/** @var array<int,array{method:string,pattern:string,regex:string,keys:array<int,string>,handler:callable|array}> */
	private array $routes = array();

	/**
	 * @param callable|array{0:string,1:string} $handler
	 */
	public function add( string $method, string $pattern, callable|array $handler ): void {
		[ $regex, $keys ] = self::compile( $pattern );

		$this->routes[] = array(
			'method'  => strtoupper( $method ),
			'pattern' => $pattern,
			'regex'   => $regex,
			'keys'    => $keys,
			'handler' => $handler,
		);
	}

	/**
	 * Übersetzt ein Muster wie /api/uptime/{token:[a-f0-9]{32,64}} in einen regulären Ausdruck.
	 *
	 * Die Klammern werden gezählt statt per Regex gesucht, damit Quantoren wie {32,64}
	 * innerhalb einer Einschränkung nicht als Ende des Platzhalters gelesen werden.
	 *
	 * @return array{0:string,1:array<int,string>}
	 */
	private static function compile( string $pattern ): array {
		$keys   = array();
		$regex  = '';
		$length = strlen( $pattern );
		$cursor = 0;

		while ( $cursor < $length ) {
			$open = strpos( $pattern, '{', $cursor );

			if ( false === $open ) {
				$regex .= preg_quote( substr( $pattern, $cursor ), '#' );
				break;
			}

			$regex .= preg_quote( substr( $pattern, $cursor, $open - $cursor ), '#' );

			// Passende schließende Klammer suchen, verschachtelte mitzählen.
			$depth = 1;
			$scan  = $open + 1;
			while ( $scan < $length && $depth > 0 ) {
				if ( '{' === $pattern[ $scan ] ) {
					$depth++;
				} elseif ( '}' === $pattern[ $scan ] ) {
					$depth--;
				}
				$scan++;
			}

			if ( $depth > 0 ) {
				// Unausgeglichene Klammer: Rest wörtlich behandeln.
				$regex .= preg_quote( substr( $pattern, $open ), '#' );
				break;
			}

			$inside     = substr( $pattern, $open + 1, $scan - $open - 2 );
			$colon      = strpos( $inside, ':' );
			$name       = false === $colon ? $inside : substr( $inside, 0, $colon );
			$constraint = false === $colon ? '[^/]+' : substr( $inside, $colon + 1 );

			$keys[] = $name;
			$regex .= '(' . $constraint . ')';

			$cursor = $scan;
		}

		return array( '#^' . $regex . '$#', $keys );
	}

	/**
	 * @param callable|array{0:string,1:string} $handler
	 */
	public function get( string $pattern, callable|array $handler ): void {
		$this->add( 'GET', $pattern, $handler );
	}

	/**
	 * @param callable|array{0:string,1:string} $handler
	 */
	public function post( string $pattern, callable|array $handler ): void {
		$this->add( 'POST', $pattern, $handler );
	}

	/**
	 * @param callable|array{0:string,1:string} $handler
	 */
	public function any( string $pattern, callable|array $handler ): void {
		$this->add( 'GET', $pattern, $handler );
		$this->add( 'POST', $pattern, $handler );
	}

	public function dispatch( Request $request ): void {
		$path   = $request->path();
		$method = $request->method();

		// HEAD wird wie GET behandelt: der Webserver verwirft den Rumpf.
		// Sonst scheitern Health-Checks, Monitoring und "curl -I" mit 405.
		if ( 'HEAD' === $method ) {
			$method = 'GET';
		}

		$pathMatched = false;

		foreach ( $this->routes as $route ) {
			if ( ! preg_match( $route['regex'], $path, $matches ) ) {
				continue;
			}

			$pathMatched = true;

			if ( $route['method'] !== $method ) {
				continue;
			}

			array_shift( $matches );
			foreach ( $route['keys'] as $index => $key ) {
				$request->params[ $key ] = (string) ( $matches[ $index ] ?? '' );
			}

			$this->invoke( $route['handler'], $request );
			return;
		}

		if ( $pathMatched ) {
			http_response_code( 405 );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			echo 'Methode nicht erlaubt.';
			return;
		}

		Response::notFound();
	}

	/**
	 * @param callable|array{0:string,1:string} $handler
	 */
	private function invoke( callable|array $handler, Request $request ): void {
		if ( is_array( $handler ) ) {
			[ $class, $action ] = $handler;
			$controller         = new $class();
			$controller->$action( $request );
			return;
		}

		$handler( $request );
	}
}
