#!/usr/bin/env php
<?php
/**
 * NorthLab Control Panel — Zeitplaner.
 *
 * Einrichtung (empfohlen, minütlich):
 *   * * * * * /usr/bin/php /pfad/zu/dashboard/bin/cron.php >> /pfad/zu/dashboard/storage/logs/cron.log 2>&1
 *
 * Ohne Cron-Zugang lässt sich stattdessen der HTTP-Auslöser verwenden:
 *   <panel>/api/cron/<token>   (Token steht unter Einstellungen)
 *
 * Aufrufe:
 *   php bin/cron.php              Alle fälligen Aufgaben
 *   php bin/cron.php --verbose    Mit Ausgabe
 *   php bin/cron.php sync         Eine bestimmte Aufgabe erzwingen
 *   php bin/cron.php --list       Zeitplan anzeigen
 */

declare( strict_types = 1 );

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( 'Dieses Skript läuft nur auf der Kommandozeile.' );
}

require_once dirname( __DIR__ ) . '/src/bootstrap.php';

use NorthLab\Core\Config;
use NorthLab\Core\Migrator;
use NorthLab\Service\Scheduler;

if ( ! Config::isInstalled() ) {
	fwrite( STDERR, "NorthLab ist noch nicht installiert. Bitte zuerst /install im Browser aufrufen.\n" );
	exit( 1 );
}

if ( Migrator::needsMigration() ) {
	Migrator::migrate();
}

$args    = array_slice( $argv, 1 );
$verbose = in_array( '--verbose', $args, true ) || in_array( '-v', $args, true );
$args    = array_values( array_filter( $args, static fn( string $a ): bool => ! str_starts_with( $a, '-' ) ) );

if ( in_array( '--list', $argv, true ) ) {
	printf( "%-16s %-10s %-21s %-21s %s\n", 'AUFGABE', 'INTERVALL', 'ZULETZT', 'NÄCHSTER LAUF', 'ERGEBNIS' );

	foreach ( Scheduler::overview() as $job ) {
		printf(
			"%-16s %-10s %-21s %-21s %s\n",
			$job['name'],
			$job['interval'] . 's',
			$job['last_run_at'] ?? '—',
			$job['next_run_at'] ?? 'sofort',
			$job['last_result'] ?? '—'
		);
	}

	exit( 0 );
}

if ( $args ) {
	$name = $args[0];

	if ( ! in_array( $name, Scheduler::jobNames(), true ) ) {
		fwrite( STDERR, 'Unbekannte Aufgabe: ' . $name . ' (verfügbar: ' . implode( ', ', Scheduler::jobNames() ) . ")\n" );
		exit( 1 );
	}

	echo Scheduler::run( $name, $verbose ), "\n";
	exit( 0 );
}

$results = Scheduler::runDue( $verbose );

if ( $verbose && ! $results ) {
	echo "Nichts fällig.\n";
}

exit( 0 );
