<?php

declare( strict_types = 1 );

namespace NorthLab\Core;

/**
 * PHP-Session mit sicheren Cookie-Einstellungen.
 */
final class Session {

	private static bool $started = false;

	public static function start(): void {
		if ( self::$started || PHP_SAPI === 'cli' ) {
			return;
		}
		if ( PHP_SESSION_ACTIVE === session_status() ) {
			self::$started = true;
			return;
		}

		$secure = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] )
			|| ( ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) === 'https' );

		session_name( (string) Config::get( 'security.session_name', 'northlab_session' ) );
		session_set_cookie_params(
			array(
				'lifetime' => 0,
				'path'     => '/',
				'domain'   => '',
				'secure'   => $secure,
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		session_start();
		self::$started = true;
	}

	public static function get( string $key, mixed $default = null ): mixed {
		self::start();
		return $_SESSION[ $key ] ?? $default;
	}

	public static function set( string $key, mixed $value ): void {
		self::start();
		$_SESSION[ $key ] = $value;
	}

	public static function forget( string $key ): void {
		self::start();
		unset( $_SESSION[ $key ] );
	}

	public static function id(): string {
		self::start();
		return (string) session_id();
	}

	public static function regenerate(): void {
		self::start();
		session_regenerate_id( true );
	}

	public static function destroy(): void {
		self::start();
		$_SESSION = array();

		if ( ini_get( 'session.use_cookies' ) ) {
			$params = session_get_cookie_params();
			setcookie(
				session_name(),
				'',
				array(
					'expires'  => time() - 42000,
					'path'     => $params['path'],
					'domain'   => $params['domain'],
					'secure'   => $params['secure'],
					'httponly' => $params['httponly'],
					'samesite' => $params['samesite'] ?? 'Lax',
				)
			);
		}

		session_destroy();
		self::$started = false;
	}

	/* ------------------------------------------------------------- Flash */

	public static function flash( string $type, string $message ): void {
		self::start();
		$_SESSION['_flash'][] = array( 'type' => $type, 'message' => $message );
	}

	/**
	 * @return array<int,array{type:string,message:string}>
	 */
	public static function takeFlash(): array {
		self::start();
		$messages = $_SESSION['_flash'] ?? array();
		unset( $_SESSION['_flash'] );
		return is_array( $messages ) ? $messages : array();
	}

	/**
	 * Formulareingaben für die Wiederanzeige nach einem Fehler merken.
	 *
	 * @param array<string,mixed> $input
	 */
	public static function flashInput( array $input ): void {
		unset( $input['_token'], $input['password'], $input['password_confirm'], $input['connect_code'] );
		self::set( '_old', $input );
	}

	/**
	 * Einmalwert fuer genau die naechste Anfrage hinterlegen.
	 *
	 * Fuer Dinge, die angezeigt und danach vergessen werden sollen - etwa ein
	 * frisch erzeugtes Passwort, das nirgends dauerhaft landen darf.
	 */
	public static function once( string $key, string $value ): void {
		self::start();
		$_SESSION['_once'][ $key ] = $value;
	}

	public static function takeOnce( string $key ): ?string {
		self::start();

		$value = $_SESSION['_once'][ $key ] ?? null;
		unset( $_SESSION['_once'][ $key ] );

		return is_string( $value ) ? $value : null;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function oldInput(): array {
		$old = self::get( '_old', array() );
		self::forget( '_old' );
		return is_array( $old ) ? $old : array();
	}
}
