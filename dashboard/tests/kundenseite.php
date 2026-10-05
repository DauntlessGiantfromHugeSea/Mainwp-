<?php
/**
 * Was der Kunde auf seiner eigenen Seite sieht: Sicherungsverlauf, der
 * Hinweis waehrend eines Laufs und die woechentliche Link-Pruefung.
 */
declare( strict_types = 1 );

require __DIR__ . '/child-stubs.php';

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

	return array( 'response' => array( 'code' => (int) $eintrag ) );
}
function wp_remote_head( $url, $args = array() ) { return nlc_test_http( 'HEAD', $url, $args ); }
function wp_remote_get( $url, $args = array() ) { return nlc_test_http( 'GET', $url, $args ); }
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0; }

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
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, (int) $f ); }
function wp_nonce_field( $a = '', $n = '_wpnonce', $r = true, $e = true ) { echo '<input type="hidden" name="' . $n . '" value="x">'; }
function get_edit_post_link( $id = 0 ) { return 'https://kunde.de/wp-admin/post.php?post=' . (int) $id . '&action=edit'; }
function add_menu_page( ...$a ) { $GLOBALS['menus'][] = $a; return 'toplevel_page'; }
function wp_send_json_success( $d = null ) { $GLOBALS['json'] = $d; throw new ExitSignal( 'json' ); }
function wp_send_json_error( $d = null, $c = 0 ) { $GLOBALS['json_error'] = $c; throw new ExitSignal( 'json' ); }

require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-options.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-status.php';

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

echo "$n Prüfungen, $fails Fehler\n";
exit( $fails > 0 ? 1 : 0 );
