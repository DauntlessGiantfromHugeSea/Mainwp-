<?php
/**
 * Winziger Server, der nur Response benutzt.
 *
 * Kopfzeilen lassen sich in der Kommandozeile nicht pruefen — header() tut
 * dort nichts. Also einmal wirklich ueber HTTP.
 */
declare( strict_types = 1 );

$root = dirname( __DIR__, 2 );
define( 'NL_ROOT', $root );
define( 'NL_SRC', $root . '/src' );

spl_autoload_register( static function ( string $c ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = NL_SRC . '/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );

require_once NL_SRC . '/helpers.php';

use NorthLab\Core\Response;

$pfad = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );

switch ( $pfad ) {
	case '/404':
		Response::notFound( 'Nichts da.' );
		break;

	case '/403':
		Response::forbidden( 'Kein Zugriff.' );
		break;

	default:
		Response::html( '<!doctype html><title>ok</title><p>ok' );
}
