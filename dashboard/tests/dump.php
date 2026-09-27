<?php
declare( strict_types = 1 );
$root = realpath( dirname( __DIR__ ) );
define( 'NL_SRC', $root . '/src' );
define( 'NL_VIEWS', $root . '/views' );
spl_autoload_register( static function ( string $c ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = NL_SRC . '/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );
function url( string $path = '' ): string { return 'https://panel.test' . $path; }
function csrf_field(): string { return '<input type="hidden" name="_token" value="t">'; }
require_once NL_SRC . '/helpers.php';
require_once NL_SRC . '/view-helpers.php';
$clients = array(); $pluginVersion = '1.1.0'; $old = array();
ob_start();
include NL_VIEWS . '/pages/sites/create.php';
$body = (string) ob_get_clean();
echo "<!doctype html><html lang=\"de\"><head><meta charset=\"utf-8\"><link rel=\"stylesheet\" href=\"app.css\"></head><body>$body<script src=\"app.js\" defer></script></body></html>";
