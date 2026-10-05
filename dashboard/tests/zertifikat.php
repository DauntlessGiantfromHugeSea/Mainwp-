<?php
/**
 * Zertifikatslaufzeiten aus Uptime Kuma.
 *
 * Zwei Formate, die beide stillschweigend danebengehen koennen: der Wortlaut
 * der Benachrichtigung und die Prometheus-Kennzahlen. Beides wird hier gegen
 * echte Beispiele geprueft.
 */
declare( strict_types = 1 );

$root = realpath( dirname( __DIR__ ) );
define( 'NL_SRC', $root . '/src' );
define( 'NL_ROOT', $root );
spl_autoload_register( static function ( string $c ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = NL_SRC . '/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );

use NorthLab\Service\CertificateService;

$fails = 0;
$n     = 0;
function check( string $label, bool $ok, string $extra = '' ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label" . ( '' !== $extra ? " ($extra)" : '' ) . "\n"; }
}

/* ============================================= Der Wortlaut von Kuma */

// Genau so baut Kuma die Meldung zusammen (server/model/monitor.js):
//   [name][url] <Typ> certificate <CN> will expire in <N> days
$echt = '[raum32.de][https://raum32.de] LETS ENCRYPT certificate raum32.de will expire in 14 days';
$r    = CertificateService::fromMessage( $echt );

check( 'Die echte Kuma-Meldung wird gelesen', null !== $r );
check( 'Die Tage stimmen', null !== $r && 14 === $r['days'], null === $r ? '—' : (string) $r['days'] );
check( 'Die Adresse wird herausgezogen', null !== $r && 'https://raum32.de' === $r['url'], null === $r ? '—' : $r['url'] );
check( 'Der Zertifikatsname auch', null !== $r && 'raum32.de' === $r['subject'], null === $r ? '—' : $r['subject'] );

// Der Name der Seite steht in der ersten Klammer und ist keine Adresse -
// die darf nicht faelschlich als URL genommen werden.
$r = CertificateService::fromMessage( '[Mein Kunde][https://kunde.de/shop] certificate kunde.de will expire in 3 days' );
check( 'Die erste Klammer wird nicht fuer die Adresse gehalten', null !== $r && 'https://kunde.de/shop' === $r['url'], null === $r ? '—' : $r['url'] );

// Die Formulierung hat sich zwischen Fassungen schon geaendert. Eine Meldung
// zu verwerfen, weil ein Wort anders lautet, waere schlimmer als sie
// grosszuegig zu erkennen.
foreach ( array(
	'[a][https://a.de] certificate a.de will be expired in 7 days',
	'[a][https://a.de] SSL certificate expires in 7 days',
	'[a][https://a.de] Das Zertifikat laeuft in 7 Tagen ab',
) as $abwandlung ) {
	$r = CertificateService::fromMessage( $abwandlung );
	check( 'Abwandlung wird erkannt: ' . substr( $abwandlung, 13, 40 ), null !== $r && 7 === $r['days'] );
}

// Abgelaufen: negative Tage muessen durchkommen, nicht auf null fallen.
$r = CertificateService::fromMessage( '[a][https://a.de] certificate a.de will expire in -2 days' );
check( 'Ein abgelaufenes Zertifikat bleibt negativ', null !== $r && -2 === $r['days'], null === $r ? '—' : (string) $r['days'] );

/* ---------------------------------- Was keine Zertifikatsmeldung ist */

// Das Wichtigste: eine gewoehnliche Ausfallmeldung darf hier nicht
// hineinrutschen und als Zertifikatswert gespeichert werden.
foreach ( array(
	'',
	'[a][https://a.de] Down: connect ETIMEDOUT',
	'[a][https://a.de] Up',
	'Monitor is back online after 5 days',
	'[a][https://a.de] certificate a.de is valid',
	// Der Faengerfall: traegt "in 3 days", hat aber nichts mit einem
	// Zertifikat zu tun. Ohne die Wortpruefung landete hier eine
	// Wartungsankuendigung als Restlaufzeit in der Datenbank.
	'[a][https://a.de] Scheduled maintenance in 3 days',
	'[a][https://a.de] Monitor resumed in 2 days',
) as $kein ) {
	check( 'Keine Zertifikatsmeldung: "' . substr( $kein, 0, 36 ) . '"', null === CertificateService::fromMessage( $kein ) );
}

/* ====================================== Die Prometheus-Kennzahlen */

$metrics = <<<'TXT'
# HELP monitor_cert_days_remaining The number of days remaining until the certificate expires
# TYPE monitor_cert_days_remaining gauge
monitor_cert_days_remaining{monitor_name="raum32",monitor_type="http",monitor_url="https://raum32.de",monitor_hostname="null",monitor_port="null"} 67
monitor_cert_days_remaining{monitor_name="Shop",monitor_type="http",monitor_url="https://shop.example/pfad",monitor_hostname="null",monitor_port="null"} 9
monitor_cert_days_remaining{monitor_name="Ping",monitor_type="ping",monitor_url="null",monitor_hostname="10.0.0.5",monitor_port="null"} 0
monitor_cert_days_remaining{monitor_name="Kaputt",monitor_type="http",monitor_url="https://kaputt.example",monitor_hostname="null",monitor_port="null"} NaN
monitor_cert_days_remaining{monitor_name="Abgelaufen",monitor_type="http",monitor_url="https://alt.example",monitor_hostname="null",monitor_port="null"} -3
monitor_status{monitor_name="raum32",monitor_url="https://raum32.de"} 1
monitor_response_time{monitor_name="raum32",monitor_url="https://raum32.de"} 142
TXT;

$werte = CertificateService::parseMetrics( $metrics );
$nach  = array();
foreach ( $werte as $w ) { $nach[ $w['url'] ] = $w['days']; }

check( 'Die Kennzahl wird gefunden', isset( $nach['https://raum32.de'] ) && 67 === $nach['https://raum32.de'] );
check( 'Auch mit Pfad in der Adresse', isset( $nach['https://shop.example/pfad'] ) && 9 === $nach['https://shop.example/pfad'] );
check( 'Der Monitorname kommt mit', 'raum32' === ( $werte[0]['name'] ?? '' ) );

// Ein Ping-Monitor hat kein Zertifikat. Kuma schreibt dort "null" als
// Adresse und 0 als Wert - das als "laeuft heute ab" zu melden waere ein
// taeglicher Fehlalarm.
check( 'Monitore ohne Adresse fallen raus', ! isset( $nach['null'] ) && 3 === count( $werte ), (string) count( $werte ) );

// NaN heisst "kein Wert", nicht null.
check( 'NaN wird nicht zu 0', ! isset( $nach['https://kaputt.example'] ) );

// NaN scheitert schon am Muster. Eine Zeichenfolge, die zwar dem Muster
// entspricht, aber keine Zahl ist, prueft die zweite Absicherung dahinter —
// sonst steht die ungeprueft da und faellt erst auf, wenn jemand das
// Muster weitet.
$krumm = CertificateService::parseMetrics(
	'monitor_cert_days_remaining{monitor_url="https://krumm.example"} 1.2.3'
);
check( 'Eine Pseudozahl wird nicht uebernommen', array() === $krumm, (string) count( $krumm ) );

check( 'Abgelaufene bleiben negativ', isset( $nach['https://alt.example'] ) && -3 === $nach['https://alt.example'] );

// Andere Kennzahlen duerfen nicht mitgelesen werden.
check( 'Fremde Kennzahlen werden ignoriert', 3 === count( $werte ) );

check( 'Leere Antwort ergibt nichts', array() === CertificateService::parseMetrics( '' ) );
check( 'Unsinn ergibt nichts', array() === CertificateService::parseMetrics( "<html>Fehler</html>\nnicht=1" ) );

// Kommentarzeilen zaehlen nicht mit.
check( 'Die HELP-Zeile ist kein Wert', 3 === count( CertificateService::parseMetrics( $metrics ) ) );

/* ------------------------------------------- Maskierte Anfuehrungszeichen */

$mitEscape = 'monitor_cert_days_remaining{monitor_name="Kunde \"A\"",monitor_url="https://a.de"} 30';
$esc       = CertificateService::parseMetrics( $mitEscape );
check( 'Maskierte Zeichen im Namen stoeren nicht', 1 === count( $esc ) && 'https://a.de' === $esc[0]['url'] );
check( 'Und der Name wird entmaskiert', 'Kunde "A"' === ( $esc[0]['name'] ?? '' ), $esc[0]['name'] ?? '—' );

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
