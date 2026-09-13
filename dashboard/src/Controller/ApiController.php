<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Request;
use NorthLab\Core\Response;
use NorthLab\Core\Setting;
use NorthLab\Repository\ClientRepository;
use NorthLab\Repository\SiteRepository;
use NorthLab\Repository\UpdateRepository;
use NorthLab\Service\ReportService;
use NorthLab\Service\Scheduler;
use NorthLab\Service\UptimeService;

/**
 * Öffentliche Endpunkte ohne Anmeldung: Monitoring-Eingang und Cron-Auslöser.
 * Beide sind ausschließlich über ein Token im Pfad geschützt.
 */
final class ApiController {

	/**
	 * Nimmt Statusmeldungen externer Monitoring-Dienste entgegen.
	 *
	 * URL:  <panel>/api/uptime/<token>
	 * Body: JSON des Dienstes oder {"status":"up"|"down"}
	 */
	public function uptime( Request $request ): void {
		$token  = (string) ( $request->params['token'] ?? '' );
		$global = trim( Setting::get( 'uptime_global_token', '' ) );

		// Zwei Betriebsarten: ein Token je Seite, oder ein Sammel-Token für alle
		// Monitore — dann wird die Seite anhand der gemeldeten Adresse zugeordnet.
		$isGlobal = '' !== $global && hash_equals( $global, $token );
		$site     = $isGlobal ? null : SiteRepository::findByMonitorToken( $token );

		if ( ! $isGlobal && null === $site ) {
			Response::json( array( 'ok' => false, 'error' => 'Unbekanntes Monitoring-Token.' ), 404 );
			return;
		}

		$secret = trim( Setting::get( 'uptime_secret', '' ) );

		if ( '' !== $secret && ! $this->verifySignature( $request, $secret ) ) {
			Response::json( array( 'ok' => false, 'error' => 'Signatur fehlt oder stimmt nicht.' ), 401 );
			return;
		}

		$body = $request->json();

		if ( ! $body ) {
			$body = $request->all();
			unset( $body['token'] );
		}

		// Probezustellung freundlich quittieren, statt den Prüfklick abzuweisen.
		if ( UptimeService::isTestPayload( $body ) ) {
			Response::json(
				array(
					'ok'      => true,
					'test'    => true,
					'message' => $isGlobal
						? 'Testzustellung angekommen. Die Verbindung steht. Bei echten Meldungen ordnet das Panel die Seite anhand der überwachten Adresse zu.'
						: sprintf( 'Testzustellung angekommen. Die Verbindung zu "%s" steht.', $site['name'] ),
				)
			);
			return;
		}

		$normalized = UptimeService::normalize( $body );

		if ( $isGlobal ) {
			$monitorUrl = null !== $normalized && '' !== $normalized['monitor_url']
				? $normalized['monitor_url']
				: UptimeService::monitorUrl( $body );

			if ( '' === $monitorUrl ) {
				Response::json(
					array(
						'ok'    => false,
						'error' => 'Sammel-Token verwendet, aber im Payload steht keine Adresse. '
							. 'Sende die überwachte URL mit (bei Uptime Kuma ist das monitor.url) '
							. 'oder nutze die seitenspezifische Monitoring-URL.',
					),
					422
				);
				return;
			}

			$site = SiteRepository::findByLooseUrl( $monitorUrl );

			if ( null === $site ) {
				Response::json(
					array(
						'ok'    => false,
						'error' => sprintf( 'Keine Seite mit dem Host "%s" im Panel registriert.', SiteRepository::hostKey( $monitorUrl ) ),
						'hint'  => 'Die Zuordnung läuft über den Hostnamen. Prüfe, ob die Seite im Panel dieselbe Domain hat.',
					),
					404
				);
				return;
			}
		}

		if ( null === $normalized ) {
			Response::json(
				array(
					'ok'    => false,
					'error' => 'Format nicht erkannt. Sende mindestens {"status":"up"} oder {"status":"down"}.',
				),
				422
			);
			return;
		}

		$changed = UptimeService::record(
			(int) $site['id'],
			$normalized['status'],
			array(
				'source'      => $normalized['source'],
				'http_code'   => $normalized['http_code'],
				'response_ms' => $normalized['response_ms'],
				'message'     => $normalized['message'],
				'raw'         => $body,
			)
		);

		Response::json(
			array(
				'ok'      => true,
				'site'    => array( 'id' => (int) $site['id'], 'name' => (string) $site['name'] ),
				'status'  => $normalized['status'],
				'source'  => $normalized['source'],
				'matched' => $isGlobal ? 'url' : 'token',
				'changed' => $changed,
			)
		);
	}

	/**
	 * Auslöser für Hoster ohne echten Cron-Zugang.
	 *
	 * URL: <panel>/api/cron/<token>
	 */
	public function cron( Request $request ): void {
		$expected = Setting::get( 'cron_token', '' );
		$provided = (string) ( $request->params['token'] ?? '' );

		if ( '' === $expected || ! hash_equals( $expected, $provided ) ) {
			Response::json( array( 'ok' => false, 'error' => 'Ungültiges Cron-Token.' ), 403 );
			return;
		}

		// Lange Läufe dürfen nicht am Zeitlimit scheitern.
		@set_time_limit( 600 );
		ignore_user_abort( true );

		$job = $request->string( 'job' );

		if ( '' !== $job ) {
			if ( ! in_array( $job, Scheduler::jobNames(), true ) ) {
				Response::json( array( 'ok' => false, 'error' => 'Unbekannte Aufgabe.' ), 422 );
				return;
			}

			Response::json( array( 'ok' => true, 'results' => array( $job => Scheduler::run( $job ) ) ) );
			return;
		}

		Response::json( array( 'ok' => true, 'results' => Scheduler::runDue() ) );
	}

	/* ------------------------------------------------- Abruf fürs Portal */

	/**
	 * Gesamtstand zum Abholen — Grundlage für Berichte im eigenen Kundenportal.
	 *
	 * GET <panel>/api/v1/export?days=30
	 * Authorization: Bearer <token>   (oder ?token=<token>)
	 *
	 * Liefert Kunden, Seiten, Verfügbarkeit, eingespielte Updates des Zeitraums,
	 * offene Updates und Sicherheitsbefunde in einem Zug.
	 */
	public function export( Request $request ): void {
		if ( ! $this->authorize( $request ) ) {
			return;
		}

		$days = max( 1, min( 365, $request->int( 'days', 30 ) ) );
		$from = gmdate( 'Y-m-d H:i:s', time() - $days * 86400 );
		$to   = nl_utc();

		$clientId = $request->int( 'client_id' );
		$sites    = SiteRepository::all( $clientId > 0 ? array( 'client_id' => $clientId ) : array() );
		$client   = $clientId > 0 ? ClientRepository::find( $clientId ) : null;

		$payload = ReportService::buildPayload( $client, $sites, $from, $to );

		$payload['clients'] = array_map(
			static fn( array $row ): array => array(
				'id'              => (int) $row['id'],
				'name'            => (string) $row['name'],
				'contact'         => (string) $row['contact_name'],
				'email'           => (string) $row['email'],
				'sites'           => (int) $row['site_count'],
				'pending_updates' => (int) $row['pending_updates'],
			),
			ClientRepository::all()
		);

		Response::json( $payload );
	}

	/**
	 * Nur die Seiten mit ihrem aktuellen Zustand — für Übersichten im Portal.
	 *
	 * GET <panel>/api/v1/sites
	 */
	public function sites( Request $request ): void {
		if ( ! $this->authorize( $request ) ) {
			return;
		}

		$out = array();

		foreach ( SiteRepository::all() as $site ) {
			$out[] = array(
				'id'              => (int) $site['id'],
				'name'            => (string) $site['name'],
				'url'             => (string) $site['url'],
				'client'          => $site['client_name'] ?? null,
				'client_id'       => $site['client_id'] ? (int) $site['client_id'] : null,
				'status'          => (string) $site['status'],
				'uptime_status'   => (string) $site['uptime_status'],
				'wp_version'      => (string) $site['wp_version'],
				'php_version'     => (string) $site['php_version'],
				'pending_updates' => (int) $site['pending_updates'],
				'security_score'  => (int) $site['security_score'],
				'tags'            => SiteRepository::tags( $site ),
				'last_sync_at'    => $site['last_sync_at'],
				'is_paused'       => ! empty( $site['is_paused'] ),
			);
		}

		Response::json( array( 'sites' => $out, 'stats' => SiteRepository::stats() ) );
	}

	/**
	 * Alle offenen Updates.
	 *
	 * GET <panel>/api/v1/updates
	 */
	public function updates( Request $request ): void {
		if ( ! $this->authorize( $request ) ) {
			return;
		}

		$rows = UpdateRepository::query(
			array(
				'site_id'   => $request->int( 'site_id' ),
				'client_id' => $request->int( 'client_id' ),
				'type'      => $request->string( 'type' ),
			)
		);

		Response::json(
			array(
				'count'   => count( $rows ),
				'by_type' => UpdateRepository::countsByType(),
				'updates' => array_map(
					static fn( array $row ): array => array(
						'site_id'         => (int) $row['site_id'],
						'site'            => (string) $row['site_name'],
						'type'            => (string) $row['type'],
						'slug'            => (string) $row['slug'],
						'name'            => (string) $row['name'],
						'current_version' => (string) $row['current_version'],
						'new_version'     => (string) $row['new_version'],
					),
					$rows
				),
			)
		);
	}

	/**
	 * Prüft das Zugriffstoken und beantwortet den Fehlerfall gleich selbst.
	 */
	private function authorize( Request $request ): bool {
		$expected = trim( Setting::get( 'api_token', '' ) );

		if ( '' === $expected ) {
			Response::json(
				array( 'ok' => false, 'error' => 'Es ist kein API-Token hinterlegt. Unter Einstellungen erzeugen.' ),
				503
			);
			return false;
		}

		$provided = trim( (string) preg_replace( '/^Bearer\s+/i', '', $request->header( 'Authorization' ) ) );

		if ( '' === $provided ) {
			$provided = $request->string( 'token' );
		}

		if ( '' === $provided || ! hash_equals( $expected, $provided ) ) {
			Response::json( array( 'ok' => false, 'error' => 'Token fehlt oder stimmt nicht.' ), 401 );
			return false;
		}

		return true;
	}

	/**
	 * Optionale HMAC-Prüfung eingehender Monitor-Webhooks.
	 */
	private function verifySignature( Request $request, string $secret ): bool {
		$provided = $request->header( 'X-NorthLab-Signature' );

		if ( '' === $provided ) {
			$provided = $request->header( 'X-Signature' );
		}
		if ( '' === $provided ) {
			return false;
		}

		$provided = (string) preg_replace( '/^sha256=/i', '', trim( $provided ) );
		$expected = hash_hmac( 'sha256', $request->rawBody(), $secret );

		return hash_equals( $expected, $provided );
	}
}
