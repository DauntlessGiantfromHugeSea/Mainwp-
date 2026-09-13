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

		if ( null !== $error ) {
			Session::flash( 'error', $error );
			Response::redirect( '/login' );
		}

		Session::forget( '_login_email' );

		$intended = (string) Session::get( '_intended', '/' );
		Session::forget( '_intended' );

		Response::redirect( str_starts_with( $intended, '/' ) ? $intended : '/' );
	}

	public function logout( Request $request ): void {
		Auth::logout();
		Response::redirect( '/login' );
	}
}
