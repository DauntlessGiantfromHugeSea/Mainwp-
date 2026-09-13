<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Config;
use NorthLab\Core\Database;
use NorthLab\Core\Request;
use NorthLab\Core\Session;
use NorthLab\Core\Setting;
use NorthLab\Core\Totp;
use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\SiteRepository;
use NorthLab\Repository\UserRepository;

final class UserController extends BaseController {

	public function index( Request $request ): void {
		Auth::requireAdmin();

		$users      = UserRepository::all();
		$assignment = array();

		foreach ( $users as $user ) {
			$assignment[ (int) $user['id'] ] = UserRepository::siteIds( (int) $user['id'] );
		}

		$this->view(
			'users/index',
			array(
				'users'      => $users,
				'assignment' => $assignment,
				'allSites'   => SiteRepository::all(),
				'roles'    => UserRepository::ROLES,
				'sessions' => Database::select(
					'SELECT s.*, u.email FROM `' . Database::table( 'sessions' ) . '` s
					 INNER JOIN `' . Database::table( 'users' ) . '` u ON u.id = s.user_id
					 WHERE s.expires_at > :now ORDER BY s.created_at DESC LIMIT 50',
					array( 'now' => nl_utc() )
				),
			)
		);
	}

	public function store( Request $request ): void {
		Auth::requireAdmin();

		[ $id, $error ] = UserRepository::create(
			$request->string( 'email' ),
			$request->string( 'name' ),
			(string) $request->post( 'password', '' ),
			$request->string( 'role', 'member' )
		);

		if ( 0 !== $id ) {
			ActivityRepository::log( 'user.created', sprintf( 'Benutzer %s angelegt.', $request->string( 'email' ) ) );
		}

		$this->respond( $request, 0 !== $id, $error ?? 'Benutzer angelegt.', '/users' );
	}

	public function update( Request $request ): void {
		Auth::requireAdmin();

		$userId = (int) $request->params['id'];
		$user   = UserRepository::find( $userId );

		if ( null === $user ) {
			$this->respond( $request, false, 'Benutzer nicht gefunden.', '/users' );
		}

		// Der letzte aktive Administrator darf sich nicht selbst aussperren.
		$losesAdmin = 'admin' === $user['role']
			&& ( 'admin' !== $request->string( 'role', 'member' ) || ! $request->bool( 'is_active' ) );

		if ( $losesAdmin && UserRepository::adminCount() <= 1 ) {
			$this->respond( $request, false, 'Es muss mindestens ein aktiver Administrator bestehen bleiben.', '/users' );
		}

		$error = UserRepository::update(
			$userId,
			array(
				'name'      => $request->string( 'name' ),
				'email'     => $request->string( 'email' ),
				'role'      => $request->string( 'role', 'member' ),
				'is_active' => $request->bool( 'is_active' ),
			)
		);

		$password = (string) $request->post( 'password', '' );

		if ( null === $error && '' !== $password ) {
			$error = UserRepository::updatePassword( $userId, $password );

			if ( null === $error ) {
				// Passwortwechsel beendet alle bestehenden Sitzungen dieses Kontos.
				Auth::logoutEverywhere( $userId );
			}
		}

		if ( null === $error ) {
			$access = $request->string( 'site_access', 'all' );
			UserRepository::setSiteAccess( $userId, $access );

			if ( 'assigned' === $access ) {
				UserRepository::setSites( $userId, array_map( 'intval', $request->arrayOfStrings( 'site_ids' ) ) );
			} else {
				UserRepository::setSites( $userId, array() );
			}
		}

		$this->respond( $request, null === $error, $error ?? 'Benutzer gespeichert.', '/users' );
	}

	/**
	 * Zwei-Faktor-Anmeldung eines Kontos zurücksetzen — für den Fall,
	 * dass jemand Gerät und Ersatzcodes verloren hat.
	 */
	public function resetTwoFactor( Request $request ): void {
		Auth::requireAdmin();

		$userId = (int) $request->params['id'];
		$user   = UserRepository::find( $userId );

		if ( null === $user ) {
			$this->respond( $request, false, 'Benutzer nicht gefunden.', '/users' );
		}

		UserRepository::disableTotp( $userId );
		Auth::logoutEverywhere( $userId );

		ActivityRepository::log(
			'user.2fa_reset',
			sprintf( 'Zwei-Faktor-Anmeldung von %s zurückgesetzt.', $user['email'] ),
			array( 'level' => 'warning' )
		);

		$this->respond( $request, true, 'Zwei-Faktor-Anmeldung zurückgesetzt. Das Konto muss sie neu einrichten.', '/users' );
	}

	public function destroy( Request $request ): void {
		Auth::requireAdmin();

		$userId = (int) $request->params['id'];

		if ( $userId === Auth::id() ) {
			$this->respond( $request, false, 'Das eigene Konto kann nicht gelöscht werden.', '/users' );
		}

		$user = UserRepository::find( $userId );

		if ( null !== $user && 'admin' === $user['role'] && UserRepository::adminCount() <= 1 ) {
			$this->respond( $request, false, 'Der letzte Administrator kann nicht gelöscht werden.', '/users' );
		}

		UserRepository::delete( $userId );
		ActivityRepository::log( 'user.deleted', sprintf( 'Benutzer %s gelöscht.', $user['email'] ?? $userId ) );

		$this->respond( $request, true, 'Benutzer gelöscht.', '/users' );
	}

	/**
	 * Einrichtungsseite für die Zwei-Faktor-Anmeldung des eigenen Kontos.
	 */
	public function showTwoFactor( Request $request ): void {
		Auth::requireLogin();

		$user = UserRepository::find( Auth::id() );

		if ( null === $user ) {
			$this->respond( $request, false, 'Konto nicht gefunden.', '/' );
		}

		$enabled = ! empty( $user['totp_enabled'] );
		$secret  = UserRepository::totpSecret( $user );

		// Solange nicht bestätigt wurde, bekommt jeder Aufruf ein frisches Geheimnis
		// erst dann, wenn noch keins vorliegt — sonst wäre ein halb eingerichteter
		// Authenticator-Eintrag nach dem Neuladen wertlos.
		if ( ! $enabled && '' === $secret ) {
			$secret = Totp::generateSecret();
			UserRepository::startTotpSetup( (int) $user['id'], $secret );
		}

		$this->view(
			'users/two-factor',
			array(
				'user'          => $user,
				'enabled'       => $enabled,
				'secret'        => $secret,
				'secretGrouped' => Totp::formatSecret( $secret ),
				'otpauth'       => Totp::uri(
					$secret,
					(string) $user['email'],
					Setting::get( 'agency_name', 'NorthLab' ) . ' Panel'
				),
				'recoveryLeft'  => UserRepository::recoveryCodesLeft( $user ),
				'freshCodes'    => (array) Session::get( '_fresh_recovery', array() ),
			)
		);

		Session::forget( '_fresh_recovery' );
	}

	public function twoFactor( Request $request ): void {
		Auth::requireLogin();

		$userId = Auth::id();
		$user   = UserRepository::find( $userId );

		if ( null === $user ) {
			$this->respond( $request, false, 'Konto nicht gefunden.', '/' );
		}

		switch ( $request->string( 'action' ) ) {
			case 'confirm':
				$secret = UserRepository::totpSecret( $user );

				if ( '' === $secret ) {
					$this->respond( $request, false, 'Es liegt kein Geheimnis vor. Bitte die Einrichtung neu starten.', '/profile/2fa' );
				}
				if ( ! Totp::verify( $secret, (string) $request->post( 'code', '' ) ) ) {
					$this->respond(
						$request,
						false,
						'Der Code stimmt nicht. Prüfe, ob die Uhrzeit deines Geräts korrekt läuft.',
						'/profile/2fa'
					);
				}

				$codes = UserRepository::confirmTotp( $userId );
				Session::set( '_fresh_recovery', $codes );

				ActivityRepository::log( 'user.2fa_enabled', 'Zwei-Faktor-Anmeldung aktiviert.' );

				$this->respond( $request, true, 'Zwei-Faktor-Anmeldung ist aktiv. Sichere jetzt die Ersatzcodes.', '/profile/2fa' );

			case 'disable':
				if ( ! password_verify( (string) $request->post( 'password', '' ), (string) $user['password_hash'] ) ) {
					$this->respond( $request, false, 'Zum Abschalten bitte das eigene Passwort bestätigen.', '/profile/2fa' );
				}

				UserRepository::disableTotp( $userId );
				ActivityRepository::log( 'user.2fa_disabled', 'Zwei-Faktor-Anmeldung abgeschaltet.', array( 'level' => 'warning' ) );

				$this->respond( $request, true, 'Zwei-Faktor-Anmeldung abgeschaltet.', '/profile/2fa' );

			case 'recovery':
				if ( empty( $user['totp_enabled'] ) ) {
					$this->respond( $request, false, 'Ersatzcodes gibt es erst mit aktiver Zwei-Faktor-Anmeldung.', '/profile/2fa' );
				}

				Session::set( '_fresh_recovery', UserRepository::regenerateRecoveryCodes( $userId ) );

				$this->respond( $request, true, 'Neue Ersatzcodes erzeugt. Die alten gelten nicht mehr.', '/profile/2fa' );

			case 'restart':
				UserRepository::disableTotp( $userId );

				$this->respond( $request, true, 'Einrichtung zurückgesetzt.', '/profile/2fa' );
		}

		$this->respond( $request, false, 'Unbekannte Aktion.', '/profile/2fa' );
	}

	/**
	 * Eigenes Profil: Name, E-Mail und Passwort.
	 */
	public function updateProfile( Request $request ): void {
		Auth::requireLogin();

		$userId = Auth::id();

		$error = UserRepository::update(
			$userId,
			array(
				'name'  => $request->string( 'name' ),
				'email' => $request->string( 'email' ),
			)
		);

		$current = (string) $request->post( 'current_password', '' );
		$new     = (string) $request->post( 'password', '' );

		if ( null === $error && '' !== $new ) {
			$user = UserRepository::find( $userId );

			if ( null === $user || ! password_verify( $current, (string) $user['password_hash'] ) ) {
				$error = 'Das aktuelle Passwort ist falsch.';
			} else {
				$error = UserRepository::updatePassword( $userId, $new );
			}
		}

		$this->respond( $request, null === $error, $error ?? 'Profil gespeichert.', $this->back( $request, '/users' ) );
	}
}
