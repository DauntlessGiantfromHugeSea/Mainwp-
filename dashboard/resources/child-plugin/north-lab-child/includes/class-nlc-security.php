<?php
defined( 'ABSPATH' ) || exit;

/**
 * Leichtgewichtiger Sicherheits- und Härtungs-Check.
 *
 * Bewusst ohne Dateiscanner: die Prüfungen sind schnell genug, um bei jedem Sync mitzulaufen.
 */
class NLC_Security {

	/** Welche Punkte dauerhaft gelten sollen, nicht nur einmal behoben wurden. */
	const OPT_HARDENING = 'nlc_hardening';

	/** Welche Punkte bewusst so bleiben duerfen. */
	const OPT_ACK = 'nlc_hardening_ack';

	/** Was beim letzten Durchsetzen herauskam — fuer ehrliche Anzeige im Panel. */
	const OPT_LAST_ENFORCE = 'nlc_hardening_last';

	/**
	 * Punkte, die sich dauerhaft halten lassen.
	 *
	 * "permanent" heisst: wird nach jedem Core-Update und taeglich erneut
	 * angewendet. readme.html legt WordPress bei jedem Core-Update wieder an —
	 * einmal loeschen reicht da nicht.
	 *
	 * @return array<int,string>
	 */
	public static function enforceable() {
		return array( 'file_editor', 'readme_exposed', 'wp_version_exposed', 'xmlrpc_enabled' );
	}

	/**
	 * Punkte, die sich nicht gefahrlos automatisch beheben lassen und darum
	 * nur quittiert werden koennen.
	 *
	 * Das Tabellen-Praefix nachtraeglich zu aendern heisst: alle Tabellen
	 * umbenennen, die wp-config.php anfassen und in options und usermeta die
	 * Schluessel mitziehen. Geht das auf halbem Weg schief, steht die Seite.
	 * Der Gewinn ist blosse Verschleierung. Darum: benennen, nicht anfassen.
	 *
	 * @return array<int,string>
	 */
	public static function acknowledgeable() {
		return array( 'db_prefix', 'admin_username', 'directory_listing', 'php_version', 'inactive_plugins' );
	}

	/**
	 * @return array<int,string>
	 */
	public static function enforced() {
		$stored = get_option( self::OPT_HARDENING, array() );

		return is_array( $stored ) ? array_values( array_intersect( $stored, self::enforceable() ) ) : array();
	}

	/**
	 * @param string $id
	 * @return bool
	 */
	public static function is_enforced( $id ) {
		return in_array( $id, self::enforced(), true );
	}

	/**
	 * @return array<string,string> Kennung => Begruendung.
	 */
	public static function acknowledged() {
		$stored = get_option( self::OPT_ACK, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$erlaubt = self::acknowledgeable();
		$sauber  = array();

		foreach ( $stored as $id => $grund ) {
			if ( in_array( $id, $erlaubt, true ) ) {
				$sauber[ $id ] = (string) $grund;
			}
		}

		return $sauber;
	}

	/**
	 * Punkte bewusst stehen lassen — mit Begruendung, damit spaeter noch
	 * nachvollziehbar ist, warum.
	 *
	 * @param array<int,string> $ids
	 * @param string            $grund
	 * @return array<string,string>
	 */
	public static function acknowledge( array $ids, $grund = '' ) {
		$aktuell = self::acknowledged();
		$grund   = sanitize_text_field( (string) $grund );

		foreach ( $ids as $id ) {
			$id = sanitize_key( (string) $id );
			if ( in_array( $id, self::acknowledgeable(), true ) ) {
				$aktuell[ $id ] = '' !== $grund ? $grund : 'Bewusst so gelassen.';
			}
		}

		update_option( self::OPT_ACK, $aktuell, true );

		return $aktuell;
	}

	/**
	 * @param array<int,string> $ids
	 * @return array<string,string>
	 */
	public static function unacknowledge( array $ids ) {
		$aktuell = self::acknowledged();

		foreach ( $ids as $id ) {
			unset( $aktuell[ sanitize_key( (string) $id ) ] );
		}

		update_option( self::OPT_ACK, $aktuell, true );

		return $aktuell;
	}

	/**
	 * Eine Haertung wieder aufgeben.
	 *
	 * @param array<int,string> $ids
	 * @return array<int,string>
	 */
	public static function relax( array $ids ) {
		$bleibt = array_values( array_diff( self::enforced(), array_map( 'sanitize_key', $ids ) ) );

		update_option( self::OPT_HARDENING, $bleibt, true );

		// Die Optionen-Schalter zurueckdrehen; die geloeschte readme.html
		// kommt beim naechsten Core-Update von selbst wieder.
		if ( ! in_array( 'wp_version_exposed', $bleibt, true ) ) {
			update_option( 'nlc_hide_generator', 0 );
		}
		if ( ! in_array( 'xmlrpc_enabled', $bleibt, true ) ) {
			update_option( 'nlc_disable_xmlrpc', 0 );
		}

		return $bleibt;
	}

	/**
	 * Billiger Teil, laeuft bei jedem Request.
	 *
	 * DISALLOW_FILE_EDIT wird erst beim Aufbau des Admin-Menues geprueft, also
	 * lange nach "plugins_loaded". Hier gesetzt bleibt es nach jedem Core-Update
	 * bestehen, ohne dass jemand die wp-config.php anfassen muss.
	 */
	public static function apply_early() {
		if ( self::is_enforced( 'file_editor' ) && ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true );
		}
	}

	/**
	 * Teurer Teil: nach jedem Core-Update und einmal taeglich.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function enforce() {
		$results = array();

		foreach ( self::enforced() as $id ) {
			switch ( $id ) {
				case 'readme_exposed':
					$readme = ABSPATH . 'readme.html';
					if ( ! file_exists( $readme ) ) {
						break; // Nichts zu tun ist keine Meldung wert.
					}
					$ok        = (bool) @unlink( $readme );
					$results[] = self::fix_result(
						$id,
						$ok,
						$ok
							? 'readme.html war nach einem Update wieder da und wurde erneut entfernt.'
							: 'readme.html ist wieder da, laesst sich aber nicht entfernen (Schreibrechte im WordPress-Verzeichnis).'
					);
					break;

				case 'wp_version_exposed':
					if ( ! get_option( 'nlc_hide_generator', 0 ) ) {
						update_option( 'nlc_hide_generator', 1 );
						$results[] = self::fix_result( $id, true, 'Generator-Tag wieder ausgeblendet.' );
					}
					break;

				case 'xmlrpc_enabled':
					if ( ! get_option( 'nlc_disable_xmlrpc', 0 ) ) {
						update_option( 'nlc_disable_xmlrpc', 1 );
						$results[] = self::fix_result( $id, true, 'XML-RPC wieder blockiert.' );
					}
					break;

				case 'file_editor':
					// Greift ueber apply_early() beim naechsten Request. Hier
					// nur melden, wenn die wp-config.php ausdruecklich das
					// Gegenteil festlegt — dann kommen wir nicht dagegen an.
					if ( defined( 'DISALLOW_FILE_EDIT' ) && ! DISALLOW_FILE_EDIT ) {
						$results[] = self::fix_result(
							$id,
							false,
							'In der wp-config.php steht DISALLOW_FILE_EDIT auf false. Das schlaegt jede Einstellung von hier.'
						);
					}
					break;
			}
		}

		if ( $results ) {
			update_option( self::OPT_LAST_ENFORCE, array( 'at' => gmdate( 'c' ), 'results' => $results ), false );
		}

		return $results;
	}

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

		$dauerhaft   = self::enforced();
		$quittiert   = self::acknowledged();
		$erzwingbar  = self::enforceable();
		$quittierbar = self::acknowledgeable();

		foreach ( $checks as $i => $check ) {
			$id = $check['id'];

			$checks[ $i ]['enforced']        = in_array( $id, $dauerhaft, true );
			$checks[ $i ]['enforceable']     = in_array( $id, $erzwingbar, true );
			$checks[ $i ]['acknowledgeable'] = in_array( $id, $quittierbar, true );
			$checks[ $i ]['acknowledged']    = isset( $quittiert[ $id ] ) ? $quittiert[ $id ] : '';

			// Ein bewusst stehengelassener Punkt soll nicht ewig mahnen — aber
			// auch nicht so aussehen, als waere er behoben. Darum ein eigener
			// Zustand statt "ok".
			if ( 'ok' !== $check['status'] && isset( $quittiert[ $id ] ) ) {
				$checks[ $i ]['status'] = 'ack';
				$checks[ $i ]['detail'] = $check['detail'] . ' — bewusst so gelassen: ' . $quittiert[ $id ];
			}

			if ( $checks[ $i ]['enforced'] && 'ok' === $check['status'] ) {
				$checks[ $i ]['detail'] = $check['detail'] . ' Wird nach jedem Update erneut gesetzt.';
			}
		}

		$total  = count( $checks );
		$passed = 0;
		foreach ( $checks as $check ) {
			if ( 'ok' === $check['status'] || 'ack' === $check['status'] ) {
				$passed++;
			}
		}

		return array(
			'score'      => $total ? (int) round( ( $passed / $total ) * 100 ) : 100,
			'passed'     => $passed,
			'total'      => $total,
			'checks'     => $checks,
			'enforced'   => $dauerhaft,
			'last_enforce' => get_option( self::OPT_LAST_ENFORCE, null ),
		);
	}

	/**
	 * Behebt die Punkte, die sich gefahrlos automatisch beheben lassen.
	 *
	 * @param array<int,string> $ids
	 * @return array<int,array<string,mixed>>
	 */
	public static function harden( array $ids, $permanent = true ) {
		$results = array();

		// Erst vormerken, dann anwenden. Andersherum stuende die Haertung nach
		// dem naechsten Core-Update wieder offen, obwohl sie gerade gemeldet
		// wurde — genau der Fall, der aufgefallen ist.
		if ( $permanent ) {
			$merken = array_values(
				array_unique(
					array_merge(
						self::enforced(),
						array_intersect( array_map( 'sanitize_key', $ids ), self::enforceable() )
					)
				)
			);
			update_option( self::OPT_HARDENING, $merken, true );
		}

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

				case 'file_editor':
					if ( defined( 'DISALLOW_FILE_EDIT' ) && ! DISALLOW_FILE_EDIT ) {
						$results[] = self::fix_result( $id, false, 'Die wp-config.php setzt DISALLOW_FILE_EDIT ausdruecklich auf false — dagegen kommt das Plugin nicht an.' );
						break;
					}
					$results[] = self::fix_result(
						$id,
						true,
						'Der Datei-Editor wird vom NorthLab-Plugin gesperrt. Gilt ab dem naechsten Seitenaufruf und ueberlebt Core-Updates.'
					);
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
		$disabled   = defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT;
		$erzwungen  = defined( 'DISALLOW_FILE_EDIT' ) && ! DISALLOW_FILE_EDIT;

		if ( $erzwungen ) {
			$detail = 'Die wp-config.php setzt DISALLOW_FILE_EDIT ausdruecklich auf false. Das laesst sich nur dort aendern.';
		} elseif ( $disabled ) {
			$detail = 'Theme-/Plugin-Editor ist gesperrt.';
		} else {
			$detail = 'Der Theme- und Plugin-Editor im Backend ist offen. Laesst sich aus dem Panel dauerhaft sperren.';
		}

		return self::check(
			'file_editor',
			'Datei-Editor deaktiviert',
			$disabled ? 'ok' : 'warn',
			'medium',
			$detail,
			! $erzwungen
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
			$default
				? 'Es wird das Standard-Präfix "wp_" verwendet. Nachträglich ändern heißt: alle Tabellen umbenennen, '
					. 'die wp-config.php anfassen und Schlüssel in options und usermeta mitziehen. Geht das schief, '
					. 'steht die Seite — und der Gewinn ist bloße Verschleierung. Darum nur von Hand und mit frischer Sicherung.'
				: 'Eigenes Tabellen-Präfix in Verwendung.'
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
 * Den billigen Teil der Haertung frueh anwenden.
 *
 * DISALLOW_FILE_EDIT wird erst beim Aufbau des Admin-Menues gelesen; hier
 * gesetzt ist es rechtzeitig da — und zwar nach jedem Core-Update wieder,
 * ohne dass jemand die wp-config.php anfassen muss.
 */
add_action( 'plugins_loaded', array( 'NLC_Security', 'apply_early' ), 1 );

/**
 * Nach einem Core-Update erneut durchsetzen.
 *
 * WordPress legt readme.html bei jedem Core-Update wieder an. Einmal loeschen
 * reicht darum nicht — genau das war der Grund, warum die Haertung "nach dem
 * Update immer weg" war.
 */
add_action( '_core_updated_successfully', array( 'NLC_Security', 'enforce' ) );

add_action(
	'upgrader_process_complete',
	function ( $upgrader, $hook_extra ) {
		$typ = is_array( $hook_extra ) && isset( $hook_extra['type'] ) ? $hook_extra['type'] : '';

		if ( 'core' === $typ ) {
			NLC_Security::enforce();
		}
	},
	10,
	2
);

/**
 * Und taeglich als Netz darunter — ein Core-Update kann auch ueber WP-CLI,
 * den Hoster oder eine Wiederherstellung kommen, ohne dass einer der Haken
 * oben feuert.
 */
add_action( 'nlc_enforce_hardening', array( 'NLC_Security', 'enforce' ) );

add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'nlc_enforce_hardening' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'nlc_enforce_hardening' );
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
