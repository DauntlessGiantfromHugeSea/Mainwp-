<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Request;
use NorthLab\Core\Response;
use NorthLab\Core\Setting;
use NorthLab\Repository\SiteRepository;
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
