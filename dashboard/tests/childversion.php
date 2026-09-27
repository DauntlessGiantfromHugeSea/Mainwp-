<?php
/**
 * Das Panel muss uebernehmen, was die Kundenseite ueber ihre Version sagt —
 * auch dann, wenn dabei kein Update stattfand.
 */
declare( strict_types = 1 );

$root = realpath( dirname( __DIR__ ) );
define( 'NL_SRC', $root . '/src' );
spl_autoload_register( static function ( string $c ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = NL_SRC . '/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );

use NorthLab\Service\ChildPluginService;

$fails = 0;
$n     = 0;
function check( string $label, bool $ok ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label\n"; }
}

// Genau der gemeldete Fall: Seite laeuft 1.4.1, Panel hat 1.4.0 gespeichert.
check( 'Neuere gemeldete Version wird uebernommen', '1.4.1' === ChildPluginService::versionToStore( '1.4.1', '1.4.0' ) );
check( 'Gleiche Version aendert nichts', null === ChildPluginService::versionToStore( '1.4.1', '1.4.1' ) );
check( 'Auch mit Leerzeichen drumherum', null === ChildPluginService::versionToStore( ' 1.4.1 ', '1.4.1' ) );

// Eine aeltere Meldung ist kein Fehler: die Seite wurde vielleicht
// zurueckgesetzt oder aus einer Sicherung geholt.
check( 'Aeltere gemeldete Version wird auch uebernommen', '1.2.0' === ChildPluginService::versionToStore( '1.2.0', '1.4.1' ) );

// Vorher unbekannt
check( 'Erste Meldung wird uebernommen', '1.4.1' === ChildPluginService::versionToStore( '1.4.1', '' ) );

// Unsinn darf nicht in die Spalte
check( 'Leere Meldung wird verworfen', null === ChildPluginService::versionToStore( '', '1.4.0' ) );
check( 'Fehlermeldung wird verworfen', null === ChildPluginService::versionToStore( 'Seite nicht erreichbar', '1.4.0' ) );
check( 'HTML wird verworfen', null === ChildPluginService::versionToStore( '<script>alert(1)</script>', '1.4.0' ) );
check( 'Ein blosses Wort wird verworfen', null === ChildPluginService::versionToStore( 'aktuell', '1.4.0' ) );

// Uebliche Schreibweisen muessen durchgehen
foreach ( array( '2', '2.0', '1.4.1', '1.4.1.2', '1.5.0-beta.1', '2.0.0-rc2' ) as $ok ) {
	check( sprintf( 'Version "%s" wird akzeptiert', $ok ), $ok === ChildPluginService::versionToStore( $ok, 'x' ) );
}

// Und der Vergleich, aus dem die Warnung entsteht, muss danach stimmen.
check( '1.4.1 gilt nicht mehr als aelter als 1.4.1', ! version_compare( '1.4.1', '1.4.1', '<' ) );
check( '1.4.0 gilt als aelter als 1.4.1', version_compare( '1.4.0', '1.4.1', '<' ) );

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
