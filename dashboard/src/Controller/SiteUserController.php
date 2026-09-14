<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Request;
use NorthLab\Core\Response;
use NorthLab\Core\Session;
use NorthLab\Repository\SiteRepository;
use NorthLab\Service\SiteUserService;

/**
 * WordPress-Benutzer einer betreuten Seite: anlegen, befristen, Passwort setzen,
 * löschen — und die Ein-Klick-Anmeldung.
 */
final class SiteUserController extends BaseController {

	public function index( Request $request ): void {
		Auth::requireLogin();

		$siteId = (int) $request->params['id'];
		Auth::requireSite( $siteId );
		$site = SiteRepository::find( $siteId );

		if ( null === $site ) {
			Response::notFound( 'Diese Seite ist nicht im Panel registriert.' );
			return;
		}

		$result = SiteUserService::all( $siteId );

		$this->view(
			'sites/users',
			array(
				'site'      => $site,
				'users'     => $result['users'],
				'loadError' => $result['error'],
				'roles'     => SiteUserService::ROLES,
				'durations' => SiteUserService::DURATIONS,
				// Nur einmal nach dem Anlegen oder Zuruecksetzen, dann weg.
				'secret'    => Session::takeOnce( 'site_user_secret' ),
			)
		);
	}

	public function store( Request $request ): void {
		Auth::requireWrite();

		$siteId = (int) $request->params['id'];
		Auth::requireSite( $siteId );

		$action = $request->string( 'user_action', 'create' );
		$userId = $request->int( 'user_id' );
		$target = '/sites/' . $siteId . '/users';

		switch ( $action ) {
			case 'create':
				$result = SiteUserService::create(
					$siteId,
					array(
						'login'           => $request->string( 'login' ),
						'email'           => $request->string( 'email' ),
						'role'            => $request->string( 'role', 'subscriber' ),
						'first_name'      => $request->string( 'first_name' ),
						'last_name'       => $request->string( 'last_name' ),
						'expires_minutes' => $request->int( 'expires_minutes' ),
					)
				);

				if ( ! $result['ok'] ) {
					Session::flashInput( $request->all() );
					$this->respond( $request, false, $result['error'], $target );
				}

				$this->handOverSecret( $request, $result, 'Benutzer angelegt.', $target );
				break;

			case 'set-password':
				$result = SiteUserService::setPassword( $siteId, $userId );

				if ( ! $result['ok'] ) {
					$this->respond( $request, false, $result['error'], $target );
				}

				$this->handOverSecret( $request, $result, 'Neues Passwort gesetzt.', $target );
				break;

			case 'reset-mail':
				$error = SiteUserService::sendReset( $siteId, $userId );
				$this->respond( $request, null === $error, $error ?? 'Zurücksetz-Mail an den Benutzer verschickt.', $target );
				break;

			case 'expiry':
				$error = SiteUserService::setExpiry( $siteId, $userId, $request->int( 'expires_minutes' ) );
				$this->respond( $request, null === $error, $error ?? 'Befristung geändert.', $target );
				break;

			case 'delete':
				$reassign = $request->int( 'reassign' );
				$error    = SiteUserService::delete( $siteId, $userId, $reassign > 0 ? $reassign : null );
				$this->respond( $request, null === $error, $error ?? 'Benutzer gelöscht.', $target );
				break;
		}

		$this->respond( $request, false, 'Unbekannte Aktion.', $target );
	}

	/**
	 * Ein-Klick-Anmeldung: Adresse holen und den Browser sofort dorthin schicken.
	 *
	 * Bewusst POST — ein GET wäre über ein fremdes Bild oder einen Link auslösbar
	 * und würde bei jedem Aufruf eine Anmeldung auf der Kundenseite erzeugen.
	 */
	public function login( Request $request ): void {
		Auth::requireWrite();

		$siteId = (int) $request->params['id'];
		Auth::requireSite( $siteId );

		$result = SiteUserService::loginLink( $siteId, $request->int( 'user_id' ) );

		if ( ! $result['ok'] ) {
			$this->respond( $request, false, $result['error'], $this->back( $request, '/sites/' . $siteId ) );
		}

		if ( $request->wantsJson() ) {
			Response::json( array( 'ok' => true, 'url' => $result['url'] ) );
			exit;
		}

		// Die Adresse ist ein Einmal-Geheimnis: weiterleiten, nicht anzeigen.
		Response::redirect( $result['url'] );
	}

	/**
	 * Erzeugte Zugangsdaten genau einmal an die nächste Seite reichen.
	 *
	 * @param array{login:string,password:string,expires_at:?int} $result
	 */
	private function handOverSecret( Request $request, array $result, string $message, string $target ): void {
		if ( $request->wantsJson() ) {
			$this->respond( $request, true, $message, $target, array( 'login' => $result['login'], 'password' => $result['password'] ) );
		}

		Session::once(
			'site_user_secret',
			json_encode(
				array(
					'login'      => $result['login'],
					'password'   => $result['password'],
					'expires_at' => $result['expires_at'],
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			)
		);

		$this->respond( $request, true, $message, $target );
	}
}
