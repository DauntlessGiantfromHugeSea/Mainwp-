<?php
/**
 * Erreicht diese Website die Storage Box?
 *
 * Auf die Kundenseite legen, im Browser aufrufen, danach wieder loeschen.
 * Es werden keine Zugangsdaten gebraucht und nichts uebertragen — es wird
 * nur gefragt, ob eine Verbindung ueberhaupt zustande kommt.
 */
header( 'Content-Type: text/plain; charset=utf-8' );
header( 'X-Robots-Tag: noindex, nofollow' );

/*
 * Diese Datei wird auf eine fremde Kundenseite gelegt und danach wieder
 * entfernt. "Danach wieder entfernen" klappt nicht immer — und was
 * liegenbleibt, ist von aussen aufrufbar. Deshalb zwei Riegel:
 *
 *  1. Sie laeuft nur zwei Stunden lang. Wer sie hochlaedt, benutzt sie in
 *     dieser Zeit; wer sie vergisst, hinterlaesst nichts Brauchbares.
 *  2. Das Ziel muss eine Storage Box sein. Ohne diese Pruefung waere die
 *     Datei ein offener Portscanner: ?box=10.0.0.5 haette von der
 *     Kundenseite aus jedes interne Ziel auf Port 22 und 23 angetastet und
 *     dessen Begruessung ausgegeben.
 */
$angelegt = (int) @filemtime( __FILE__ );

if ( $angelegt > 0 && time() - $angelegt > 7200 ) {
	http_response_code( 410 );
	exit( "Dieses Werkzeug ist abgelaufen. Bitte die Datei löschen.\n" );
}

$wirt = (string) ( $_GET['box'] ?? 'uXXXXXX.your-storagebox.de' );

if ( ! preg_match( '/^u\d+(-sub\d+)?\.your-storagebox\.de$/i', $wirt ) ) {
	http_response_code( 400 );
	exit(
		"Als Ziel ist nur eine Storage Box erlaubt, etwa:\n"
		. "  ?box=u123456.your-storagebox.de\n"
	);
}

printf( "Ziel: %s\n", $wirt );
printf( "PHP:  %s\n\n", PHP_VERSION );

// --- Loest der Name auf? ----------------------------------------------------
if ( filter_var( $wirt, FILTER_VALIDATE_IP ) ) {
	printf( "Namensaufloesung: entfaellt, %s ist bereits eine Adresse\n", $wirt );
} else {
	$ip = gethostbyname( $wirt );

	if ( $ip === $wirt ) {
		echo "Namensaufloesung: FEHLT — der Host ist von hier aus nicht aufloesbar.\n";
	} else {
		printf( "Namensaufloesung: %s\n", $ip );
	}
}

// --- Kommt eine Verbindung zustande? ---------------------------------------
foreach ( array( 23, 22 ) as $port ) {
	$begonnen = microtime( true );
	$fehler   = 0;
	$text     = '';

	$verbindung = @fsockopen( $wirt, $port, $fehler, $text, 8 );

	if ( ! is_resource( $verbindung ) ) {
		printf( "Port %d: ZU (%s)\n", $port, $text !== '' ? $text : 'keine Antwort' );
		continue;
	}

	// Ein SSH-Server meldet sich von selbst mit seiner Kennung.
	stream_set_timeout( $verbindung, 5 );
	$gruss = trim( (string) fgets( $verbindung, 128 ) );
	fclose( $verbindung );

	printf(
		"Port %d: OFFEN nach %d ms%s\n",
		$port,
		(int) ( ( microtime( true ) - $begonnen ) * 1000 ),
		$gruss !== '' ? ' — ' . $gruss : ''
	);
}

// --- Gibt es ueberhaupt etwas, womit PHP sftp sprechen koennte? -------------
echo "\nWerkzeuge auf dieser Seite:\n";
printf( "  ssh2-Erweiterung: %s\n", extension_loaded( 'ssh2' ) ? 'vorhanden' : 'fehlt' );
printf( "  openssl:          %s\n", extension_loaded( 'openssl' ) ? 'vorhanden' : 'fehlt' );
printf( "  curl:             %s\n", extension_loaded( 'curl' ) ? 'vorhanden' : 'fehlt' );
printf( "  exec() erlaubt:   %s\n", function_exists( 'exec' ) && ! in_array( 'exec', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true ) ? 'ja' : 'nein' );
printf( "  Speichergrenze:   %s\n", ini_get( 'memory_limit' ) );
printf( "  max_execution:    %s s\n", ini_get( 'max_execution_time' ) );
