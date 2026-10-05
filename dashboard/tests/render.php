<?php
/**
 * Rendert die drei geaenderten Ansichten ohne Datenbank, um Laufzeitfehler und
 * die Typ-Weichen zu pruefen.
 */
declare( strict_types = 1 );

$root = realpath( dirname( __DIR__ ) );
define( 'NL_ROOT', $root );
define( 'NL_VERSION', '1.0.0' );
define( 'NL_SRC', $root . '/src' );
define( 'NL_VIEWS', $root . '/views' );

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'NorthLab\\' ) ) {
			return;
		}
		$path = NL_SRC . '/' . str_replace( '\\', '/', substr( $class, 9 ) ) . '.php';
		if ( is_file( $path ) ) {
			require_once $path;
		}
	}
);

// Vor den echten Helfern definieren; diese sind mit function_exists geschuetzt.
function url( string $path = '' ): string { return 'https://panel.test' . $path; }
function csrf_field(): string { return '<input type="hidden" name="_token" value="t">'; }

require_once NL_SRC . '/helpers.php';
require_once NL_SRC . '/view-helpers.php';

use NorthLab\Core\Auth;
use NorthLab\Core\View;
use NorthLab\Repository\SiteRepository;

// Angemeldeten Administrator vortaeuschen, damit kein Datenbankzugriff noetig ist.
$auth = new ReflectionClass( Auth::class );
$auth->setStaticPropertyValue( 'user', array( 'id' => 1, 'role' => 'admin', 'name' => 'Test' ) );
$auth->setStaticPropertyValue( 'resolved', true );

$failures = array();
$checks   = 0;

function check( string $label, bool $condition ): void {
	global $failures, $checks;
	$checks++;
	if ( ! $condition ) {
		$failures[] = $label;
	}
}

function render( string $view, array $data ): string {
	$shared = new ReflectionProperty( View::class, 'shared' );
	$shared->setAccessible( true );
	$shared->setValue( null, array() );

	extract( $data, EXTR_SKIP );
	ob_start();
	include NL_VIEWS . '/pages/' . $view . '.php';
	$body = (string) ob_get_clean();

	// Was das Template via View::set gesetzt hat, gehoert zur Ausgabe dazu -
	// im echten Betrieb rendert das Layout es mit.
	foreach ( $shared->getValue() as $value ) {
		if ( is_string( $value ) ) {
			$body .= "\n" . $value;
		}
	}

	return $body;
}

function site( string $type ): array {
	return array(
		'id'                 => 7,
		'name'               => 'Kundenshop',
		'url'                => 'https://shop.kunde.de',
		'site_type'          => $type,
		'status'             => 'connected',
		'uptime_status'      => 'up',
		'client_id'          => null,
		'client_name'        => null,
		'tags'               => 'shop',
		'notes'              => '',
		'pending_updates'    => 3,
		'security_score'     => 82,
		'wp_version'         => 'wordpress' === $type ? '6.6' : '',
		'php_version'        => 'wordpress' === $type ? '8.3' : '',
		'child_version'      => '',
		'last_sync_at'       => gmdate( 'Y-m-d H:i:s' ),
		'last_seen_at'       => gmdate( 'Y-m-d H:i:s' ),
		'created_at'         => gmdate( 'Y-m-d H:i:s' ),
		'last_error'         => '',
		'is_paused'          => 0,
		'verify_ssl'         => 1,
		'http_user'          => '',
		'auto_update_policy' => 'inherit',
		'admin_url'          => '',
		'maintenance_mode'   => 0,
		'maintenance_until'  => null,
		'branding_bar'       => 0,
		'branding_login'     => 0,
	);
}

$showData = static function ( string $type ): array {
	return array(
		'site'       => site( $type ),
		'client'     => null,
		'clients'    => array(),
		// Sicherung: Stand aus der Datenbank, hier fest vorgegeben.
		'backupLast'      => null,
		'backupMirror'    => 0,
		'backupScheduled' => true,
		'backupReady'     => false,
		'payload'    => null,
		'updates'    => array(),
		'uptime'     => array( 'percent' => 99.9, 'incidents' => 1, 'downtime_seconds' => 120, 'avg_response_ms' => 210 ),
		'incidents'  => array(),
		'series'     => array(),
		'activity'   => array(),
		'monitorUrl' => 'https://panel.test/api/uptime/abc',
		'tasks'      => array( 'revisions' => 'Revisionen aufräumen' ),
		'mmodeDesign' => array(
			'headline'    => 'Wartungsmodus',
			'message'     => 'Wir sind gleich wieder da.',
			'color'       => '#f9907a',
			'logo'        => 'https://north-flow.de/api/assets/188bbf40d9ac4509bcc22045f2e96247',
			'retry_after' => 3600,
		),
		'mmodeTimes' => array( 60 => '1 Stunde', 0 => 'Bis auf Widerruf' ),
		'childShipped'  => '1.4.0',
		'branding'      => array( 'logo' => 'https://north-flow.de/l.png', 'site' => 'https://north-lab.de' ),
		'brandingState' => array( 'login_logo' => 'https://raum32.de/logo.png', 'login_logo_height' => 72, 'login_logo_link' => '' ),
		'childOutdated' => false,
		'features'      => array(
			array( 'key' => 'users', 'label' => 'Die Benutzerverwaltung', 'ok' => true, 'reason' => null ),
			array( 'key' => 'autologin', 'label' => 'Die Ein-Klick-Anmeldung', 'ok' => true, 'reason' => null ),
		),
	);
};

// ---------------------------------------------------------------- Detailseite
$wp      = render( 'sites/show', $showData( 'wordpress' ) );
$monitor = render( 'sites/show', $showData( 'monitor' ) );

check( 'WP-Seite zeigt den Updates-Tab', str_contains( $wp, 'data-tab="updates"' ) );
check( 'WP-Seite zeigt den Wartungs-Tab', str_contains( $wp, 'data-tab="maintenance"' ) );
check( 'WP-Seite zeigt den Sicherheits-Tab', str_contains( $wp, 'data-tab="security"' ) );
check( 'WP-Seite zeigt "Verbindung erneuern"', str_contains( $wp, 'Verbindung erneuern' ) );

/* ------------------------------------------------- Sicherheit: dauerhaft und quittiert */

$sicher = render( 'sites/show', array_replace(
	$showData( 'wordpress' ),
	array(
		'payload' => array(
			'security' => array(
				'score'  => 70,
				'passed' => 7,
				'total'  => 10,
				'checks' => array(
					array(
						'id' => 'readme_exposed', 'label' => 'readme.html entfernt', 'status' => 'ok',
						'severity' => 'low', 'detail' => 'Keine readme.html im Root. Wird nach jedem Update erneut gesetzt.',
						'fixable' => true, 'enforced' => true, 'enforceable' => true, 'acknowledgeable' => false, 'acknowledged' => '',
					),
					array(
						'id' => 'file_editor', 'label' => 'Datei-Editor deaktiviert', 'status' => 'warn',
						'severity' => 'medium', 'detail' => 'Der Theme- und Plugin-Editor im Backend ist offen.',
						'fixable' => true, 'enforced' => false, 'enforceable' => true, 'acknowledgeable' => false, 'acknowledged' => '',
					),
					array(
						'id' => 'db_prefix', 'label' => 'Individuelles Tabellen-Präfix', 'status' => 'ack',
						'severity' => 'low', 'detail' => 'Standard-Präfix — bewusst so gelassen: Kunde zahlt die Migration nicht.',
						'fixable' => false, 'enforced' => false, 'enforceable' => false, 'acknowledgeable' => true, 'acknowledged' => 'Kunde zahlt die Migration nicht.',
					),
				),
			),
		),
	)
) );

check( 'Dauerhafte Haertung wird als solche ausgewiesen', str_contains( $sicher, '>dauerhaft<' ) );
check( 'Ein quittierter Punkt heisst nicht "Bestanden"', str_contains( $sicher, 'Bewusst so' ) );
check( 'Und traegt die Begruendung', str_contains( $sicher, 'Kunde zahlt die Migration nicht' ) );
check( 'Es gibt einen Knopf fuers dauerhafte Setzen', str_contains( $sicher, 'Ausgewählte dauerhaft setzen' ) );
check( 'Und einen zum Aufheben', str_contains( $sicher, 'value="relax"' ) );
check( 'Und einen zum Quittieren', str_contains( $sicher, 'value="acknowledge"' ) );
check( 'Das Nachziehen laesst sich anstossen', str_contains( $sicher, 'value="enforce"' ) );
check( 'Der Grund fuer das Praefix wird erklaert', str_contains( $sicher, 'bewusst so gelassen' ) );

// Ein quittierbarer Punkt muss auch auswaehlbar sein - sonst laesst sich der
// Vermerk gar nicht setzen.
check(
	'Ein quittierbarer Punkt ist auswaehlbar',
	str_contains( $sicher, 'value="db_prefix"' )
);

/* ------------------------------------------------------------- Zertifikat */

$sslData = static function ( $tage ) use ( $showData ) {
	$d = $showData( 'wordpress' );
	$d['site']['ssl_days_left']  = $tage;
	$d['site']['ssl_subject']    = null === $tage ? '' : 'kunde.de';
	$d['site']['ssl_checked_at'] = null === $tage ? null : gmdate( 'Y-m-d H:i:s' );
	$d['sslWarnDays']            = 21;
	return $d;
};

$sslUnbekannt = render( 'sites/show', $sslData( null ) );
check( 'Ohne Meldung steht "unbekannt"', str_contains( $sslUnbekannt, 'unbekannt' ) );
check( 'Und woher es kommen soll', str_contains( $sslUnbekannt, 'Uptime Kuma' ) );
check( 'Keine erfundene Zahl', ! str_contains( $sslUnbekannt, 'Tage Restlaufzeit' ) );

$sslGut = render( 'sites/show', $sslData( 67 ) );
check( 'Eine lange Laufzeit wird gezeigt', str_contains( $sslGut, '>67<' ) );
check( 'Und gilt als in Ordnung', str_contains( $sslGut, 'stat ok' ) );
check( 'Der Zertifikatsname steht dabei', str_contains( $sslGut, 'kunde.de' ) );

$sslKnapp = render( 'sites/show', $sslData( 14 ) );
check( 'Knappe Laufzeit wird gewarnt', str_contains( $sslKnapp, 'stat warn' ) );

$sslDringend = render( 'sites/show', $sslData( 5 ) );
check( 'Unter einer Woche wird es rot', str_contains( $sslDringend, 'stat bad' ) );

// Abgelaufen darf nicht als "-3 Tage Restlaufzeit" dastehen.
$sslWeg = render( 'sites/show', $sslData( -3 ) );
check( 'Abgelaufen wird als abgelaufen benannt', str_contains( $sslWeg, 'abgelaufen' ) );
check( 'Ohne Minuszeichen in der Zahl', ! str_contains( $sslWeg, '>-3<' ) );
check( 'Und rot', str_contains( $sslWeg, 'stat bad' ) );

// Eine Seite aus der Zeit vor Schema 12 hat die Spalte gar nicht.
$ohneSpalte = $showData( 'wordpress' );
unset( $ohneSpalte['site']['ssl_days_left'], $ohneSpalte['site']['ssl_subject'], $ohneSpalte['site']['ssl_checked_at'] );
$alt = render( 'sites/show', $ohneSpalte );
check( 'Ohne die Spalte bricht nichts', str_contains( $alt, 'Zertifikat' ) && ! str_contains( $alt, 'Warning' ) );

/* ------------------------------------------------------------- Link-Pruefung */

$ohneLinks = render( 'sites/show', $showData( 'wordpress' ) );
check( 'Die Link-Karte ist da', str_contains( $ohneLinks, 'Link-Prüfung' ) );
check( 'Ohne Ergebnis steht ein Hinweis', str_contains( $ohneLinks, 'einmal pro Woche' ) );

$mitLinks = render( 'sites/show', array_replace(
	$showData( 'wordpress' ),
	array(
		'payload' => array(
			'links' => array(
				'enabled'  => true,
				'at'       => gmdate( 'c', time() - 7200 ),
				'checked'  => 412,
				'broken'   => 2,
				'unsure'   => 3,
				'progress' => array( 'running' => false ),
				'examples' => array(
					array( 'url' => 'https://weg.de/alt', 'status' => 404, 'title' => 'Über uns' ),
				),
			),
		),
	)
) );

check( 'Die Zahl der toten Links steht da', str_contains( $mitLinks, 'Tote Links' ) );
check( 'Ein Beispiel wird gezeigt', str_contains( $mitLinks, 'https://weg.de/alt' ) );
check( 'Unklare werden getrennt erklaert', str_contains( $mitLinks, 'automatische Abrufe abweisen' ) );
check( 'Es gibt einen Knopf zum Pruefen', str_contains( $mitLinks, '/links' ) );

$laufend = render( 'sites/show', array_replace(
	$showData( 'wordpress' ),
	array(
		'payload' => array(
			'links' => array(
				'enabled'  => true,
				'at'       => '',
				'progress' => array( 'running' => true, 'phase' => 'check', 'done' => 120, 'total' => 400 ),
			),
		),
	)
) );
check( 'Ein laufender Durchgang wird angezeigt', str_contains( $laufend, '120 von 400 geprüft' ) );
check( 'Und gesagt, wo die Last liegt', str_contains( $laufend, 'dieser Server hat damit keine Arbeit' ) );

$aus = render( 'sites/show', array_replace(
	$showData( 'wordpress' ),
	array( 'payload' => array( 'links' => array( 'enabled' => false ) ) )
) );
check( 'Abgeschaltet wird das auch gesagt', str_contains( $aus, 'abgeschaltet' ) );


check( 'WP-Seite zeigt den Sicherungs-Tab', str_contains( $wp, 'data-tab="backup"' ) );
check( 'Ohne Ziel wird darauf hingewiesen', str_contains( $wp, 'kein Sicherungsziel eingerichtet' ) );
check( 'Ohne Ziel kein Abruf-Knopf', ! str_contains( $wp, 'data-snapshot-load' ) );

// Mit eingerichtetem Ziel und einem gelaufenen Backup.
$mitZiel = render( 'sites/show', array_replace(
	$showData( 'wordpress' ),
	array(
		'backupReady'  => true,
		'backupMirror' => 5242880,
		'backupLast'   => array(
			'status' => 'success', 'started_at' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
			'files_total' => 1234, 'message' => '',
		),
	)
) );

check( 'Mit Ziel laesst sich der Speicher abfragen', str_contains( $mitZiel, 'data-snapshot-load' ) );
check( 'Die Abrufadresse zeigt auf diese Seite', str_contains( $mitZiel, '/backups/7/snapshots' ) );
check( 'Der Spiegel wird beziffert', str_contains( $mitZiel, '5 MB' ) || str_contains( $mitZiel, '5,0 MB' ) );
check( 'Die Dateizahl steht da', str_contains( $mitZiel, '1.234' ) );
check( 'Und der Lauf gilt als erfolgreich', str_contains( $mitZiel, 'erfolgreich' ) );

// Eine Seite, die nachts aussen vor bleibt.
$ohnePlan = render( 'sites/show', array_replace(
	$showData( 'wordpress' ),
	array( 'backupReady' => true, 'backupScheduled' => false )
) );

check( 'Ausgenommene Seite wird als solche gezeigt', str_contains( $ohnePlan, 'ausgenommen' ) );
check( 'Und laesst sich wieder aufnehmen', str_contains( $ohnePlan, 'In den Zeitplan aufnehmen' ) );
check( 'Eine eingeplante Seite laesst sich herausnehmen',
	str_contains( $mitZiel, 'Aus dem Zeitplan nehmen' ) );
check( 'Von Hand sichern geht in beiden Faellen',
	str_contains( $mitZiel, '/backups/7/run' ) && str_contains( $ohnePlan, '/backups/7/run' ) );
// Der Knopf stoesst nur an; gearbeitet wird im Hintergrund.
check( 'Der Knopf verspricht keinen Lauf im Browser',
	str_contains( $mitZiel, 'Läuft im Hintergrund, nicht im Browser' ) );

$vorgemerkt = render( 'sites/show', array_replace(
	$showData( 'wordpress' ),
	array( 'backupReady' => true, 'site' => array_replace( site( 'wordpress' ), array( 'backup_requested_at' => gmdate( 'Y-m-d H:i:s' ) ) ) )
) );

check( 'Eine vorgemerkte Sicherung wird als solche gezeigt',
	str_contains( $vorgemerkt, 'Vorgemerkt — startet innerhalb einer Minute' ) );

check( 'Monitor-Seite ohne Sicherungs-Tab', ! str_contains( $monitor, 'data-tab="backup"' ) );
check( 'Monitor-Seite ohne Updates-Tab', ! str_contains( $monitor, 'data-tab="updates"' ) );
check( 'Monitor-Seite ohne Plugin-Tab', ! str_contains( $monitor, 'data-tab="plugins"' ) );
check( 'Monitor-Seite ohne Theme-Tab', ! str_contains( $monitor, 'data-tab="themes"' ) );
check( 'Monitor-Seite ohne Sicherheits-Tab', ! str_contains( $monitor, 'data-tab="security"' ) );
check( 'Monitor-Seite ohne Wartungs-Tab', ! str_contains( $monitor, 'data-tab="maintenance"' ) );
check( 'Monitor-Seite ohne Wartungs-Panel', ! str_contains( $monitor, 'data-tab-panel="maintenance"' ) );
check( 'Monitor-Seite ohne Updates-Panel', ! str_contains( $monitor, 'data-tab-panel="updates"' ) );
check( 'Monitor-Seite behaelt den Uptime-Tab', str_contains( $monitor, 'data-tab="uptime"' ) );
check( 'Monitor-Seite behaelt Einstellungen', str_contains( $monitor, 'data-tab="settings"' ) );
check( 'Monitor-Seite behaelt das Protokoll', str_contains( $monitor, 'data-tab="activity"' ) );
check( 'Monitor-Seite ohne Reconnect-Formular', ! str_contains( $monitor, '/reconnect' ) );
check( 'Monitor-Seite ohne Update-Richtlinie', ! str_contains( $monitor, 'auto_update_policy' ) );
check( 'Monitor-Seite nennt die Betreuungsart', str_contains( $monitor, 'Nur Überwachung' ) );
check( 'Monitor-Seite bietet "Jetzt prüfen"', str_contains( $monitor, 'Jetzt prüfen' ) );
check( 'Monitor-Seite zeigt keinen Sicherheitswert', ! str_contains( $monitor, 'Sicherheitswert' ) );
check( 'Monitor-Seite behaelt die Verfügbarkeit', str_contains( $monitor, 'Verfügbarkeit (30 Tage)' ) );
check( 'Monitor-Seite kann entfernt werden', str_contains( $monitor, '/delete' ) );

// Jeder sichtbare Tab braucht sein Panel und umgekehrt.
foreach ( array( 'wordpress' => $wp, 'monitor' => $monitor ) as $type => $html ) {
	preg_match_all( '/data-tab="([a-z]+)"/', $html, $tabs );
	preg_match_all( '/data-tab-panel="([a-z]+)"/', $html, $panels );
	check(
		sprintf( '%s: Tabs und Panels passen zusammen', $type ),
		array_values( array_unique( $tabs[1] ) ) === array_values( array_unique( $panels[1] ) )
	);
}

// ------------------------------------------------------------------ Branding
check( 'WP-Seite bietet das Branding an', str_contains( $wp, '/sites/7/branding' ) );
check( 'Mit Schalter fuer die Support-Leiste', str_contains( $wp, 'name="bar"' ) );
check( 'Mit Schalter fuer die Login-Leiste', str_contains( $wp, 'name="login_bar"' ) );
check( 'Und dem Kundenlogo pro Seite', str_contains( $wp, 'name="login_logo"' ) );
check( 'Das Kundenlogo ist vorbelegt', str_contains( $wp, 'value="https://raum32.de/logo.png"' ) );
check( 'Zentrale Gestaltung wird verlinkt', str_contains( $wp, '/settings#branding' ) );
check( 'Monitor-Seite ohne Branding', ! str_contains( $monitor, '/sites/7/branding' ) );

$brandOn                           = $showData( 'wordpress' );
$brandOn['site']['branding_bar']   = 1;
$brandOn['site']['branding_login'] = 1;
$brandOnOut                        = render( 'sites/show', $brandOn );
check( 'Aktives Branding wird gekennzeichnet', (bool) preg_match( '#Agentur-Branding.*?badge ok">aktiv#s', $brandOnOut ) );
check( 'Support-Leiste steht an', (bool) preg_match( '/name="bar" value="1" checked/', $brandOnOut ) );
check( 'Login-Leiste steht an', (bool) preg_match( '/name="login_bar" value="1" checked/', $brandOnOut ) );
check( 'Bei aus stehen sie aus', ! preg_match( '/name="bar" value="1" checked/', $wp ) );

// ---------------------------------------------- Grund fuer blockierte Funktionen
$blockedData             = $showData( 'wordpress' );
$blockedData['features'] = array(
	array( 'key' => 'users', 'label' => 'Die Benutzerverwaltung', 'ok' => true, 'reason' => null ),
	array(
		'key'    => 'autologin',
		'label'  => 'Die Ein-Klick-Anmeldung',
		'ok'     => false,
		'reason' => 'Die Ein-Klick-Anmeldung braucht das Child-Plugin 1.2.0, auf dieser Seite läuft 1.1.0.',
	),
);
$withReason = render( 'sites/show', $blockedData );

check( 'Blockierte Funktion wird benannt', str_contains( $withReason, 'Nicht verfügbar auf dieser Seite' ) );
check( 'Mit dem konkreten Grund', str_contains( $withReason, 'braucht das Child-Plugin 1.2.0' ) );
check( 'Freie Funktionen tauchen dort nicht auf', ! str_contains( $withReason, 'Die Benutzerverwaltung' ) );
check( 'Zaehler nennt die Anzahl', (bool) preg_match( '#Nicht verfügbar auf dieser Seite.*?badge warn">1<#s', $withReason ) );

$fineData             = $showData( 'wordpress' );
$fineData['features'] = array(
	array( 'key' => 'users', 'label' => 'Die Benutzerverwaltung', 'ok' => true, 'reason' => null ),
);
$allFine = render( 'sites/show', $fineData );
check( 'Ohne Einschraenkung keine Karte', ! str_contains( $allFine, 'Nicht verfügbar auf dieser Seite' ) );

// Reine Ueberwachung soll die Karte nicht zeigen - dort ist ohnehin alles klar.
$monitorBlocked             = $showData( 'monitor' );
$monitorBlocked['features'] = array(
	array( 'key' => 'autologin', 'label' => 'Die Ein-Klick-Anmeldung', 'ok' => false, 'reason' => 'nur überwacht' ),
);
$monitorReason = render( 'sites/show', $monitorBlocked );
check( 'Monitor-Seite zeigt die Karte nicht', ! str_contains( $monitorReason, 'Nicht verfügbar auf dieser Seite' ) );

// ------------------------------------------------- Child-Plugin-Update
$oldChild                          = $showData( 'wordpress' );
$oldChild['site']['child_version'] = '1.2.0';
$oldChild['childOutdated']         = true;
$oldChild['payload']               = array(
	'environment' => array( 'wp_version' => '6.6' ),
	'plugins' => array(), 'themes' => array(), 'security' => array(),
	'content' => array(), 'users' => array(), 'health' => array(),
);
$staleChild = render( 'sites/show', $oldChild );

check( 'Veraltetes Child-Plugin wird oben gemeldet', str_contains( $staleChild, 'Child-Plugin veraltet' ) );
check(
	'Mit beiden Versionsnummern',
	str_contains( $staleChild, $oldChild['site']['child_version'] )
		&& str_contains( $staleChild, $oldChild['childShipped'] )
);
check( 'Und einem Knopf zum Aktualisieren', str_contains( $staleChild, '/sites/7/child-update' ) );
check( 'Auch in der Umgebungstabelle', substr_count( $staleChild, '/sites/7/child-update' ) >= 2 );

$freshChild                          = $showData( 'wordpress' );
$freshChild['site']['child_version'] = '1.3.0';
$freshChild['childOutdated']         = false;
$current                             = render( 'sites/show', $freshChild );
check( 'Aktuelles Child-Plugin meldet nichts', ! str_contains( $current, 'Child-Plugin veraltet' ) );
check( 'Und bietet keinen Knopf', ! str_contains( $current, '/sites/7/child-update' ) );

// Reine Ueberwachung hat kein Child-Plugin.
$monitorChild                  = $showData( 'monitor' );
$monitorChild['childOutdated'] = false;
$monitorOut                    = render( 'sites/show', $monitorChild );
check( 'Monitor-Seite ohne Child-Update', ! str_contains( $monitorOut, 'child-update' ) );

// ------------------------------------------------- Updates aus der Ferne
$payloadData = $showData( 'wordpress' );
$payloadData['payload'] = array(
	'plugins' => array(
		array( 'name' => 'WooCommerce', 'file' => 'woocommerce/woocommerce.php', 'version' => '9.0.0', 'new_version' => '9.1.2', 'active' => true ),
		array( 'name' => 'Yoast SEO', 'file' => 'wordpress-seo/wp-seo.php', 'version' => '22.0', 'new_version' => '22.4', 'active' => false ),
		array( 'name' => 'Aktuell', 'file' => 'aktuell/aktuell.php', 'version' => '1.0', 'new_version' => '', 'active' => true ),
	),
	'themes'  => array(
		array( 'name' => 'Astra', 'stylesheet' => 'astra', 'version' => '4.6', 'new_version' => '4.7', 'active' => true ),
		array( 'name' => 'Twenty', 'stylesheet' => 'twentytwentyfour', 'version' => '1.2', 'new_version' => '', 'active' => false ),
	),
	'security' => array(), 'environment' => array(), 'content' => array(), 'users' => array(), 'health' => array(),
);
$withPlugins = render( 'sites/show', $payloadData );

check( 'Veraltetes Plugin bekommt einen Aktualisieren-Knopf',
	str_contains( $withPlugins, 'value="7|plugin|woocommerce/woocommerce.php"' ) );
check( 'Auch ein inaktives veraltetes Plugin',
	str_contains( $withPlugins, 'value="7|plugin|wordpress-seo/wp-seo.php"' ) );
check( 'Ein aktuelles Plugin bekommt keinen',
	! str_contains( $withPlugins, 'value="7|plugin|aktuell/aktuell.php"' ) );
check( 'Veraltetes Theme bekommt einen Aktualisieren-Knopf',
	str_contains( $withPlugins, 'value="7|theme|astra"' ) );
check( 'Ein aktuelles Theme bekommt keinen',
	! str_contains( $withPlugins, 'value="7|theme|twentytwentyfour"' ) );
check( 'Die Knoepfe gehen an die Update-Zentrale',
	str_contains( $withPlugins, 'action="https://panel.test/updates/apply"' ) );
check( 'Zaehler nennt die Zahl der Updates', str_contains( $withPlugins, '>2 mit Update</span>' ) );
check( 'Sammelknopf fragt nach', str_contains( $withPlugins, 'Alle 2 Plugin-Updates dieser Seite jetzt einspielen?' ) );

// Das aktive Plugin darf nicht loeschbar sein - WordPress lehnt das ohnehin ab.
// Der Sammelknopf oben nennt dieselbe Datei; ohne <tr>-Anker traf die Suche
// die Kopfzeile der Tabelle statt der Plugin-Zeile.
preg_match_all( '#<tr>(?:(?!</tr>).)*?woocommerce/woocommerce\.php.*?</tr>#s', $withPlugins, $rows );
$wooRow = array( $rows[0] ? end( $rows[0] ) : '' );
check( 'Die Plugin-Zeile wurde gefunden', '' !== $wooRow[0] && str_contains( $wooRow[0], 'WooCommerce' ) );
check( 'Aktives Plugin bietet kein Loeschen', ! str_contains( $wooRow[0] ?? '', 'value="delete"' ) );
check( 'Aktives Plugin bietet Deaktivieren', str_contains( $wooRow[0] ?? '', 'value="deactivate"' ) );

// Jedes Formular braucht genau einen Button, sonst greift die Klicksperre falsch.
preg_match_all( '#<form[^>]*>(.*?)</form>#s', $withPlugins, $allForms );
$multi = 0;
foreach ( $allForms[1] as $inner ) {
	if ( substr_count( $inner, '<button' ) > 1 ) {
		$multi++;
	}
}
check( 'Kein Formular mit mehreren Buttons', 0 === $multi );

// Jedes dieser Formulare muss ein CSRF-Feld tragen.
$missingToken = 0;
foreach ( $allForms[0] as $form ) {
	if ( str_contains( $form, 'method="post"' ) && ! str_contains( $form, 'name="_token"' ) ) {
		$missingToken++;
	}
}
check( 'Jedes POST-Formular traegt ein CSRF-Feld', 0 === $missingToken );

// --------------------------------------------- Wartungsmodus und Anmeldung
check( 'WP-Seite bietet die Ein-Klick-Anmeldung', str_contains( $wp, '/sites/7/login' ) );
check( 'WP-Seite verlinkt die Benutzerverwaltung', str_contains( $wp, '/sites/7/users' ) );
check( 'WP-Seite bietet den Wartungsmodus', str_contains( $wp, '/sites/7/maintenance-mode' ) );
check( 'Anmeldung laeuft per POST', (bool) preg_match( '#<form method="post" action="[^"]*/sites/7/login"#', $wp ) );

check( 'Monitor-Seite ohne Ein-Klick-Anmeldung', ! str_contains( $monitor, '/sites/7/login' ) );
check( 'Monitor-Seite ohne Benutzerverwaltung', ! str_contains( $monitor, '/sites/7/users' ) );
check( 'Monitor-Seite ohne Wartungsmodus', ! str_contains( $monitor, 'maintenance-mode' ) );

// Bei laufendem Wartungsmodus muss der Hinweis oben stehen.
$activeData                             = $showData( 'wordpress' );
$activeData['site']['maintenance_mode'] = 1;
$activeData['site']['maintenance_until'] = gmdate( 'Y-m-d H:i:s', time() + 3600 );
$active                                  = render( 'sites/show', $activeData );

check( 'Laufender Wartungsmodus wird oben gemeldet', str_contains( $active, 'Wartungsmodus aktiv' ) );
check( 'Und laesst sich sofort beenden', str_contains( $active, 'Jetzt beenden' ) );
check( 'Das Ende wird genannt', str_contains( $active, 'Endet automatisch am' ) );
check( 'Aus-Formular sendet disable=1', (bool) preg_match( '/name="disable" value="1"/', $active ) );

// Bestaetigung und Klicksperre duerfen sich nicht ins Gehege kommen.
preg_match_all( '#<form[^>]*maintenance-mode"[^>]*>(.*?)</form>#s', $active, $mmForms );
$multiButton = 0;
foreach ( $mmForms[1] as $inner ) {
	if ( substr_count( $inner, '<button' ) > 1 ) {
		$multiButton++;
	}
}
check( 'Kein Wartungsformular mit zwei Buttons', 0 === $multiButton );

// ------------------------------------------------------------- Benutzerseite
$usersPage = render(
	'sites/users',
	array(
		'site'      => site( 'wordpress' ),
		'users'     => array(
			array( 'id' => 1, 'login' => 'chef', 'email' => 'chef@kunde.de', 'roles' => array( 'administrator' ), 'last_login' => null, 'expires_at' => null, 'from_panel' => false ),
			array( 'id' => 2, 'login' => 'temp', 'email' => 'temp@kunde.de', 'roles' => array( 'editor' ), 'last_login' => null, 'expires_at' => time() + 3600, 'from_panel' => true ),
		),
		'loadError' => '',
		'roles'     => \NorthLab\Service\SiteUserService::ROLES,
		'durations' => \NorthLab\Service\SiteUserService::DURATIONS,
		'secret'    => null,
		'old'       => array(),
	)
);

check( 'Benutzerseite listet beide Konten', str_contains( $usersPage, 'chef' ) && str_contains( $usersPage, 'temp' ) );
check( 'Befristung wird gekennzeichnet', str_contains( $usersPage, 'badge warn">bis' ) );
check( 'Passwort-Aktion fragt nach', str_contains( $usersPage, 'Alle offenen Sitzungen dieses Kontos werden beendet' ) );
check( 'Loeschen fragt nach', str_contains( $usersPage, 'Vorhandene Inhalte gehen an den ältesten Administrator' ) );
check( 'Anlegen bietet die Befristungen an', str_contains( $usersPage, '7 Tage' ) );
check( 'Ohne frische Zugangsdaten kein Passwortkasten', ! str_contains( $usersPage, 'nur jetzt sichtbar' ) );

// Frisch erzeugte Zugangsdaten
$withSecret = render(
	'sites/users',
	array(
		'site'      => site( 'wordpress' ),
		'users'     => array(),
		'loadError' => '',
		'roles'     => \NorthLab\Service\SiteUserService::ROLES,
		'durations' => \NorthLab\Service\SiteUserService::DURATIONS,
		'secret'    => json_encode( array( 'login' => 'northlab-support', 'password' => 'Geheim!2345', 'expires_at' => time() + 3600 ) ),
		'old'       => array(),
	)
);

check( 'Zugangsdaten werden einmal gezeigt', str_contains( $withSecret, 'Geheim!2345' ) );
check( 'Mit dem Hinweis auf die Einmaligkeit', str_contains( $withSecret, 'nur jetzt sichtbar' ) );
check( 'Und dem Loeschzeitpunkt', str_contains( $withSecret, 'automatisch gelöscht' ) );

// Reine Ueberwachung hat keine Benutzer
$monitorUsers = render(
	'sites/users',
	array(
		'site'      => site( 'monitor' ),
		'users'     => array(),
		'loadError' => '',
		'roles'     => \NorthLab\Service\SiteUserService::ROLES,
		'durations' => \NorthLab\Service\SiteUserService::DURATIONS,
		'secret'    => null,
		'old'       => array(),
	)
);

check( 'Monitor-Seite hat keine Benutzerverwaltung', str_contains( $monitorUsers, 'keine Benutzer verwalten' ) );
check( 'Und zeigt auch kein Anlegen-Formular', ! str_contains( $monitorUsers, 'user_action' ) );

// ------------------------------------------------------------------- Liste
$list = render(
	'sites/index',
	array(
		'sites'   => array( array_replace( site( 'wordpress' ), array( 'maintenance_mode' => 1, 'branding_bar' => 1 ) ), site( 'monitor' ) ),
		'clients' => array(),
		'tags'    => array( 'shop' ),
		'filters' => array(
			'client_id'     => 0,
			'status'        => '',
			'uptime_status' => '',
			'search'        => '',
			'tag'           => '',
			'has_updates'   => false,
			'site_type'     => '',
		),
	)
);

check( 'Liste kennzeichnet reine Überwachung', str_contains( $list, 'nur Überwachung' ) );
check( 'Liste bietet den Typfilter', str_contains( $list, 'name="type"' ) );
check( 'Liste zeigt den WordPress-Updatezähler', str_contains( $list, '>3</a>' ) );
check( 'Liste kennzeichnet den Wartungsmodus', str_contains( $list, '>Wartung</span>' ) );
check( 'Liste kennzeichnet aktives Branding', str_contains( $list, '>Branding</span>' ) );
check( 'Liste bietet Wartungsmodus als Sammelaktion', str_contains( $list, 'value="mmode-on"' ) && str_contains( $list, 'value="mmode-off"' ) );
check( 'Liste bietet Child-Update als Sammelaktion', str_contains( $list, 'value="child-update"' ) );
check( 'Liste bietet Branding als Sammelaktion', str_contains( $list, 'value="branding-on"' ) && str_contains( $list, 'value="branding-off"' ) );

// ------------------------------------------------------------------ Formular
$form = render( 'sites/create', array( 'clients' => array(), 'pluginVersion' => '1.1.0', 'old' => array() ) );

check( 'Formular bietet beide Typen', substr_count( $form, 'data-site-type' ) === 2 );
check( 'Formular waehlt WordPress vor', (bool) preg_match( '/value="wordpress" data-site-type\s*\n?\s*checked/', $form ) );
check( 'Verbindungscode haengt am WordPress-Zweig', str_contains( $form, '<div class="field full" data-when="wordpress">' ) );
check( 'Monitor-Hinweis ist zunaechst versteckt', str_contains( $form, 'data-when="monitor" hidden' ) );

// Vorbelegung nach einem Fehlversuch.
$formMonitor = render( 'sites/create', array( 'clients' => array(), 'pluginVersion' => '1.1.0', 'old' => array( 'site_type' => 'monitor' ) ) );
check( 'Formular merkt sich den Monitor-Typ', (bool) preg_match( '/value="monitor" data-site-type\s*\n?\s*checked/', $formMonitor ) );

// --------------------------------------------------------- Einstellungen
$settingsPage = render(
	'settings/index',
	array(
		'settings'  => array( 'agency_color' => '#f9907a', 'agency_name' => 'NorthLab' ),
		'catalog'   => \NorthLab\Service\EventBus::catalog(),
		'notifyOn'  => array(),
		'pushOn'    => array( 'site.offline' ),
		'pushReady' => true,
		'pushCount' => 2,
		'iconSource' => true,
		'iconOwn'    => true,
		'iconStamp'  => '1700000000',
		'jobs'      => array(),
		'cronToken' => 'x',
		'cronUrl'   => 'https://panel.test/api/cron/x',
		'config'    => array(
			'app_url'  => 'https://panel.test',
			'timezone' => 'Europe/Berlin',
			'db_name'    => 'northlab',
			'db_prefix'  => 'nl_',
			'verify_ssl' => true,
			'mail'       => array(),
		),
		'old'       => array(),
	)
);

check( 'Einstellungen haben den Reiter Wartungsseite', str_contains( $settingsPage, 'data-tab="mmode"' ) );
check( 'Und das passende Panel', str_contains( $settingsPage, 'data-tab-panel="mmode"' ) );
check( 'Farbe faellt auf die Agenturfarbe zurueck', str_contains( $settingsPage, 'name="mmode_color" class="mono" placeholder="#f9907a"' ) );
check( 'Die Agenturfarbe ist vorbelegt', str_contains( $settingsPage, 'value="#f9907a"' ) );
check( 'Logo-Feld ist da', str_contains( $settingsPage, 'name="mmode_logo"' ) );

preg_match_all( '/data-tab="([a-z_]+)"/', $settingsPage, $sTabs );
preg_match_all( '/data-tab-panel="([a-z_]+)"/', $settingsPage, $sPanels );
check(
	'Einstellungen: Reiter und Panels passen zusammen',
	array_values( array_unique( $sTabs[1] ) ) === array_values( array_unique( $sPanels[1] ) )
);

// Neue Ereignisse muessen in den Benachrichtigungen auftauchen.
check( 'Wartungsmodus ist ein abonnierbares Ereignis', str_contains( $settingsPage, 'Wartungsmodus eingeschaltet' ) );
check( 'Ein-Klick-Anmeldung ebenso', str_contains( $settingsPage, 'Ein-Klick-Anmeldung benutzt' ) );
check( 'Child-Update ebenso', str_contains( $settingsPage, 'Child-Plugin aktualisiert' ) );
check( 'Einstellungen haben den Reiter Branding', str_contains( $settingsPage, 'data-tab="branding"' ) );
check( 'Und den Reiter fuer Meldungen', str_contains( $settingsPage, 'data-tab="push"' ) );
check( 'Und den Reiter fuer das Symbol', str_contains( $settingsPage, 'data-tab="icon"' ) );
check( 'Logo per Adresse ist moeglich', str_contains( $settingsPage, 'name="icon_url"' ) );
check( 'Logo per Upload auch', str_contains( $settingsPage, 'name="icon_file"' ) );
check( 'Das Formular kann Dateien uebertragen', str_contains( $settingsPage, 'enctype="multipart/form-data"' ) );
check( 'Hintergrund und Rand sind einstellbar', str_contains( $settingsPage, 'name="icon_bg"' ) && str_contains( $settingsPage, 'name="icon_padding"' ) );
check( 'Die Vorschauflaeche ist da', str_contains( $settingsPage, 'id="icon-canvas"' ) );
check( 'Und weiss, woher das Logo kommt', str_contains( $settingsPage, 'data-icon-source' ) );
check( 'Zuruecksetzen wird angeboten', str_contains( $settingsPage, 'value="icon_reset"' ) );
check( 'Und fragt vorher nach', str_contains( $settingsPage, 'Eigenes Logo und alle daraus erzeugten Symbole entfernen?' ) );
check( 'Das Symbol-Skript wird geladen', str_contains( $settingsPage, 'assets/js/icon.js' ) );

// Ohne hinterlegtes Logo darf keine Vorschau vorgegaukelt werden.
$noLogo = render(
	'settings/index',
	array(
		'settings'   => array( 'agency_color' => '#f9907a', 'agency_name' => 'NorthLab' ),
		'catalog'    => \NorthLab\Service\EventBus::catalog(),
		'notifyOn'   => array(),
		'pushOn'     => array(),
		'pushReady'  => false,
		'pushCount'  => 0,
		'iconSource' => false,
		'iconOwn'    => false,
		'iconStamp'  => '0',
		'jobs'       => array(),
		'cronToken'  => 'x',
		'cronUrl'    => 'https://panel.test/api/cron/x',
		'config'     => array( 'app_url' => 'https://panel.test', 'timezone' => 'Europe/Berlin', 'db_name' => 'n', 'db_prefix' => 'nl_', 'verify_ssl' => true, 'mail' => array() ),
		'old'        => array(),
	)
);
check( 'Ohne Logo keine Vorschauflaeche', ! str_contains( $noLogo, 'id="icon-canvas"' ) );
check( 'Sondern das mitgelieferte Symbol', str_contains( $noLogo, 'Noch kein eigenes Logo hinterlegt' ) );
check( 'Und kein Zuruecksetzen', ! str_contains( $noLogo, 'value="icon_reset"' ) );
check( 'Push zeigt die Zahl der Geraete', str_contains( $settingsPage, '2 Gerät(e) angemeldet' ) );
check( 'Ein- und Ausschalter sind angelegt', str_contains( $settingsPage, 'data-push-enable' ) && str_contains( $settingsPage, 'data-push-disable' ) );
check( 'Probemeldung ist moeglich', str_contains( $settingsPage, '/push/test' ) );
check( 'Vorgemerkte Ereignisse sind angehakt', (bool) preg_match( '/name="push_on\[\]" value="site\.offline"\s+checked/', $settingsPage ) );
check( 'Nicht vorgemerkte nicht', ! preg_match( '/name="push_on\[\]" value="backup\.failed"\s+checked/', $settingsPage ) );
check( 'Der iPhone-Hinweis steht da', str_contains( $settingsPage, 'Zum Home-Bildschirm' ) );
check( 'Push-Skript wird geladen', str_contains( $settingsPage, 'assets/js/push.js' ) );

// Ohne Schluesselpaar muss der Weg dorthin sichtbar sein.
$noKeys = render(
	'settings/index',
	array_merge(
		array(
			'settings'  => array( 'agency_color' => '#f9907a', 'agency_name' => 'NorthLab' ),
			'catalog'   => \NorthLab\Service\EventBus::catalog(),
			'notifyOn'  => array(),
			'pushOn'    => array(),
			'pushReady' => false,
			'pushCount' => 0,
			'iconSource' => false,
			'iconOwn'    => false,
			'iconStamp'  => '0',
			'jobs'      => array(),
			'cronToken' => 'x',
			'cronUrl'   => 'https://panel.test/api/cron/x',
			'config'    => array( 'app_url' => 'https://panel.test', 'timezone' => 'Europe/Berlin', 'db_name' => 'n', 'db_prefix' => 'nl_', 'verify_ssl' => true, 'mail' => array() ),
			'old'       => array(),
		)
	)
);
check( 'Ohne Schluessel wird das Erzeugen angeboten', str_contains( $noKeys, 'Schlüsselpaar erzeugen' ) );
check( 'Und kein Einschalter vorgegaukelt', ! str_contains( $noKeys, 'data-push-enable' ) );
check( 'Und kein Erneuern angeboten', ! str_contains( $noKeys, 'Schlüsselpaar erneuern' ) );
check( 'Und die Felder dazu', str_contains( $settingsPage, 'name="branding_phone"' ) && str_contains( $settingsPage, 'name="branding_capability"' ) );

// -------------------------------------------------------------- Bericht
$reportSite = static function ( bool $managed ): array {
	return array(
		'id'              => 7,
		'name'            => 'Kundenshop',
		'url'             => 'https://shop.kunde.de',
		'status'          => 'connected',
		'type'            => $managed ? 'wordpress' : 'monitor',
		'managed'         => $managed,
		'uptime_status'   => 'up',
		'wp_version'      => $managed ? '6.6' : '',
		'php_version'     => $managed ? '8.3' : '',
		'security_score'  => $managed ? 82 : 0,
		'pending_updates' => $managed ? 2 : 0,
		'availability'    => array( 'percent' => 99.9, 'incidents' => 1, 'downtime_seconds' => 120 ),
		'incidents'       => array(),
		'updates_applied' => array(),
		'security_issues' => array(),
		'maintenance'     => array(),
	);
};

$report = array(
	'agency'  => array( 'name' => 'NorthLab', 'email' => 'hallo@north-lab.de', 'logo' => '', 'color' => '#f9907a' ),
	'client'  => null,
	'period'  => array( 'start' => gmdate( 'Y-m-d', time() - 2592000 ), 'end' => gmdate( 'Y-m-d' ), 'days' => 30 ),
	'summary' => array(
		'sites'            => 2,
		'updates_applied'  => 0,
		'avg_uptime'       => 99.9,
		'avg_security'     => 82,
		'incidents'        => 1,
		'downtime_seconds' => 120,
		'pending_updates'  => 2,
	),
	'sites'   => array( $reportSite( true ), $reportSite( false ) ),
);

ob_start();
include NL_VIEWS . '/report/document.php';
$reportHtml = (string) ob_get_clean();

check( 'Bericht meldet keine bestandene Prüfung für Monitor-Seiten', substr_count( $reportHtml, 'Alle Prüfungen bestanden' ) === 1 );
check( 'Bericht erklaert die reine Überwachung', str_contains( $reportHtml, 'überwacht, nicht gewartet' ) );
check( 'Bericht zeigt die Verfügbarkeit beider Seiten', substr_count( $reportHtml, 'Verfügbarkeit' ) >= 2 );

// ------------------------------------------------------ Reine Typlogik
check( 'isManaged: Standard ist WordPress', SiteRepository::isManaged( array() ) );
check( 'isManaged: monitor ist nicht verwaltet', ! SiteRepository::isManaged( array( 'site_type' => 'monitor' ) ) );
check( 'typeLabel kennt beide Typen', 2 === count( SiteRepository::TYPES ) );
check( 'typeLabel faellt auf WordPress zurueck', SiteRepository::typeLabel( array() ) === SiteRepository::TYPES['wordpress'] );

printf( "%d Prüfungen, %d Fehler\n", $checks, count( $failures ) );
foreach ( $failures as $failure ) {
	echo "  FEHLT: $failure\n";
}
exit( $failures ? 1 : 0 );
