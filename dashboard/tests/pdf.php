<?php
/**
 * PDF-Erzeugung — gegen ein echtes Chromium.
 *
 * Der klassische Fehlschlag ist nicht "kein PDF", sondern ein PDF mit einer
 * leeren Seite. Darum wird der Text hier wieder herausgelesen und verglichen.
 *
 *   php dashboard/tests/pdf.php              pruefen
 *   php dashboard/tests/pdf.php kaputt       Unterlauf: Programm gibt es nicht
 */
declare( strict_types = 1 );

$src = realpath( dirname( __DIR__ ) . '/src' );
$tmp = sys_get_temp_dir() . '/nl-pdf';

if ( ! isset( $argv[1] ) ) {
	exec( 'rm -rf ' . escapeshellarg( $tmp ) );
}

@mkdir( $tmp . '/wurzel', 0700, true );
@mkdir( $tmp . '/storage', 0700, true );

file_put_contents(
	$tmp . '/wurzel/config.php',
	"<?php return array( 'app' => array( 'key' => '" . base64_encode( str_repeat( 'p', 32 ) ) . "', 'url' => 'https://panel.example' ) );\n"
);

define( 'NL_ROOT', $tmp . '/wurzel' );
define( 'NL_SRC', $src );
define( 'NL_STORAGE', $tmp . '/storage' );
define( 'NL_VIEWS', dirname( __DIR__ ) . '/views' );
define( 'NL_VERSION', '1.0.0' );

spl_autoload_register( static function ( string $c ) use ( $src ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = $src . '/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );

require_once $src . '/helpers.php';
require_once $src . '/view-helpers.php';

use NorthLab\Core\Config;
use NorthLab\Core\Database;
use NorthLab\Core\Migrator;
use NorthLab\Core\Setting;
use NorthLab\Service\PdfService;

$fails = 0;
$n     = 0;
function check( string $label, bool $ok, string $extra = '' ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label" . ( '' !== $extra ? " ($extra)" : '' ) . "\n"; }
}

Config::load( NL_ROOT . '/config.php' );

shell_exec( 'mariadb -u root -e ' . escapeshellarg(
	'DROP DATABASE IF EXISTS nl_pdf;CREATE DATABASE nl_pdf CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
	. "GRANT ALL ON nl_pdf.* TO 'nl'@'127.0.0.1';FLUSH PRIVILEGES;"
) . ' 2>&1' );

Database::boot( array( 'host' => '127.0.0.1', 'port' => 3306, 'name' => 'nl_pdf', 'user' => 'nl', 'pass' => 'nlpass', 'prefix' => 'nl_' ) );
Migrator::migrate();

/* ------------------------------------------- Unterlauf: Programm fehlt */

if ( isset( $argv[1] ) && 'kaputt' === $argv[1] ) {
	Setting::set( 'pdf_binary', '/gibt/es/nicht/chromium' );

	// Eine Vorgabe, die nicht ausfuehrbar ist, darf nicht einfach genommen
	// werden - sonst scheitert es spaeter mit einer unverstaendlichen Meldung.
	$ziel = NL_STORAGE . '/kaputt.pdf';
	$r    = PdfService::fromHtml( '<html><body>x</body></html>', $ziel );

	check( 'Ohne Programm wird kein PDF behauptet', ! $r['ok'] );
	check( 'Und gesagt, was zu installieren ist', false !== strpos( $r['error'], 'apt install' ), $r['error'] );
	check( 'Es bleibt keine Datei liegen', ! is_file( $ziel ) );

	printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
	exit( $fails ? 1 : 0 );
}

/* ----------------------------------------------- Ein Programm suchen */

$chromium = trim( (string) shell_exec( 'command -v chromium chromium-browser google-chrome wkhtmltopdf 2>/dev/null | head -1' ) );

if ( '' === $chromium ) {
	// In der Entwicklungsumgebung liegt Chromium bei Playwright.
	$kandidaten = glob( '/opt/pw-browsers/chromium-*/chrome-linux/chrome' ) ?: array();
	$chromium   = $kandidaten ? (string) $kandidaten[0] : '';
}

if ( '' === $chromium ) {
	echo "  UEBERSPRUNGEN: kein Chromium und kein wkhtmltopdf gefunden\n";
	echo "0 Prüfungen, 0 Fehler\n";
	exit( 0 );
}

Setting::set( 'pdf_binary', $chromium );

check( 'Ein PDF-Erzeuger wird gefunden', PdfService::available() );
check( 'Und richtig eingeordnet', 'chromium' === ( PdfService::renderer()['kind'] ?? '' ) || 'wkhtmltopdf' === ( PdfService::renderer()['kind'] ?? '' ) );

/* ------------------------------------------------------ Echte Erzeugung */

$marke = 'Prüfbericht für Müller & Söhne';
$zahl  = 'Verfügbarkeit 99,97 Prozent';

$html = '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>Bericht</title></head><body>'
	. '<h1>' . $marke . '</h1><p>' . $zahl . '</p>'
	. '<table><tr><th>Seite</th><th>Updates</th></tr><tr><td>raum32.de</td><td>7</td></tr></table>'
	. '</body></html>';

$ziel = NL_STORAGE . '/bericht.pdf';
$r    = PdfService::fromHtml( $html, $ziel );

check( 'Die Erzeugung meldet Erfolg', $r['ok'], $r['error'] );
check( 'Die Datei liegt da', is_file( $ziel ) );
check( 'Sie ist nicht winzig', $r['bytes'] > 1000, (string) $r['bytes'] );

$kopf = is_file( $ziel ) ? (string) file_get_contents( $ziel, false, null, 0, 5 ) : '';
check( 'Es ist wirklich ein PDF', '%PDF-' === $kopf, $kopf );

check( 'Die Datei ist nicht fuer alle lesbar', is_file( $ziel ) && 0600 === ( fileperms( $ziel ) & 0777 ),
	is_file( $ziel ) ? decoct( fileperms( $ziel ) & 0777 ) : '—' );

/* --------------------------- Der eigentliche Punkt: steht Text drin? */

$pdftotext = trim( (string) shell_exec( 'command -v pdftotext 2>/dev/null' ) );

if ( '' === $pdftotext ) {
	echo "  HINWEIS: pdftotext fehlt — der Textvergleich entfaellt (apt install poppler-utils)\n";
} else {
	$text = (string) shell_exec( escapeshellarg( $pdftotext ) . ' ' . escapeshellarg( $ziel ) . ' - 2>/dev/null' );

	// Eine leere Seite ist der haeufigste stille Fehlschlag: PDF da, Groesse
	// plausibel, Inhalt weg.
	check( 'Das PDF ist nicht leer', strlen( trim( $text ) ) > 10, strlen( trim( $text ) ) . ' Zeichen' );
	check( 'Die Ueberschrift steht drin', false !== strpos( $text, 'Prüfbericht' ), substr( trim( $text ), 0, 60 ) );
	check( 'Umlaute kommen heil an', false !== strpos( $text, 'Müller' ) && false !== strpos( $text, 'Söhne' ) );
	check( 'Das kaufmaennische Und auch', false !== strpos( $text, '&' ) );
	check( 'Die Zahlen stehen drin', false !== strpos( $text, '99,97' ) );
	check( 'Der Tabelleninhalt auch', false !== strpos( $text, 'raum32.de' ) );
}

/* ------------------------------------------------------- Aufraeumen */

$reste = glob( PdfService::workDir() . '/*' ) ?: array();
check( 'Die Zwischendatei ist weg', array() === array_filter( $reste, static fn( $p ): bool => str_ends_with( (string) $p, '.html' ) ),
	implode( ', ', array_map( 'basename', $reste ) ) );
check( 'Das Browserprofil ist weg', array() === array_filter( $reste, static fn( $p ): bool => is_dir( (string) $p ) ),
	implode( ', ', array_map( 'basename', $reste ) ) );

/* ======================================== Bericht ueber eine einzelne Seite */

use NorthLab\Repository\ReportRepository;
use NorthLab\Service\ReportService;

$kundeId = Database::insert( 'clients', array(
	'name' => 'Kunde GmbH', 'created_at' => nl_utc(),
) );

$eins = Database::insert( 'sites', array(
	'name' => 'raum32.de', 'url' => 'https://raum32.de', 'status' => 'connected',
	'client_id' => $kundeId, 'created_at' => nl_utc(), 'updated_at' => nl_utc(),
) );
$zwei = Database::insert( 'sites', array(
	'name' => 'essen-bc.de', 'url' => 'https://essen-bc.de', 'status' => 'connected',
	'client_id' => $kundeId, 'created_at' => nl_utc(), 'updated_at' => nl_utc(),
) );

$von = gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 );
$bis = nl_utc();

// Ohne Seitenangabe: beide Seiten des Kunden.
[ $kundenBericht ] = ReportService::generate( $kundeId, $von, $bis, false );
$kb = ReportRepository::find( (int) $kundenBericht );

check( 'Der Kundenbericht entsteht', null !== $kb );
check( 'Er traegt den Kundennamen', null !== $kb && false !== strpos( (string) $kb['title'], 'Kunde GmbH' ) );
check( 'Und ist keiner Seite zugeordnet', null !== $kb && null === $kb['site_id'] );
check( 'Beide Seiten stehen drin',
	null !== $kb && false !== strpos( (string) $kb['html'], 'raum32.de' ) && false !== strpos( (string) $kb['html'], 'essen-bc.de' ) );

// Mit Seitenangabe: nur diese eine.
[ $seitenBericht, $fehler ] = ReportService::generate( null, $von, $bis, false, null, $eins );
$sb = ReportRepository::find( (int) $seitenBericht );

check( 'Der Seitenbericht entsteht', null !== $sb, (string) $fehler );
check( 'Er traegt den Seitennamen', null !== $sb && false !== strpos( (string) $sb['title'], 'raum32.de' ) );
check( 'Und ist der Seite zugeordnet', null !== $sb && $eins === (int) $sb['site_id'] );
check( 'Die andere Seite fehlt', null !== $sb && false === strpos( (string) $sb['html'], 'essen-bc.de' ) );

// Der Kunde haengt an der Seite - sein Name darf trotzdem auftauchen, aber
// die Seitenangabe muss die Auswahl bestimmen, nicht der Kunde.
[ $trotzKunde ] = ReportService::generate( $kundeId, $von, $bis, false, null, $eins );
$tk = ReportRepository::find( (int) $trotzKunde );
check( 'Die Seitenangabe schlaegt die Kundenangabe',
	null !== $tk && false === strpos( (string) $tk['html'], 'essen-bc.de' ) );

/* ------------------------------------------------- Berechtigung und Unsinn */

[ $verboten, $grund ] = ReportService::generate( null, $von, $bis, false, array( $zwei ), $eins );
check( 'Eine Seite ausserhalb der Berechtigung wird abgewiesen', 0 === $verboten );
check( 'Und der Grund genannt', is_string( $grund ) && false !== strpos( $grund, 'Berechtigung' ), (string) $grund );

[ $weg, $grund2 ] = ReportService::generate( null, $von, $bis, false, null, 999999 );
check( 'Eine erfundene Seite wird abgewiesen', 0 === $weg );
check( 'Auch hier mit Grund', is_string( $grund2 ) && false !== strpos( $grund2, 'nicht gefunden' ), (string) $grund2 );

/* ------------------------------- Ein Seitenbericht geht nicht an den Kunden */

// Sonst bekaeme der Kunde eine Mail, die niemand angefordert hat - und zwar
// jedes Mal, wenn jemand im Panel kurz nachsehen will.
Database::update( 'clients', array( 'report_email_enabled' => 1, 'email' => 'kunde@example.de' ), array( 'id' => $kundeId ) );
// Gezaehlt wird genau der Eintrag, den die Zustellung schreibt — egal ob
// der Versand gelingt oder nicht. Auf "verschickt" zu pruefen ginge daneben,
// sobald auf dem Pruefrechner kein sendmail liegt: dann steht dort
// "fehlgeschlagen", und ein ungewollter Versand bliebe unbemerkt.
$versandZeilen = static function (): int {
	return (int) Database::scalar(
		'SELECT COUNT(*) FROM `' . Database::table( 'activity' ) . "` WHERE `action` = 'report.email'"
	);
};

$vorher = $versandZeilen();

ReportService::generate( null, $von, $bis, true, null, $eins );
check( 'Ein Seitenbericht wird gar nicht erst zugestellt', $vorher === $versandZeilen(),
	sprintf( '%d -> %d', $vorher, $versandZeilen() ) );

// Gegenprobe: beim Kundenbericht wird sehr wohl zugestellt. Ohne die waere
// nicht zu unterscheiden, ob die Zustellung ueberhaupt noch funktioniert.
ReportService::generate( $kundeId, $von, $bis, true );
check( 'Beim Kundenbericht dagegen schon', $versandZeilen() > $vorher,
	sprintf( '%d -> %d', $vorher, $versandZeilen() ) );

/* ----------------------------------------- Und das Ganze als echtes PDF */

if ( '' !== $pdftotext && null !== $sb ) {
	$ziel2 = NL_STORAGE . '/seitenbericht.pdf';
	$p2    = PdfService::fromHtml( (string) $sb['html'], $ziel2 );

	check( 'Der Seitenbericht wird zu einem PDF', $p2['ok'], $p2['error'] );

	$t2 = (string) shell_exec( escapeshellarg( $pdftotext ) . ' ' . escapeshellarg( $ziel2 ) . ' - 2>/dev/null' );
	check( 'Im PDF steht die Seite', false !== strpos( $t2, 'raum32.de' ), substr( trim( $t2 ), 0, 70 ) );
	check( 'Und nicht die andere', false === strpos( $t2, 'essen-bc.de' ) );
	@unlink( $ziel2 );
}

/* ------------------------------------------------- Der Unterlauf ohne Programm */

$aus = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' kaputt 2>&1' );

if ( preg_match( '/(\d+) Prüfungen, (\d+) Fehler/u', $aus, $m ) ) {
	$n     += (int) $m[1];
	$fails += (int) $m[2];
	if ( (int) $m[2] > 0 ) { echo $aus; }
} else {
	check( 'Der Unterlauf laeuft durch', false, trim( $aus ) );
}

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
