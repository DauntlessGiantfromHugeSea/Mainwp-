<?php
/**
 * Prueft, ob das Panel den richtigen Grund nennt, wenn eine Funktion auf einer
 * Kundenseite nicht laeuft.
 */
declare( strict_types = 1 );

$root = realpath( dirname( __DIR__ ) );
define( 'NL_SRC', $root . '/src' );
spl_autoload_register( static function ( string $c ): void {
	if ( ! str_starts_with( $c, 'NorthLab\\' ) ) { return; }
	$p = NL_SRC . '/' . str_replace( '\\', '/', substr( $c, 9 ) ) . '.php';
	if ( is_file( $p ) ) { require_once $p; }
} );

use NorthLab\Service\ChildFeature;

// Die Freigaben werden jetzt hereingereicht - der Test braucht keine Datenbank.
$allOn = array(
	'allow_updates'     => true,
	'allow_install'     => true,
	'allow_user_mgmt'   => true,
	'allow_maintenance' => true,
	'allow_content'     => true,
	'allow_backup'      => true,
	'allow_autologin'   => true,
	'allow_mmode'       => true,
	'allow_self_update' => true,
	'allow_branding'    => true,
	'allow_links'       => true,
);

// Nicht hart eintragen: eine Zahl, die bei jedem Versionssprung von Hand
// nachgezogen werden muss, geht genau dann schief, wenn niemand daran denkt.
$shipped = '0.0.0';
$kopf    = (string) file_get_contents( $root . '/resources/child-plugin/north-lab-child/north-lab-child.php' );
if ( preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)/mi', $kopf, $m ) ) {
	$shipped = trim( $m[1] );
}

$fails = 0;
$n     = 0;
function check( string $label, bool $ok, string $extra = '' ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label" . ( '' !== $extra ? " ($extra)" : '' ) . "\n"; }
}

function site( array $over = array() ): array {
	// $shipped steht im aeusseren Gueltigkeitsbereich.
	return array_replace(
		array( 'id' => 7, 'name' => 'Kunde', 'site_type' => 'wordpress', 'child_version' => $GLOBALS['shipped'] ),
		$over
	);
}

/* ---------------------------------------------------- Versionsvergleich */

check(
	'Aktuelle Version: Anmeldung frei',
	null === ChildFeature::unavailable( site(), 'autologin', $allOn )
);

$old = ChildFeature::unavailable( site( array( 'child_version' => '1.1.0' ) ), 'autologin', $allOn );
check( 'Alte Version blockiert die Anmeldung', null !== $old );
check( 'Und nennt die noetige Version', is_string( $old ) && str_contains( $old, '1.2.0' ) );
check( 'Und die installierte', is_string( $old ) && str_contains( $old, '1.1.0' ) );
check( 'Und sagt, was zu tun ist', is_string( $old ) && str_contains( $old, 'aktuelle Plugin einspielen' ) );

check(
	'Alte Version blockiert nicht, was es schon immer gab',
	null === ChildFeature::unavailable( site( array( 'child_version' => '1.1.0' ) ), 'users', $allOn )
);

check(
	'1.2.0 blockiert das Selbst-Update',
	null !== ChildFeature::unavailable( site( array( 'child_version' => '1.2.0' ) ), 'selfupdate', $allOn )
);
check(
	'1.3.0 laesst es zu',
	null === ChildFeature::unavailable( site( array( 'child_version' => '1.3.0' ) ), 'selfupdate', $allOn )
);

check(
	'Unbekannte Version blockiert nichts vorschnell',
	null === ChildFeature::unavailable( site( array( 'child_version' => '' ) ), 'autologin', $allOn )
);

check(
	'Reine Ueberwachung wird sauber abgewiesen',
	str_contains( (string) ChildFeature::unavailable( site( array( 'site_type' => 'monitor' ) ), 'autologin', $allOn ), 'nur überwacht' )
);

check(
	'Unbekannte Funktion blockiert nicht',
	null === ChildFeature::unavailable( site(), 'gibtsnicht', $allOn )
);

/* ----------------------------------------------------------- Uebersicht */

$blockedFor = static function ( array $site, array $caps ): array {
	$keys = array();
	foreach ( array_keys( ChildFeature::FEATURES ) as $key ) {
		if ( null !== ChildFeature::unavailable( $site, $key, $caps ) ) {
			$keys[] = $key;
		}
	}
	sort( $keys );
	return $keys;
};

check( 'Bei aktueller Version ist alles frei', array() === $blockedFor( site(), $allOn ) );
check(
	'Bei 1.0.0 fehlen genau die spaeter dazugekommenen Funktionen',
	array( 'autologin', 'backuplog', 'branding', 'links', 'mmode', 'selfupdate' ) === $blockedFor( site( array( 'child_version' => '1.0.0' ) ), $allOn ),
	implode( ', ', $blockedFor( site( array( 'child_version' => '1.0.0' ) ), $allOn ) )
);

/* -------------------------------------------- Abgeschaltete Freigaben */

$noLogin = array_replace( $allOn, array( 'allow_autologin' => false ) );
$reason  = ChildFeature::unavailable( site(), 'autologin', $noLogin );
check( 'Abgeschaltete Anmeldung wird erkannt', null !== $reason );
check( 'Und der Weg zum Einschalten genannt', is_string( $reason ) && str_contains( $reason, 'Einstellungen → NorthLab' ) );
check( 'Andere Funktionen bleiben davon unberuehrt', null === ChildFeature::unavailable( site(), 'users', $noLogin ) );
check(
	'Nur die abgeschaltete Funktion faellt aus',
	array( 'autologin' ) === $blockedFor( site(), $noLogin )
);

// Eine Seite, die noch gar keine Freigaben gemeldet hat (Sync vor 1.3.1),
// darf nicht faelschlich als "alles abgeschaltet" gelten.
check( 'Ohne gemeldete Freigaben wird nichts blockiert', array() === $blockedFor( site(), array() ) );

// Zu alte Version schlaegt die Freigabe: die Versionsmeldung ist hilfreicher.
$bothWrong = ChildFeature::unavailable( site( array( 'child_version' => '1.1.0' ) ), 'autologin', $noLogin );
check( 'Bei beidem wird die Version genannt', is_string( $bothWrong ) && str_contains( $bothWrong, '1.2.0' ) );

/* ------------------------------------------ Jede Funktion hat eine Mindestversion */

check( 'Die ausgelieferte Version liess sich lesen', '0.0.0' !== $shipped, $shipped );

foreach ( ChildFeature::FEATURES as $key => $spec ) {
	check(
		sprintf( 'Mindestversion von "%s" ist nicht hoeher als die ausgelieferte', $key ),
		version_compare( $spec['min'], $shipped, '<=' )
	);
	check(
		sprintf( 'Funktion "%s" nennt einen Freigabe-Schalter', $key ),
		'' !== $spec['flag']
	);
}

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
