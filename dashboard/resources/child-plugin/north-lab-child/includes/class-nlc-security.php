<?php
defined( 'ABSPATH' ) || exit;

/**
 * Leichtgewichtiger Sicherheits- und Härtungs-Check.
 *
 * Bewusst ohne Dateiscanner: die Prüfungen sind schnell genug, um bei jedem Sync mitzulaufen.
 */
class NLC_Security {

	/**
	 * @return array{score:int,checks:array<int,array<string,mixed>>}
	 */
	public static function scan() {
		$checks = array(
			self::check_ssl(),
			self::check_wp_version_exposed(),
			self::check_file_editor(),
			self::check_debug_display(),
			self::check_directory_listing(),
			self::check_admin_username(),
			self::check_php_version(),
			self::check_core_version(),
			self::check_xmlrpc(),
			self::check_registration(),
			self::check_db_prefix(),
			self::check_readme_exposed(),
			self::check_pending_updates(),
			self::check_inactive_extensions(),
		);

		$total  = count( $checks );
		$passed = 0;
		foreach ( $checks as $check ) {
			if ( 'ok' === $check['status'] ) {
				$passed++;
			}
		}

		return array(
			'score'  => $total ? (int) round( ( $passed / $total ) * 100 ) : 100,
			'passed' => $passed,
			'total'  => $total,
			'checks' => $checks,
		);
	}

	/**
	 * Behebt die Punkte, die sich gefahrlos automatisch beheben lassen.
	 *
	 * @param array<int,string> $ids
	 * @return array<int,array<string,mixed>>
	 */
	public static function harden( array $ids ) {
		$results = array();

		foreach ( $ids as $id ) {
			switch ( $id ) {
				case 'wp_version_exposed':
					update_option( 'nlc_hide_generator', 1 );
					$results[] = self::fix_result( $id, true, 'Generator-Meta-Tag wird ausgeblendet.' );
					break;

				case 'xmlrpc_enabled':
					update_option( 'nlc_disable_xmlrpc', 1 );
					$results[] = self::fix_result( $id, true, 'XML-RPC wird blockiert.' );
					break;

				case 'readme_exposed':
					$readme = ABSPATH . 'readme.html';
					$ok     = file_exists( $readme ) ? @unlink( $readme ) : true;
					$results[] = self::fix_result( $id, (bool) $ok, $ok ? 'readme.html entfernt.' : 'readme.html konnte nicht entfernt werden.' );
					break;

				case 'directory_listing':
					$results[] = self::fix_result( $id, false, 'Muss serverseitig behoben werden (Options -Indexes).' );
					break;

				default:
					$results[] = self::fix_result( $id, false, 'Für diesen Punkt gibt es keine automatische Behebung.' );
			}
		}

		return $results;
	}

	/* ------------------------------------------------------------- Einzelchecks */

	protected static function check_ssl() {
		$home = (string) get_option( 'home' );
		return self::check(
			'ssl',
			'HTTPS aktiv',
			0 === strpos( $home, 'https://' ) ? 'ok' : 'fail',
			'high',
			0 === strpos( $home, 'https://' ) ? 'Die Seite läuft über HTTPS.' : 'Die Seiten-URL nutzt kein HTTPS.'
		);
	}

	protected static function check_wp_version_exposed() {
		$hidden = (bool) get_option( 'nlc_hide_generator', 0 );
		return self::check(
			'wp_version_exposed',
			'WordPress-Version verborgen',
			$hidden ? 'ok' : 'warn',
			'low',
			$hidden ? 'Generator-Tag wird entfernt.' : 'Die WordPress-Version steht im Quelltext (Generator-Meta-Tag).',
			true
		);
	}

	protected static function check_file_editor() {
		$disabled = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
		return self::check(
			'file_editor',
			'Datei-Editor deaktiviert',
			$disabled ? 'ok' : 'warn',
			'medium',
			$disabled ? 'Theme-/Plugin-Editor ist gesperrt.' : "Setze define('DISALLOW_FILE_EDIT', true); in der wp-config.php."
		);
	}

	protected static function check_debug_display() {
		$bad = defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY );
		return self::check(
			'debug_display',
			'Debug-Ausgabe aus',
			$bad ? 'fail' : 'ok',
			'high',
			$bad ? 'WP_DEBUG_DISPLAY ist aktiv — Fehler werden im Frontend ausgegeben.' : 'Keine Debug-Ausgabe im Frontend.'
		);
	}

	protected static function check_directory_listing() {
		$dir = wp_upload_dir();
		if ( ! empty( $dir['error'] ) ) {
			return self::check( 'directory_listing', 'Kein Directory-Listing', 'warn', 'medium', 'Upload-Verzeichnis nicht prüfbar.' );
		}

		$response = wp_remote_get(
			trailingslashit( $dir['baseurl'] ),
			array( 'timeout' => 8, 'redirection' => 2, 'sslverify' => false )
		);

		if ( is_wp_error( $response ) ) {
			return self::check( 'directory_listing', 'Kein Directory-Listing', 'warn', 'medium', 'Prüfung nicht möglich: ' . $response->get_error_message() );
		}

		$body    = (string) wp_remote_retrieve_body( $response );
		$listing = false !== stripos( $body, '<title>Index of' ) || false !== stripos( $body, 'Parent Directory' );

		return self::check(
			'directory_listing',
			'Kein Directory-Listing',
			$listing ? 'fail' : 'ok',
			'medium',
			$listing ? 'Das Upload-Verzeichnis ist auflistbar.' : 'Verzeichnisauflistung ist deaktiviert.'
		);
	}

	protected static function check_admin_username() {
		$exists = (bool) get_user_by( 'login', 'admin' );
		return self::check(
			'admin_username',
			'Kein Benutzer "admin"',
			$exists ? 'warn' : 'ok',
			'medium',
			$exists ? 'Es existiert ein Benutzer mit dem Login "admin".' : 'Kein Standard-Login "admin" vorhanden.'
		);
	}

	protected static function check_php_version() {
		$ok = version_compare( PHP_VERSION, '8.1', '>=' );
		return self::check(
			'php_version',
			'PHP-Version unterstützt',
			$ok ? 'ok' : 'fail',
			'high',
			sprintf( 'Läuft auf PHP %s.', PHP_VERSION )
		);
	}

	protected static function check_core_version() {
		$updates = NLC_Updates::core_updates();
		return self::check(
			'core_version',
			'WordPress-Core aktuell',
			$updates ? 'fail' : 'ok',
			'high',
			$updates ? sprintf( 'Core-Update auf %s verfügbar.', $updates[0]['new_version'] ) : 'Core ist auf dem aktuellen Stand.'
		);
	}

	protected static function check_xmlrpc() {
		$disabled = (bool) get_option( 'nlc_disable_xmlrpc', 0 ) || ! apply_filters( 'xmlrpc_enabled', true );
		return self::check(
			'xmlrpc_enabled',
			'XML-RPC deaktiviert',
			$disabled ? 'ok' : 'warn',
			'low',
			$disabled ? 'XML-RPC ist blockiert.' : 'XML-RPC ist erreichbar (Brute-Force-Vektor).',
			true
		);
	}

	protected static function check_registration() {
		$open = (bool) get_option( 'users_can_register' );
		$role = (string) get_option( 'default_role' );
		$risky = $open && in_array( $role, array( 'administrator', 'editor' ), true );

		return self::check(
			'registration',
			'Registrierung sicher konfiguriert',
			$risky ? 'fail' : 'ok',
			'high',
			$risky ? sprintf( 'Offene Registrierung mit Standardrolle "%s".', $role ) : 'Registrierungseinstellungen sind unkritisch.'
		);
	}

	protected static function check_db_prefix() {
		global $wpdb;
		$default = 'wp_' === $wpdb->prefix;
		return self::check(
			'db_prefix',
			'Individuelles Tabellen-Präfix',
			$default ? 'warn' : 'ok',
			'low',
			$default ? 'Es wird das Standard-Präfix "wp_" verwendet.' : 'Eigenes Tabellen-Präfix in Verwendung.'
		);
	}

	protected static function check_readme_exposed() {
		$exists = file_exists( ABSPATH . 'readme.html' );
		return self::check(
			'readme_exposed',
			'readme.html entfernt',
			$exists ? 'warn' : 'ok',
			'low',
			$exists ? 'readme.html verrät die WordPress-Version.' : 'Keine readme.html im Root.',
			true
		);
	}

	protected static function check_pending_updates() {
		$updates = NLC_Updates::available();
		$count   = count( $updates['plugins'] ) + count( $updates['themes'] );
		return self::check(
			'pending_updates',
			'Keine offenen Plugin-/Theme-Updates',
			$count > 0 ? ( $count > 5 ? 'fail' : 'warn' ) : 'ok',
			'medium',
			$count > 0 ? sprintf( '%d Update(s) ausstehend.', $count ) : 'Alles aktuell.'
		);
	}

	protected static function check_inactive_extensions() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$inactive = 0;
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( ! is_plugin_active( $file ) ) {
				$inactive++;
			}
		}
		return self::check(
			'inactive_plugins',
			'Keine verwaisten Plugins',
			$inactive > 3 ? 'warn' : 'ok',
			'low',
			$inactive > 0 ? sprintf( '%d inaktive(s) Plugin(s) installiert.', $inactive ) : 'Keine inaktiven Plugins.'
		);
	}

	/* ----------------------------------------------------------------- Helfer */

	/**
	 * @param string $id
	 * @param string $label
	 * @param string $status ok|warn|fail
	 * @param string $severity low|medium|high
	 * @param string $detail
	 * @param bool   $fixable
	 * @return array<string,mixed>
	 */
	protected static function check( $id, $label, $status, $severity, $detail, $fixable = false ) {
		return array(
			'id'       => $id,
			'label'    => $label,
			'status'   => $status,
			'severity' => $severity,
			'detail'   => $detail,
			'fixable'  => (bool) $fixable,
		);
	}

	/**
	 * @param string $id
	 * @param bool   $success
	 * @param string $message
	 * @return array<string,mixed>
	 */
	protected static function fix_result( $id, $success, $message ) {
		return array(
			'id'      => $id,
			'success' => (bool) $success,
			'message' => $message,
		);
	}
}

/**
 * Härtungs-Maßnahmen anwenden, die als Option gesetzt wurden.
 */
add_action(
	'init',
	function () {
		if ( get_option( 'nlc_hide_generator', 0 ) ) {
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
		}
		if ( get_option( 'nlc_disable_xmlrpc', 0 ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
		}
	}
);

/**
 * Letzten Login mitschreiben — wird im Dashboard und in Reports ausgewiesen.
 */
add_action(
	'wp_login',
	function ( $login, $user ) {
		if ( $user instanceof WP_User ) {
			update_user_meta( $user->ID, 'nlc_last_login', gmdate( 'c' ) );
		}
	},
	10,
	2
);
