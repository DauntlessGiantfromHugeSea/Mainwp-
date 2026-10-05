#!/usr/bin/env php
<?php
/**
 * NorthLab — Sicherungsverlauf der Kundenseiten.
 *
 * Beantwortet "warum steht auf der Kundenseite keine Sicherung?", ohne zu
 * raten. Drei Ursachen sehen von aussen gleich aus:
 *
 *   1. Das Panel hat selbst keine erfolgreichen Laeufe
 *   2. Das Child-Plugin dort ist zu alt fuer die Uebersicht
 *   3. Es wurde nur noch nicht uebertragen
 *
 * Aufrufe:
 *   php bin/backup-history.php            nachsehen
 *   php bin/backup-history.php --push     die Vorgeschichte jetzt uebertragen
 *   php bin/backup-history.php --push 7   nur fuer die Seite mit der Kennung 7
 */

declare( strict_types = 1 );

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'Dieses Skript läuft nur auf der Kommandozeile.' );
}

require_once dirname( __DIR__ ) . '/src/bootstrap.php';

use NorthLab\Core\Config;
use NorthLab\Core\Migrator;
use NorthLab\Repository\SiteRepository;
use NorthLab\Service\BackupService;
use NorthLab\Service\ChildClient;

if ( ! Config::isInstalled() ) {
	fwrite( STDERR, "NorthLab ist noch nicht installiert.\n" );
	exit( 1 );
}

if ( Migrator::needsMigration() ) {
	Migrator::migrate();
}

$args    = array_slice( $argv, 1 );
$push    = in_array( '--push', $args, true );
$nurSite = 0;

foreach ( $args as $arg ) {
	if ( ctype_digit( $arg ) ) {
		$nurSite = (int) $arg;
	}
}

$spalten = "%-4s %-26s %-8s %-11s %-9s %s\n";

printf( $spalten, 'ID', 'Seite', 'Child', 'im Panel', 'dort', 'Befund' );
echo str_repeat( '-', 104 ) . "\n";

$mitLaeufen  = 0;
$uebertragen = 0;
$gesehen     = 0;

foreach ( SiteRepository::all() as $site ) {
	$siteId = (int) $site['id'];

	if ( $nurSite > 0 && $siteId !== $nurSite ) {
		continue;
	}

	$gesehen++;

	// Erst ohne die Kundenseite urteilen — das spart den Abruf bei allem,
	// was ohnehin schon feststeht.
	$bericht = BackupService::historyReport( $site );
	$dort    = '—';

	$lohnt = $bericht['managed']
		&& ! $bericht['too_old']
		&& ( $bericht['ok'] > 0 || $bericht['failed'] > 0 );

	if ( $lohnt ) {
		$antwort = ChildClient::post( $site, '/backup', array( 'action' => 'log' ), 20 );

		if ( ! $antwort['ok'] ) {
			$bericht['verdict'] = 'nicht erreichbar: ' . mb_strimwidth( (string) $antwort['error'], 0, 46, '…' );
		} else {
			$anzahl  = count( (array) ( $antwort['data']['log'] ?? array() ) );
			$dort    = (string) $anzahl;
			$bericht = BackupService::historyReport( $site, $anzahl );

			if ( $push ) {
				$gesendet = BackupService::pushHistory( $site );

				$bericht['verdict'] = $gesendet >= 0
					? sprintf( '%d übertragen', $gesendet )
					: 'Übertragung fehlgeschlagen';

				$uebertragen += max( 0, $gesendet );
			}
		}
	}

	if ( $bericht['ok'] > 0 ) {
		$mitLaeufen++;
	}

	printf(
		$spalten,
		$siteId,
		mb_strimwidth( (string) $site['name'], 0, 25, '…' ),
		'' !== $bericht['version'] ? $bericht['version'] : '?',
		$bericht['failed'] > 0
			? sprintf( '%d (+%d x)', $bericht['ok'], $bericht['failed'] )
			: (string) $bericht['ok'],
		$dort,
		$bericht['verdict']
	);
}

echo "\n";

if ( 0 === $gesehen ) {
	echo "Keine Seite gefunden.\n";
} elseif ( 0 === $mitLaeufen ) {
	echo "Keine einzige Seite hat eine erfolgreiche Sicherung im Panel.\n";
	echo "Dann fehlt nicht die Anzeige, sondern die Sicherung selbst —\n";
	echo "unter Sicherungen → Einrichten nachsehen, ob der Speicher erreichbar ist.\n";
} elseif ( $push ) {
	printf( "%d Einträge übertragen.\n", $uebertragen );
} else {
	echo "Zum Übertragen: php bin/backup-history.php --push\n";
}
