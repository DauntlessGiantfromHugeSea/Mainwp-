<?php
/**
 * Annahme des Logos, Bereinigung, SVG-Zusammenbau und Ausliefern.
 */
declare( strict_types = 1 );

/**
 * Eigene Setting-Klasse, bevor der Autoloader die echte laedt — die ginge an
 * die Datenbank. Der Autoloader ueberspringt, was schon deklariert ist.
 */
namespace NorthLab\Core {
	class Setting {
		/** @var array<string,string> */
		public static array $values = array( 'icon_bg' => '#000000', 'icon_padding' => '18' );

		public static function get( string $name, $default = '' ) {
			return self::$values[ $name ] ?? $default;
		}

		public static function set( string $name, string $value ): void {
			self::$values[ $name ] = $value;
		}
	}
}

namespace {

$root = realpath( dirname( __DIR__ ) );
define( 'NL_ROOT', $root );
define( 'NL_SRC', $root . '/src' );
define( 'NL_STORAGE', sys_get_temp_dir() . '/nlc-icon-test' );
define( 'NL_VERSION', '1.0.0' );

spl_autoload_register( static function ( string $c ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = NL_SRC . '/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );

use NorthLab\Service\IconService;
use NorthLab\Core\Setting;


$fails = 0;
$n     = 0;
function check( string $label, bool $ok ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label\n"; }
}

IconService::reset();

/* -------------------------------------------------- Ohne eigenes Logo */

check( 'Ohne Logo ist keine Quelle da', ! IconService::hasSource() );
check( 'Und kein eigenes Symbol', ! IconService::hasOwnRaster() );
check( 'Das SVG faellt auf die Marke zurueck', str_contains( IconService::svg(), '<svg' ) );
check( 'Die 192er-Fassung kommt aus dem Lieferumfang',
	str_contains( (string) IconService::rasterPath( 'icon-192' ), '/public/assets/icons/' ) );
check( 'Unbekannte Groesse gibt es nicht', null === IconService::rasterPath( 'icon-9999' ) );

/* --------------------------------------------------------- Annahme */

$png = base64_decode(
	'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
);
$dir = IconService::directory();

// Direkt ablegen, als kaeme es vom Upload.
$store = new ReflectionMethod( IconService::class, 'store' );
$store->setAccessible( true );

check( 'Leere Datei wird abgelehnt', null !== $store->invoke( null, '', 'image/png' ) );
check( 'Unbekanntes Format wird abgelehnt', null !== $store->invoke( null, 'xx', 'application/pdf' ) );
check( 'Zu grosse Datei wird abgelehnt', null !== $store->invoke( null, str_repeat( 'x', 3000000 ), 'image/png' ) );

check( 'Ein PNG wird angenommen', null === $store->invoke( null, $png, 'image/png' ) );
check( 'Und liegt danach vor', IconService::hasSource() );
check( 'Mit dem richtigen Medientyp', 'image/png' === IconService::sourceMime() );

// Ein zweites Logo ersetzt das erste, statt sich danebenzulegen.
check( 'Ein SVG wird angenommen', null === $store->invoke( null, '<svg xmlns="http://www.w3.org/2000/svg"><circle r="5"/></svg>', 'image/svg+xml' ) );
check( 'Das alte PNG ist weg', ! is_file( $dir . '/source.png' ) );
check( 'Nur eine Quelle liegt vor', 1 === count( glob( $dir . '/source.*' ) ) );

/* ----------------------------------------------- SVG wird bereinigt */

$evil = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)">'
	. '<script>alert(2)</script>'
	. '<a href="javascript:alert(3)"><rect width="10" height="10"/></a>'
	. '<foreignObject><body onload="alert(4)">x</body></foreignObject>'
	. '</svg>';

$clean = IconService::cleanSvg( $evil );

check( 'Skripte fliegen raus', ! str_contains( $clean, '<script' ) );
check( 'Ereignisse fliegen raus', ! str_contains( strtolower( $clean ), 'onload' ) );
check( 'javascript: fliegt raus', ! str_contains( strtolower( $clean ), 'javascript:' ) );
check( 'foreignObject fliegt raus', ! str_contains( strtolower( $clean ), 'foreignobject' ) );
check( 'Der Inhalt bleibt erhalten', str_contains( $clean, '<rect' ) );
check( 'Etwas, das kein SVG ist, wird verworfen', '' === IconService::cleanSvg( 'nur text' ) );

/* ------------------------------------------------ SVG-Zusammenbau */

$store->invoke( null, $png, 'image/png' );
$svg = IconService::svg();

check( 'Das SVG traegt den Hintergrund', str_contains( $svg, 'fill="#000000"' ) );
check( 'Und bindet das Logo als Daten-URI ein', str_contains( $svg, 'href="data:image/png;base64,' ) );
check( 'Es laedt nichts von aussen nach', ! preg_match( '#href="https?://#', $svg ) );
check( 'Das Seitenverhaeltnis bleibt erhalten', str_contains( $svg, 'preserveAspectRatio="xMidYMid meet"' ) );

// Rand wirkt sich auf die Masse aus.
Setting::$values = array( 'icon_bg' => '#112233', 'icon_padding' => '0' );
$full = IconService::svg();
check( 'Ohne Rand fuellt das Logo die Flaeche', str_contains( $full, 'width="512"' ) );
check( 'Die eingestellte Farbe wird uebernommen', str_contains( $full, 'fill="#112233"' ) );

Setting::$values = array( 'icon_bg' => 'kein-hex', 'icon_padding' => '999' );
check( 'Unsinnige Farbe faellt auf Schwarz', '#000000' === IconService::background() );
check( 'Uebertriebener Rand wird gedeckelt', 40 === IconService::padding() );

/* -------------------------------------------- Fassungen entgegennehmen */

$dataUrl = 'data:image/png;base64,' . base64_encode( $png );

check( 'Ein PNG wird angenommen', null === IconService::saveRaster( 'icon-192', $dataUrl ) );
check( 'Und liegt danach vor', IconService::hasOwnRaster() );
check( 'Es wird auch ausgeliefert', str_contains( (string) IconService::rasterPath( 'icon-192' ), '/branding/icon-192.png' ) );
check( 'Unbekannte Groesse wird abgelehnt', null !== IconService::saveRaster( 'riesig', $dataUrl ) );
check( 'Kein Daten-URI wird abgelehnt', null !== IconService::saveRaster( 'icon-192', 'https://example.com/x.png' ) );
check( 'JPEG als PNG ausgegeben wird abgelehnt', null !== IconService::saveRaster( 'icon-192', 'data:image/png;base64,' . base64_encode( "\xff\xd8\xffhallo" ) ) );
check( 'Ein anderes Format im Daten-URI wird abgelehnt', null !== IconService::saveRaster( 'icon-192', 'data:image/svg+xml;base64,' . base64_encode( '<svg/>' ) ) );

/* ------------------------ Neues Logo macht alte Fassungen ungueltig */

check( 'Vor dem Wechsel liegt eine eigene Fassung vor', IconService::hasOwnRaster() );
$store->invoke( null, '<svg xmlns="http://www.w3.org/2000/svg"><circle r="7"/></svg>', 'image/svg+xml' );
check( 'Ein neues Logo wirft die alten Fassungen weg', ! IconService::hasOwnRaster() );
check( 'Solange kommt wieder das mitgelieferte Symbol',
	str_contains( (string) IconService::rasterPath( 'icon-192' ), '/public/assets/icons/' ) );

/* ---------------------------------------------------- Zuruecksetzen */

IconService::reset();
check( 'Zuruecksetzen entfernt die Quelle', ! IconService::hasSource() );
check( 'Und die erzeugten Fassungen', ! IconService::hasOwnRaster() );
check( 'Danach kommt wieder das mitgelieferte Symbol',
	str_contains( (string) IconService::rasterPath( 'icon-192' ), '/public/assets/icons/' ) );

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );

}
