<?php

declare( strict_types = 1 );

namespace NorthLab\Core;

use RuntimeException;

/**
 * PHP-Templates mit gemeinsamem Layout.
 */
final class View {

	/**
	 * Werte, die ein Template an sein Layout durchreicht (Titel, Kopfzeilen-Aktionen).
	 *
	 * @var array<string,mixed>
	 */
	private static array $shared = array();

	/**
	 * Aus einem Template heraus einen Wert ans Layout geben.
	 */
	public static function set( string $key, mixed $value ): void {
		self::$shared[ $key ] = $value;
	}

	/**
	 * Rendert ein Template innerhalb des Layouts.
	 *
	 * @param array<string,mixed> $data
	 */
	public static function render( string $template, array $data = array(), string $layout = 'layout/app' ): void {
		self::$shared = array();

		$content = self::capture( 'pages/' . $template, $data );

		// Was das Template via View::set() gesetzt hat, gewinnt gegenüber den Controller-Daten.
		$data = array_merge( $data, self::$shared );

		$data['content'] = $content;
		$data['flash']   = Session::takeFlash();

		Response::html( self::capture( $layout, $data ) );
	}

	/**
	 * Rendert ein Template ohne Layout (z. B. für AJAX-Fragmente).
	 *
	 * @param array<string,mixed> $data
	 */
	public static function partial( string $template, array $data = array() ): string {
		return self::capture( 'partials/' . $template, $data );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function capture( string $template, array $data = array() ): string {
		$file = NL_VIEWS . '/' . $template . '.php';

		if ( ! is_file( $file ) ) {
			throw new RuntimeException( 'Template nicht gefunden: ' . $template );
		}

		extract( $data, EXTR_SKIP );

		ob_start();
		include $file;

		return (string) ob_get_clean();
	}
}
