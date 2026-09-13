<?php
/**
 * Gemeinsamer Einstiegspunkt für Web und CLI.
 */

declare( strict_types = 1 );

define( 'NL_START', microtime( true ) );
define( 'NL_VERSION', '1.0.0' );
define( 'NL_ROOT', dirname( __DIR__ ) );
define( 'NL_SRC', NL_ROOT . '/src' );
define( 'NL_VIEWS', NL_ROOT . '/views' );
define( 'NL_STORAGE', NL_ROOT . '/storage' );
define( 'NL_RESOURCES', NL_ROOT . '/resources' );
define( 'NL_CONFIG_FILE', NL_ROOT . '/config.php' );

if ( PHP_VERSION_ID < 80100 ) {
	http_response_code( 500 );
	exit( 'NorthLab benötigt PHP 8.1 oder neuer. Gefunden: ' . PHP_VERSION );
}

foreach ( array( 'pdo_mysql', 'openssl', 'json', 'mbstring', 'curl' ) as $nl_ext ) {
	if ( ! extension_loaded( $nl_ext ) ) {
		http_response_code( 500 );
		exit( 'Die PHP-Erweiterung "' . $nl_ext . '" fehlt. NorthLab kann ohne sie nicht starten.' );
	}
}

/**
 * PSR-4-artiger Autoloader für den Namensraum NorthLab\.
 */
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'NorthLab\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$path     = NL_SRC . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $path ) ) {
			require_once $path;
		}
	}
);

require_once NL_SRC . '/helpers.php';
require_once NL_SRC . '/view-helpers.php';

use NorthLab\Core\Config;
use NorthLab\Core\Database;
use NorthLab\Core\Logger;

Config::load( NL_CONFIG_FILE );

date_default_timezone_set( Config::get( 'app.timezone', 'Europe/Berlin' ) );
mb_internal_encoding( 'UTF-8' );

$nl_debug = (bool) Config::get( 'app.debug', false );
ini_set( 'display_errors', $nl_debug ? '1' : '0' );
error_reporting( $nl_debug ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE );

set_exception_handler(
	static function ( Throwable $e ): void {
		Logger::exception( $e );
		if ( PHP_SAPI === 'cli' ) {
			fwrite( STDERR, 'Fehler: ' . $e->getMessage() . PHP_EOL );
			exit( 1 );
		}
		http_response_code( 500 );
		if ( \NorthLab\Core\Config::get( 'app.debug', false ) ) {
			echo '<pre style="padding:20px;font:13px/1.5 ui-monospace,monospace">'
				. htmlspecialchars( (string) $e, ENT_QUOTES ) . '</pre>';
		} else {
			echo '<h1>Interner Fehler</h1><p>Details stehen in storage/logs/app.log.</p>';
		}
		exit;
	}
);

if ( Config::isInstalled() ) {
	Database::boot( Config::get( 'db' ) );
}
