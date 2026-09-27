<?php
/**
 * Das Child-Plugin nennt im Kopf "Requires PHP: 7.4" und laeuft auch dort.
 * Eine PHP-8-Funktion faellt erst auf der Kundenseite auf — und dann als
 * weisser Bildschirm. Diese Pruefung faengt sie vorher ab.
 */
declare( strict_types = 1 );

$root = dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child';

// Funktion => ab welcher PHP-Version es sie gibt.
$tooNew = array(
	'str_starts_with'   => '8.0',
	'str_contains'      => '8.0',
	'str_ends_with'     => '8.0',
	'array_is_list'     => '8.1',
	'enum_exists'       => '8.1',
	'json_validate'     => '8.3',
	'array_find'        => '8.4',
	'array_any'         => '8.4',
	'array_all'         => '8.4',
);

// Sprachmittel, die es in 7.4 noch nicht gibt.
$syntax = array(
	'/\?\?=/'                              => 'Null-Coalescing-Zuweisung (8.0)',
	'/\?->/'                               => 'Nullsafe-Operator (8.0)',
	'/(^|[^a-z_])match\s*\(/i'             => 'match-Ausdruck (8.0)',
	'/^\s*enum\s+[A-Z]/mi'                 => 'Enum (8.1)',
	'/function\s+__construct\s*\([^)]*(public|private|protected)\s+\$/s' => 'Konstruktor-Promotion (8.0)',
	'/^\s*(public|private|protected)\s+readonly\s/mi' => 'readonly (8.1)',
	'/:\s*(never|static)\s*\{/'            => 'Rueckgabetyp never/static (8.0/8.1)',
	'/#\[[A-Z]/'                           => 'Attribut (8.0)',
);

$declaredHeader = '';
$main           = $root . '/north-lab-child.php';
if ( preg_match( '/^\s*\*\s*Requires PHP:\s*(.+)$/mi', (string) file_get_contents( $main ), $m ) ) {
	$declaredHeader = trim( $m[1] );
}

$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );
$fails = 0;
$n     = 0;

function check( string $label, bool $ok ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label\n"; }
}

check( 'Der Plugin-Kopf nennt eine Mindestversion', '' !== $declaredHeader );
check( 'Und die ist 7.4', '7.4' === $declaredHeader );

foreach ( $files as $file ) {
	/** @var SplFileInfo $file */
	if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
		continue;
	}

	$name = str_replace( $root . '/', '', $file->getPathname() );
	$code = (string) file_get_contents( $file->getPathname() );

	// Kommentare und Zeichenketten stoeren die Suche; grob entfernen reicht,
	// denn ein Treffer wird ohnehin von Hand angesehen.
	$stripped = (string) preg_replace( '#//[^\n]*|/\*.*?\*/#s', '', $code );

	foreach ( $tooNew as $fn => $since ) {
		check(
			sprintf( '%s benutzt kein %s() (erst ab PHP %s)', $name, $fn, $since ),
			! preg_match( '/(^|[^a-zA-Z0-9_$>])' . preg_quote( $fn, '/' ) . '\s*\(/', $stripped )
		);
	}

	foreach ( $syntax as $pattern => $what ) {
		check(
			sprintf( '%s benutzt kein %s', $name, $what ),
			! preg_match( $pattern, $stripped )
		);
	}
}

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
