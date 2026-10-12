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

/* ================= Das Zeichen fuers Kundenmenue — getrennt vom Favicon */

// Zwei Zwecke, zwei Dateien. Das Panel-Symbol darf ein farbiges PNG mit
// Hintergrund sein; als Silhouette im Kundenmenue taugt genau das nicht.
// Vorher haben sich beide dieselbe Quelle geteilt — dabei konnte man das
// eine nicht aendern, ohne das andere mitzuaendern.

IconService::reset();
IconService::clearGlyph();

check( 'Ohne Hinterlegung gibt es kein Zeichen', ! IconService::hasGlyph() );
check( 'Und menuGlyph sagt das auch', ! IconService::menuGlyph()['ok'] );
check( 'Mit einem Grund', str_contains( IconService::menuGlyph()['reason'], 'Kundenmenü' ) );

/* ------------------------------------------- Die Form, und nur die Form */

$boese = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" onload="alert(1)">'
	. '<script>alert(2)</script>'
	. '<style>path{fill:red}</style>'
	. '<a href="javascript:alert(3)"><path d="M4 4h40v40H4z" fill="#f00" stroke="blue"/></a>'
	. '<image href="http://angreifer.example/x.png" x="0" y="0" width="10" height="10"/>'
	. '<foreignObject><b>hi</b></foreignObject>'
	. '<circle cx="24" cy="24" r="10" fill="url(#verweis)"/>'
	. '<text x="0" y="10">NorthLab</text>'
	. '</svg>';

$form = IconService::silhouette( $boese );

check( 'Die Form bleibt erhalten', str_contains( $form, '<circle' ) && str_contains( $form, 'r="10"' ), $form );
check( 'Die viewBox auch — ohne sie waere die Groesse geraten', str_contains( $form, 'viewBox="0 0 48 48"' ) );

foreach ( array(
	'ein Skript'              => 'script',
	'ein Ereignis'            => 'onload',
	'ein Stilblock'           => 'style',
	'eine javascript-Adresse' => 'javascript',
	'ein nachgeladenes Bild'  => 'image',
	'ein foreignObject'       => 'foreignObject',
	'Text'                    => 'NorthLab',
	'eine Kontur'             => 'stroke',
	'ein Verweis als Farbe'   => 'url(',
) as $was => $nadel ) {
	check( "Aus der Silhouette faellt $was heraus", ! str_contains( $form, $nadel ), $nadel );
}

check( 'Es bleibt genau eine Farbe uebrig', 1 === substr_count( $form, 'fill=' ), $form );
check( 'Und die rote aus der Vorlage ist es nicht', ! str_contains( $form, '#f00' ) );

check( 'Ohne viewBox lieber gar nichts',
	'' === IconService::silhouette( '<svg xmlns="http://www.w3.org/2000/svg"><circle r="5"/></svg>' ) );
check( 'Und was kein SVG ist, ergibt auch keins',
	'' === IconService::silhouette( '<html><body>kein SVG</body></html>' ) );

/* ----------------------------------- Hinterlegen — und was dabei abprallt */

/** Einen Upload nachstellen. is_uploaded_file() gilt nur fuer echte. */
$hochladen = static function ( string $inhalt ): ?string {
	$tmp = tempnam( sys_get_temp_dir(), 'nlc' );
	file_put_contents( (string) $tmp, $inhalt );

	$m = new ReflectionMethod( IconService::class, 'sniff' );
	$m->setAccessible( true );

	if ( 'image/svg+xml' !== $m->invoke( null, $tmp ) ) {
		unlink( (string) $tmp );
		return 'kein SVG';
	}

	$form = IconService::silhouette( $inhalt );
	unlink( (string) $tmp );

	if ( '' === $form ) {
		return 'keine Form';
	}

	file_put_contents( IconService::directory() . '/glyph.svg', $form );
	IconService::touch();

	return null;
};

check( 'Ein Pixelbild wird abgewiesen', 'kein SVG' === $hochladen( "\x89PNG\r\n\x1a\n" . str_repeat( 'x', 64 ) ) );
check( 'Danach gibt es immer noch kein Zeichen', ! IconService::hasGlyph() );

check( 'Ein SVG ohne viewBox wird abgewiesen',
	'keine Form' === $hochladen( '<svg xmlns="http://www.w3.org/2000/svg"><circle r="5"/></svg>' ) );

check( 'Ein brauchbares SVG kommt durch',
	null === $hochladen( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><circle cx="24" cy="24" r="20" fill="#f00"/></svg>' ) );
check( 'Und ist danach hinterlegt', IconService::hasGlyph() );

$glyph = IconService::menuGlyph();

check( 'menuGlyph liefert es', $glyph['ok'], $glyph['reason'] );
check( 'Als Daten-URI', str_starts_with( $glyph['data'], 'data:image/svg+xml;base64,' ) );
check( 'Es laedt nichts von aussen nach', ! str_contains( $glyph['data'], 'http' ) );
check( 'Und ist klein genug fuers Backend', strlen( $glyph['data'] ) <= IconService::GLYPH_MAX_BYTES );
check( 'Die Farbe aus der Vorlage ist verworfen',
	! str_contains( (string) base64_decode( substr( $glyph['data'], 26 ) ), '#f00' ) );

/* --------------------------------- Die beiden kommen sich nicht ins Gehege */

// Das Favicon bleibt ein PNG, das Kundenmenue bleibt die Silhouette.
$store->invoke( null, "\x89PNG\r\n\x1a\n" . str_repeat( 'x', 512 ), 'image/png' );

check( 'Das Panel-Symbol ist jetzt ein PNG', 'image/png' === IconService::sourceMime() );
check( 'Das Zeichen fuers Kundenmenue steht trotzdem noch', IconService::hasGlyph() );
check( 'Und bleibt ein SVG', str_starts_with( IconService::menuGlyph()['data'], 'data:image/svg+xml' ) );

// Und andersherum: das Zeichen zuruecknehmen laesst das Favicon in Ruhe.
IconService::clearGlyph();

check( 'Das Zeichen laesst sich zuruecknehmen', ! IconService::hasGlyph() );
check( 'Das Panel-Symbol ist noch da', IconService::hasSource() );
check( 'Und immer noch das PNG', 'image/png' === IconService::sourceMime() );

// Das Panel-Symbol zuruecksetzen raeumt das Kundenzeichen nicht mit weg.
$hochladen( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><circle cx="24" cy="24" r="20"/></svg>' );
IconService::reset();

check( 'Panel-Symbol zuruecksetzen laesst das Kundenzeichen stehen', IconService::hasGlyph() );
check( 'Das Panel-Symbol ist dabei weg', ! IconService::hasSource() );

IconService::clearGlyph();

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );

}
