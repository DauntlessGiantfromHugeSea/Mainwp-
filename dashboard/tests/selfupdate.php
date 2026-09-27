<?php
/**
 * Prueft die Update-Kette Panel -> Kundenseite: echte RSA-Signatur, echte
 * Pruefsumme, und jeder Manipulationsversuch dazwischen.
 */
declare( strict_types = 1 );

require __DIR__ . '/child-stubs.php';

define( 'HOUR_IN_SECONDS_X', 3600 );

$GLOBALS['http']       = array();   // Antworten, die wp_remote_post liefern soll
$GLOBALS['downloads']  = array();
$GLOBALS['unlinked']   = array();

function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['last_post'] = array( 'url' => $url, 'args' => $args );
	return $GLOBALS['http'][ $url ] ?? new WP_Error();
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? ( $r['body'] ?? '' ) : ''; }
function wp_parse_url( $u ) { return parse_url( $u ); }
function download_url( $url, $timeout = 300 ) {
	if ( ! isset( $GLOBALS['downloads'][ $url ] ) ) {
		return new WP_Error();
	}
	$tmp = tempnam( sys_get_temp_dir(), 'nlc' );
	file_put_contents( $tmp, $GLOBALS['downloads'][ $url ] );
	return $tmp;
}
function delete_site_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); }
function wp_update_plugins() {}
function add_filter( ...$a ) {}
function wp_cache_delete( ...$a ) {}
function is_plugin_active( $p ) { return ! empty( $GLOBALS['active'][ $p ] ); }
function activate_plugin( $p, $redirect = '', $network = false, $silent = false ) {
	$GLOBALS['activations'][] = array( 'plugin' => $p, 'silent' => $silent );
	if ( ! empty( $GLOBALS['activation_fails'] ) ) {
		return new WP_Error( 'nlc_test', 'Aktivierung abgelehnt.' );
	}
	$GLOBALS['active'][ $p ] = true;
	return null;
}

/**
 * Bildet Plugin_Upgrader nach - einschliesslich der Eigenheit, das Plugin vor
 * dem Ersetzen still abzuschalten (deactivate_plugin_before_upgrade).
 */
class Plugin_Upgrader {
	public $skin;
	public function __construct( $skin = null ) { $this->skin = $skin; }
	public function upgrade( $plugin ) {
		$GLOBALS['active'][ $plugin ] = false;   // genau das macht WordPress
		return $GLOBALS['upgrade_result'] ?? true;
	}
}
class Automatic_Upgrader_Skin { public $result = null; }
class WP_Upgrader_Skin {}

define( 'NLC_VERSION', '1.2.0' );

require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-options.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-selfupdate.php';

// --- Panel-Seite: echtes Schluesselpaar, echte Signatur ---------------------
$pair    = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $pair, $privateKey );
$publicKey = openssl_pkey_get_details( $pair )['key'];

// Ein zweites, fremdes Schluesselpaar fuer den Faelschungsversuch.
$evil = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
openssl_pkey_export( $evil, $evilPrivate );

$package     = 'https://panel.north-lab.de/api/child/package/abc123def456';
$packageBody = 'PK' . str_repeat( 'x', 500 );   // steht fuer das ZIP
$packageHash = hash( 'sha256', $packageBody );

$GLOBALS['downloads'][ $package ] = $packageBody;

NLC_Options::save_connection(
	array(
		'connection_id' => 'abc123def456',
		'public_key'    => $publicKey,
		'dashboard_url' => 'https://panel.north-lab.de',
	)
);
NLC_Options::save_settings( array( 'allow_self_update' => true ) );

$manifestUrl = 'https://panel.north-lab.de/api/child/manifest';

/**
 * Baut eine Panel-Antwort wie ChildUpdateController::manifest.
 */
function panel_response( array $overrides, string $key, ?string $signOverride = null ): array {
	$manifest = array_merge(
		array(
			'slug'         => 'north-lab-child',
			'plugin'       => 'north-lab-child/north-lab-child.php',
			'version'      => '1.3.0',
			'package'      => $GLOBALS['pkg'],
			'sha256'       => $GLOBALS['pkghash'],
			'requires'     => '6.0',
			'requires_php' => '7.4',
			'name'         => 'NorthLab Child',
			'generated_at' => time(),
		),
		$overrides
	);

	$canonical = (string) json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	openssl_sign( $signOverride ?? $canonical, $sig, $key, OPENSSL_ALGO_SHA256 );

	return array( 'code' => 200, 'body' => json_encode( array( 'ok' => true, 'manifest' => $canonical, 'signature' => base64_encode( $sig ) ) ) );
}

$GLOBALS['pkg']     = $package;
$GLOBALS['pkghash'] = $packageHash;

$fails = 0;
$n     = 0;
function check( string $label, bool $ok ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label\n"; }
}
function fresh(): void { unset( $GLOBALS['transients']['nlc_update_manifest'] ); }

/* ------------------------------------------------------------- Gutfall */

$GLOBALS['http'][ $manifestUrl ] = panel_response( array(), $privateKey );
fresh();
$m = NLC_Selfupdate::manifest( true );

check( 'Gueltiges Manifest wird angenommen', is_array( $m ) );
check( 'Version wird uebernommen', '1.3.0' === ( $m['version'] ?? '' ) );
check( 'Die Verbindungskennung wird mitgeschickt', 'abc123def456' === ( $GLOBALS['last_post']['args']['body']['connection_id'] ?? '' ) );

// Update landet im WordPress-Transient
$transient = (object) array( 'response' => array(), 'no_update' => array() );
$updater   = new NLC_Selfupdate();
$out       = $updater->inject( $transient );

check( 'Update erscheint in der WordPress-Liste', isset( $out->response['north-lab-child/north-lab-child.php'] ) );
check( 'Mit der neuen Versionsnummer', '1.3.0' === $out->response['north-lab-child/north-lab-child.php']->new_version );
check( 'Und der Paketadresse des Panels', $package === $out->response['north-lab-child/north-lab-child.php']->package );

// Aktuelle Version: kein Update, aber in no_update, damit Auto-Update waehlbar ist
fresh();
$GLOBALS['http'][ $manifestUrl ] = panel_response( array( 'version' => '1.2.0' ), $privateKey );
$transient = (object) array( 'response' => array(), 'no_update' => array() );
$out       = $updater->inject( $transient );
check( 'Gleiche Version meldet kein Update', ! isset( $out->response['north-lab-child/north-lab-child.php'] ) );
check( 'Taucht aber unter no_update auf', isset( $out->no_update['north-lab-child/north-lab-child.php'] ) );

fresh();
$GLOBALS['http'][ $manifestUrl ] = panel_response( array( 'version' => '1.1.0' ), $privateKey );
$transient = (object) array( 'response' => array(), 'no_update' => array() );
$out       = $updater->inject( $transient );
check( 'Aeltere Version loest kein Downgrade aus', ! isset( $out->response['north-lab-child/north-lab-child.php'] ) );

/* ------------------------------------------------- Angriffe auf das Manifest */

fresh();
$GLOBALS['http'][ $manifestUrl ] = panel_response( array(), $evilPrivate );
check( 'Fremd signiertes Manifest wird verworfen', null === NLC_Selfupdate::manifest( true ) );

// Inhalt nach dem Signieren veraendert
fresh();
$tampered            = panel_response( array(), $privateKey );
$decoded             = json_decode( $tampered['body'], true );
$inner               = json_decode( $decoded['manifest'], true );
$inner['package']    = 'https://boese.example/schadcode.zip';
$decoded['manifest'] = json_encode( $inner, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$tampered['body']    = json_encode( $decoded );
$GLOBALS['http'][ $manifestUrl ] = $tampered;
check( 'Nachtraeglich geaendertes Manifest wird verworfen', null === NLC_Selfupdate::manifest( true ) );

// Korrekt signiert, aber Paket von fremdem Host
fresh();
$GLOBALS['http'][ $manifestUrl ] = panel_response( array( 'package' => 'https://boese.example/schadcode.zip' ), $privateKey );
check( 'Paket von fremdem Host wird verworfen', null === NLC_Selfupdate::manifest( true ) );

// Korrekt signiert, aber http statt https
fresh();
$GLOBALS['http'][ $manifestUrl ] = panel_response( array( 'package' => 'http://panel.north-lab.de/api/child/package/abc123def456' ), $privateKey );
check( 'Unverschluesseltes Paket wird verworfen', null === NLC_Selfupdate::manifest( true ) );

// Altes, echt signiertes Manifest
fresh();
$GLOBALS['http'][ $manifestUrl ] = panel_response( array( 'generated_at' => time() - 200000 ), $privateKey );
check( 'Veraltetes Manifest wird verworfen', null === NLC_Selfupdate::manifest( true ) );

// Pruefsumme fehlt oder ist Unsinn
fresh();
$GLOBALS['http'][ $manifestUrl ] = panel_response( array( 'sha256' => 'keine-summe' ), $privateKey );
check( 'Unplausible Pruefsumme wird verworfen', null === NLC_Selfupdate::manifest( true ) );

fresh();
$GLOBALS['http'][ $manifestUrl ] = panel_response( array( 'sha256' => '' ), $privateKey );
check( 'Fehlende Pruefsumme wird verworfen', null === NLC_Selfupdate::manifest( true ) );

// Panel antwortet mit Fehler
fresh();
$GLOBALS['http'][ $manifestUrl ] = array( 'code' => 500, 'body' => 'kaputt' );
check( 'Serverfehler wird verworfen', null === NLC_Selfupdate::manifest( true ) );

fresh();
$GLOBALS['http'][ $manifestUrl ] = array( 'code' => 200, 'body' => 'kein json' );
check( 'Antwort ohne JSON wird verworfen', null === NLC_Selfupdate::manifest( true ) );

/* --------------------------------------------------- Angriffe auf das Paket */

fresh();
$GLOBALS['http'][ $manifestUrl ] = panel_response( array(), $privateKey );
NLC_Selfupdate::manifest( true );

$file = $updater->verified_download( false, $package );
check( 'Passendes Paket wird durchgelassen', is_string( $file ) && is_file( $file ) );
check( 'Und es ist auch das richtige', is_string( $file ) && $packageBody === file_get_contents( $file ) );
if ( is_string( $file ) ) { unlink( $file ); }

// Panel liefert etwas anderes aus als signiert
$GLOBALS['downloads'][ $package ] = 'PK' . str_repeat( 'BOESE', 100 );
$bad                              = $updater->verified_download( false, $package );
check( 'Ausgetauschtes Paket wird abgelehnt', $bad instanceof WP_Error );
check( 'Mit der richtigen Begruendung', $bad instanceof WP_Error && 'nlc_package_mismatch' === $bad->code );
$GLOBALS['downloads'][ $package ] = $packageBody;

// Ein fremdes Paket geht das Plugin gar nichts an - WordPress macht weiter wie sonst
$other = $updater->verified_download( false, 'https://downloads.wordpress.org/plugin/woocommerce.zip' );
check( 'Fremde Pakete bleiben unberuehrt', false === $other );

/* ------------------------------------------------------------ Abschaltbar */

NLC_Options::save_settings( array( 'allow_self_update' => false ) );
$off = NLC_Selfupdate::run();
check( 'Abgeschaltet passiert nichts', empty( $off['ok'] ) && str_contains( $off['message'], 'deaktiviert' ) );
NLC_Options::save_settings( array( 'allow_self_update' => true ) );

/* -------------------------------------------------- Ohne Verbindung */

NLC_Options::clear_connection();
fresh();
check( 'Ohne Verbindung gibt es kein Manifest', null === NLC_Selfupdate::manifest( true ) );

/* ------------------------------------ Panel und Kundenseite im Zusammenspiel */

// Bis hierher hat der Test selbst signiert. Jetzt signiert der echte
// Panel-Code - nur so faellt auf, wenn beide Haelften auseinanderlaufen.
require_once dirname( __DIR__ ) . '/src/Core/Crypto.php';

NLC_Options::save_connection(
	array(
		'connection_id' => 'abc123def456',
		'public_key'    => $publicKey,
		'dashboard_url' => 'https://panel.north-lab.de',
	)
);

// Wortgleich mit ChildUpdateController::manifest().
$panelManifest = array(
	'slug'         => 'north-lab-child',
	'plugin'       => 'north-lab-child/north-lab-child.php',
	'version'      => '1.3.0',
	'package'      => $package,
	'sha256'       => $packageHash,
	'requires'     => '6.0',
	'requires_php' => '7.4',
	'name'         => 'NorthLab Child',
	'generated_at' => time(),
);
$canonical = (string) json_encode( $panelManifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

$GLOBALS['http'][ $manifestUrl ] = array(
	'code' => 200,
	'body' => json_encode(
		array(
			'ok'        => true,
			'manifest'  => $canonical,
			'signature' => \NorthLab\Core\Crypto::sign( $canonical, $privateKey ),
		)
	),
);

fresh();
$real = NLC_Selfupdate::manifest( true );
check( 'Die echte Panel-Signatur wird akzeptiert', is_array( $real ) );
check( 'Und liefert die erwartete Version', '1.3.0' === ( $real['version'] ?? '' ) );
check( 'Die Paketadresse kommt unveraendert an', $package === ( $real['package'] ?? '' ) );
check( 'Die Pruefsumme kommt unveraendert an', $packageHash === ( $real['sha256'] ?? '' ) );

// Ein Schraegstrich, der beim Kodieren maskiert wuerde, darf die Signatur nicht brechen.
$slashy    = array_merge( $panelManifest, array( 'package' => $package . '?v=1/2' ) );
$canonical = (string) json_encode( $slashy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
$GLOBALS['http'][ $manifestUrl ] = array(
	'code' => 200,
	'body' => json_encode(
		array( 'ok' => true, 'manifest' => $canonical, 'signature' => \NorthLab\Core\Crypto::sign( $canonical, $privateKey ) )
	),
);
fresh();
$slashResult = NLC_Selfupdate::manifest( true );
check( 'Schraegstriche in der Adresse brechen die Signatur nicht', is_array( $slashResult ) );

/* ------------------------------------ Das Plugin darf nicht abgeschaltet bleiben */

// WordPress schaltet ein Plugin vor dem Ersetzen still ab. Im Backend aktiviert
// die Oberflaeche es danach wieder - beim Update per Panel gibt es keine.

NLC_Options::save_connection(
	array(
		'connection_id' => 'abc123def456',
		'public_key'    => $publicKey,
		'dashboard_url' => 'https://panel.north-lab.de',
	)
);
NLC_Options::save_settings( array( 'allow_self_update' => true ) );

$GLOBALS['http'][ $manifestUrl ] = panel_response( array(), $privateKey );
$GLOBALS['active']               = array( 'north-lab-child/north-lab-child.php' => true );
$GLOBALS['activations']          = array();
fresh();

$run = NLC_Selfupdate::run();

check( 'Update meldet Erfolg', ! empty( $run['ok'] ) );
check( 'Das Plugin ist danach wieder aktiv', ! empty( $GLOBALS['active']['north-lab-child/north-lab-child.php'] ) );
check( 'Es wurde genau einmal aktiviert', 1 === count( $GLOBALS['activations'] ) );
check( 'Und zwar still, ohne Aktivierungshaken', ! empty( $GLOBALS['activations'][0]['silent'] ) );

// War es vorher aus, bleibt es aus - das Panel schaltet nichts ein, was jemand
// bewusst abgeschaltet hat.
$GLOBALS['active']      = array( 'north-lab-child/north-lab-child.php' => false );
$GLOBALS['activations'] = array();
fresh();
NLC_Selfupdate::run();
check( 'Ein abgeschaltetes Plugin bleibt abgeschaltet', array() === $GLOBALS['activations'] );

// Scheitert die Aktivierung, muss die Meldung das sagen statt Erfolg zu melden.
$GLOBALS['active']           = array( 'north-lab-child/north-lab-child.php' => true );
$GLOBALS['activations']      = array();
$GLOBALS['activation_fails'] = true;
fresh();
$broken = NLC_Selfupdate::run();
check( 'Gescheiterte Aktivierung wird gemeldet', empty( $broken['ok'] ) );
check( 'Mit dem Hinweis auf das Backend', str_contains( (string) $broken['message'], 'unter Plugins aktivieren' ) );
$GLOBALS['activation_fails'] = false;

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
