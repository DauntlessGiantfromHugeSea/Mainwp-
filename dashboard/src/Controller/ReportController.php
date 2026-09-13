<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Request;
use NorthLab\Core\Response;
use NorthLab\Repository\ClientRepository;
use NorthLab\Repository\ReportRepository;
use NorthLab\Service\ReportService;

final class ReportController extends BaseController {

	public function index( Request $request ): void {
		Auth::requireLogin();

		$this->view(
			'reports/index',
			array(
				'reports' => ReportRepository::recent( 60, $request->int( 'client_id' ) ),
				'clients' => ClientRepository::all(),
			)
		);
	}

	public function generate( Request $request ): void {
		Auth::requireWrite();

		$clientId = $request->int( 'client_id' );
		$days     = max( 1, min( 365, $request->int( 'days', 30 ) ) );
		$deliver  = $request->bool( 'deliver' );

		[ $id, $error ] = ReportService::generate(
			$clientId > 0 ? $clientId : null,
			gmdate( 'Y-m-d H:i:s', time() - $days * 86400 ),
			nl_utc(),
			$deliver
		);

		if ( 0 === $id ) {
			$this->respond( $request, false, (string) $error, '/reports' );
		}

		$this->respond(
			$request,
			true,
			$deliver ? 'Bericht erstellt und versendet.' : 'Bericht erstellt.',
			'/reports/' . $id
		);
	}

	public function show( Request $request ): void {
		Auth::requireLogin();

		$report = ReportRepository::find( (int) $request->params['id'] );

		if ( null === $report ) {
			Response::notFound( 'Bericht nicht gefunden.' );
			return;
		}

		// Der Bericht bringt sein eigenes Layout mit und wird direkt ausgeliefert.
		Response::html( (string) $report['html'] );
	}

	public function download( Request $request ): void {
		Auth::requireLogin();

		$report = ReportRepository::find( (int) $request->params['id'] );

		if ( null === $report ) {
			Response::notFound( 'Bericht nicht gefunden.' );
			return;
		}

		$slug = preg_replace( '/[^a-z0-9]+/i', '-', (string) $report['title'] ) ?? 'bericht';

		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . strtolower( trim( $slug, '-' ) ) . '.html"' );
		header( 'X-Content-Type-Options: nosniff' );

		echo (string) $report['html'];
		exit;
	}

	public function destroy( Request $request ): void {
		Auth::requireWrite();

		ReportRepository::delete( (int) $request->params['id'] );

		$this->respond( $request, true, 'Bericht gelöscht.', '/reports' );
	}
}
