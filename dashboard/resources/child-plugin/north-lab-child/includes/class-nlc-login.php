<?php
defined( 'ABSPATH' ) || exit;

/**
 * Ein-Klick-Anmeldung aus dem Panel.
 *
 * Das Panel fordert signiert eine Einmal-Adresse an; diese Seite hinterlegt
 * dazu einen Transient und gibt die Adresse zurueck. Wer sie oeffnet, wird
 * angemeldet und sofort ins Backend geleitet.
 *
 * Der Token steht zwangslaeufig in der URL und landet damit im Zugriffsprotokoll
 * des Webservers. Deshalb: nur wenige Sekunden gueltig, genau einmal verwendbar
 * und beim Einloesen sofort geloescht.
 */
class NLC_Login {

	const QUERY_VAR    = 'northlab-login';
	const TRANSIENT    = 'nlc_login_';
	const TTL          = 90;
	const META_LAST    = 'nlc_last_login';
	const META_PANEL   = 'nlc_panel_login_at';

	public function hooks() {
		// Frueh genug, um vor Weiterleitungen und dem Wartungsmodus zu greifen.
		add_action( 'init', array( $this, 'maybe_login' ), 1 );
	}

	/**
	 * Erzeugt eine Einmal-Adresse fuer den gewuenschten Benutzer.
	 *
	 * @param array<string,mixed> $data user_id oder login; ohne Angabe der aelteste Administrator.
	 * @return array<string,mixed>
	 */
	public static function issue( array $data ) {
		if ( ! NLC_Options::setting( 'allow_autologin' ) ) {
			return array(
				'ok'    => false,
				'error' => 'Die Ein-Klick-Anmeldung ist auf dieser Seite deaktiviert.',
			);
		}

		$user = self::resolve_user( $data );

		if ( ! $user ) {
			return array( 'ok' => false, 'error' => 'Benutzer nicht gefunden.' );
		}

		// Ein gesperrtes oder rollenloses Konto darf auch das Panel nicht oeffnen.
		if ( empty( $user->roles ) ) {
			return array( 'ok' => false, 'error' => 'Dieses Konto hat keine Rolle und kann sich nicht anmelden.' );
		}

		$token = bin2hex( random_bytes( 32 ) );

		set_transient(
			self::TRANSIENT . hash( 'sha256', $token ),
			array(
				'user'   => (int) $user->ID,
				'issued' => time(),
			),
			self::TTL
		);

		return array(
			'ok'         => true,
			'url'        => add_query_arg( self::QUERY_VAR, $token, home_url( '/' ) ),
			'expires_in' => self::TTL,
			'user'       => array(
				'id'    => (int) $user->ID,
				'login' => $user->user_login,
				'roles' => array_values( $user->roles ),
			),
		);
	}

	/**
	 * @param array<string,mixed> $data
	 * @return WP_User|null
	 */
	private static function resolve_user( array $data ) {
		if ( ! empty( $data['user_id'] ) ) {
			$user = get_userdata( (int) $data['user_id'] );
			return $user ?: null;
		}

		if ( ! empty( $data['login'] ) ) {
			$user = get_user_by( 'login', (string) $data['login'] );
			return $user ?: null;
		}

		// Ohne Angabe: der zuerst angelegte Administrator.
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'number'  => 1,
				'orderby' => 'ID',
				'order'   => 'ASC',
			)
		);

		return $admins ? $admins[0] : null;
	}

	public function maybe_login() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- der Token IST der Nachweis.
		$token = isset( $_GET[ self::QUERY_VAR ] ) ? (string) $_GET[ self::QUERY_VAR ] : '';

		if ( '' === $token ) {
			return;
		}

		// Ein bereits angemeldeter Benutzer braucht nichts weiter als die Weiterleitung.
		if ( is_user_logged_in() ) {
			self::redirect_to_admin();
		}

		if ( ! preg_match( '/^[0-9a-f]{64}$/', $token ) ) {
			self::deny();
		}

		$key     = self::TRANSIENT . hash( 'sha256', $token );
		$payload = get_transient( $key );

		// Sofort verbrauchen, noch bevor irgendetwas schiefgehen kann.
		delete_transient( $key );

		if ( ! is_array( $payload ) || empty( $payload['user'] ) ) {
			self::deny();
		}

		if ( time() - (int) $payload['issued'] > self::TTL ) {
			self::deny();
		}

		$user = get_userdata( (int) $payload['user'] );

		if ( ! $user ) {
			self::deny();
		}

		wp_set_current_user( $user->ID );
		// Sitzungscookie ohne "Angemeldet bleiben" — der Zugang endet mit dem Browser.
		wp_set_auth_cookie( $user->ID, false );
		do_action( 'wp_login', $user->user_login, $user );

		update_user_meta( $user->ID, self::META_LAST, gmdate( 'c' ) );
		update_user_meta( $user->ID, self::META_PANEL, gmdate( 'c' ) );

		self::redirect_to_admin();
	}

	private static function redirect_to_admin() {
		// Ohne Token in der Adresszeile — sonst steht er im Verlauf und im Referrer.
		wp_safe_redirect( admin_url() );
		exit;
	}

	private static function deny() {
		wp_die(
			esc_html__( 'Dieser Anmeldelink ist abgelaufen oder wurde bereits benutzt.', 'north-lab-child' ),
			esc_html__( 'Anmeldung nicht möglich', 'north-lab-child' ),
			array( 'response' => 403, 'back_link' => false )
		);
	}
}
