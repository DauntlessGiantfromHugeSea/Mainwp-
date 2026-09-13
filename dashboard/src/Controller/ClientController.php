<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Request;
use NorthLab\Core\Response;
use NorthLab\Repository\ClientRepository;
use NorthLab\Repository\ReportRepository;
use NorthLab\Repository\SiteRepository;
use NorthLab\Repository\UptimeRepository;

final class ClientController extends BaseController {

	public function index( Request $request ): void {
		Auth::requireLogin();

		$this->view( 'clients/index', array( 'clients' => ClientRepository::all( Auth::visibleSiteIds() ) ) );
	}

	public function show( Request $request ): void {
		Auth::requireLogin();

		$clientId = (int) $request->params['id'];
		$client   = ClientRepository::find( $clientId );

		if ( null === $client ) {
			Response::notFound( 'Kunde nicht gefunden.' );
			return;
		}

		// Ein eingeschränktes Konto sieht einen Kunden nur, wenn ihm mindestens
		// eine von dessen Seiten zugeordnet ist.
		if ( ! Auth::seesAllSites() && ! SiteRepository::all( array( 'client_id' => $clientId, 'site_ids' => Auth::visibleSiteIds() ) ) ) {
			Response::forbidden( 'Dieser Kunde ist deinem Konto nicht zugeordnet.' );
			return;
		}

		$from  = gmdate( 'Y-m-d H:i:s', time() - 30 * 86400 );
		$to    = nl_utc();
		$sites = SiteRepository::all( array( 'client_id' => $clientId, 'site_ids' => Auth::visibleSiteIds() ) );

		$uptime = array();
		foreach ( $sites as $site ) {
			$uptime[ (int) $site['id'] ] = UptimeRepository::availability( (int) $site['id'], $from, $to );
		}

		$this->view(
			'clients/show',
			array(
				'client'  => $client,
				'sites'   => $sites,
				'uptime'  => $uptime,
				'reports' => ReportRepository::recent( 20, $clientId ),
			)
		);
	}

	public function store( Request $request ): void {
		Auth::requireWrite();

		[ $id, $error ] = ClientRepository::create( $this->payload( $request ) );

		$this->respond(
			$request,
			0 !== $id,
			$error ?? 'Kunde angelegt.',
			0 !== $id ? '/clients/' . $id : '/clients'
		);
	}

	public function update( Request $request ): void {
		Auth::requireWrite();

		$clientId = (int) $request->params['id'];
		$error    = ClientRepository::update( $clientId, $this->payload( $request ) );

		$this->respond( $request, null === $error, $error ?? 'Kunde gespeichert.', '/clients/' . $clientId );
	}

	public function destroy( Request $request ): void {
		Auth::requireWrite();

		ClientRepository::delete( (int) $request->params['id'] );

		$this->respond( $request, true, 'Kunde entfernt. Die zugeordneten Seiten bleiben bestehen.', '/clients' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function payload( Request $request ): array {
		return array(
			'name'                  => $request->string( 'name' ),
			'contact_name'          => $request->string( 'contact_name' ),
			'email'                 => $request->string( 'email' ),
			'report_frequency'      => $request->string( 'report_frequency' ),
			'report_webhook_url'    => $request->string( 'report_webhook_url' ),
			'report_webhook_secret' => $request->string( 'report_webhook_secret' ),
			'report_email_enabled'  => $request->bool( 'report_email_enabled' ),
			'notes'                 => $request->string( 'notes' ),
		);
	}
}
