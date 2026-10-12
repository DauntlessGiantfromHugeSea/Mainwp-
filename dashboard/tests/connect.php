<?php
/**
 * Der Verbindungsablauf auf der Kundenseite: Code pruefen, verbrauchen,
 * Verbindung speichern — und was passiert, wenn die Antwort verloren geht.
 */
declare( strict_types = 1 );

require __DIR__ . '/child-stubs.php';

function wp_hash_password( $p ) { return 'hash:' . hash( 'sha256', (string) $p ); }
function wp_check_password( $p, $h ) { return hash_equals( (string) $h, 'hash:' . hash( 'sha256', (string) $p ) ); }
function sanitize_text_field_x( $v ) { return trim( strip_tags( (string) $v ) ); }

require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-options.php';

$fails = 0;
$n     = 0;
function check( string $label, bool $ok ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label\n"; }
}
function reset_all(): void {
	$GLOBALS['options']    = array();
	$GLOBALS['transients'] = array();
}

/* ------------------------------------------------------- Schreibweisen */

reset_all();
$code = NLC_Options::generate_connect_code();

check( 'Code ist 32 Zeichen Grossbuchstaben-Hex', (bool) preg_match( '/^[0-9A-F]{32}$/', $code ) );
check( 'Richtiger Code wird angenommen', null === NLC_Options::check_connect_code( $code ) );
check( 'Kleinschreibung wird angenommen', null === NLC_Options::check_connect_code( strtolower( $code ) ) );
check( 'Fuehrendes Leerzeichen stoert nicht', null === NLC_Options::check_connect_code( '  ' . $code ) );
check( 'Zeilenumbruch am Ende stoert nicht', null === NLC_Options::check_connect_code( $code . "\n" ) );
check( 'Leerzeichen mittendrin stoeren nicht', null === NLC_Options::check_connect_code( substr( $code, 0, 8 ) . ' ' . substr( $code, 8 ) ) );

/* ------------------------------------- Die drei Gruende sind unterscheidbar */

reset_all();
$missing = NLC_Options::check_connect_code( 'IRGENDWAS' );
check( 'Ohne erzeugten Code: eigener Grund', is_string( $missing ) && str_contains( $missing, 'kein Verbindungscode bereit' ) );
check( 'Und der sagt, was zu tun ist', is_string( $missing ) && str_contains( $missing, 'neuen erzeugen' ) );

reset_all();
$code = NLC_Options::generate_connect_code();
$GLOBALS['options']['nlc_connect_code']['expires'] = time() - 1;
$expired = NLC_Options::check_connect_code( $code );
check( 'Abgelaufen: eigener Grund', is_string( $expired ) && str_contains( $expired, 'abgelaufen' ) );
check( 'Und die Gueltigkeitsdauer steht dabei', is_string( $expired ) && str_contains( $expired, '60 Minuten' ) );
check( 'Ein abgelaufener Code wird entfernt', ! isset( $GLOBALS['options']['nlc_connect_code'] ) );

reset_all();
$code  = NLC_Options::generate_connect_code();
$wrong = NLC_Options::check_connect_code( str_repeat( 'A', 32 ) );
check( 'Falscher Code: eigener Grund', is_string( $wrong ) && str_contains( $wrong, 'stimmt nicht' ) );
check( 'Ein falscher Versuch verbraucht den Code nicht', null === NLC_Options::check_connect_code( $code ) );

/* --------------------------------------------------- Verbrauchen */

reset_all();
$code = NLC_Options::generate_connect_code();
check( 'Verbrauchen klappt einmal', NLC_Options::consume_connect_code( $code ) );
check( 'Und danach nicht mehr', ! NLC_Options::consume_connect_code( $code ) );

/* ----------------------------------- Verlorene Antwort, ehrliche Wiederholung */

reset_all();
$code = NLC_Options::generate_connect_code();
$key  = "-----BEGIN PUBLIC KEY-----\nMIIB...\n-----END PUBLIC KEY-----";
$id   = 'abc123def456';

NLC_Options::consume_connect_code( $code );
NLC_Options::save_connection( array( 'connection_id' => $id, 'public_key' => $key, 'dashboard_url' => 'https://panel.test' ) );
NLC_Options::remember_connect( $code, $id, $key );

check( 'Dieselbe Anfrage darf sich wiederholen', NLC_Options::was_just_connected( $code, $id, $key ) );
check( 'Auch mit anderer Schreibweise', NLC_Options::was_just_connected( strtolower( $code ) . ' ', $id, $key ) );

check( 'Anderer Code zaehlt nicht', ! NLC_Options::was_just_connected( str_repeat( 'B', 32 ), $id, $key ) );
check( 'Andere Kennung zaehlt nicht', ! NLC_Options::was_just_connected( $code, 'fremd0000000', $key ) );
check( 'Anderer Schluessel zaehlt nicht', ! NLC_Options::was_just_connected( $code, $id, 'anderer schluessel' ) );

// Nach Ablauf der Merkfrist ist Schluss.
$GLOBALS['transients']['nlc_last_connect']['exp'] = time() - 1;
check( 'Nach der Merkfrist zaehlt es nicht mehr', ! NLC_Options::was_just_connected( $code, $id, $key ) );

// Ohne bestehende Verbindung darf nichts durchgehen.
reset_all();
NLC_Options::remember_connect( $code, $id, $key );
check( 'Ohne gespeicherte Verbindung zaehlt es nicht', ! NLC_Options::was_just_connected( $code, $id, $key ) );

// Und die Merkfrist alleine oeffnet keine Tuer: ohne passenden Code nichts.
reset_all();
NLC_Options::save_connection( array( 'connection_id' => $id, 'public_key' => $key, 'dashboard_url' => 'https://panel.test' ) );
NLC_Options::remember_connect( $code, $id, $key );
check( 'Ein fremder Code oeffnet die Wiederholung nicht', ! NLC_Options::was_just_connected( 'FREMD', $id, $key ) );

/* -------------- Der Fall, der es ausgeloest hat: spaeterer Fehler */

// Alte Reihenfolge war: Code verbrauchen, DANN den Schluessel pruefen. Ein
// ungueltiger Schluessel liess den Code verbrannt zurueck.
reset_all();
$code = NLC_Options::generate_connect_code();

// So laeuft es jetzt: erst pruefen ...
$problem = NLC_Options::check_connect_code( $code );
check( 'Pruefen allein verbraucht nichts', null === $problem );

// ... dann faellt der Schluessel durch, und der Code lebt weiter.
check( 'Nach abgelehntem Schluessel gilt der Code noch', null === NLC_Options::check_connect_code( $code ) );

// Erst der erfolgreiche Durchlauf verbraucht ihn.
NLC_Options::consume_connect_code( $code );
check( 'Erst der Erfolg verbraucht den Code', null !== NLC_Options::check_connect_code( $code ) );

/* ================================ Der Endpunkt selbst: /connect und /update */

// Doppel statt der echten Klassen: NLC_Info und NLC_Updates greifen tief in
// WordPress hinein. Hier geht es um den Endpunkt, nicht um sie.
class NLC_Info {
	public static function ping() { return array( 'version' => '1.0.0' ); }
	public static function collect( $force = false ) { return array( 'site' => array() ); }
}

class NLC_Updates {
	public static function available() {
		return array(
			'core'         => array( array( 'slug' => 'wordpress' ) ),
			'plugins'      => array( array( 'slug' => 'eins' ), array( 'slug' => 'zwei' ) ),
			'themes'       => array( array( 'slug' => 'thema' ) ),
			'translations' => 2,
		);
	}

	public static function run( array $items ) {
		$GLOBALS['nlc_update_aufrufe'] = $items;

		return array_map(
			static function ( $eintrag ) { return array( 'slug' => $eintrag['slug'], 'success' => true ); },
			$items
		);
	}
}

require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-symbol.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-rest.php';

$rest = new NLC_REST();

/** Ein echtes Schluesselpaar — openssl_pkey_get_public prueft wirklich. */
$paar = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
$oeff = (string) openssl_pkey_get_details( $paar )['key'];

/* ------------------------------------------- Das Dashboard darf nicht wechseln */

reset_all();
$code = NLC_Options::generate_connect_code();

$erste = $rest->connect(
	new WP_REST_Request(
		array(
			'code'          => $code,
			'public_key'    => $oeff,
			'connection_id' => 'abc123',
			'dashboard_url' => 'https://panel.north-lab.de',
		)
	)
);

check( 'Die erste Verbindung kommt zustande', is_array( $erste ) && ! empty( $erste['connected'] ) );
check( 'Danach gilt die Seite als verbunden', NLC_Options::is_connected() );

// Der Fall, um den es geht: wer an einen gueltigen Code kommt — er steht im
// Backend im Klartext und geht bei der Uebernahme per Mail an die Agentur —
// setzte frueher einfach eine eigene dashboard_url und war damit das
// Dashboard dieser Seite. Ueber die Selbstaktualisierung heisst das:
// beliebiger PHP-Code, signiert mit dem eigenen Schluessel.
$code2 = NLC_Options::generate_connect_code();
$fremd = $rest->connect(
	new WP_REST_Request(
		array(
			'code'          => $code2,
			'public_key'    => $oeff,
			'connection_id' => 'uebernahme',
			'dashboard_url' => 'https://angreifer.example',
		)
	)
);

check( 'Ein fremdes Dashboard wird abgewiesen', $fremd instanceof WP_Error );
check( 'Mit einem verstaendlichen Grund', $fremd instanceof WP_Error && str_contains( $fremd->get_error_message(), 'anderen Dashboard' ) );
check( 'Die bestehende Verbindung bleibt, wie sie war', 'abc123' === ( NLC_Options::connection()['connection_id'] ?? '' ) );
check( 'Und der Code ist nicht verbrannt', null === NLC_Options::check_connect_code( $code2 ) );

// Das Erneuern derselben Verbindung muss weiter gehen — genau das tut das
// Panel, wenn es ein Schluesselpaar ersetzt.
$erneut = $rest->connect(
	new WP_REST_Request(
		array(
			'code'          => $code2,
			'public_key'    => $oeff,
			'connection_id' => 'neu456',
			'dashboard_url' => 'https://panel.north-lab.de/',
		)
	)
);

check( 'Dasselbe Dashboard darf die Verbindung erneuern', is_array( $erneut ) && ! empty( $erneut['connected'] ) );
check( 'Und die neue Kennung steht', 'neu456' === ( NLC_Options::connection()['connection_id'] ?? '' ) );

/* ----------------------------- Was nicht signiert ist, wird nicht gelesen */

// Die Signatur deckt Verbindung, Zeit, Nonce, Methode, Route und den Hash des
// Rumpfes ab — nicht den Query-String. get_param() greift aber auf die
// Abfrage zurueck, sobald ein Wert im Rumpf fehlt. Wer auf dem
// Uebertragungsweg sass, haengte "&all=1" an eine Anfrage fuer ein einzelnes
// Plugin, und die Seite aktualisierte alles.
NLC_Options::save_settings( array( 'allow_updates' => true ) );

$GLOBALS['nlc_update_aufrufe'] = array();

$angehaengt = $rest->update(
	new WP_REST_Request(
		array( 'items' => array( array( 'type' => 'plugin', 'slug' => 'eins' ) ) ),
		array( 'all' => '1' ),
		'POST',
		'/northlab/v1/update'
	)
);

$verarbeitet = $GLOBALS['nlc_update_aufrufe'];

check( 'Ein angehaengtes all=1 wird nicht beachtet', 1 === count( $verarbeitet ), (string) count( $verarbeitet ) );
check( 'Es bleibt bei dem einen Plugin', 'eins' === ( $verarbeitet[0]['slug'] ?? '' ) );

// Dasselbe fuer die Liste selbst: wer sie nur an die Adresse haengt,
// bestimmt sonst, was eingespielt wird.
$GLOBALS['nlc_update_aufrufe'] = array();

$rest->update(
	new WP_REST_Request(
		array( 'all' => false ),
		array( 'items' => array( array( 'type' => 'plugin', 'slug' => 'untergeschoben' ) ) ),
		'POST',
		'/northlab/v1/update'
	)
);

check( 'Eine nur angehaengte Liste wird nicht eingespielt', array() === $GLOBALS['nlc_update_aufrufe'] );

// Im Rumpf, also signiert, gilt es weiterhin.
$GLOBALS['nlc_update_aufrufe'] = array();
$rest->update( new WP_REST_Request( array( 'all' => true ), array(), 'POST', '/northlab/v1/update' ) );
check( 'Im signierten Rumpf gilt all weiterhin', count( $GLOBALS['nlc_update_aufrufe'] ) > 1, (string) count( $GLOBALS['nlc_update_aufrufe'] ) );

/* ------------------------------------------------------- Das Menuesymbol */

// Der Punkt: dieser Weg haengt an nichts. Weder am Branding noch an einem
// Schalter — wer das Plugin hat, bekommt das Zeichen. Haengt es wieder an
// einer Erlaubnis, wird dieser Block rot.
NLC_Options::save_settings(
	array(
		'allow_branding'  => false,
		'allow_updates'   => false,
		'allow_backup'    => false,
		'allow_restore'   => false,
		'allow_maintlog'  => false,
	)
);

$zeichen = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><circle cx="4" cy="4" r="3"/></svg>' );

$antwort = $rest->symbol(
	new WP_REST_Request(
		array( 'glyph' => $zeichen, 'stamp' => 'abc123' ),
		array(),
		'POST',
		'/northlab/v1/symbol'
	)
);

check( 'Das Symbol kommt auch ohne Branding-Erlaubnis an', is_array( $antwort ) && true === ( $antwort['symbol'] ?? false ) );
check( 'Und liegt danach bereit', NLC_Symbol::has() );
check( 'Unveraendert', $zeichen === NLC_Symbol::get() );
check( 'Der Stand wird zurueckgemeldet', is_array( $antwort ) && 'abc123' === ( $antwort['stamp'] ?? '' ) );

// Leer bedeutet: wieder weg. Sonst bliebe ein zurueckgezogenes Logo stehen.
$weg = $rest->symbol( new WP_REST_Request( array( 'glyph' => '', 'stamp' => 'ohne' ), array(), 'POST', '/northlab/v1/symbol' ) );
check( 'Ein leeres Zeichen raeumt auf', is_array( $weg ) && false === ( $weg['symbol'] ?? true ) );
check( 'Danach ist nichts mehr hinterlegt', ! NLC_Symbol::has() );

// Was nicht durchgeht, geht nicht durch — auch hier nicht.
$boese = $rest->symbol(
	new WP_REST_Request(
		array( 'glyph' => 'data:text/html;base64,' . base64_encode( '<script>x</script>' ), 'stamp' => 'x' ),
		array(),
		'POST',
		'/northlab/v1/symbol'
	)
);
check( 'Ein fremdes Format wird abgewiesen', is_array( $boese ) && false === ( $boese['symbol'] ?? true ) );
check( 'Und hinterlaesst nichts', ! NLC_Symbol::has() );

// Nur der signierte Rumpf zaehlt, wie ueberall sonst auch.
$angehaengt = $rest->symbol(
	new WP_REST_Request( array(), array( 'glyph' => $zeichen ), 'POST', '/northlab/v1/symbol' )
);
check( 'Ein nur angehaengtes Zeichen wird nicht uebernommen', is_array( $angehaengt ) && false === ( $angehaengt['symbol'] ?? true ) );
check( 'Es liegt auch nichts da', ! NLC_Symbol::has() );

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
