<?php

declare( strict_types = 1 );

namespace NorthLab\Core;

/**
 * Antwort-Hilfen.
 */
final class Response {

	public static function html( string $content, int $status = 200 ): void {
		http_response_code( $status );
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: same-origin' );
		echo $content;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function json( array $data, int $status = 200 ): void {
		http_response_code( $status );
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'X-Content-Type-Options: nosniff' );
		echo (string) json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	public static function redirect( string $path, int $status = 302 ): never {
		$target = str_starts_with( $path, 'http' ) ? $path : url( $path );
		header( 'Location: ' . $target, true, $status );
		exit;
	}

	public static function download( string $file, string $filename, string $contentType = 'application/octet-stream' ): never {
		if ( ! is_file( $file ) ) {
			http_response_code( 404 );
			exit( 'Datei nicht gefunden.' );
		}

		header( 'Content-Type: ' . $contentType );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $filename ) . '"' );
		header( 'Content-Length: ' . (string) filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: no-store' );

		readfile( $file );
		exit;
	}

	public static function notFound( string $message = 'Seite nicht gefunden.' ): void {
		http_response_code( 404 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo '<!doctype html><meta charset="utf-8"><title>404</title>'
			. '<div style="font:16px/1.6 system-ui;padding:60px;text-align:center">'
			. '<h1 style="font-size:48px;margin:0">404</h1><p>' . e( $message ) . '</p>'
			. '<p><a href="' . e( url( '/' ) ) . '">Zur Übersicht</a></p></div>';
	}

	public static function forbidden( string $message = 'Kein Zugriff.' ): void {
		http_response_code( 403 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo '<!doctype html><meta charset="utf-8"><title>403</title>'
			. '<div style="font:16px/1.6 system-ui;padding:60px;text-align:center">'
			. '<h1 style="font-size:48px;margin:0">403</h1><p>' . e( $message ) . '</p></div>';
	}
}
