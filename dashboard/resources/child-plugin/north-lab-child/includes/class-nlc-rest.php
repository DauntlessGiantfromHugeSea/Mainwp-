<?php
defined( 'ABSPATH' ) || exit;

/**
 * REST-Schnittstelle, über die das Dashboard diese Seite steuert.
 */
class NLC_REST {

	/** @var NLC_REST|null */
	protected static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$signed = array( NLC_Auth::class, 'verify' );

		register_rest_route(
			NLC_REST_NS,
			'/connect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'connect' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'code'           => array( 'required' => true, 'type' => 'string' ),
					'public_key'     => array( 'required' => true, 'type' => 'string' ),
					'connection_id'  => array( 'required' => true, 'type' => 'string' ),
					'dashboard_url'  => array( 'required' => true, 'type' => 'string' ),
					'dashboard_name' => array( 'required' => false, 'type' => 'string' ),
				),
			)
		);

		$routes = array(
			'/ping'        => array( 'GET', 'ping' ),
			'/status'      => array( 'GET', 'status' ),
			'/update'      => array( 'POST', 'update' ),
			'/plugins'     => array( 'POST', 'plugins' ),
			'/themes'      => array( 'POST', 'themes' ),
			'/auto-update' => array( 'POST', 'auto_update' ),
			'/users'       => array( 'POST', 'users' ),
			'/content'     => array( 'POST', 'content' ),
			'/maintenance' => array( 'POST', 'maintenance' ),
			'/security'    => array( 'POST', 'security' ),
			'/disconnect'  => array( 'POST', 'disconnect' ),
		);

		foreach ( $routes as $route => $config ) {
			list( $method, $callback ) = $config;
			register_rest_route(
				NLC_REST_NS,
				$route,
				array(
					'methods'             => 'GET' === $method ? WP_REST_Server::READABLE : WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $callback ),
					'permission_callback' => $signed,
				)
			);
		}
	}

	/* ------------------------------------------------------------- Verbindung */

	/**
	 * Erstverbindung: Code prüfen, öffentlichen Schlüssel des Dashboards hinterlegen.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function connect( WP_REST_Request $request ) {
		if ( ! function_exists( 'openssl_verify' ) ) {
			return new WP_Error( 'nlc_no_openssl', 'Auf dieser Seite fehlt die OpenSSL-Erweiterung von PHP.', array( 'status' => 501 ) );
		}

		$code = (string) $request->get_param( 'code' );
		if ( ! NLC_Options::consume_connect_code( $code ) ) {
			return new WP_Error( 'nlc_bad_code', 'Verbindungscode ist ungültig oder abgelaufen.', array( 'status' => 403 ) );
		}

		$public_key = (string) $request->get_param( 'public_key' );
		if ( false === openssl_pkey_get_public( $public_key ) ) {
			return new WP_Error( 'nlc_bad_key', 'Übergebener öffentlicher Schlüssel ist ungültig.', array( 'status' => 400 ) );
		}

		NLC_Options::save_connection(
			array(
				'connection_id'  => sanitize_text_field( (string) $request->get_param( 'connection_id' ) ),
				'public_key'     => $public_key,
				'dashboard_url'  => esc_url_raw( (string) $request->get_param( 'dashboard_url' ) ),
				'dashboard_name' => sanitize_text_field( (string) $request->get_param( 'dashboard_name' ) ),
				'connected_at'   => gmdate( 'c' ),
			)
		);

		return rest_ensure_response(
			array(
				'connected' => true,
				'child'     => NLC_Info::collect( true ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function disconnect( WP_REST_Request $request ) {
		NLC_Options::clear_connection();
		return rest_ensure_response( array( 'disconnected' => true ) );
	}

	/* ---------------------------------------------------------------- Auslesen */

	public function ping( WP_REST_Request $request ) {
		return rest_ensure_response( NLC_Info::ping() );
	}

	public function status( WP_REST_Request $request ) {
		$force = rest_sanitize_boolean( $request->get_param( 'refresh' ) );
		return rest_ensure_response( NLC_Info::collect( $force ) );
	}

	/* ----------------------------------------------------------------- Aktionen */

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		if ( ! NLC_Options::setting( 'allow_updates' ) ) {
			return new WP_Error( 'nlc_updates_disabled', 'Updates über das Dashboard sind auf dieser Seite deaktiviert.', array( 'status' => 403 ) );
		}

		$items = $request->get_param( 'items' );
		$items = is_array( $items ) ? $items : array();

		if ( rest_sanitize_boolean( $request->get_param( 'all' ) ) ) {
			$available = NLC_Updates::available();
			$items     = array();
			foreach ( $available['core'] as $entry ) {
				$items[] = array( 'type' => 'core', 'slug' => 'wordpress' );
			}
			foreach ( $available['plugins'] as $entry ) {
				$items[] = array( 'type' => 'plugin', 'slug' => $entry['slug'] );
			}
			foreach ( $available['themes'] as $entry ) {
				$items[] = array( 'type' => 'theme', 'slug' => $entry['slug'] );
			}
			if ( $available['translations'] > 0 ) {
				$items[] = array( 'type' => 'translation', 'slug' => 'all' );
			}
		}

		if ( ! $items ) {
			return rest_ensure_response( array( 'results' => array(), 'message' => 'Nichts zu aktualisieren.' ) );
		}

		self::raise_limits();
		$results = NLC_Updates::run( $items );

		return rest_ensure_response(
			array(
				'results' => $results,
				'updates' => NLC_Updates::available(),
			)
		);
	}

	public function plugins( WP_REST_Request $request ) {
		$action  = sanitize_key( (string) $request->get_param( 'action' ) );
		$targets = (array) $request->get_param( 'targets' );

		if ( 'list' === $action ) {
			return rest_ensure_response( array( 'plugins' => NLC_Info::plugins() ) );
		}

		self::raise_limits();
		return rest_ensure_response( array( 'results' => NLC_Actions::plugins( $action, $targets ) ) );
	}

	public function themes( WP_REST_Request $request ) {
		$action  = sanitize_key( (string) $request->get_param( 'action' ) );
		$targets = (array) $request->get_param( 'targets' );

		if ( 'list' === $action ) {
			return rest_ensure_response( array( 'themes' => NLC_Info::themes() ) );
		}

		self::raise_limits();
		return rest_ensure_response( array( 'results' => NLC_Actions::themes( $action, $targets ) ) );
	}

	public function auto_update( WP_REST_Request $request ) {
		$kind    = 'theme' === $request->get_param( 'kind' ) ? 'theme' : 'plugin';
		$targets = (array) $request->get_param( 'targets' );
		$enable  = rest_sanitize_boolean( $request->get_param( 'enable' ) );

		return rest_ensure_response( array( 'results' => NLC_Actions::set_auto_update( $kind, $targets, $enable ) ) );
	}

	public function users( WP_REST_Request $request ) {
		$action = sanitize_key( (string) $request->get_param( 'action' ) );

		if ( '' === $action || 'list' === $action ) {
			return rest_ensure_response( array( 'users' => NLC_Actions::list_users( (array) $request->get_param( 'args' ) ) ) );
		}

		return rest_ensure_response( array( 'result' => NLC_Actions::user_action( $action, (array) $request->get_param( 'data' ) ) ) );
	}

	public function content( WP_REST_Request $request ) {
		$action = sanitize_key( (string) $request->get_param( 'action' ) );

		if ( '' === $action || 'list' === $action ) {
			return rest_ensure_response( array( 'content' => NLC_Actions::list_content( (array) $request->get_param( 'args' ) ) ) );
		}
		if ( 'create' === $action ) {
			return rest_ensure_response( array( 'result' => NLC_Actions::create_content( (array) $request->get_param( 'data' ) ) ) );
		}

		return new WP_Error( 'nlc_bad_action', 'Unbekannte Aktion.', array( 'status' => 400 ) );
	}

	public function maintenance( WP_REST_Request $request ) {
		if ( 'list' === $request->get_param( 'action' ) ) {
			return rest_ensure_response( array( 'tasks' => NLC_Maintenance::tasks() ) );
		}

		self::raise_limits();
		$tasks = (array) $request->get_param( 'tasks' );

		return rest_ensure_response( array( 'results' => NLC_Maintenance::run( $tasks ) ) );
	}

	public function security( WP_REST_Request $request ) {
		$action = sanitize_key( (string) $request->get_param( 'action' ) );

		if ( 'harden' === $action ) {
			return rest_ensure_response(
				array(
					'results' => NLC_Security::harden( (array) $request->get_param( 'checks' ) ),
					'scan'    => NLC_Security::scan(),
				)
			);
		}

		return rest_ensure_response( array( 'scan' => NLC_Security::scan() ) );
	}

	/**
	 * Langlaufende Aktionen brauchen mehr Luft.
	 */
	protected static function raise_limits() {
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			@set_time_limit( 600 );
		}
		wp_raise_memory_limit( 'admin' );
		ignore_user_abort( true );
	}
}
