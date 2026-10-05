<?php
/**
 * Haertung als Zustand, nicht als einmalige Aktion.
 *
 * Gemeldet war: "immer nach dem Update geht das weg". Stimmt — WordPress legt
 * readme.html bei jedem Core-Update wieder an. Hier wird geprueft, dass das
 * Plugin den Punkt danach selbst wieder zumacht, statt ihn offen zu lassen.
 *
 * Der Fall "wp-config.php verbietet es ausdruecklich" laeuft in einem eigenen
 * Prozess: eine Konstante laesst sich nur einmal setzen.
 */
declare( strict_types = 1 );

$unterlauf = isset( $argv[1] ) && 'wpconfig-false' === $argv[1];

if ( $unterlauf ) {
	define( 'DISALLOW_FILE_EDIT', false );
}

require __DIR__ . '/child-stubs.php';

/* ------------------------------------------------- Was der Scan sonst braucht */

class NLC_Updates {
	public static function core_updates() { return $GLOBALS['core_updates'] ?? array(); }
	public static function available() {
		return array( 'core' => array(), 'plugins' => array(), 'themes' => array(), 'translations' => 0 );
	}
}

class NLC_Test_WPDB { public $prefix = 'wp_'; }
$GLOBALS['wpdb'] = new NLC_Test_WPDB();

function wp_upload_dir() { return array( 'baseurl' => 'https://kunde.de/wp-content/uploads/', 'error' => '' ); }
function wp_remote_get( $url, $args = array() ) { return array( 'body' => '<html>nichts</html>' ); }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }
function get_plugins() { return array(); }
function is_plugin_active( $f ) { return true; }
function apply_filters( $tag, $value ) { return $value; }
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }

require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-security.php';

$fails = 0;
$n     = 0;
function check( string $label, bool $ok, string $extra = '' ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label" . ( '' !== $extra ? " ($extra)" : '' ) . "\n"; }
}

/** WordPress-Wurzel als echtes Verzeichnis — readme.html wird wirklich angelegt. */
$wurzel = ABSPATH;
if ( ! is_dir( $wurzel ) ) { mkdir( $wurzel, 0700, true ); }
$readme = $wurzel . 'readme.html';

/** readme.html wegraeumen, egal ob Datei oder (aus einem Abbruch) Verzeichnis. */
function readme_weg( string $pfad ): void {
	if ( is_dir( $pfad ) ) { @rmdir( $pfad ); return; }
	@unlink( $pfad );
}
readme_weg( $readme );

function status_von( array $scan, string $id ): array {
	foreach ( $scan['checks'] as $c ) { if ( $id === $c['id'] ) { return $c; } }
	return array( 'id' => $id, 'status' => '(fehlt)', 'detail' => '', 'fixable' => false );
}

/** Ein Ergebnis aus einer Liste, die auch leer sein darf. */
function ergebnis( array $liste, int $i = 0 ): array {
	return isset( $liste[ $i ] ) && is_array( $liste[ $i ] )
		? $liste[ $i ] + array( 'success' => null, 'message' => '' )
		: array( 'success' => null, 'message' => '(kein Ergebnis)' );
}

/* =================================================== Unterlauf: wp-config sagt nein */

if ( $unterlauf ) {
	NLC_Security::harden( array( 'file_editor' ) );
	$ergebnis = NLC_Security::harden( array( 'file_editor' ) );

	check( 'Setzt die wp-config.php false, wird kein Erfolg gemeldet', false === ergebnis( $ergebnis )['success'] );
	check( 'Und der Grund steht dabei', false !== strpos( ergebnis( $ergebnis )['message'], 'wp-config.php' ) );

	$e = NLC_Security::enforce();
	check( 'Das Durchsetzen meldet denselben Konflikt', 1 === count( $e ) && false === ergebnis( $e )['success'] );

	NLC_Security::apply_early(); // darf nicht fatal werden
	check( 'apply_early bricht nicht an der bestehenden Konstante', true );

	$scan = NLC_Security::scan();
	check( 'Der Punkt gilt weiter als offen', 'warn' === status_von( $scan, 'file_editor' )['status'] );
	check( 'Und laesst sich nicht als behebbar anbieten', false === status_von( $scan, 'file_editor' )['fixable'] );

	echo "$n Prüfungen, $fails Fehler\n";
	exit( $fails > 0 ? 1 : 0 );
}

/* ====================================================== readme.html, der Kernfall */

touch( $readme );
check( 'Vorbedingung: readme.html liegt da', file_exists( $readme ) );

$ergebnis = NLC_Security::harden( array( 'readme_exposed' ) );
check( 'readme.html wird entfernt', ! file_exists( $readme ) );
check( 'Und als Erfolg gemeldet', true === ergebnis( $ergebnis )['success'] );
check( 'Der Punkt ist jetzt dauerhaft vorgemerkt', NLC_Security::is_enforced( 'readme_exposed' ) );

// Das Core-Update legt sie wieder an.
touch( $readme );
check( 'Nach dem Core-Update ist sie wieder da', file_exists( $readme ) );

$e = NLC_Security::enforce();
check( 'Das Durchsetzen entfernt sie erneut', ! file_exists( $readme ) );
check( 'Und sagt, dass sie wieder da war', 1 === count( $e ) && false !== strpos( ergebnis( $e )['message'], 'wieder da' ) );

// Nichts zu tun darf auch nichts melden - sonst rauscht taeglich eine
// Erfolgsmeldung durch, die nichts bedeutet.
$leer = NLC_Security::enforce();
check( 'Ohne Arbeit gibt es keine Meldung', array() === $leer );

/* ------------------------------------- Scheitern muss als Scheitern durchkommen */

// Ein nicht entfernbares readme.html erzwingen. Entzogene Schreibrechte
// helfen hier nicht: als root laufen die Laeufe trotzdem durch. Ein
// Verzeichnis dagegen laesst sich auch von root nicht per unlink loeschen —
// damit ist der Fehlerpfad unabhaengig vom Benutzer erreichbar.
readme_weg( $readme );
mkdir( $readme, 0700 );
check( 'Vorbedingung: readme.html ist nicht per unlink entfernbar', is_dir( $readme ) && ! @unlink( $readme ) );

$e = NLC_Security::enforce();
check( 'Ein gescheitertes Entfernen wird als Fehler gemeldet', 1 === count( $e ) && false === ergebnis( $e )['success'] );
check( 'Und nennt den wahrscheinlichen Grund', false !== strpos( ergebnis( $e )['message'], 'Schreibrechte' ) );
check( 'Es wird nicht faelschlich Erfolg gemeldet', false === strpos( ergebnis( $e )['message'], 'erneut entfernt' ) );

readme_weg( $readme );

/* ============================================================ Datei-Editor */

check( 'Vorher ist die Konstante nicht gesetzt', ! defined( 'DISALLOW_FILE_EDIT' ) );

$ergebnis = NLC_Security::harden( array( 'file_editor' ) );
check( 'Der Datei-Editor wird als behoben gemeldet', true === ergebnis( $ergebnis )['success'] );
check( 'Und dauerhaft vorgemerkt', NLC_Security::is_enforced( 'file_editor' ) );

NLC_Security::apply_early();
check( 'apply_early setzt die Konstante', defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT );
check( 'Der Scan sieht den Punkt jetzt als erledigt', 'ok' === status_von( NLC_Security::scan(), 'file_editor' )['status'] );

/* ======================================================= Nur Erlaubtes merken */

NLC_Security::harden( array( 'db_prefix', 'php_version', 'erfundener_punkt' ) );
check( 'Das Tabellen-Praefix wird nicht dauerhaft vorgemerkt', ! NLC_Security::is_enforced( 'db_prefix' ) );
check( 'Erfundene Kennungen auch nicht', ! NLC_Security::is_enforced( 'erfundener_punkt' ) );
check( 'Die echten Vormerkungen bleiben', NLC_Security::is_enforced( 'file_editor' ) && NLC_Security::is_enforced( 'readme_exposed' ) );

/* ============================================================ Quittieren */

$vorher = NLC_Security::scan();
$vor    = status_von( $vorher, 'db_prefix' );
check( 'Das Standard-Praefix gilt zunaechst als Hinweis', 'warn' === $vor['status'] );
check( 'Und sagt, warum es nicht automatisch geht', false !== strpos( $vor['detail'], 'steht die Seite' ) );

NLC_Security::acknowledge( array( 'db_prefix' ), 'Kunde zahlt die Migration nicht.' );
$nachher = NLC_Security::scan();
$nach    = status_von( $nachher, 'db_prefix' );

check( 'Quittiert bekommt er einen eigenen Zustand', 'ack' === $nach['status'] );
check( 'Nicht faelschlich "ok"', 'ok' !== $nach['status'] );
check( 'Die Begruendung steht dabei', false !== strpos( $nach['detail'], 'Kunde zahlt' ) );
check( 'Und zaehlt in die Wertung', $nachher['score'] > $vorher['score'] );

NLC_Security::acknowledge( array( 'core_version' ), 'nicht erlaubt' );
check( 'Ein Core-Update laesst sich nicht wegquittieren', 'ack' !== status_von( NLC_Security::scan(), 'core_version' )['status'] );

NLC_Security::unacknowledge( array( 'db_prefix' ) );
check( 'Zuruecknehmen geht', 'warn' === status_von( NLC_Security::scan(), 'db_prefix' )['status'] );

/* ============================================================ Aufgeben */

NLC_Security::harden( array( 'wp_version_exposed', 'xmlrpc_enabled' ) );
check( 'Generator ausgeblendet', 1 === (int) get_option( 'nlc_hide_generator' ) );
check( 'XML-RPC blockiert', 1 === (int) get_option( 'nlc_disable_xmlrpc' ) );

NLC_Security::relax( array( 'wp_version_exposed' ) );
check( 'Die Vormerkung ist weg', ! NLC_Security::is_enforced( 'wp_version_exposed' ) );
check( 'Und der Schalter zurueckgedreht', 0 === (int) get_option( 'nlc_hide_generator' ) );
check( 'Die anderen bleiben unberuehrt', NLC_Security::is_enforced( 'xmlrpc_enabled' ) && 1 === (int) get_option( 'nlc_disable_xmlrpc' ) );

/* ====================================== Der Unterlauf mit der wp-config-Sperre */

$php  = PHP_BINARY;
$cmd  = escapeshellarg( $php ) . ' ' . escapeshellarg( __FILE__ ) . ' wpconfig-false 2>&1';
$aus  = (string) shell_exec( $cmd );
$ok   = (bool) preg_match( '/(\d+) Prüfungen, (\d+) Fehler/u', $aus, $m );

check( 'Der Unterlauf laeuft durch', $ok, trim( $aus ) );
if ( $ok ) {
	$n     += (int) $m[1];
	$fails += (int) $m[2];
	if ( (int) $m[2] > 0 ) { echo $aus; }
}

readme_weg( $readme );

echo "$n Prüfungen, $fails Fehler\n";
exit( $fails > 0 ? 1 : 0 );
