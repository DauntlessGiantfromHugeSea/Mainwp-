<?php
/**
 * Was der Kunde auf seiner eigenen Seite sieht: Sicherungsverlauf, der
 * Hinweis waehrend eines Laufs und die woechentliche Link-Pruefung.
 */
declare( strict_types = 1 );

require __DIR__ . '/child-stubs.php';

// Die echte Fassung aus dem Plugin, nicht eine abgeschriebene: so prueft
// der Nachzieh-Lauf gegen das, was wirklich ausgeliefert wird.
preg_match(
	"/define\\( 'NLC_VERSION', '([^']+)' \\)/",
	(string) file_get_contents( dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/north-lab-child.php' ),
	$fassung
);
define( 'NLC_VERSION', $fassung[1] ?? '0' );

define( 'WEEK_IN_SECONDS', 604800 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ARRAY_A', 'ARRAY_A' );

/* ----------------------------------------------------------- Ergaenzungen */

function wp_parse_url( $url, $component = -1 ) { return -1 === $component ? parse_url( (string) $url ) : parse_url( (string) $url, $component ); }
function is_ssl() { return true; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function number_format_i18n( $zahl, $dez = 0 ) { return number_format( (float) $zahl, (int) $dez, ',', '.' ); }
function human_time_diff( $von, $bis = 0 ) { return 'kurz'; }
function add_filter( ...$a ) {}
function esc_sql( $v ) { return str_replace( "'", "''", (string) $v ); }
function wp_next_scheduled( $hook, $args = array() ) { return $GLOBALS['cron'][ $hook ] ?? false; }
function wp_schedule_event( $ts, $rec, $hook, $args = array() ) { $GLOBALS['cron'][ $hook ] = $ts; $GLOBALS['cron_rec'][ $hook ] = $rec; return true; }
function wp_schedule_single_event( $ts, $hook, $args = array() ) { $GLOBALS['cron'][ $hook ] = $ts; return true; }
function wp_unschedule_event( $ts, $hook, $args = array() ) { unset( $GLOBALS['cron'][ $hook ] ); return true; }
function wp_clear_scheduled_hook( $hook, $args = array() ) { unset( $GLOBALS['cron'][ $hook ] ); return true; }

$GLOBALS['posts']    = array();
$GLOBALS['requests'] = array();
$GLOBALS['antworten'] = array();

function url_to_postid( $url ) {
	foreach ( $GLOBALS['posts'] as $id => $p ) {
		if ( isset( $p['permalink'] ) && rtrim( $p['permalink'], '/' ) === rtrim( (string) $url, '/' ) ) {
			return (int) $id;
		}
	}
	return 0;
}
function get_post_status( $id ) { return $GLOBALS['posts'][ $id ]['status'] ?? false; }
function get_permalink( $id ) { return $GLOBALS['posts'][ $id ]['permalink'] ?? ''; }

/** Antwort je Adresse und Methode aus der Tabelle; alles andere ist 200. */
function nlc_test_http( $methode, $url, $args ) {
	$GLOBALS['requests'][] = $methode . ' ' . $url;
	$eintrag = $GLOBALS['antworten'][ $url ][ $methode ] ?? ( $GLOBALS['antworten'][ $url ]['*'] ?? 200 );

	if ( $eintrag instanceof WP_Error ) { return $eintrag; }

	return array(
		'response' => array( 'code' => (int) $eintrag ),
		'body'     => (string) ( $GLOBALS['antwort_body'][ $url ] ?? '' ),
	);
}
function wp_remote_head( $url, $args = array() ) { return nlc_test_http( 'HEAD', $url, $args ); }
function wp_remote_get( $url, $args = array() ) { return nlc_test_http( 'GET', $url, $args ); }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0; }
function wp_remote_post( $url, $args = array() ) { $GLOBALS['posted'] = $args['body'] ?? array(); return nlc_test_http( 'POST', $url, $args ); }
/** Der Rumpf kommt aus der Tabelle; ohne Eintrag ist er leer. */
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }

class NLC_Test_WPDB {
	public $posts = 'wp_posts';
	public function prepare( $sql, ...$args ) {
		foreach ( $args as $a ) {
			$sql = preg_replace( '/%d/', (string) (int) $a, $sql, 1 );
		}
		return $sql;
	}
	public function get_results( $sql, $output = null ) {
		preg_match( '/LIMIT (\d+) OFFSET (\d+)/', $sql, $m );
		$limit  = (int) ( $m[1] ?? 20 );
		$offset = (int) ( $m[2] ?? 0 );
		$alle   = array();
		foreach ( $GLOBALS['posts'] as $id => $p ) {
			if ( 'publish' === $p['status'] ) {
				$alle[] = array( 'ID' => (string) $id, 'post_title' => $p['title'], 'post_content' => $p['content'] );
			}
		}
		return array_slice( $alle, $offset, $limit );
	}
}
$GLOBALS['wpdb'] = new NLC_Test_WPDB();

require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-cache.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-maintenance-mode.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-backuplog.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-maintlog.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-banner.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-links.php';

$fails = 0;
$n     = 0;
function check( string $label, bool $ok, string $extra = '' ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label" . ( '' !== $extra ? " ($extra)" : '' ) . "\n"; }
}

/* ===================================================== Sicherungsverlauf */

check( 'Anfangs ist nichts vermerkt', array() === NLC_Backuplog::entries() );
check( 'Und kein letzter Lauf', null === NLC_Backuplog::latest() );

NLC_Backuplog::record( array( 'status' => 'ok', 'bytes' => 1048576, 'seconds' => 90, 'snapshot' => 'a1b2c3', 'message' => 'Alles gut' ) );
$letzter = NLC_Backuplog::latest();
check( 'Ein Lauf wird vermerkt', is_array( $letzter ) && 'ok' === $letzter['status'] );
check( 'Mit Groesse', 1048576 === (int) $letzter['bytes'] );

NLC_Backuplog::record( array( 'status' => 'failed', 'message' => 'Speicher nicht erreichbar' ) );
check( 'Der neueste steht vorn', 'failed' === NLC_Backuplog::latest()['status'] );
check( 'Der aeltere bleibt erhalten', 2 === count( NLC_Backuplog::entries() ) );

// Erfundene Zustaende duerfen nicht durchrutschen - sonst steht spaeter ein
// unbekanntes Wort in der Tabelle und die Anzeige faellt auf "erfolgreich".
NLC_Backuplog::record( array( 'status' => 'irgendwas' ) );
check( 'Ein unbekannter Zustand wird zu "ok"', 'ok' === NLC_Backuplog::latest()['status'] );

for ( $i = 0; $i < NLC_Backuplog::MAX + 5; $i++ ) {
	NLC_Backuplog::record( array( 'status' => 'ok', 'message' => 'Lauf ' . $i ) );
}
check( 'Die Liste waechst nicht unbegrenzt', NLC_Backuplog::MAX === count( NLC_Backuplog::entries() ) );

check( 'Groessen werden lesbar', '1,0 MB' === NLC_Backuplog::size( 1048576 ), NLC_Backuplog::size( 1048576 ) );
check( 'Kilobyte ohne Nachkomma', '512 KB' === NLC_Backuplog::size( 524288 ), NLC_Backuplog::size( 524288 ) );
check( 'Gigabyte mit Nachkomma', '2,5 GB' === NLC_Backuplog::size( (int) ( 2.5 * 1024 * 1024 * 1024 ) ), NLC_Backuplog::size( (int) ( 2.5 * 1024 * 1024 * 1024 ) ) );
check( 'Ohne Groesse steht ein Strich', '—' === NLC_Backuplog::size( 0 ) );
check( 'Sekunden bleiben Sekunden', '45 s' === NLC_Backuplog::duration( 45 ) );
check( 'Minuten werden Minuten', '5 min' === NLC_Backuplog::duration( 300 ) );
check( 'Stunden werden Stunden', '1 h 30 min' === NLC_Backuplog::duration( 5400 ) );

/* ============================================ Hinweis waehrend des Laufs */

check( 'Anfangs laeuft nichts', null === NLC_Backuplog::running() );

NLC_Backuplog::start( 600, 'Nachtlauf' );
check( 'Der Lauf ist angekuendigt', null !== NLC_Backuplog::running() );

$erster = NLC_Backuplog::running()['started'];
NLC_Backuplog::start( 600 );
check( 'Ein neues Lebenszeichen verschiebt den Beginn nicht', $erster === NLC_Backuplog::running()['started'] );

// Das Wichtigste: stirbt das Panel mitten im Lauf, darf der Hinweis nicht
// ewig stehen bleiben.
$GLOBALS['options'][ NLC_Backuplog::OPT_RUNNING ] = array( 'until' => time() - 1, 'started' => time() - 900 );
check( 'Eine abgelaufene Ankuendigung gilt als beendet', null === NLC_Backuplog::running() );
check( 'Und wird dabei aufgeraeumt', ! isset( $GLOBALS['options'][ NLC_Backuplog::OPT_RUNNING ] ) );

NLC_Backuplog::start( 999999 );
check( 'Die Frist ist nach oben gedeckelt', NLC_Backuplog::running()['until'] <= time() + NLC_Backuplog::MAX_LEASE );

NLC_Backuplog::start( 600 );
NLC_Backuplog::record( array( 'status' => 'ok' ) );
check( 'Ein abgeschlossener Lauf beendet den Hinweis', null === NLC_Backuplog::running() );

/* ------------------------------------------- Vorgeschichte nachreichen */

// Die Kundenseite kennt nur, was ihr gemeldet wurde. Laeuft das Panel schon
// laenger, muss es die alten Laeufe nachreichen koennen — sonst steht dort
// "noch keine Sicherung", obwohl seit Wochen gesichert wird.
delete_option( NLC_Backuplog::OPT_LOG );
check( 'Vorher ist die Liste leer', array() === NLC_Backuplog::entries() );

$vorgeschichte = array(
	array( 'at' => '2026-10-04T02:14:00+00:00', 'status' => 'ok', 'bytes' => 2048, 'files' => 10, 'seconds' => 120, 'message' => 'Lauf A' ),
	array( 'at' => '2026-10-03T02:14:00+00:00', 'status' => 'failed', 'message' => 'Lauf B' ),
);
NLC_Backuplog::replace( $vorgeschichte );

$log = NLC_Backuplog::entries();
check( 'Die Vorgeschichte kommt an', 2 === count( $log ) );
check( 'Der Zeitpunkt wird uebernommen, nicht auf jetzt gesetzt',
	'2026-10-04T02:14:00+00:00' === $log[0]['at'], (string) $log[0]['at'] );
check( 'Die Reihenfolge bleibt', 'Lauf A' === $log[0]['message'] && 'Lauf B' === $log[1]['message'] );
check( 'Der Fehlschlag bleibt ein Fehlschlag', 'failed' === $log[1]['status'] );

// Ersetzen, nicht ergaenzen: sonst stuende nach dem zweiten Nachreichen
// alles doppelt da.
NLC_Backuplog::replace( $vorgeschichte );
check( 'Nochmal nachreichen verdoppelt nichts', 2 === count( NLC_Backuplog::entries() ) );

NLC_Backuplog::replace( array_fill( 0, NLC_Backuplog::MAX + 10, array( 'status' => 'ok' ) ) );
check( 'Auch dabei bleibt die Liste gedeckelt', NLC_Backuplog::MAX === count( NLC_Backuplog::entries() ) );

NLC_Backuplog::replace( array( array( 'status' => 'quatsch' ), 'kein array' ) );
$geprueft = NLC_Backuplog::entries();
check( 'Unsinn wird aussortiert', 1 === count( $geprueft ) );
check( 'Und ein unbekannter Zustand faellt auf "ok"', 'ok' === $geprueft[0]['status'] );

// Ein frisch gemeldeter Lauf traegt immer die aktuelle Zeit, auch wenn das
// Panel versehentlich eine mitschickt.
delete_option( NLC_Backuplog::OPT_LOG );
NLC_Backuplog::record( array( 'status' => 'ok', 'at' => '2001-01-01T00:00:00+00:00' ) );
check( 'Ein neuer Lauf traegt die jetzige Zeit',
	substr( (string) NLC_Backuplog::latest()['at'], 0, 4 ) !== '2001', (string) NLC_Backuplog::latest()['at'] );

delete_option( NLC_Backuplog::OPT_LOG );

/* ================================================ Fortschritt des Laufs */

NLC_Backuplog::stop();
NLC_Backuplog::start( 600, 'Sicherung', array( 'phase' => 'Dateien werden geholt', 'done' => 300, 'total' => 1200 ) );
$lauf = NLC_Backuplog::running();

check( 'Die Phase kommt an', 'Dateien werden geholt' === $lauf['phase'] );
check( 'Der Stand kommt an', 300 === $lauf['done'] && 1200 === $lauf['total'] );
check( 'Der Anteil wird gerechnet', 25 === $lauf['percent'], var_export( $lauf['percent'], true ) );

$text = NLC_Backuplog::progress_text( $lauf );
check( 'Der Fortschritt steht als Satz da', false !== strpos( $text, '300 von 1.200' ), $text );
check( 'Mit Prozent', false !== strpos( $text, '25 %' ), $text );
check( 'Und mit der Phase', 0 === strpos( $text, 'Dateien werden geholt' ), $text );

// Ohne Gesamtzahl laesst sich kein Anteil angeben - dann darf auch keiner
// erfunden werden, sonst steht da ein Balken, der nichts bedeutet.
NLC_Backuplog::start( 600, 'Sicherung', array( 'phase' => 'Datenbank wird exportiert' ) );
$ohne = NLC_Backuplog::running();
check( 'Ohne Gesamtzahl gibt es keinen Anteil', null === $ohne['percent'] );
check( 'Aber die Phase steht da', 'Datenbank wird exportiert' === NLC_Backuplog::progress_text( $ohne ) );

// Mehr erledigt als insgesamt gibt es nicht.
NLC_Backuplog::start( 600, 'Sicherung', array( 'phase' => 'x', 'done' => 1500, 'total' => 900 ) );
$zuviel = NLC_Backuplog::running();
check( 'Mehr als 100 % gibt es nicht', 100 === $zuviel['percent'] && 900 === $zuviel['done'] );

// Der Beginn bleibt ueber alle Lebenszeichen hinweg derselbe.
NLC_Backuplog::stop();
NLC_Backuplog::start( 600, 'Sicherung', array( 'done' => 0, 'total' => 10 ) );
$beginn = NLC_Backuplog::running()['started'];
NLC_Backuplog::start( 600, 'Sicherung', array( 'done' => 5, 'total' => 10 ) );
check( 'Ein neues Lebenszeichen verschiebt den Beginn nicht', $beginn === NLC_Backuplog::running()['started'] );
check( 'Aber der Stand zieht nach', 50 === NLC_Backuplog::running()['percent'] );

// Bleibt die Meldung aus, duerfen alte Zahlen nicht als aktuell gelten.
check( 'Frische Zahlen gelten als frisch', ! NLC_Backuplog::stale( NLC_Backuplog::running() ) );
$alt = NLC_Backuplog::running();
$alt['at'] = time() - 1200;
check( 'Alte Zahlen werden als alt erkannt', NLC_Backuplog::stale( $alt ) );

/* ======================================================== Das Banner */

NLC_Backuplog::start( 600 );
NLC_Banner::save( array( 'enabled' => true, 'audience' => 'loggedin', 'text' => 'Sicherung läuft', 'accent' => '#ff3399' ) );

$GLOBALS['current'] = 0;
check( 'Abgemeldete sehen es nicht (Voreinstellung)', ! NLC_Banner::visible_now() );

$GLOBALS['current'] = 5;
$GLOBALS['users'][5] = array( 'login' => 'kunde', 'roles' => array( 'administrator' ), 'can' => true );
check( 'Angemeldete sehen es', NLC_Banner::visible_now() );

NLC_Banner::save( array( 'audience' => 'all' ) );
$GLOBALS['current'] = 0;
check( 'Auf Wunsch sehen es alle', NLC_Banner::visible_now() );

NLC_Banner::save( array( 'enabled' => false ) );
check( 'Abgeschaltet sieht es niemand', ! NLC_Banner::visible_now() );

NLC_Banner::save( array( 'enabled' => true, 'audience' => 'all' ) );
NLC_Backuplog::stop();
check( 'Ohne laufende Sicherung kein Banner', ! NLC_Banner::visible_now() );

NLC_Backuplog::start( 600 );
$markup = NLC_Banner::markup( NLC_Banner::state(), (array) NLC_Backuplog::running() );
check( 'Das Banner traegt den Text', false !== strpos( $markup, 'Sicherung läuft' ) );
check( 'Und die gewaehlte Farbe', false !== strpos( $markup, '#ff3399' ) );
check( 'Es liegt unten rechts', false !== strpos( $markup, 'right:16px;bottom:16px' ) );

$mitStand = NLC_Banner::markup(
	NLC_Banner::state(),
	array( 'started' => time(), 'phase' => 'Dateien werden geholt', 'done' => 300, 'total' => 1200, 'percent' => 25, 'at' => time() )
);
check( 'Das Banner nennt den Stand', false !== strpos( $mitStand, '300 von 1.200' ) );
check( 'Und zeigt einen Balken', false !== strpos( $mitStand, 'width:25%' ), 'kein Balken' );

$ohneStand = NLC_Banner::markup( NLC_Banner::state(), array( 'started' => time() ) );
// Auf das Element pruefen, nicht auf den Klassennamen: der steht immer im
// CSS-Block, auch wenn gar kein Balken gezeichnet wird.
check( 'Ohne Stand kein Balken', false === strpos( $ohneStand, '<span class="nlc-bb-bar">' ) );
check( 'Und keine Fortschrittszeile', false === strpos( $ohneStand, '<p class="nlc-bb-step">' ) );
check( 'Mit Stand aber schon', false !== strpos( $mitStand, '<span class="nlc-bb-bar">' ) );

$boese = NLC_Banner::markup(
	array( 'text' => 'Hallo</p><script>alert(1)</script>', 'accent' => '#000"><script>x</script>', 'logo' => 'javascript:alert(2)' ),
	array( 'started' => time() )
);
check( 'Text wird maskiert', false === strpos( $boese, '<script>alert(1)' ) );
check( 'Die Farbe faellt auf die Vorgabe', false === strpos( $boese, '<script>x' ) );
check( 'Ein Logo mit javascript: wird nicht eingebunden', false === strpos( $boese, 'javascript:alert(2)' ) );

/* ------------------------------------------- Logo kommt aus dem Branding */

require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-branding.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-symbol.php';

delete_option( NLC_Banner::OPTION );
NLC_Branding::save( array( 'logo' => 'https://agentur.de/logo.png', 'accent' => '#00aaff' ) );

check( 'Ohne eigenes Logo wird das der Agentur genommen',
	'https://agentur.de/logo.png' === NLC_Banner::state()['logo'], NLC_Banner::state()['logo'] );
check( 'Und deren Farbe', '#00aaff' === NLC_Banner::state()['accent'] );

// Entscheidend: das geliehene Logo darf beim Speichern nicht festgeschrieben
// werden. Sonst bliebe das alte stehen, sobald das Branding ein neues bekommt.
NLC_Banner::save( array( 'text' => 'Anderer Text' ) );
NLC_Branding::save( array( 'logo' => 'https://agentur.de/neu.png' ) );
check( 'Ein neues Agenturlogo schlaegt durch',
	'https://agentur.de/neu.png' === NLC_Banner::state()['logo'], NLC_Banner::state()['logo'] );

// Ein ausdruecklich gesetztes Logo bleibt dagegen.
NLC_Banner::save( array( 'logo' => 'https://kunde.de/eigenes.png' ) );
NLC_Branding::save( array( 'logo' => 'https://agentur.de/noch-neuer.png' ) );
check( 'Ein eigenes Logo wird nicht ueberschrieben',
	'https://kunde.de/eigenes.png' === NLC_Banner::state()['logo'], NLC_Banner::state()['logo'] );

delete_option( NLC_Banner::OPTION );

/* ==================================================== Adressen aufbereiten */

check( 'Anker wird verworfen', '' === NLC_Links::normalize( '#oben' ) );
check( 'mailto wird verworfen', '' === NLC_Links::normalize( 'mailto:a@b.de' ) );
check( 'tel wird verworfen', '' === NLC_Links::normalize( 'tel:+4930123' ) );
check( 'javascript wird verworfen', '' === NLC_Links::normalize( 'javascript:void(0)' ) );
check( 'data wird verworfen', '' === NLC_Links::normalize( 'data:text/html,x' ) );
check( 'ftp wird verworfen', '' === NLC_Links::normalize( 'ftp://server/datei' ) );

check( 'Seitenrelativ wird absolut', 'https://kunde.de/impressum' === NLC_Links::normalize( '/impressum' ) );
check( 'Protokollrelativ bekommt ein Protokoll', 'https://cdn.de/x.js' === NLC_Links::normalize( '//cdn.de/x.js' ) );
check( 'Der Anker faellt weg', 'https://a.de/x' === NLC_Links::normalize( 'https://a.de/x#stelle' ) );
check( 'Absolute bleiben', 'https://a.de/x' === NLC_Links::normalize( 'https://a.de/x' ) );

// Ohne Beitragsadresse laesst sich das nicht aufloesen - dann lieber weglassen
// als raten. Mit Adresse muss es aber klappen, sonst faellt der Link stumm raus.
check( 'Beitragsrelativ ohne Grundlage faellt weg', '' === NLC_Links::normalize( 'seite.html' ) );
check( 'Beitragsrelativ mit Grundlage wird aufgeloest',
	'https://kunde.de/blog/seite.html' === NLC_Links::normalize( 'seite.html', 'https://kunde.de/blog/beitrag' ),
	NLC_Links::normalize( 'seite.html', 'https://kunde.de/blog/beitrag' ) );
check( 'Ein Schritt nach oben wird gerechnet',
	'https://kunde.de/ziel' === NLC_Links::normalize( '../ziel', 'https://kunde.de/blog/beitrag' ),
	NLC_Links::normalize( '../ziel', 'https://kunde.de/blog/beitrag' ) );

/* ============================================= Adressen aus dem Beitragstext */

$html = '<p>Text <a href="/a">A</a> und <a href=\'https://b.de/x\'>B</a> '
	. '<a href="/a">nochmal A</a> <a href="https://c.de/?x=1&amp;y=2">C</a> '
	. '<a href="#oben">Sprung</a> <a href="mailto:x@y.de">Mail</a></p>'
	. '<link rel="stylesheet" href="/style.css">';

$gefunden = NLC_Links::extract( $html );
check( 'Doppelte Adressen nur einmal', 1 === count( array_filter( $gefunden, function ( $u ) { return 'https://kunde.de/a' === $u; } ) ) );
check( 'Einfache Anfuehrungszeichen werden gelesen', in_array( 'https://b.de/x', $gefunden, true ) );
check( 'Entities werden aufgeloest', in_array( 'https://c.de/?x=1&y=2', $gefunden, true ) );
$alsText = implode( ' ', $gefunden );
check( 'Kein Anker in der Liste', false === strpos( $alsText, '#oben' ), $alsText );
check( 'Kein mailto in der Liste', false === strpos( $alsText, 'mailto' ), $alsText );
check( 'Keine leeren Eintraege', ! in_array( '', $gefunden, true ) );
check( 'Ein link-Element ist kein Verweis', ! in_array( 'https://kunde.de/style.css', $gefunden, true ) );
check( 'Genau drei Adressen', 3 === count( $gefunden ), implode( ' | ', $gefunden ) );

/* ====================================================== Antworten einordnen */

check( '200 ist in Ordnung', 'ok' === NLC_Links::classify( 200 )['kind'] );
check( '301 ist in Ordnung', 'ok' === NLC_Links::classify( 301 )['kind'] );
check( '404 ist kaputt', 'broken' === NLC_Links::classify( 404 )['kind'] );
check( '410 ist kaputt', 'broken' === NLC_Links::classify( 410 )['kind'] );

// Der wichtigste Punkt: ein 403 ist kein toter Link. Wuerde er als kaputt
// gelten, bestuende die Liste groesstenteils aus Fehlalarmen.
check( '403 ist nicht kaputt, nur unklar', 'unsure' === NLC_Links::classify( 403 )['kind'] );
check( '401 ebenso', 'unsure' === NLC_Links::classify( 401 )['kind'] );
check( '429 ebenso', 'unsure' === NLC_Links::classify( 429 )['kind'] );
check( '999 ebenso', 'unsure' === NLC_Links::classify( 999 )['kind'] );
check( '500 ist unklar, nicht kaputt', 'unsure' === NLC_Links::classify( 500 )['kind'] );
check( 'Und der Grund steht dabei', false !== strpos( NLC_Links::classify( 403 )['reason'], 'blockt' ) );

/* ============================================== Interne Links ohne HTTP */

$GLOBALS['posts'] = array(
	10 => array( 'status' => 'publish', 'permalink' => 'https://kunde.de/blog/alpha', 'title' => 'Alpha', 'content' => '' ),
	11 => array( 'status' => 'draft',   'permalink' => 'https://kunde.de/blog/beta',  'title' => 'Beta',  'content' => '' ),
);
$GLOBALS['requests'] = array();

check( 'Ein veroeffentlichter interner Beitrag ist in Ordnung', 'ok' === NLC_Links::check_url( 'https://kunde.de/blog/alpha' )['kind'] );
check( 'Und zwar ohne einen einzigen Abruf', array() === $GLOBALS['requests'] );

check( 'Ein nicht veroeffentlichter ist kaputt', 'broken' === NLC_Links::check_url( 'https://kunde.de/blog/beta' )['kind'] );
check( 'Auch das ohne Abruf', array() === $GLOBALS['requests'] );

// Dateien unter wp-content: im Dateisystem nachsehen statt abrufen.
if ( ! is_dir( ABSPATH . 'wp-content' ) ) { mkdir( ABSPATH . 'wp-content', 0700, true ); }
file_put_contents( ABSPATH . 'wp-content/da.pdf', 'x' );
@unlink( ABSPATH . 'wp-content/weg.pdf' );

check( 'Eine vorhandene Datei ist in Ordnung', 'ok' === NLC_Links::check_url( 'https://kunde.de/wp-content/da.pdf' )['kind'] );
check( 'Eine fehlende Datei ist kaputt', 'broken' === NLC_Links::check_url( 'https://kunde.de/wp-content/weg.pdf' )['kind'] );
check( 'Beides ohne Abruf', array() === $GLOBALS['requests'] );

// Was sich nicht zuordnen laesst - ein Kategorie-Archiv etwa - muss abgerufen
// werden. Hier darf nicht geraten werden.
$GLOBALS['antworten'] = array( 'https://kunde.de/kategorie/news' => array( '*' => 200 ) );
check( 'Ein Archiv wird wirklich abgerufen', 'ok' === NLC_Links::check_url( 'https://kunde.de/kategorie/news' )['kind'] );
check( 'Und erzeugt genau dafuer einen Abruf', 1 === count( $GLOBALS['requests'] ) );

/* ================================================== Externe Links abrufen */

$GLOBALS['requests']  = array();
$GLOBALS['antworten'] = array(
	'https://gut.de/'     => array( '*' => 200 ),
	'https://weg.de/'     => array( '*' => 404 ),
	'https://headlos.de/' => array( 'HEAD' => 405, 'GET' => 200 ),
	'https://sperre.de/'  => array( '*' => 403 ),
	'https://tot.de/'     => array( '*' => new WP_Error( 'http_request_failed', 'Konnte nicht verbinden' ) ),
);

check( 'Erreichbar ist in Ordnung', 'ok' === NLC_Links::check_url( 'https://gut.de/' )['kind'] );
check( 'Dafuer genuegt ein HEAD', 1 === count( $GLOBALS['requests'] ) && 0 === strpos( $GLOBALS['requests'][0], 'HEAD' ) );

$GLOBALS['requests'] = array();
check( '404 wird als kaputt erkannt', 'broken' === NLC_Links::check_url( 'https://weg.de/' )['kind'] );

$GLOBALS['requests'] = array();
$r = NLC_Links::check_url( 'https://headlos.de/' );
check( 'Wer HEAD nicht mag, wird mit GET nachgefragt', 'ok' === $r['kind'], $r['kind'] . '/' . $r['status'] );
check( 'Und das waren zwei Abrufe', 2 === count( $GLOBALS['requests'] ), implode( ', ', $GLOBALS['requests'] ) );

check( 'Eine Sperre gilt als unklar', 'unsure' === NLC_Links::check_url( 'https://sperre.de/' )['kind'] );

$r = NLC_Links::check_url( 'https://tot.de/' );
check( 'Keine Verbindung ist kaputt', 'broken' === $r['kind'] );
check( 'Und der Grund wird durchgereicht', false !== strpos( $r['reason'], 'Konnte nicht verbinden' ) );

/* ==================================================== Der ganze Durchgang */

$GLOBALS['cron']      = array();
$GLOBALS['requests']  = array();
$GLOBALS['antworten'] = array(
	'https://extern-a.de/'          => array( '*' => 200 ),
	'https://extern-b.de/'          => array( '*' => 404 ),
	// Keinem Beitrag und keiner Datei zuzuordnen - das beantwortet nur ein
	// echter Abruf, und der faellt hier auf 404.
	'https://kunde.de/blog/fehlt'   => array( '*' => 404 ),
);
$GLOBALS['posts'] = array(
	10 => array( 'status' => 'publish', 'permalink' => 'https://kunde.de/blog/alpha', 'title' => 'Alpha',
		'content' => '<a href="https://extern-a.de/">A</a><a href="https://extern-b.de/">B</a><a href="/blog/alpha">Selbst</a>' ),
	11 => array( 'status' => 'publish', 'permalink' => 'https://kunde.de/blog/gamma', 'title' => 'Gamma',
		'content' => '<a href="https://extern-b.de/">nochmal B</a><a href="/blog/fehlt">Weg</a>' ),
);
delete_option( NLC_Links::OPT_RESULT );
delete_option( NLC_Links::OPT_STATE );

NLC_Links::begin();
check( 'Ein Durchgang beginnt', null !== NLC_Links::state() );
check( 'Und plant die erste Etappe', isset( $GLOBALS['cron'][ NLC_Links::HOOK_STEP ] ) );

$runden = 0;
while ( null !== NLC_Links::state() && $runden < 50 ) {
	unset( $GLOBALS['cron'][ NLC_Links::HOOK_STEP ] );
	NLC_Links::step();
	$runden++;
}

$ergebnis = NLC_Links::result_last();
check( 'Der Durchgang kommt zum Ende', null !== $ergebnis, 'nach ' . $runden . ' Etappen' );
check( 'Danach laeuft nichts mehr', null === NLC_Links::state() );

if ( null !== $ergebnis ) {
	$kaputte = array_column( $ergebnis['broken'], 'url' );
	sort( $kaputte );
	check( 'Beide toten Links gefunden',
		array( 'https://extern-b.de/', 'https://kunde.de/blog/fehlt' ) === $kaputte,
		implode( ' | ', $kaputte ) );
	check( 'Der heile Link fehlt in der Liste', ! in_array( 'https://extern-a.de/', $kaputte, true ) );
	check( 'Vier verschiedene Adressen geprueft', 4 === (int) $ergebnis['checked'], (string) $ergebnis['checked'] );

	// Dieselbe Adresse steht in zwei Beitraegen - und darf trotzdem nur
	// einmal abgerufen werden.
	$b_abrufe = count( array_filter( $GLOBALS['requests'], function ( $r ) { return false !== strpos( $r, 'extern-b' ); } ) );
	check( 'Eine Adresse wird nur einmal abgerufen', 1 === $b_abrufe, (string) $b_abrufe );

	// Der Link auf den eigenen, veroeffentlichten Beitrag darf keinen
	// Abruf ausgeloest haben.
	$selbst = count( array_filter( $GLOBALS['requests'], function ( $r ) { return false !== strpos( $r, '/blog/alpha' ); } ) );
	check( 'Kein Abruf der Seite auf sich selbst', 0 === $selbst, (string) $selbst );
}

/* ---------------------------------------- Obergrenze und Etappengroesse */

delete_option( NLC_Links::OPT_RESULT );
delete_option( NLC_Links::OPT_STATE );
NLC_Links::save_config( array( 'max_links' => 50, 'batch' => 2 ) );
$conf = NLC_Links::config();
check( 'Eine zu kleine Etappengroesse wird angehoben', 5 === (int) $conf['batch'], (string) $conf['batch'] );

$viele = '';
for ( $i = 0; $i < 120; $i++ ) { $viele .= '<a href="https://viel.de/' . $i . '">x</a>'; }
$GLOBALS['posts']     = array( 20 => array( 'status' => 'publish', 'permalink' => 'https://kunde.de/viel', 'title' => 'Viel', 'content' => $viele ) );
$GLOBALS['antworten'] = array();

NLC_Links::begin();
NLC_Links::step();
$nach_einer = NLC_Links::state();
check( 'Eine Etappe prueft nicht alles auf einmal',
	null !== $nach_einer && (int) $nach_einer['checked'] <= (int) $conf['batch'] && (int) $nach_einer['checked'] < 50,
	null === $nach_einer ? 'schon fertig' : (string) $nach_einer['checked'] );

$runden = 0;
while ( null !== NLC_Links::state() && $runden < 200 ) { NLC_Links::step(); $runden++; }
$ergebnis = NLC_Links::result_last();
check( 'Die Obergrenze je Durchgang greift', null !== $ergebnis && 50 === (int) $ergebnis['links'], null === $ergebnis ? '—' : (string) $ergebnis['links'] );

/* ------------------------------------------------------------- Zeitplan */

$GLOBALS['cron'] = array();
NLC_Links::save_config( array( 'enabled' => true ) );
check( 'Eingeschaltet wird woechentlich geplant',
	isset( $GLOBALS['cron'][ NLC_Links::HOOK_SCAN ] ) && 'weekly' === ( $GLOBALS['cron_rec'][ NLC_Links::HOOK_SCAN ] ?? '' ) );

NLC_Links::save_config( array( 'enabled' => false ) );
check( 'Abgeschaltet wird der Plan entfernt', ! isset( $GLOBALS['cron'][ NLC_Links::HOOK_SCAN ] ) );

$plaene = NLC_Links::add_interval( array() );
check( 'Der woechentliche Takt wird angemeldet', 604800 === (int) $plaene['weekly']['interval'] );

@unlink( ABSPATH . 'wp-content/da.pdf' );

/* ============================================ Die Seite des Kunden rendern */

// Ein Fehler hier ist ein weisser Bildschirm im Backend des Kunden. Die Seite
// wird darum wirklich gerendert, nicht nur eingebunden.
function wp_nonce_field( $a = '', $n = '_wpnonce', $r = true, $e = true ) { echo '<input type="hidden" name="' . $n . '" value="x">'; }
function get_edit_post_link( $id = 0 ) { return 'https://kunde.de/wp-admin/post.php?post=' . (int) $id . '&action=edit'; }
function add_menu_page( ...$a ) { $GLOBALS['menus'][] = $a; return 'toplevel_page'; }
function wp_send_json_success( $d = null ) { $GLOBALS['json'] = $d; throw new ExitSignal( 'json' ); }
function wp_send_json_error( $d = null, $c = 0 ) { $GLOBALS['json_error'] = $c; throw new ExitSignal( 'json' ); }

require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-options.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-status.php';
// Gehoeren hierher und nicht weiter unten: seit das Zurueckspielen von Haus
// aus ansteht, zeigt die Kundenseite den Abschnitt bei jedem Aufbau — und
// der braucht diese Klassen. Im echten Plugin sind ohnehin alle Dateien
// geladen, bevor irgendetwas gerendert wird.
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-backup.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-restore.php';

$GLOBALS['options']['nlc_connection'] = array(
	'connection_id' => 'abc', 'public_key' => 'x',
	'dashboard_url' => 'https://panel.north-lab.de', 'dashboard_name' => 'NorthLab',
	'connected_at'  => gmdate( 'c' ),
);
$GLOBALS['current']  = 9;
$GLOBALS['users'][9] = array( 'login' => 'kunde', 'roles' => array( 'administrator' ), 'can' => true );

/** Die Seite rendern und den Text zurueckgeben. */
function seite(): string {
	ob_start();
	try {
		NLC_Status::instance()->render();
	} catch ( Throwable $e ) {
		ob_end_clean();
		return 'FEHLER: ' . $e->getMessage();
	}
	return (string) ob_get_clean();
}

// Mit laufender Sicherung und Fortschritt.
NLC_Backuplog::stop();
NLC_Backuplog::start( 600, 'Sicherung', array( 'phase' => 'Dateien werden geholt', 'done' => 342, 'total' => 1200 ) );
$html = seite();

check( 'Die Seite rendert ohne Fehler', 0 !== strpos( $html, 'FEHLER:' ), substr( $html, 0, 160 ) );
check( 'Sie nennt die Agentur', false !== strpos( $html, 'NorthLab' ) );
check( 'Sie sagt, dass gerade gesichert wird', false !== strpos( $html, 'Es läuft gerade eine Sicherung' ) );
check( 'Sie zeigt den Stand', false !== strpos( $html, '342 von 1.200' ), 'kein Stand' );
check( 'Und einen Balken mit dem richtigen Anteil', false !== strpos( $html, 'width:28%' ), 'kein Balken' );
check( 'Sie fuehrt sich selbst nach', false !== strpos( $html, 'nlc_fortschritt' ) );
check( 'Die Sicherungstabelle ist da', false !== strpos( $html, 'Erfolgreich' ) || false !== strpos( $html, 'noch keine Sicherung' ) );

// Ohne Gesamtzahl darf kein Balken erscheinen.
NLC_Backuplog::start( 600, 'Sicherung', array( 'phase' => 'Datenbank wird exportiert' ) );
$ohne = seite();
check( 'Ohne Gesamtzahl bleibt der Balken verborgen', false !== strpos( $ohne, 'display:none;max-width:420px' ), 'Balken sichtbar' );
check( 'Die Phase steht trotzdem da', false !== strpos( $ohne, 'Datenbank wird exportiert' ) );

// Ohne laufende Sicherung.
NLC_Backuplog::stop();
$ruhig = seite();
check( 'Ohne Lauf kein Hinweis', false === strpos( $ruhig, 'Es läuft gerade eine Sicherung' ) );
check( 'Und kein Nachfuehren', false === strpos( $ruhig, 'nlc_fortschritt' ) );
check( 'Der Knopf fuer die Link-Pruefung ist da', false !== strpos( $ruhig, 'Jetzt prüfen' ) );

// Die Abfrage des Fortschritts.
NLC_Backuplog::start( 600, 'Sicherung', array( 'phase' => 'Übertragung', 'done' => 9, 'total' => 10 ) );
unset( $GLOBALS['json'], $GLOBALS['json_error'] );
try { NLC_Status::instance()->handle_progress(); } catch ( ExitSignal $e ) { /* wie in WordPress */ }
check( 'Die Abfrage antwortet', isset( $GLOBALS['json'] ) && ! empty( $GLOBALS['json']['running'] ) );
check( 'Mit dem Anteil', 90 === ( $GLOBALS['json']['percent'] ?? null ), var_export( $GLOBALS['json']['percent'] ?? null, true ) );

// Wer nichts darf, bekommt auch nichts.
$GLOBALS['current'] = 0;
unset( $GLOBALS['json'], $GLOBALS['json_error'] );
try { NLC_Status::instance()->handle_progress(); } catch ( ExitSignal $e ) { /* wie in WordPress */ }
check( 'Ohne Berechtigung keine Auskunft', 403 === ( $GLOBALS['json_error'] ?? 0 ) && ! isset( $GLOBALS['json'] ) );
$GLOBALS['current'] = 9;

NLC_Backuplog::stop();

/* ================================= Wartung: was die Agentur getan hat */

// Das Panel ist die Quelle, die Kundenseite haelt nur eine Kopie. Alles, was
// hier ankommt, wird vorher beschnitten — auch wenn es signiert war.
NLC_Maintlog::replace( array(
	array(
		'at'    => gmdate( 'c', time() - 2 * 86400 ),
		'ok'    => true,
		'items' => array(
			array( 'type' => 'plugin', 'name' => 'Contact Form 7', 'from' => '5.9', 'to' => '6.0' ),
			array( 'type' => 'core', 'name' => 'WordPress', 'from' => '6.6', 'to' => '6.7' ),
		),
	),
	array( 'at' => gmdate( 'c', time() - 30 * 86400 ), 'ok' => false, 'note' => 'Sicherung schlug fehl', 'items' => array() ),
) );

NLC_Maintlog::set_state( array(
	'ssl_days'  => 9,
	'pending'   => 3,
	'contact'   => 'hallo@north-lab.de',
	'backup_at' => gmdate( 'c' ),
) );

$w = seite();

check( 'Die Wartungstabelle ist da', false !== strpos( $w, 'Was wurde aktualisiert' ) );
check( 'Mit dem Namen des Pakets', false !== strpos( $w, 'Contact Form 7 6.0' ), '' );
check( 'Und der Zusammenfassung', false !== strpos( $w, '2 Aktualisierungen eingespielt' ) );
check( 'Ein Fehlschlag wird nicht beschoenigt', false !== strpos( $w, 'Mit Fehler' ) );

// Die Kacheln oben: was ein Kunde liest, ohne zu scrollen.
check( 'Die Kachel nennt die letzte Wartung', false !== strpos( $w, 'Zuletzt gewartet' ) );
check( 'Die offenen Updates stehen da', false !== strpos( $w, 'Offene Updates' ) );
check( 'Und die Restlaufzeit des Zertifikats', false !== strpos( $w, '9 Tage' ) );
// Neun Tage sind knapp — das muss anders aussehen als neunzig.
check( 'Knapp wird farblich hervorgehoben', false !== strpos( $w, '#b32d2e' ) );

// Ohne den fehlgeschlagenen Eintrag ist die Kachel die einzige Stelle, die
// die Warnfarbe setzen koennte — sonst prueft man die Tabelle mit.
NLC_Maintlog::replace( array(
	array( 'at' => gmdate( 'c' ), 'ok' => true, 'items' => array( array( 'type' => 'core', 'name' => 'WordPress', 'to' => '6.7' ) ) ),
) );
NLC_Maintlog::set_state( array( 'ssl_days' => 80, 'pending' => 0 ) );
$ruhiger = seite();
check( 'Bei viel Restlaufzeit keine Warnfarbe', false === strpos( $ruhiger, '#b32d2e' ) );
check( 'Sie steht aber trotzdem da', false !== strpos( $ruhiger, '80 Tage' ) );
check( 'Und "alles aktuell" statt einer Zahl', false !== strpos( $ruhiger, 'alles aktuell' ) );

// Fehlt eine Angabe, steht ein Strich da — keine erfundene Null.
NLC_Maintlog::set_state( array() );
$leer = seite();
check( 'Ohne Angabe wird nichts erfunden', false !== strpos( $leer, 'wird überwacht' ) );

/* ----------------------------------------- Was von aussen kommt, wird beschnitten */

NLC_Maintlog::replace( array(
	array(
		'at'    => '<script>alert(1)</script>',
		'ok'    => true,
		'count' => -5,
		'items' => array(
			array( 'type' => 'boese', 'name' => '<img src=x onerror=alert(2)>', 'to' => '1.0"><b>' ),
			// Eine echte Vorabversion muss durchkommen — Buchstaben gehoeren
			// in eine Versionsangabe.
			array( 'type' => 'plugin', 'name' => 'WooCommerce', 'to' => '9.4.0-beta2' ),
		),
	),
) );

$eintrag = NLC_Maintlog::latest();

check( 'Ein unbekannter Typ wird zu "plugin"', 'plugin' === $eintrag['items'][0]['type'], (string) $eintrag['items'][0]['type'] );
check( 'Ein Name aus Markup bleibt nichts uebrig', '' === $eintrag['items'][0]['name'], (string) $eintrag['items'][0]['name'] );
check( 'Aus der Version verschwinden Anfuehrungszeichen und Klammern',
	! preg_match( '/[<>"\']/', (string) $eintrag['items'][0]['to'] ), (string) $eintrag['items'][0]['to'] );
check( 'Eine echte Vorabversion bleibt unangetastet', '9.4.0-beta2' === $eintrag['items'][1]['to'], (string) $eintrag['items'][1]['to'] );
check( 'Eine negative Anzahl wird zu null', 0 === (int) $eintrag['count'], (string) $eintrag['count'] );

$boese = seite();
check( 'Kein Skript landet auf der Seite', false === strpos( $boese, '<script>alert(1)' ) );
check( 'Und kein Bild-Handler', false === strpos( $boese, 'onerror=alert(2)' ) );
// Namenlos heisst: faellt ganz aus der Aufzaehlung. Besser als maskiert
// anzuzeigen, was ohnehin niemand lesen will.
check( 'Der unbrauchbare Name taucht gar nicht erst auf', false === strpos( $boese, 'img src=x' ) );
check( 'Der brauchbare daneben schon', false !== strpos( $boese, 'WooCommerce 9.4.0-beta2' ) );

/* ------------------------------------------------- Die Kachel im Dashboard */

NLC_Maintlog::replace( array(
	array( 'at' => gmdate( 'c', time() - 3600 ), 'ok' => true, 'items' => array( array( 'type' => 'plugin', 'name' => 'Yoast', 'to' => '22.1' ) ) ),
) );
NLC_Maintlog::set_state( array( 'pending' => 0, 'contact' => 'hallo@north-lab.de' ) );

ob_start();
NLC_Status::instance()->render_widget();
$widget = (string) ob_get_clean();

check( 'Die Kachel nennt die letzte Wartung', false !== strpos( $widget, 'Zuletzt gewartet' ) );
check( 'Und was dabei passiert ist', false !== strpos( $widget, '1 Aktualisierung eingespielt' ) );
check( 'Sie verlinkt die ausfuehrliche Seite', false !== strpos( $widget, 'page=north-lab' ) );
check( 'Und bietet einen Kontakt an', false !== strpos( $widget, 'mailto:hallo@north-lab.de' ) );

/* ============================ Das Menuesymbol: Logo statt Schild */

require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-branding.php';

$logo = 'data:image/svg+xml;base64,' . base64_encode(
	'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" fill="#000"><circle cx="24" cy="24" r="20"/></svg>'
);

// Was keine saubere Daten-URI ist, kommt gar nicht erst an. Eine fremde
// Adresse im Menue wuerde bei jedem Backend-Aufruf nach Hause telefonieren.
check( 'Eine fremde Adresse wird abgewiesen', '' === NLC_Symbol::sanitize( 'https://angreifer.example/logo.svg' ) );
check( 'javascript: ebenso', '' === NLC_Symbol::sanitize( 'javascript:alert(1)' ) );
check( 'Ein anderes Schema auch', '' === NLC_Symbol::sanitize( 'data:text/html;base64,' . base64_encode( '<b>x</b>' ) ) );
check( 'Kaputtes Base64 faellt durch', '' === NLC_Symbol::sanitize( 'data:image/png;base64,###' ) );
check( 'Etwas aus dem url() auszubrechen geht nicht', '' === NLC_Symbol::sanitize( 'data:image/png;base64,AAA") ; x:url("y' ) );
check( 'Und zu gross ist zu gross', '' === NLC_Symbol::sanitize( 'data:image/png;base64,' . str_repeat( 'A', 20000 ) ) );
check( 'Eine saubere Daten-URI kommt durch', $logo === NLC_Symbol::sanitize( $logo ) );

// Das Symbol haengt nicht am Branding: wer das Plugin hat, bekommt es.
// Frueher stand es in der Branding-Option und kam deshalb nur dort an, wo
// jemand Branding angewandt hatte.
NLC_Symbol::set( $logo );
NLC_Branding::save( array( 'bar_enabled' => false, 'login_bar_enabled' => false, 'logo' => '' ) );
check( 'Branding abschalten nimmt das Symbol nicht mit', NLC_Symbol::has() );
check( 'Und es ist dasselbe geblieben', $logo === NLC_Symbol::get() );

// Ohne Logo bleibt das Schild.
NLC_Symbol::set( '' );
$GLOBALS['menus'] = array();
NLC_Status::instance()->menu();

check( 'Ohne Logo bleibt das Schild', 'dashicons-shield-alt' === ( $GLOBALS['menus'][0][5] ?? '' ), (string) ( $GLOBALS['menus'][0][5] ?? '' ) );

ob_start();
NLC_Status::instance()->menu_style();
check( 'Und es wird kein Stil ausgegeben', '' === trim( (string) ob_get_clean() ) );

// Mit Logo: leere Flaeche im Menue, gefuellt per Maske.
NLC_Symbol::set( $logo );
$GLOBALS['menus'] = array();
NLC_Status::instance()->menu();

check( 'Mit Logo wird die Flaeche freigeraeumt', 'none' === ( $GLOBALS['menus'][0][5] ?? '' ), (string) ( $GLOBALS['menus'][0][5] ?? '' ) );

ob_start();
NLC_Status::instance()->menu_style();
$stil = (string) ob_get_clean();

check( 'Der Stil kommt', str_contains( $stil, 'nlc-menu-symbol' ) );
// Als Maske, nicht als Hintergrundbild: nur so nimmt das Symbol die Farbe
// des Menues an — grau, weiss wenn der Punkt aktiv ist.
check( 'Das Logo wird als Maske gesetzt', str_contains( $stil, 'mask:url(' ) || str_contains( $stil, 'mask:url("' ) , $stil );
check( 'Auch mit Praefix fuer aeltere Browser', str_contains( $stil, '-webkit-mask:' ) );
check( 'Die Farbe kommt vom Menue', str_contains( $stil, 'background-color:currentColor' ) );
check( 'Und es trifft den richtigen Menuepunkt', str_contains( $stil, 'toplevel_page_north-lab' ) );

/* ========================= Zurückspielen: der Weg für den Kunden */

// Ein echtes Verzeichnis, damit realpath() etwas zu tun hat — die
// Pfadpruefung ist genau das, was hier geprueft wird.
$wpInhalt = sys_get_temp_dir() . '/nlc-kundenseite/wp-content';
@mkdir( $wpInhalt . '/plugins/north-lab-child', 0700, true );
@mkdir( $wpInhalt . '/plugins/woocommerce', 0700, true );

define( 'WP_CONTENT_DIR', $wpInhalt );


// Steht an: die Sperre ist der Freigabecode der Agentur, nicht dieser
// Haken. Waere er aus, muesste ihn im Ernstfall erst jemand suchen.
check( 'Zurückspielen steht von Haus aus an',
	true === NLC_Options::default_settings()['allow_restore'] );

/* -------------------------------------- Der Nachzieh-Lauf nach dem Update */

// Ein Plugin-Update loest den Aktivierungs-Haken nicht aus. Ohne diesen
// Lauf erreichte der neue Standardwert keine Installation, in der die
// Optionen schon einmal gespeichert wurden — also keine einzige.
$GLOBALS['options']['nlc_settings'] = array_merge(
	NLC_Options::default_settings(),
	array( 'allow_restore' => false )
);
unset( $GLOBALS['options']['nlc_upgraded'] );

// Welcher Schritt wann faellig ist — daran haengt, dass ein Schritt genau
// einmal greift und nicht bei jedem spaeteren Update erneut.
check( 'Von alt auf 1.9.0 ist der Schritt faellig', array( 'allow_restore' ) === NLC_Options::upgrade_steps( '1.8.0', '1.9.0' ) );
check( 'Von einer Neuinstallation aus auch', array( 'allow_restore' ) === NLC_Options::upgrade_steps( '0', '1.9.0' ) );
check( 'Auf derselben Fassung faellt nichts an', array() === NLC_Options::upgrade_steps( '1.9.0', '1.9.0' ) );
check( 'Und spaeter nie wieder', array() === NLC_Options::upgrade_steps( '1.9.0', '2.4.0' ) );
check( 'Auch nicht beim uebernaechsten Mal', array() === NLC_Options::upgrade_steps( '2.0.0', '2.4.0' ) );
check( 'Eine aeltere laufende Fassung loest nichts aus', array() === NLC_Options::upgrade_steps( '2.0.0', '1.9.0' ) );
// Und ein Update, das den Schritt noch gar nicht kennt, auch nicht: sonst
// stuende in den Optionen etwas, womit die laufende Fassung nichts anfaengt.
check( 'Ein Update unterhalb des Schritts loest ihn nicht aus', array() === NLC_Options::upgrade_steps( '1.8.0', '1.8.5' ) );

NLC_Options::upgrade();
check( 'Der Nachzieh-Lauf schaltet das Zurückspielen an', true === NLC_Options::setting( 'allow_restore' ) );
check( 'Und merkt sich die Fassung', NLC_VERSION === (string) get_option( 'nlc_upgraded', '' ) );

// Wer es danach bewusst abschaltet, bei dem bleibt es aus: der Lauf
// greift genau einmal und nicht bei jedem Seitenaufruf wieder.
NLC_Options::save_settings( array( 'allow_restore' => false ) );
NLC_Options::upgrade();
check( 'Ein zweiter Lauf schaltet nichts wieder an', false === NLC_Options::setting( 'allow_restore' ) );

// Eine frische Installation hat noch gar keine Optionen — da darf der
// Lauf nichts anlegen, sonst stuende der Satz zweimal unterschiedlich da.
unset( $GLOBALS['options']['nlc_settings'], $GLOBALS['options']['nlc_upgraded'] );
NLC_Options::upgrade();
check( 'Ohne gespeicherte Optionen legt der Lauf keine an', ! isset( $GLOBALS['options']['nlc_settings'] ) );
check( 'Vermerkt aber die Fassung', NLC_VERSION === (string) get_option( 'nlc_upgraded', '' ) );

NLC_Options::save_settings( array() );

NLC_Options::save_settings( array( 'allow_restore' => false ) );
$ohne = seite();
check( 'Abgeschaltet steht davon nichts auf der Seite', false === strpos( $ohne, 'Sicherung zurückspielen' ) );

NLC_Options::save_settings( array( 'allow_restore' => true ) );
$mit = seite();

check( 'Eingeschaltet gibt es den Abschnitt', false !== strpos( $mit, 'Sicherung zurückspielen' ) );
check( 'Mit einem Feld für sechs Ziffern', false !== strpos( $mit, 'maxlength="6"' ) );
check( 'Und der Erklärung, woher der Code kommt', false !== strpos( $mit, 'Freigabecode' ) );
// Der Kunde soll wissen, dass nichts verloren geht — sonst traut sich
// niemand, den Knopf zu druecken.
check( 'Es steht da, dass vorher gesichert wird', false !== strpos( $mit, 'jetzige Stand gesichert' ) );
check( 'Und dass der Code nur einmal gilt', false !== strpos( $mit, 'ein einziges Mal' ) );

// Läuft gerade etwas, gibt es kein Eingabefeld — sonst tippt jemand
// mittendrin einen zweiten Code ein.
$verbindung = NLC_Options::connection();
$adresse    = rtrim( (string) $verbindung['dashboard_url'], '/' ) . '/api/child/restore/' . rawurlencode( (string) $verbindung['connection_id'] );

$GLOBALS['antworten'][ $adresse ]['GET'] = 200;
$laufend = seite();
check( 'Ohne laufenden Vorgang gibt es das Feld', false !== strpos( $laufend, 'maxlength="6"' ) );

// Jetzt mit Antwort "laeuft".
function nlc_test_body( string $url, array $daten ): void {
	$GLOBALS['antwort_body'][ $url ] = wp_json_encode( $daten );
}

$GLOBALS['antwort_body'][ $adresse ] = wp_json_encode( array( 'running' => true, 'phase' => 'Datenbank wird eingespielt' ) );

$laeuft = seite();
check( 'Während eines Laufs steht die Phase da', false !== strpos( $laeuft, 'Datenbank wird eingespielt' ) );
check( 'Und kein zweites Eingabefeld', false === strpos( $laeuft, 'maxlength="6"' ) );
check( 'Mit der Bitte, nichts zu bearbeiten', false !== strpos( $laeuft, 'es würde gleich wieder überschrieben' ) );

unset( $GLOBALS['antwort_body'][ $adresse ] );

/* ------------------------------------- Pfade: was abprallt, prallt ab */

// Die gefaehrlichste Stelle im ganzen Plugin. Hier entsteht aus einer
// Angabe von aussen ein Pfad, in den geschrieben wird.
$basis = realpath( NLC_Backup::roots()['content'] );

foreach ( array(
	'../../../etc/passwd',
	'..',
	'wp-content/../../wp-config.php',
	'a/../../b',
	"boese\0.php",
) as $boese ) {
	check( 'Abgewiesen: ' . str_replace( "\0", '\\0', $boese ), null === NLC_Restore::target( 'content', $boese ) );
}

// Das Plugin darf sich nicht selbst ueberschreiben, waehrend es laeuft.
check( 'Das eigene Plugin ist tabu', null === NLC_Restore::target( 'content', 'plugins/north-lab-child/north-lab-child.php' ) );
check( 'Auch der Ordner selbst', null === NLC_Restore::target( 'content', 'plugins/north-lab-child' ) );

// Ein gewoehnlicher Pfad muss natuerlich durchkommen.
$gut = NLC_Restore::target( 'content', 'plugins/woocommerce/woocommerce.php' );
check( 'Ein normaler Pfad kommt durch', null !== $gut );
check( 'Und liegt unterhalb von wp-content',
	is_string( $gut ) && 0 === strpos( $gut, (string) $basis . '/' ), (string) $gut );
check( 'Das wp-content-Präfix wird nicht doppelt gesetzt',
	NLC_Restore::target( 'content', 'wp-content/plugins/x/y.php' ) === NLC_Restore::target( 'content', 'plugins/x/y.php' ) );

echo "$n Prüfungen, $fails Fehler\n";
exit( $fails > 0 ? 1 : 0 );
