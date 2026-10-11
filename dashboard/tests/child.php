<?php
/**
 * Prueft die neuen Child-Plugin-Klassen mit einem winzigen WordPress-Ersatz:
 * nur die Funktionen, die diese Klassen wirklich anfassen.
 */
declare( strict_types = 1 );

// Der WordPress-Ersatz steht in child-stubs.php. Er stand hier frueher ein
// zweites Mal, Wort fuer Wort — und beide Kopien liefen auseinander, sobald
// eine Pruefung eine neue Funktion brauchte.
require __DIR__ . '/child-stubs.php';

require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-options.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-cache.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-maintenance-mode.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-login.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-actions.php';

$fails = 0;
$n     = 0;
function check( string $label, bool $ok ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label\n"; }
}

/* ------------------------------------------------------------ Wartungsmodus */

check( 'Standardmaessig aus', empty( NLC_Maintenance_Mode::state()['enabled'] ) );

$state = NLC_Maintenance_Mode::save( array( 'enabled' => true, 'until' => 0, 'headline' => 'Wartungsmodus' ) );
check( 'Laesst sich einschalten', ! empty( $state['enabled'] ) );
check( 'Merkt sich den Startzeitpunkt', $state['started_at'] > 0 );

$GLOBALS['current'] = 0;
check( 'Besucher sehen die Wartungsseite', NLC_Maintenance_Mode::should_hide() );

$GLOBALS['users'][5] = array( 'login' => 'redakteur', 'roles' => array( 'editor' ), 'can' => true, 'meta' => array() );
$GLOBALS['current']  = 5;
check( 'Redakteure arbeiten weiter', ! NLC_Maintenance_Mode::should_hide() );
$GLOBALS['current'] = 0;

// Abgelaufenes Fenster
NLC_Maintenance_Mode::save( array( 'enabled' => true, 'until' => time() - 10 ) );
check( 'Abgelaufenes Fenster schaltet sich ab', ! NLC_Maintenance_Mode::state()['enabled'] );
check( 'Und der Zustand ist auch gespeichert', empty( get_option( 'nlc_maintenance_mode' )['enabled'] ) );

NLC_Maintenance_Mode::save( array( 'enabled' => true, 'until' => time() + 600 ) );
check( 'Laufendes Fenster bleibt an', NLC_Maintenance_Mode::state()['enabled'] );

// Caches: nur beim Wechsel leeren. Ein Seiten-Cache liefert sein HTML aus,
// bevor WordPress den Wartungsmodus prueft - ohne Leeren bliebe die Seite
// sichtbar. Bei jedem Speichern zu leeren waere aber unnoetige Last.
NLC_Maintenance_Mode::save( array( 'enabled' => false ) );
$GLOBALS['cache_flushed'] = 0;

$an = NLC_Maintenance_Mode::save( array( 'enabled' => true, 'until' => 0 ) );
check( 'Einschalten leert die Caches', 1 === $GLOBALS['cache_flushed'] );
check( 'Und sagt, was geleert wurde', in_array( 'Object-Cache', $an['flushed'], true ) );

$nochmal = NLC_Maintenance_Mode::save( array( 'enabled' => true, 'headline' => 'Anderer Text' ) );
check( 'Erneutes Speichern ohne Wechsel leert nichts', 1 === $GLOBALS['cache_flushed'] );
check( 'Und meldet auch nichts geleert', array() === $nochmal['flushed'] );

$aus = NLC_Maintenance_Mode::save( array( 'enabled' => false ) );
check( 'Abschalten leert die Caches wieder', 2 === $GLOBALS['cache_flushed'] );

// Die Antwort traegt Diagnosefelder. Schickt das Panel sie beim naechsten
// Mal zurueck - und genau das tut es, wenn es den Zustand durchreicht -,
// duerfen sie nicht als Einstellung haengenbleiben.
NLC_Maintenance_Mode::save( $aus );
$gespeichert = (array) $GLOBALS['options']['nlc_maintenance_mode'];
check( 'flushed landet nicht in der Option', ! array_key_exists( 'flushed', $gespeichert ) );
check( 'caches landet nicht in der Option', ! array_key_exists( 'caches', $gespeichert ) );
check( 'Auch sonst nichts Fremdes', array() === array_diff_key( $gespeichert, NLC_Maintenance_Mode::defaults() ) );

// Zustandsfrage ohne Ruecksicht auf den angemeldeten Benutzer.
NLC_Maintenance_Mode::save( array( 'enabled' => true, 'until' => 0 ) );
$GLOBALS['current'] = 7;
$GLOBALS['users'][7] = array( 'login' => 'redakteur', 'roles' => array( 'editor' ), 'can' => true );
check( 'Redakteur sieht die Seite weiter', ! NLC_Maintenance_Mode::should_hide() );
check( 'Der Modus gilt trotzdem als an', NLC_Maintenance_Mode::state_is_on() );
$GLOBALS['current'] = 0;

// Farben
check( 'Hex mit sechs Stellen bleibt', '#ff33aa' === NLC_Maintenance_Mode::sanitize_color( '#FF33AA' ) );
check( 'Kurzform wird ausgeschrieben', '#ff33aa' === NLC_Maintenance_Mode::sanitize_color( '#f3a' ) );
check( 'Unsinn faellt auf die Vorgabe', '#f9907a' === NLC_Maintenance_Mode::sanitize_color( 'javascript:alert(1)' ) );
check( 'Leer faellt auf die Vorgabe', '#f9907a' === NLC_Maintenance_Mode::sanitize_color( '' ) );

// Seite rendern
$page = NLC_Maintenance_Mode::page(
	array(
		'headline' => 'Wartungsmodus',
		'message'  => 'Gleich zurück.',
		'color'    => '#ff3399',
		'logo'     => 'https://north-flow.de/api/assets/abc',
		'until'    => time() + 1800,
	)
);
check( 'Seite zeigt die Ueberschrift', str_contains( $page, '<h1><span class="pulse"></span>Wartungsmodus</h1>' ) );
check( 'Seite nutzt die gewaehlte Farbe', str_contains( $page, '--brand: #ff3399;' ) );
check( 'Seite bindet das Logo ein', str_contains( $page, 'src="https://north-flow.de/api/assets/abc"' ) );
check( 'Seite nennt keine Uhrzeit', ! str_contains( $page, 'Voraussichtlich' ) );
check( 'Auch sonst keine Uhrzeit im Text', ! preg_match( '/\d{1,2}:\d{2}/', strip_tags( $page ) ) );
check( 'Seite bleibt aus dem Index', str_contains( $page, 'noindex, nofollow' ) );
check( 'Seite traegt den Marker fuer die Gegenprobe', str_contains( $page, '<meta name="nlc-maintenance" content="1">' ) );
check( 'Seite laedt nichts von aussen ausser dem Logo', 1 === substr_count( $page, 'https://' ) );

// Kein Logo: Name statt Bild
$plain = NLC_Maintenance_Mode::page( array( 'logo' => '' ) );
check( 'Ohne Logo steht der Seitenname da', str_contains( $plain, '<div class="wordmark">Kundenseite</div>' ) );

// Einschleusversuche
$evil = NLC_Maintenance_Mode::page(
	array(
		'headline' => 'Hallo</h1><script>alert(1)</script>',
		'message'  => '<img src=x onerror=alert(2)>',
		'color'    => '#000"><script>alert(3)</script>',
		'logo'     => 'javascript:alert(4)',
	)
);
check( 'Ueberschrift wird maskiert', ! str_contains( $evil, '<script>' ) );
check( 'Text wird maskiert', ! str_contains( $evil, '<img src=x' ) );
check( 'Der Text steht als Klartext da', str_contains( $evil, '&lt;img src=x onerror=alert(2)&gt;' ) );
check( 'Farbe faellt auf die Vorgabe zurueck', str_contains( $evil, '--brand: #f9907a;' ) );
check( 'Nicht-URL im Logo wird verworfen', ! str_contains( $evil, 'javascript:' ) );

/* -------------------------------------------------------- Ein-Klick-Anmeldung */

NLC_Options::save_settings( array( 'allow_autologin' => true ) );
$GLOBALS['users'][1] = array( 'login' => 'chef', 'roles' => array( 'administrator' ), 'can' => true, 'meta' => array() );
$GLOBALS['users'][9] = array( 'login' => 'rollenlos', 'roles' => array(), 'can' => false, 'meta' => array() );

$issued = NLC_Login::issue( array() );
check( 'Ohne Angabe wird der aelteste Administrator gewaehlt', 'chef' === ( $issued['user']['login'] ?? '' ) );
// Nicht die Startseite: die liegt bei fast jedem Kunden im Seiten-Cache und
// wird als fertiges HTML ausgeliefert, ohne dass PHP ueberhaupt laeuft. Der
// Anmeldelink verpuffte dann wirkungslos — man landete einfach auf der
// Startseite, nicht angemeldet. wp-login.php wird von keinem vernuenftig
// eingestellten Cache ausgeliefert.
check( 'Adresse zeigt auf wp-login.php, nicht auf die Startseite', str_starts_with( (string) $issued['url'], 'https://kunde.de/wp-login.php?northlab-login=' ) );
check( 'Die Antwort nennt die gefundenen Seiten-Caches', array_key_exists( 'caches', $issued ) && is_array( $issued['caches'] ) );

parse_str( (string) parse_url( $issued['url'], PHP_URL_QUERY ), $query );
$token = $query['northlab-login'];
check( 'Token ist 64 Hex-Zeichen lang', (bool) preg_match( '/^[0-9a-f]{64}$/', $token ) );
check( 'Der Klartext-Token steht nicht im Speicher', ! isset( $GLOBALS['transients'][ 'nlc_login_' . $token ] ) );
check( 'Gespeichert wird nur der Hash', isset( $GLOBALS['transients'][ 'nlc_login_' . hash( 'sha256', $token ) ] ) );

$login = new NLC_Login();

// Einloesen
$_GET['northlab-login'] = $token;
$GLOBALS['current']     = 0;
try { $login->maybe_login(); } catch ( ExitSignal $e ) {}
check( 'Anmeldung setzt ein Cookie', 1 === count( $GLOBALS['cookies'] ) );
check( 'Die Antwort darf nirgends zwischengespeichert werden', $GLOBALS['nocache'] > 0 );
check( 'Und zwar ohne "angemeldet bleiben"', false === $GLOBALS['cookies'][0]['remember'] );
check( 'Danach geht es ins Backend', 'https://kunde.de/wp-admin/' === end( $GLOBALS['redirects'] ) );
check( 'Der Token ist verbraucht', false === get_transient( 'nlc_login_' . hash( 'sha256', $token ) ) );

// Zweiter Versuch mit demselben Token
$GLOBALS['current'] = 0;
$before             = count( $GLOBALS['died'] );
try { $login->maybe_login(); } catch ( ExitSignal $e ) {}
check( 'Ein zweites Mal geht nicht', count( $GLOBALS['died'] ) === $before + 1 );

// Abgelaufener Token
$issued2 = NLC_Login::issue( array( 'login' => 'chef' ) );
parse_str( (string) parse_url( $issued2['url'], PHP_URL_QUERY ), $q2 );
$key = 'nlc_login_' . hash( 'sha256', $q2['northlab-login'] );
$GLOBALS['transients'][ $key ]['v']['issued'] = time() - 200;
$_GET['northlab-login']                       = $q2['northlab-login'];
$GLOBALS['current']                           = 0;
$before                                       = count( $GLOBALS['died'] );
try { $login->maybe_login(); } catch ( ExitSignal $e ) {}
check( 'Ein alter Token wird abgewiesen', count( $GLOBALS['died'] ) === $before + 1 );

// Erfundener Token
$_GET['northlab-login'] = str_repeat( 'a', 64 );
$before                 = count( $GLOBALS['died'] );
try { $login->maybe_login(); } catch ( ExitSignal $e ) {}
check( 'Ein erfundener Token wird abgewiesen', count( $GLOBALS['died'] ) === $before + 1 );

// Unsinn statt Token
$_GET['northlab-login'] = '../../etc/passwd';
$before                 = count( $GLOBALS['died'] );
try { $login->maybe_login(); } catch ( ExitSignal $e ) {}
check( 'Kein Hex-Token wird sofort abgewiesen', count( $GLOBALS['died'] ) === $before + 1 );

// Ohne Parameter passiert nichts
unset( $_GET['northlab-login'] );
$before = count( $GLOBALS['died'] ) + count( $GLOBALS['redirects'] );
$login->maybe_login();
check( 'Normale Aufrufe bleiben unberuehrt', count( $GLOBALS['died'] ) + count( $GLOBALS['redirects'] ) === $before );

// Schon als jemand anders angemeldet.
// Das war der haeufigste Fehlerfall: im eigenen Browser laeuft noch eine
// Kundensitzung, der Link leitete frueher nur weiter — und man stand als der
// falsche Benutzer im Backend oder bekam "keine ausreichenden Rechte". Das
// sah nach einem kaputten Link aus, war aber eine fremde Sitzung.
$fremd = NLC_Login::issue( array( 'login' => 'chef' ) );
parse_str( (string) parse_url( $fremd['url'], PHP_URL_QUERY ), $q3 );
$_GET['northlab-login'] = $q3['northlab-login'];
$GLOBALS['current']     = 5;          // der Redakteur ist noch angemeldet
$GLOBALS['logouts']     = array();
$vorherCookies          = count( $GLOBALS['cookies'] );
try { $login->maybe_login(); } catch ( ExitSignal $e ) {}
check( 'Eine fremde Sitzung wird vorher beendet', array( 5 ) === $GLOBALS['logouts'] );
check( 'Und danach wird der gemeinte Benutzer angemeldet', count( $GLOBALS['cookies'] ) === $vorherCookies + 1
	&& 1 === (int) end( $GLOBALS['cookies'] )['id'] );
check( 'Es geht ins Backend, nicht auf eine Fehlerseite', 'https://kunde.de/wp-admin/' === end( $GLOBALS['redirects'] ) );

// Schon als derselbe Benutzer angemeldet: dann gibt es nichts zu beenden.
$gleich = NLC_Login::issue( array( 'login' => 'chef' ) );
parse_str( (string) parse_url( $gleich['url'], PHP_URL_QUERY ), $q4 );
$_GET['northlab-login'] = $q4['northlab-login'];
$GLOBALS['current']     = 1;
$GLOBALS['logouts']     = array();
try { $login->maybe_login(); } catch ( ExitSignal $e ) {}
check( 'Die eigene Sitzung wird nicht unnoetig beendet', array() === $GLOBALS['logouts'] );
check( 'Und es geht trotzdem ins Backend', 'https://kunde.de/wp-admin/' === end( $GLOBALS['redirects'] ) );

// Abgeschaltet
NLC_Options::save_settings( array( 'allow_autologin' => false ) );
$off = NLC_Login::issue( array() );
check( 'Abgeschaltet gibt es keine Adresse', empty( $off['ok'] ) && '' === ( $off['url'] ?? '' ) );
NLC_Options::save_settings( array( 'allow_autologin' => true ) );

// Konto ohne Rolle
$none = NLC_Login::issue( array( 'user_id' => 9 ) );
check( 'Ein rollenloses Konto bekommt keine Adresse', empty( $none['ok'] ) );

// Unbekannter Benutzer
$gone = NLC_Login::issue( array( 'user_id' => 4242 ) );
check( 'Unbekannter Benutzer wird abgewiesen', empty( $gone['ok'] ) );

/* --------------------------------------------------- Befristete Konten */

$GLOBALS['deleted'] = array();
$GLOBALS['users']   = array(
	1  => array( 'login' => 'chef', 'roles' => array( 'administrator' ), 'can' => true, 'meta' => array() ),
	20 => array( 'login' => 'abgelaufen', 'roles' => array( 'editor' ), 'can' => true, 'meta' => array( 'nlc_expires_at' => time() - 60 ) ),
	21 => array( 'login' => 'laeuft-noch', 'roles' => array( 'editor' ), 'can' => true, 'meta' => array( 'nlc_expires_at' => time() + 3600 ) ),
	22 => array( 'login' => 'unbefristet', 'roles' => array( 'editor' ), 'can' => true, 'meta' => array() ),
);

$removed = NLC_Actions::purge_expired_users();

check( 'Genau ein Konto wird entfernt', array( 'abgelaufen' ) === $removed );
check( 'Das laufende Konto bleibt', isset( $GLOBALS['users'][21] ) );
check( 'Das unbefristete Konto bleibt', isset( $GLOBALS['users'][22] ) );
check( 'Der Administrator bleibt', isset( $GLOBALS['users'][1] ) );
check( 'Inhalte gehen an den aeltesten Administrator', 1 === ( $GLOBALS['deleted'][0]['reassign'] ?? 0 ) );

// Ein abgelaufener Administrator, der selbst Empfaenger waere, darf nicht sich
// selbst zugewiesen und geloescht werden.
$GLOBALS['deleted'] = array();
$GLOBALS['users']   = array(
	1 => array( 'login' => 'chef', 'roles' => array( 'administrator' ), 'can' => true, 'meta' => array( 'nlc_expires_at' => time() - 60 ) ),
);
$removed = NLC_Actions::purge_expired_users();
check( 'Der einzige Administrator wird nicht geloescht', array() === $removed && isset( $GLOBALS['users'][1] ) );
check( 'Seine Befristung wird stattdessen aufgehoben', ! isset( $GLOBALS['users'][1]['meta']['nlc_expires_at'] ) );

/* --------------------------------------------------------- Passwort setzen */

$GLOBALS['users']              = array( 3 => array( 'login' => 'kunde', 'roles' => array( 'editor' ), 'can' => true, 'meta' => array() ) );
$GLOBALS['sessions_destroyed'] = array();
$GLOBALS['passwords']          = array();

$result = NLC_Actions::user_action( 'set-password', array( 'id' => 3 ) );
check( 'Passwort setzen meldet Erfolg', ! empty( $result['success'] ) );
check( 'Das neue Passwort kommt genau einmal zurueck', 24 === strlen( (string) ( $result['password'] ?? '' ) ) );
check( 'Offene Sitzungen werden beendet', 1 === count( $GLOBALS['sessions_destroyed'] ) );
check( 'Das Passwort steht in keiner Meldung', ! str_contains( (string) $result['message'], (string) $result['password'] ) );

$gone = NLC_Actions::user_action( 'set-password', array( 'id' => 999 ) );
check( 'Unbekannter Benutzer wird abgewiesen', empty( $gone['success'] ) );

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
