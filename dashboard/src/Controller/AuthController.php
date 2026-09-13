<?php

declare( strict_types = 1 );

namespace NorthLab\Controller;

use NorthLab\Core\Auth;
use NorthLab\Core\Request;
use NorthLab\Core\Response;
use NorthLab\Core\Session;
use NorthLab\Core\Setting;
use NorthLab\Core\View;

final class AuthController extends BaseController {

	public function showLogin( Request $request ): void {
		if ( Auth::check() ) {
			Response::redirect( '/' );
		}

		View::render(
			'login',
			array(
				'agencyName' => Setting::get( 'agency_name', 'NorthLab' ),
				'agencyLogo' => Setting::get( 'agency_logo_url', '' ),
				'flash'      => Session::takeFlash(),
				'email'      => (string) Session::get( '_login_email', '' ),
			),
			'layout/auth'
		);
	}

	public function login( Request $request ): void {
		$email    = $request->string( 'email' );
		$password = (string) $request->post( 'password', '' );

		Session::set( '_login_email', $email );

		$error = Auth::attempt( $email, $password, $request );

		if ( Auth::CHALLENGE_REQUIRED === $error ) {
			Session::forget( '_login_email' );
			Response::redirect( '/login/2fa' );
		}

		if ( null !== $error ) {
			Session::flash( 'error', $error );
			Response::redirect( '/login' );
		}

		Session::forget( '_login_email' );

		$intended = (string) Session::get( '_intended', '/' );
		Session::forget( '_intended' );

		Response::redirect( str_starts_with( $intended, '/' ) ? $intended : '/' );
	}

	/**
	 * Zweiter Faktor nach erfolgreicher Passwortprüfung.
	 */
	public function showChallenge( Request $request ): void {
		if ( Auth::check() ) {
			Response::redirect( '/' );
		}

		$pending = Auth::pendingUser();

		if ( null === $pending ) {
			Session::flash( 'error', 'Bitte zuerst mit E-Mail und Passwort anmelden.' );
			Response::redirect( '/login' );
		}

		View::render(
			'login-2fa',
			array(
				'agencyName' => Setting::get( 'agency_name', 'NorthLab' ),
				'agencyLogo' => Setting::get( 'agency_logo_url', '' ),
				'email'      => (string) $pending['email'],
				'flash'      => Session::takeFlash(),
			),
			'layout/auth'
		);
	}

	public function challenge( Request $request ): void {
		$error = Auth::completeChallenge( (string) $request->post( 'code', '' ), $request );

		if ( null !== $error ) {
			Session::flash( 'error', $error );
			Response::redirect( null === Auth::pendingUser() ? '/login' : '/login/2fa' );
		}

		$intended = (string) Session::get( '_intended', '/' );
		Session::forget( '_intended' );

		Response::redirect( str_starts_with( $intended, '/' ) ? $intended : '/' );
	}

	public function logout( Request $request ): void {
		Auth::logout();
		Response::redirect( '/login' );
	}
}
