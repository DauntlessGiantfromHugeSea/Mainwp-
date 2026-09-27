<?php
/**
 * bin/update.sh: spielt neuen Code ein, ohne Laufzeitdaten zu vernichten.
 *
 * Der Anlass war ernst: rsync --delete hat storage/restic (SSH-Schluessel und
 * known_hosts), storage/backups (die Spiegel aller Kundenseiten) und
 * storage/branding (das hochgeladene Logo) bei jedem Update geloescht, weil
 * es diese Ordner im Quellbaum nicht gibt.
 */
declare( strict_types = 1 );

$root = dirname( __DIR__ );
$tmp  = sys_get_temp_dir() . '/nlc-update-test';

$fails = 0;
$n     = 0;
function check( string $label, bool $ok, string $extra = '' ): void {
	global $fails, $n;
	$n++;
	if ( ! $ok ) { $fails++; echo "  FEHLT: $label" . ( '' !== $extra ? " ($extra)" : '' ) . "\n"; }
}

if ( 0 !== posix_geteuid() ) {
	echo "0 Prüfungen, 0 Fehler\n";
	fwrite( STDERR, "übersprungen: update.sh setzt Dateirechte und braucht root\n" );
	exit( 0 );
}

exec( 'rm -rf ' . escapeshellarg( $tmp ) );
mkdir( $tmp . '/quelle', 0755, true );

$benutzer = getenv( 'NL_TEST_DB_USER' ) ?: 'nl';
$passwort = getenv( 'NL_TEST_DB_PASS' ) ?: 'nlpass';

shell_exec( 'mariadb -u root -e ' . escapeshellarg(
	'DROP DATABASE IF EXISTS nl_update;CREATE DATABASE nl_update CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
	. "GRANT ALL ON nl_update.* TO '$benutzer'@'127.0.0.1';FLUSH PRIVILEGES;"
) . ' 2>&1' );

/* --- Eine Quelle bauen, die aussieht wie der Git-Klon -------------------- */

$quelle = $tmp . '/quelle';

shell_exec( sprintf(
	'git init -q --bare %s/fern.git && git clone -q %s/fern.git %s/klon 2>&1',
	escapeshellarg( $tmp ), escapeshellarg( $tmp ), escapeshellarg( $tmp )
) );

$klon = $tmp . '/klon';

// Nur das Noetige aus dem echten Baum: update.sh, cron.php, src, storage-Geruest.
foreach ( array( 'bin', 'src', 'storage', 'views', 'public', 'resources' ) as $teil ) {
	shell_exec( sprintf( 'mkdir -p %s && cp -a %s %s/', escapeshellarg( $klon . '/dashboard' ),
		escapeshellarg( $root . '/' . $teil ), escapeshellarg( $klon . '/dashboard' ) ) );
}

file_put_contents( $klon . '/dashboard/public/neu-aus-dem-update.php', "<?php // frisch\n" );

shell_exec( sprintf(
	'cd %s && git add -A && git -c user.email=t@t -c user.name=t commit -qm start && git push -q origin HEAD 2>&1',
	escapeshellarg( $klon )
) );

/* --- Und ein Ziel, das im Betrieb steht ---------------------------------- */

$ziel = $tmp . '/ziel';
shell_exec( sprintf( 'mkdir -p %s && cp -a %s/. %s/', escapeshellarg( $ziel ),
	escapeshellarg( $klon . '/dashboard' ), escapeshellarg( $ziel ) ) );
unlink( $ziel . '/public/neu-aus-dem-update.php' );

file_put_contents(
	$ziel . '/config.php',
	"<?php return array( 'app' => array( 'key' => '" . base64_encode( str_repeat( 'u', 32 ) ) . "' ),"
	. " 'db' => array( 'host' => '127.0.0.1', 'port' => 3306, 'name' => 'nl_update',"
	. " 'user' => '$benutzer', 'pass' => '$passwort', 'prefix' => 'nl_' ) );\n"
);

// Laufzeitdaten, wie sie im Betrieb entstehen — im Quellbaum gibt es sie nicht.
$laufzeit = array(
	'storage/restic/.ssh/id_ed25519'     => 'GEHEIMER-SCHLUESSEL',
	'storage/restic/.ssh/known_hosts'    => 'wirt ssh-ed25519 AAAA',
	'storage/backups/site-1/index.php'   => 'gespiegelte Kundendatei',
	'storage/backups/_panel/datenbank.sql.gz' => 'export',
	'storage/branding/icon-512.png'      => 'PNG',
	'storage/branding/source.svg'        => '<svg/>',
	'storage/logs/app.log'               => 'protokoll',
);

foreach ( $laufzeit as $pfad => $inhalt ) {
	@mkdir( dirname( $ziel . '/' . $pfad ), 0750, true );
	file_put_contents( $ziel . '/' . $pfad, $inhalt );
}

// Etwas Veraltetes ausserhalb von storage/ — das soll verschwinden.
file_put_contents( $ziel . '/public/uralt.php', "<?php // weg damit\n" );

shell_exec( 'chown -R www-data:www-data ' . escapeshellarg( $ziel . '/storage' ) );

/* --- Schema anlegen, damit die Pruefung in update.sh durchlaeuft --------- */

shell_exec( sprintf(
	'cd %s && php -r %s 2>&1',
	escapeshellarg( $ziel ),
	escapeshellarg(
		'define("NL_ROOT","' . $ziel . '"); require "' . $ziel . '/src/bootstrap.php"; NorthLab\\Core\\Migrator::migrate();'
	)
) );

/* --- Das Update laufen lassen -------------------------------------------- */

$ausgabe = (string) shell_exec( sprintf(
	'NL_SRC=%s NL_TARGET=%s NL_USER=www-data bash %s 2>&1',
	escapeshellarg( $klon ), escapeshellarg( $ziel ), escapeshellarg( $klon . '/dashboard/bin/update.sh' )
) );

check( 'Das Update laeuft durch', str_contains( $ausgabe, 'Update abgeschlossen' ),
	implode( ' | ', array_slice( array_filter( explode( "\n", $ausgabe ) ), -3 ) ) );

/* --- Und jetzt das Entscheidende ----------------------------------------- */

foreach ( $laufzeit as $pfad => $inhalt ) {
	check( 'Bleibt erhalten: ' . $pfad, is_file( $ziel . '/' . $pfad ) );
}

check( 'Der Schluessel ist unveraendert',
	'GEHEIMER-SCHLUESSEL' === trim( (string) @file_get_contents( $ziel . '/storage/restic/.ssh/id_ed25519' ) ) );
check( 'Der Spiegel ist unveraendert',
	'gespiegelte Kundendatei' === trim( (string) @file_get_contents( $ziel . '/storage/backups/site-1/index.php' ) ) );

check( 'Neuer Code kommt an', is_file( $ziel . '/public/neu-aus-dem-update.php' ) );
check( 'Veraltetes ausserhalb storage/ verschwindet', ! is_file( $ziel . '/public/uralt.php' ) );
check( 'Die config.php bleibt', is_file( $ziel . '/config.php' ) );

// Das Geruest muss trotzdem geliefert werden.
check( 'Das Geruest unter storage/ kommt mit', is_file( $ziel . '/storage/.htaccess' ) );

// Und der Betriebsbenutzer muss noch drankommen.
$besitzer = posix_getpwuid( (int) fileowner( $ziel . '/storage/restic/.ssh/id_ed25519' ) )['name'] ?? '';
check( 'Die Laufzeitdaten gehoeren weiter dem Betriebsbenutzer', 'www-data' === $besitzer, $besitzer );

printf( "%d Prüfungen, %d Fehler\n", $n, $fails );
exit( $fails ? 1 : 0 );
