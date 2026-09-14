<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Request;
use NorthLab\Core\Response;
use NorthLab\Repository\PushRepository;
use NorthLab\Service\PushService;

/**
 * An- und Abmeldung der Geräte für Push-Meldungen.
 *
 * Der Browser spricht hier mit JavaScript, deshalb durchweg JSON.
 */
final class PushController extends BaseController {

	/**
	 * Öffentlicher Schlüssel für den Browser.
	 *
	 * Kein Geheimnis — das Gegenstück bleibt im Panel. Ohne ihn kann der Browser
	 * aber kein Abonnement anlegen.
	 */
	public function key( Request $request ): void {
		Auth::requireLogin();

		Response::json(
			array(
				'ok'      => PushService::ready(),
				'key'     => PushService::ready() ? (string) \NorthLab\Core\Setting::get( 'push_public_key', '' ) : '',
				'devices' => PushRepository::countForUser( Auth::id() ),
			)
		);
	}

	public function subscribe( Request $request ): void {
		Auth::requireLogin();

		$endpoint = trim( (string) $request->post( 'endpoint', '' ) );
		$p256dh   = trim( (string) $request->post( 'p256dh', '' ) );
		$auth     = trim( (string) $request->post( 'auth', '' ) );

		if ( '' === $endpoint || ! filter_var( $endpoint, FILTER_VALIDATE_URL ) ) {
			Response::json( array( 'ok' => false, 'error' => 'Kein gültiger Push-Endpunkt.' ), 422 );
			return;
		}
		if ( '' === $p256dh || '' === $auth ) {
			Response::json( array( 'ok' => false, 'error' => 'Die Schlüssel des Geräts fehlen.' ), 422 );
			return;
		}
		// Der Endpunkt landet in einer Spalte mit 500 Zeichen.
		if ( strlen( $endpoint ) > 500 ) {
			Response::json( array( 'ok' => false, 'error' => 'Der Push-Endpunkt ist unerwartet lang.' ), 422 );
			return;
		}

		PushRepository::save(
			Auth::id(),
			$endpoint,
			$p256dh,
			$auth,
			substr( trim( (string) $request->post( 'label', '' ) ), 0, 191 )
		);

		Response::json( array( 'ok' => true, 'devices' => PushRepository::countForUser( Auth::id() ) ) );
	}

	public function unsubscribe( Request $request ): void {
		Auth::requireLogin();

		$endpoint = trim( (string) $request->post( 'endpoint', '' ) );

		if ( '' !== $endpoint ) {
			$existing = PushRepository::findByEndpoint( $endpoint );

			// Nur eigene Geraete abmelden — fremde gehen dieses Konto nichts an.
			if ( null !== $existing && (int) $existing['user_id'] === Auth::id() ) {
				PushRepository::delete( (int) $existing['id'] );
			}
		}

		Response::json( array( 'ok' => true, 'devices' => PushRepository::countForUser( Auth::id() ) ) );
	}

	/**
	 * Probemeldung an die eigenen Geräte.
	 */
	public function test( Request $request ): void {
		Auth::requireLogin();

		if ( ! PushService::ready() ) {
			$this->respond( $request, false, 'Push ist noch nicht eingerichtet.', '/settings#push' );
		}

		if ( 0 === PushRepository::countForUser( Auth::id() ) ) {
			$this->respond( $request, false, 'Für dieses Konto ist noch kein Gerät angemeldet.', '/settings#push' );
		}

		$result = PushService::test();

		$message = $result['sent'] > 0
			? sprintf( 'Probemeldung an %d Gerät(e) verschickt.', $result['sent'] )
			: sprintf(
				'Kein Gerät erreicht (%d fehlgeschlagen, %d entfernt). Ein Blick ins Protokoll nennt den Grund.',
				$result['failed'],
				$result['removed']
			);

		$this->respond( $request, $result['sent'] > 0, $message, '/settings#push' );
	}
}
