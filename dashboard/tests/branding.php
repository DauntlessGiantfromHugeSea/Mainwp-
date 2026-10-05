<?php
/**
 * Prueft Support-Leiste und Login-Branding des Child-Plugins.
 */
declare( strict_types = 1 );

require __DIR__ . '/child-stubs.php';

$GLOBALS['caps'] = array( 'read' => true, 'edit_posts' => true, 'manage_options' => true );

function current_user_can_stub( $cap ) { return ! empty( $GLOBALS['caps'][ $cap ] ); }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function str_starts_with_polyfill( $h, $n ) { return 0 === strpos( $h, $n ); }

require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-options.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-cache.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-maintenance-mode.php';
require_once dirname( __DIR__ ) . '/resources/child-plugin/north-lab-child/includes/class-nlc-branding.php';

$fails = 0;
$n     = 0;
function check( string $label, bool $ok ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label\n"; }
}

$full = array(
	'bar_enabled'       => true,
	'logo'              => 'https://north-flow.de/api/assets/188bbf40',
	'site'              => 'https://north-lab.de',
	'email'             => 'hallo@north-lab.de',
	'phone'             => '+49 1590 5535295',
	'text'              => 'Kontakt bei Fragen oder Problemen:',
	'capability'        => 'read',
	'login_bar_enabled' => true,
	'login_bar_text'    => 'Betreut von',
	'login_logo'        => 'https://raum32.de/wp-content/uploads/2026/08/favicon_b.png',
	'login_logo_height' => 72,
	'login_logo_link'   => '',
	'accent'            => '#e8917a',
	'tint'              => '#fdf3f0',
);

/* ------------------------------------------------------- Support-Leiste */

$bar = NLC_Branding::bar_markup( $full );

check( 'Leiste wird ausgegeben', str_contains( $bar, 'id="nl-supportbar"' ) );
check( 'Logo ist drin', str_contains( $bar, 'src="https://north-flow.de/api/assets/188bbf40"' ) );
check( 'Ansprache ist drin', str_contains( $bar, 'Kontakt bei Fragen oder Problemen:' ) );
check( 'Website verlinkt ohne Schema im Text', str_contains( $bar, '<span>north-lab.de</span>' ) );
check( 'Website oeffnet in neuem Tab, abgeschirmt', str_contains( $bar, 'target="_blank" rel="noopener"' ) );
check( 'Mailadresse verlinkt', str_contains( $bar, 'href="mailto:hallo@north-lab.de"' ) );
check( 'Telefon wird angezeigt', str_contains( $bar, '<span>+49 1590 5535295</span>' ) );
check( 'Telefon waehlbar ohne Leerzeichen', str_contains( $bar, 'href="tel:+4915905535295"' ) );
check( 'Akzentfarbe kommt an', (bool) preg_match( '/--nl-accent:\s+#e8917a;/', $bar ) );
check( 'Hintergrundton kommt an', (bool) preg_match( '/--nl-tint:\s+#fdf3f0;/', $bar ) );
check( 'Keine notice-Klasse — sonst verschiebt WordPress die Box', ! preg_match( '/class="[^"]*\b(notice|updated|error)\b/', $bar ) );
check( 'Symbole sind eingebettet, nicht nachgeladen', substr_count( $bar, '<svg' ) === 3 && ! str_contains( $bar, '<img src="data' ) );

// Ohne Kontaktweg keine leere Leiste
$empty = NLC_Branding::bar_markup( array_merge( $full, array( 'site' => '', 'email' => '', 'phone' => '' ) ) );
check( 'Ohne Kontaktweg gar keine Leiste', '' === $empty );

// Einzelne Wege weglassen
$mailOnly = NLC_Branding::bar_markup( array_merge( $full, array( 'site' => '', 'phone' => '' ) ) );
check( 'Nur E-Mail reicht', str_contains( $mailOnly, 'mailto:' ) );
check( 'Und zeigt die anderen nicht', ! str_contains( $mailOnly, 'tel:' ) && ! str_contains( $mailOnly, 'target="_blank"' ) );

/* ------------------------------------------------------ Login-Branding */

$loginBar = NLC_Branding::login_bar_markup( $full );
check( 'Login-Leiste wird ausgegeben', str_contains( $loginBar, 'id="nl-loginbar"' ) );
check( 'Mit dem Text', str_contains( $loginBar, 'Betreut von' ) );
check( 'Und der Adresse', str_contains( $loginBar, '<span>north-lab.de</span>' ) );
check( 'Sie liegt fest oben', str_contains( $loginBar, 'position: fixed;' ) );
check( 'Und schafft sich Platz', str_contains( $loginBar, 'body.login{ padding-top: 52px; }' ) );

$noBrand = NLC_Branding::login_bar_markup( array_merge( $full, array( 'logo' => '', 'site' => '' ) ) );
check( 'Ohne Logo und Adresse keine Login-Leiste', '' === $noBrand );

/* ---------------------------------------------------------- Maskierung */

$evil = NLC_Branding::bar_markup(
	array_merge(
		$full,
		array(
			'text'  => 'Hallo</span><script>alert(1)</script>',
			'site'  => 'javascript:alert(2)',
			'email' => '"><script>alert(3)</script>',
			'phone' => '+49 <script>alert(4)</script> 123',
			'accent' => '#fff"><script>alert(5)</script>',
		)
	)
);
check( 'Kein Skript in der Leiste', ! str_contains( $evil, '<script>' ) );
check( 'Nicht-URL als Website verworfen', ! str_contains( $evil, 'javascript:' ) );
check( 'Unsinn in der Telefonnummer entfernt', ! str_contains( $evil, 'alert(4)' ) );
check( 'Farbe faellt auf die Vorgabe zurueck', (bool) preg_match( '/--nl-accent:\s+#e8917a;/', $evil ) );

/* ------------------------------------------------- Telefon-Umwandlung */

check( 'Plus bleibt vorn', '+4915905535295' === NLC_Branding::dial( '+49 1590 5535295' ) );
check( 'Ohne Plus keine Erfindung', '015905535295' === NLC_Branding::dial( '01590 5535295' ) );
check( 'Klammern und Striche fliegen raus', '+493012345' === NLC_Branding::dial( '+49 (0)30 / 12-345' ) );
check( 'Ohne Landesvorwahl bleibt die 0 stehen', '03012345' === NLC_Branding::dial( '(0)30 / 12-345' ) );
check( 'Anzeige behaelt die Formatierung', '+49 (0)30 / 12-345' === NLC_Branding::phone( '+49 (0)30 / 12-345' ) );

/* ------------------------------------------------------- Speichern */

$saved = NLC_Branding::save( $full );
check( 'Speichern behaelt die Schalter', ! empty( $saved['bar_enabled'] ) && ! empty( $saved['login_bar_enabled'] ) );
check( 'Hoehe wird begrenzt', 240 === NLC_Branding::save( array_merge( $full, array( 'login_logo_height' => 9999 ) ) )['login_logo_height'] );
check( 'Und nach unten auch', 24 === NLC_Branding::save( array_merge( $full, array( 'login_logo_height' => 1 ) ) )['login_logo_height'] );
check( 'Unbekannte Sichtbarkeit faellt zurueck', 'read' === NLC_Branding::save( array_merge( $full, array( 'capability' => 'delete_site' ) ) )['capability'] );
check( 'Bekannte Sichtbarkeit bleibt', 'manage_options' === NLC_Branding::save( array_merge( $full, array( 'capability' => 'manage_options' ) ) )['capability'] );

/* ---------------------------------------------------------- Sichtbarkeit */

NLC_Branding::save( array_merge( $full, array( 'capability' => 'manage_options' ) ) );
$state = NLC_Branding::state();
check( 'Zustand bleibt erhalten', 'manage_options' === $state['capability'] );

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
