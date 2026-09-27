<?php
declare( strict_types = 1 );
define( 'NL_ROOT', realpath( dirname( __DIR__ ) ) );
function url( string $path = '' ): string { return 'https://panel.test' . $path; }
require_once NL_ROOT . '/src/helpers.php';

$fails = 0;
$check = static function ( string $label, bool $ok ) use ( &$fails ): void {
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label\n"; }
};

$js   = nl_asset( '/assets/js/app.js' );
$css  = nl_asset( '/assets/css/app.css' );
$gone = nl_asset( '/assets/js/gibtsnicht.js' );

$check( 'app.js bekommt einen Stempel', (bool) preg_match( '#^https://panel\.test/assets/js/app\.js\?v=\d{10,}$#', $js ) );
$check( 'app.css bekommt einen Stempel', (bool) preg_match( '#^https://panel\.test/assets/css/app\.css\?v=\d{10,}$#', $css ) );
$check( 'Stempel unterscheiden sich je Datei', $js !== $css );
$check( 'Fehlende Datei bricht nicht ab', str_ends_with( $gone, '?v=1' ) );

// Nach einer Aenderung muss sich der Stempel aendern.
$before = nl_asset( '/assets/js/app.js' );
touch( NL_ROOT . '/public/assets/js/app.js', time() + 60 );
clearstatcache();
$check( 'Neuer Stempel nach Dateiaenderung', nl_asset( '/assets/js/app.js' ) !== $before );
touch( NL_ROOT . '/public/assets/js/app.js' );

printf( "5 Prüfungen, %d Fehler\n", $fails );
exit( $fails ? 1 : 0 );
