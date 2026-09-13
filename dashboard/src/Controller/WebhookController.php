<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Config;
use NorthLab\Core\Crypto;
use NorthLab\Core\Request;
use NorthLab\Core\Setting;
use NorthLab\Repository\ClientRepository;
use NorthLab\Repository\WebhookRepository;
use NorthLab\Service\EventBus;
use NorthLab\Service\WebhookService;

final class WebhookController extends BaseController {

	public function index( Request $request ): void {
		Auth::requireLogin();

		// Die Empfangs-URL fürs Monitoring soll einfach da sein, wenn man die
		// Seite aufruft — ein zusätzlicher Klick vorher bringt niemandem etwas.
		$token = trim( Setting::get( 'uptime_global_token', '' ) );

		if ( '' === $token && Auth::isAdmin() ) {
			$token = Crypto::monitorToken();
			Setting::set( 'uptime_global_token', $token );
		}

		$this->view(
			'webhooks/index',
			array(
				'webhooks'   => WebhookRepository::all(),
				'catalog'    => EventBus::catalog(),
				'clients'    => ClientRepository::options(),
				'deliveries' => WebhookRepository::deliveries( $request->int( 'webhook_id' ), 40 ),
				'queue'      => WebhookRepository::queueStats(),
				'selected'   => $request->int( 'webhook_id' ),
				'incomingUrl' => '' !== $token
					? rtrim( (string) Config::get( 'app.url', '' ), '/' ) . '/api/uptime/' . $token
					: '',
				'apiToken'    => Auth::isAdmin() ? Setting::get( 'api_token', '' ) : '',
				'panelUrl'    => rtrim( (string) Config::get( 'app.url', '' ), '/' ),
			)
		);
	}

	public function store( Request $request ): void {
		Auth::requireWrite();

		[ $id, $error ] = WebhookRepository::create( $this->payload( $request ) );

		$this->respond( $request, 0 !== $id, $error ?? 'Webhook angelegt.', '/webhooks' );
	}

	public function update( Request $request ): void {
		Auth::requireWrite();

		$error = WebhookRepository::update( (int) $request->params['id'], $this->payload( $request ) );

		$this->respond( $request, null === $error, $error ?? 'Webhook gespeichert.', '/webhooks' );
	}

	public function destroy( Request $request ): void {
		Auth::requireWrite();

		WebhookRepository::delete( (int) $request->params['id'] );

		$this->respond( $request, true, 'Webhook entfernt.', '/webhooks' );
	}

	public function test( Request $request ): void {
		Auth::requireWrite();

		$result = WebhookService::test( (int) $request->params['id'] );

		$this->respond(
			$request,
			$result['success'],
			$result['success']
				? 'Testzustellung erfolgreich: ' . $result['message']
				: 'Testzustellung fehlgeschlagen: ' . $result['message'],
			'/webhooks'
		);
	}

	/**
	 * Erstbefüllung: den kompletten Stand an alle Endpunkte schicken.
	 */
	public function snapshot( Request $request ): void {
		Auth::requireWrite();

		$queued = WebhookService::sendSnapshot(
			max( 1, min( 365, $request->int( 'days', 30 ) ) ),
			$request->int( 'webhook_id' )
		);

		if ( 0 === $queued ) {
			$this->respond(
				$request,
				false,
				'Kein passender Endpunkt. Der Gesamtstand geht nur an aktive Endpunkte ohne Kundenbindung, '
					. 'die das Ereignis "snapshot.full" abonniert haben.',
				'/webhooks'
			);
		}

		$this->respond(
			$request,
			true,
			sprintf( 'Gesamtstand an %d Endpunkt(e) eingereiht — wird binnen einer Minute zugestellt.', $queued ),
			'/webhooks'
		);
	}

	public function retry( Request $request ): void {
		Auth::requireWrite();

		WebhookRepository::retry( (int) $request->params['id'] );

		$this->respond( $request, true, 'Zustellung erneut eingereiht.', $this->back( $request, '/webhooks' ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function payload( Request $request ): array {
		$events = $request->arrayOfStrings( 'events' );

		// Wer nichts auswählt, will alles — das ist der Normalfall.
		if ( ! $events ) {
			$events = array_keys( EventBus::catalog() );
		}

		return array(
			'name'       => $request->string( 'name' ),
			'target_url' => $request->string( 'target_url' ),
			'secret'     => $request->string( 'secret' ),
			'events'     => $events,
			'client_id'  => $request->int( 'client_id' ),
			'is_active'  => ! $request->bool( 'has_active_field' ) || $request->bool( 'is_active' ),
		);
	}
}
