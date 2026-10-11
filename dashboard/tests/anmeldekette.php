<?php
/**
 * Die Kette der Ein-Klick-Anmeldung, von aussen angetastet.
 *
 * "Klappt nicht" ist keine Fehlermeldung. Hier wird geprueft, dass das Panel
 * jeden der Faelle, an denen es wirklich scheitert, erkennt und benennt —
 * gegen einen echten HTTP-Server, nicht gegen Annahmen.
 *
 *   php dashboard/tests/anmeldekette.php
 */
declare( strict_types = 1 );

$root = realpath( dirname( __DIR__ ) );
define( 'NL_ROOT', $root );
define( 'NL_SRC', $root . '/src' );
define( 'NL_VERSION', '1.0.0' );

spl_autoload_register( static function ( string $c ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = NL_SRC . '/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );

require_once NL_SRC . '/helpers.php';

use NorthLab\Core\Http;
use NorthLab\Service\SiteUserService;

$fails = 0;
$n     = 0;
function check( string $label, bool $ok, string $extra = '' ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label" . ( '' !== $extra ? " ($extra)" : '' ) . "\n"; }
}

/* ============================== Der Wirt: www ist keine andere Seite */

echo "Wirt\n";

// Genau hier hat es geklemmt. Das Panel speichert die Adresse so, wie sie
// jemand eingetragen hat; WordPress antwortet mit dem, was home_url()
// liefert. "kunde.de" und "www.kunde.de" sind zwei Zeichenketten und
// dieselbe Seite — eine Pruefung, die das nicht weiss, sperrt die Anmeldung
// auf jeder Seite aus, die das eine so und das andere so eingetragen hat.
check( 'www. davor ist derselbe Wirt', Http::sameHost( 'https://www.kunde.de/wp-login.php', 'https://kunde.de' ) );
check( 'Und andersherum auch', Http::sameHost( 'https://kunde.de/wp-login.php', 'https://www.kunde.de' ) );
check( 'Grossschreibung ebenso', Http::sameHost( 'https://KUNDE.DE/wp-login.php', 'https://kunde.de' ) );
check( 'Ein Schlusspunkt im Namen auch', Http::sameHost( 'https://kunde.de./wp-login.php', 'https://kunde.de' ) );
check( 'Ein anderer Port stoert nicht', Http::sameHost( 'https://kunde.de:8443/wp-login.php', 'https://kunde.de' ) );

// Was wirklich fremd ist, bleibt fremd.
check( 'Ein fremder Wirt wird abgewiesen', ! Http::sameHost( 'https://angreifer.example/wp-login', 'https://kunde.de' ) );
check( 'Ein angehaengter Name ebenso', ! Http::sameHost( 'https://kunde.de.angreifer.example/', 'https://kunde.de' ) );
check( 'Ein Unterwirt ist nicht dieselbe Seite', ! Http::sameHost( 'https://shop.kunde.de/', 'https://kunde.de' ) );
check( 'javascript: ist keine Adresse', ! Http::sameHost( 'javascript:alert(1)', 'https://kunde.de' ) );
check( 'Leer auch nicht', ! Http::sameHost( '', 'https://kunde.de' ) );

/* ====================================== Was die Adresse wirklich antwortet */

echo "Antwort der Seite\n";

$port   = 8732;
$laeuft = static function () use ( $port ): bool {
	$fp = @fsockopen( '127.0.0.1', $port, $e, $t, 0.3 );
	if ( $fp ) { fclose( $fp ); return true; }
	return false;
};

$server = null;

if ( ! $laeuft() ) {
	$server = proc_open(
		sprintf( 'exec php -S 127.0.0.1:%d %s', $port, escapeshellarg( __DIR__ . '/servers/login-router.php' ) ),
		array( 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ),
		$pipes
	);
	for ( $i = 0; $i < 40 && ! $laeuft(); $i++ ) { usleep( 100000 ); }
}

if ( ! $laeuft() ) {
	check( 'Der Testserver startet', false );
	printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
	exit( 1 );
}

$hole = static fn( string $pfad ): array => Http::probe( 'http://127.0.0.1:' . $port . $pfad, 10, false );

// Der eigentliche Beweis: setzt WordPress das Anmeldecookie, hat der Token
// gegriffen. Alles andere ist Vermutung.
$erfolg = $hole( '/erfolg' );

check( 'Die Probe folgt der Weiterleitung nicht', 302 === $erfolg['status'], (string) $erfolg['status'] );
check( 'Sie sieht das Ziel', 'https://kunde.de/wp-admin/' === $erfolg['location'], $erfolg['location'] );
check( 'Und das Anmeldecookie', (bool) preg_grep( '/^wordpress_logged_in_/', $erfolg['cookies'] ), implode( ' | ', $erfolg['cookies'] ) );

$cache = $hole( '/cache' );
check( 'Ohne Anmeldung kommt kein Cookie', array() === $cache['cookies'] );
check( 'Aber das Weiterleitungsziel steht da', 'https://kunde.de/' === $cache['location'], $cache['location'] );

/* ============================================ Und was das Panel daraus macht */

echo "Erklärung\n";

$satz = static fn( string $pfad, array $caches = array() ): string
	=> SiteUserService::erklaerung( $hole( $pfad ), 'https://kunde.de', $caches );

// Ein Plugin, das die Anmeldeseite versteckt — der Fall, den man ohne
// Diagnose nie findet, weil im Browser einfach "nichts passiert".
$versteckt = $satz( '/versteckt' );
check( 'Eine versteckte Anmeldeseite wird erkannt', str_contains( $versteckt, 'versteckt' ), $versteckt );
check( 'Und die Plugins werden beim Namen genannt', str_contains( $versteckt, 'Hide Login' ), $versteckt );

$gesperrt = $satz( '/gesperrt' );
check( 'Eine gesperrte ebenso', str_contains( $gesperrt, '403' ), $gesperrt );

// Ein Cache liefert die Startseite aus, bevor PHP den Token sieht.
$mitCache = $satz( '/cache', array( 'WP Rocket' ) );
check( 'Eine Weiterleitung weg vom Backend faellt auf', str_contains( $mitCache, 'Statt ins Backend' ), $mitCache );
check( 'Und der Cache wird benannt', str_contains( $mitCache, 'WP Rocket' ), $mitCache );
check( 'Mit dem konkreten Rat', str_contains( $mitCache, 'wp-login.php ausnehmen' ), $mitCache );

// Dieselbe Weiterleitung ohne erkannten Cache darf nicht so tun, als wuesste
// sie es genauer.
$ohneCache = $satz( '/cache' );
check( 'Ohne erkannten Cache wird keiner behauptet', ! str_contains( $ohneCache, 'Cache (' ), $ohneCache );
check( 'Stattdessen die ehrliche Vermutung', str_contains( $ohneCache, 'Reverse Proxy' ), $ohneCache );

$formular = $satz( '/formular' );
check( 'Das Anmeldeformular wird erkannt', str_contains( $formular, 'nicht eingelöst' ), $formular );

$fremd = $satz( '/fremd' );
check( 'Eine Weiterleitung auf einen fremden Wirt wird benannt', str_contains( $fremd, 'fremden Wirt' ), $fremd );

if ( is_resource( $server ) ) {
	proc_terminate( $server );
	proc_close( $server );
}

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
