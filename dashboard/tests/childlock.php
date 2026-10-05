<?php
/**
 * Child-Plugin geaendert, Version vergessen?
 *
 * Genau das ist passiert: zwei Commits lang wurden Dateien des Plugins
 * geaendert, die Versionsnummer blieb stehen. Seiten, die schon auf der
 * alten Nummer standen, bekamen die Aenderungen nie — das Selbst-Update
 * vergleicht Versionen, nicht Inhalte. Gemerkt hat es niemand, weil nichts
 * dagegen prueft.
 *
 * Diese Pruefung haelt eine Pruefsumme des Plugins neben der Version fest.
 * Aendert sich der Inhalt, ohne dass die Version steigt, wird sie rot.
 *
 *   php dashboard/tests/childlock.php            pruefen
 *   php dashboard/tests/childlock.php --update   nach einem Versionssprung neu festhalten
 */
declare( strict_types = 1 );

$root   = dirname( __DIR__ ) . '/resources/child-plugin';
$plugin = $root . '/north-lab-child';
$lock   = $root . '/version.lock';

$fails = 0;
$n     = 0;
function check( string $label, bool $ok, string $extra = '' ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label" . ( '' !== $extra ? " ($extra)" : '' ) . "\n"; }
}

/** Version aus dem Plugin-Kopf. */
function plugin_version( string $datei ): string {
	if ( ! is_file( $datei ) ) {
		return '';
	}
	return preg_match( '/^\s*\*\s*Version:\s*([0-9][0-9.]*)/mi', (string) file_get_contents( $datei ), $m )
		? trim( $m[1] )
		: '';
}

/**
 * Pruefsumme ueber alle Dateien des Plugins.
 *
 * Pfade gehen mit ein, damit auch eine umbenannte oder geloeschte Datei
 * auffaellt — nicht nur geaenderter Inhalt.
 */
function plugin_summe( string $verzeichnis ): string {
	$dateien = array();

	$lauf = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $verzeichnis, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $lauf as $datei ) {
		/** @var SplFileInfo $datei */
		if ( $datei->isFile() ) {
			$dateien[ substr( $datei->getPathname(), strlen( $verzeichnis ) + 1 ) ] = (string) $datei->getPathname();
		}
	}

	ksort( $dateien );

	$zusammen = '';
	foreach ( $dateien as $relativ => $absolut ) {
		$zusammen .= $relativ . "\0" . hash_file( 'sha256', $absolut ) . "\n";
	}

	return hash( 'sha256', $zusammen );
}

$version = plugin_version( $plugin . '/north-lab-child.php' );
$summe   = plugin_summe( $plugin );

/* ------------------------------------------------------------ Festhalten */

if ( in_array( '--update', $argv, true ) ) {
	file_put_contents( $lock, $version . ' ' . $summe . "\n" );
	echo "Festgehalten: $version $summe\n";
	exit( 0 );
}

/* --------------------------------------------------------------- Pruefen */

check( 'Der Plugin-Kopf nennt eine Version', '' !== $version );
check( 'Es gibt eine festgehaltene Version', is_file( $lock ), 'dann einmal mit --update anlegen' );

if ( ! is_file( $lock ) || '' === $version ) {
	printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
	exit( $fails ? 1 : 0 );
}

$teile = preg_split( '/\s+/', trim( (string) file_get_contents( $lock ) ) );
$festVersion = (string) ( $teile[0] ?? '' );
$festSumme   = (string) ( $teile[1] ?? '' );

check( 'Die festgehaltene Zeile ist lesbar', '' !== $festVersion && 64 === strlen( $festSumme ) );

if ( $summe === $festSumme ) {
	check( 'Unveraendert — dann muss auch die Version gleich bleiben', $version === $festVersion,
		"festgehalten $festVersion, im Kopf $version" );
} else {
	// Der eigentliche Zweck: Inhalt anders, Version gleich.
	check(
		'Das Plugin wurde geaendert — dann muss die Version steigen',
		version_compare( $version, $festVersion, '>' ),
		"festgehalten $festVersion, im Kopf $version — bitte die Version erhöhen und "
			. "danach: php dashboard/tests/childlock.php --update"
	);
}

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
