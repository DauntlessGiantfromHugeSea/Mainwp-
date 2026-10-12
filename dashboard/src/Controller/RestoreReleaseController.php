<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Request;
use NorthLab\Core\Response;
use NorthLab\Repository\SiteRepository;
use NorthLab\Service\RestoreRunService;

/**
 * Der Weg, auf dem die Kundenseite ihren Freigabecode einlöst.
 *
 * Bewusst hier und nicht auf der Kundenseite: so verlässt das Geheimnis das
 * Panel nie. Die Kundenseite bekommt den Code nie zu Gesicht, kennt seinen
 * Hash nicht und kann ihn deshalb auch nicht in Ruhe durchprobieren — sie
 * muss für jeden Versuch hierher, und hier wird mitgezählt.
 *
 * Ohne Anmeldung erreichbar, wie die anderen /api/child-Wege: ausgewiesen
 * wird sich mit der Verbindungskennung der Seite. Die allein reicht aber
 * nicht, sie ist nur die Adresse — der Code ist der Nachweis.
 */
final class RestoreReleaseController extends BaseController {

	public function redeem( Request $request ): void {
		$site = $this->site( (string) $request->params['connection'] );

		if ( null === $site ) {
			// Dieselbe Antwort wie bei einem falschen Code: sonst verrät
			// die Unterscheidung, welche Verbindungskennungen es gibt.
			Response::json( array( 'ok' => false, 'error' => 'Der Code stimmt nicht oder ist abgelaufen.' ), 403 );
			return;
		}

		$ergebnis = RestoreRunService::redeem( (int) $site['id'], (string) $request->post( 'code', '' ) );

		if ( ! $ergebnis['ok'] ) {
			Response::json( array( 'ok' => false, 'error' => $ergebnis['error'] ), 403 );
			return;
		}

		Response::json(
			array(
				'ok'    => true,
				'scope' => $ergebnis['scope'],
				'label' => RestoreRunService::SCOPES[ $ergebnis['scope'] ] ?? $ergebnis['scope'],
			)
		);
	}

	/**
	 * Was die Kundenseite über einen laufenden Vorgang erfahren darf.
	 */
	public function status( Request $request ): void {
		$site = $this->site( (string) $request->params['connection'] );

		if ( null === $site ) {
			Response::json( array( 'running' => false ) );
			return;
		}

		Response::json( RestoreRunService::stateForSite( (int) $site['id'] ) );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function site( string $connectionId ): ?array {
		$connectionId = trim( $connectionId );

		if ( '' === $connectionId || ! preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $connectionId ) ) {
			return null;
		}

		return SiteRepository::findByConnectionId( $connectionId );
	}
}
