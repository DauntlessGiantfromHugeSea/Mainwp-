<?php

declare( strict_types = 1 );

namespace NorthLab\Service;

use NorthLab\Core\Auth;
use NorthLab\Core\Database;
use NorthLab\Core\Logger;
use NorthLab\Core\Setting;
use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\UptimeRepository;
use NorthLab\Repository\WebhookRepository;
use Throwable;

/**
 * Interner Zeitplaner. Ein einziger System-Cron ruft jede Minute bin/cron.php auf,
 * die Fälligkeit der einzelnen Aufgaben verwaltet diese Klasse.
 */
final class Scheduler {

	/** Aufgaben, die nach abgelaufener Wartezeit erneut laufen. */
	private const JOBS = array(
		'webhooks'     => array( 'label' => 'Webhook-Warteschlange', 'interval' => 60 ),
		'heartbeat'    => array( 'label' => 'Erreichbarkeitsprüfung', 'interval' => 300 ),
		'sync'         => array( 'label' => 'Seiten synchronisieren', 'interval' => 900 ),
		'auto_updates' => array( 'label' => 'Automatische Updates', 'interval' => 3600 ),
		'reports'      => array( 'label' => 'Fällige Berichte', 'interval' => 3600 ),
		'cleanup'      => array( 'label' => 'Aufräumen', 'interval' => 86400 ),
	);

	/** Eine Aufgabe gilt nach dieser Zeit als hängengeblieben. */
	private const LOCK_TIMEOUT = 1800;

	/**
	 * Alle fälligen Aufgaben ausführen.
	 *
	 * @return array<string,string>
	 */
	public static function runDue( bool $verbose = false ): array {
		$log = array();

		foreach ( array_keys( self::JOBS ) as $name ) {
			if ( ! self::isDue( $name ) ) {
				continue;
			}

			$log[ $name ] = self::run( $name, $verbose );
		}

		return $log;
	}

	/**
	 * Eine Aufgabe erzwingen (ignoriert die Fälligkeit).
	 */
	public static function run( string $name, bool $verbose = false ): string {
		if ( ! isset( self::JOBS[ $name ] ) ) {
			return 'Unbekannte Aufgabe: ' . $name;
		}

		if ( ! self::acquireLock( $name ) ) {
			return 'übersprungen (läuft bereits)';
		}

		$started = microtime( true );

		try {
			$result = self::execute( $name );
		} catch ( Throwable $e ) {
			Logger::exception( $e );
			$result = 'Fehler: ' . $e->getMessage();
		}

		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		self::releaseLock( $name, $result, $duration );

		if ( $verbose ) {
			fwrite( STDOUT, sprintf( "[%s] %s: %s (%d ms)\n", gmdate( 'H:i:s' ), $name, $result, $duration ) );
		}

		return $result;
	}

	private static function execute( string $name ): string {
		switch ( $name ) {
			case 'webhooks':
				$stats = WebhookService::processQueue( 50 );
				return sprintf( '%d zugestellt, %d fehlgeschlagen', $stats['sent'], $stats['failed'] );

			case 'heartbeat':
				if ( ! Setting::getBool( 'heartbeat_enabled', true ) ) {
					return 'deaktiviert';
				}
				$stats = SyncService::heartbeatAll();
				return sprintf( '%d erreichbar, %d offline', $stats['up'], $stats['down'] );

			case 'sync':
				$stats = SyncService::all();
				return sprintf( '%d erfolgreich, %d fehlgeschlagen', $stats['ok'], $stats['failed'] );

			case 'auto_updates':
				$stats = UpdateService::runAutoUpdates();
				return sprintf( '%d Seite(n), %d Update(s)', $stats['sites'], $stats['applied'] );

			case 'reports':
				return sprintf( '%d Bericht(e) erstellt', ReportService::runScheduled() );

			case 'cleanup':
				$activity = ActivityRepository::prune( Setting::getInt( 'activity_retention', 180 ) );
				$uptime   = UptimeRepository::prune( Setting::getInt( 'uptime_retention', 365 ) );
				$hooks    = WebhookRepository::prune( 30 );
				$sessions = Auth::pruneSessions();

				// Ein täglicher Gesamtstand fängt auf, wenn einzelne Ereignisse
				// unterwegs verloren gegangen sind.
				$snapshot = Setting::getBool( 'snapshot_daily', true )
					? WebhookService::sendSnapshot( 30 )
					: 0;

				return sprintf(
					'%d Protokoll, %d Uptime, %d Zustellungen, %d Sitzungen entfernt, %d Gesamtstand',
					$activity,
					$uptime,
					$hooks,
					$sessions,
					$snapshot
				);
		}

		return 'nichts zu tun';
	}

	/* ------------------------------------------------------------ Zustand */

	public static function isDue( string $name ): bool {
		$job = self::state( $name );

		if ( null === $job || null === $job['next_run_at'] ) {
			return true;
		}

		return strtotime( (string) $job['next_run_at'] . ' UTC' ) <= time();
	}

	public static function interval( string $name ): int {
		if ( 'sync' === $name ) {
			return max( 300, Setting::getInt( 'sync_interval', 900 ) );
		}
		if ( 'heartbeat' === $name ) {
			return max( 60, Setting::getInt( 'heartbeat_interval', 300 ) );
		}

		return (int) ( self::JOBS[ $name ]['interval'] ?? 3600 );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function state( string $name ): ?array {
		return Database::selectOne(
			'SELECT * FROM `' . Database::table( 'jobs' ) . '` WHERE `name` = :name',
			array( 'name' => $name )
		);
	}

	/**
	 * Übersicht für die Statusanzeige.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function overview(): array {
		$rows = array();

		foreach ( self::JOBS as $name => $definition ) {
			$state = self::state( $name );

			$rows[] = array(
				'name'        => $name,
				'label'       => $definition['label'],
				'interval'    => self::interval( $name ),
				'last_run_at' => $state['last_run_at'] ?? null,
				'next_run_at' => $state['next_run_at'] ?? null,
				'last_result' => $state['last_result'] ?? null,
				'duration_ms' => (int) ( $state['last_duration_ms'] ?? 0 ),
				'is_running'  => ! empty( $state['is_running'] ),
			);
		}

		return $rows;
	}

	/**
	 * Wann lief der Cron zuletzt überhaupt?
	 */
	public static function lastHeartbeat(): ?string {
		$value = Database::scalar( 'SELECT MAX(`last_run_at`) FROM `' . Database::table( 'jobs' ) . '`' );

		return is_string( $value ) ? $value : null;
	}

	public static function isCronHealthy(): bool {
		$last = self::lastHeartbeat();

		// Die Webhook-Aufgabe läuft minütlich; nach 15 Minuten Stille stimmt etwas nicht.
		return null !== $last && ( time() - (int) strtotime( $last . ' UTC' ) ) < 900;
	}

	/* --------------------------------------------------------- Sperren */

	private static function acquireLock( string $name ): bool {
		$state = self::state( $name );

		if ( null === $state ) {
			Database::insert(
				'jobs',
				array(
					'name'       => $name,
					'is_running' => 1,
					'locked_at'  => nl_utc(),
				)
			);
			return true;
		}

		if ( ! empty( $state['is_running'] ) ) {
			$lockedAt = ! empty( $state['locked_at'] ) ? (int) strtotime( (string) $state['locked_at'] . ' UTC' ) : 0;

			// Abgelaufene Sperre eines abgestürzten Laufs übernehmen.
			if ( time() - $lockedAt < self::LOCK_TIMEOUT ) {
				return false;
			}
		}

		Database::update( 'jobs', array( 'is_running' => 1, 'locked_at' => nl_utc() ), array( 'name' => $name ) );

		return true;
	}

	private static function releaseLock( string $name, string $result, int $durationMs ): void {
		Database::update(
			'jobs',
			array(
				'is_running'       => 0,
				'locked_at'        => null,
				'last_run_at'      => nl_utc(),
				'next_run_at'      => nl_utc( self::interval( $name ) ),
				'last_duration_ms' => $durationMs,
				'last_result'      => substr( $result, 0, 500 ),
			),
			array( 'name' => $name )
		);
	}

	/**
	 * @return array<int,string>
	 */
	public static function jobNames(): array {
		return array_keys( self::JOBS );
	}
}
