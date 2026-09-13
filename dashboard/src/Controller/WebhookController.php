<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Request;
use NorthLab\Repository\ClientRepository;
use NorthLab\Repository\WebhookRepository;
use NorthLab\Service\EventBus;
use NorthLab\Service\WebhookService;

final class WebhookController extends BaseController {

	public function index( Request $request ): void {
		Auth::requireLogin();

		$this->view(
			'webhooks/index',
			array(
				'webhooks'   => WebhookRepository::all(),
				'catalog'    => EventBus::catalog(),
				'clients'    => ClientRepository::options(),
				'deliveries' => WebhookRepository::deliveries( $request->int( 'webhook_id' ), 60 ),
				'queue'      => WebhookRepository::queueStats(),
				'selected'   => $request->int( 'webhook_id' ),
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

	public function retry( Request $request ): void {
		Auth::requireWrite();

		WebhookRepository::retry( (int) $request->params['id'] );

		$this->respond( $request, true, 'Zustellung erneut eingereiht.', $this->back( $request, '/webhooks' ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function payload( Request $request ): array {
		return array(
			'name'       => $request->string( 'name' ),
			'target_url' => $request->string( 'target_url' ),
			'secret'     => $request->string( 'secret' ),
			'events'     => $request->arrayOfStrings( 'events' ),
			'client_id'  => $request->int( 'client_id' ),
			'is_active'  => $request->bool( 'is_active' ),
		);
	}
}
