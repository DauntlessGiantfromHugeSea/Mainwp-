<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Database;
use NorthLab\Core\Request;
use NorthLab\Repository\ActivityRepository;
use NorthLab\Repository\UserRepository;

final class UserController extends BaseController {

	public function index( Request $request ): void {
		Auth::requireAdmin();

		$this->view(
			'users/index',
			array(
				'users'    => UserRepository::all(),
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

		$this->respond( $request, null === $error, $error ?? 'Benutzer gespeichert.', '/users' );
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
