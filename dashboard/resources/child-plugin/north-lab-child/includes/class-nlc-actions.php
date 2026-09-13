<?php
defined( 'ABSPATH' ) || exit;

/**
 * Verwaltungsaktionen: Plugins, Themes, Benutzer, Inhalte.
 */
class NLC_Actions {

	/* ---------------------------------------------------------------- Plugins */

	/**
	 * @param string            $action activate|deactivate|delete|install
	 * @param array<int,string> $targets Plugin-Dateien bzw. wp.org-Slugs bei install.
	 * @return array<int,array<string,mixed>>
	 */
	public static function plugins( $action, array $targets ) {
		self::load_admin_includes();
		$results = array();

		foreach ( $targets as $target ) {
			$target = (string) $target;
			switch ( $action ) {
				case 'activate':
					$res       = activate_plugin( $target );
					$results[] = self::outcome( $target, ! is_wp_error( $res ), is_wp_error( $res ) ? $res->get_error_message() : 'Plugin aktiviert.' );
					break;

				case 'deactivate':
					deactivate_plugins( array( $target ) );
					$results[] = self::outcome( $target, ! is_plugin_active( $target ), 'Plugin deaktiviert.' );
					break;

				case 'delete':
					if ( is_plugin_active( $target ) ) {
						$results[] = self::outcome( $target, false, 'Aktive Plugins werden nicht gelöscht. Bitte zuerst deaktivieren.' );
						break;
					}
					$res       = delete_plugins( array( $target ) );
					$results[] = self::outcome( $target, true === $res, is_wp_error( $res ) ? $res->get_error_message() : 'Plugin gelöscht.' );
					break;

				case 'install':
					$results[] = self::install_plugin( $target );
					break;

				default:
					$results[] = self::outcome( $target, false, 'Unbekannte Aktion: ' . $action );
			}
		}

		return $results;
	}

	/**
	 * Installiert ein Plugin von wordpress.org oder aus einer ZIP-URL.
	 *
	 * @param string $target Slug oder https-URL zu einer ZIP-Datei.
	 * @return array<string,mixed>
	 */
	protected static function install_plugin( $target ) {
		if ( ! NLC_Options::setting( 'allow_install' ) ) {
			return self::outcome( $target, false, 'Installationen sind auf dieser Seite deaktiviert.' );
		}

		$package = $target;

		if ( ! preg_match( '#^https?://#i', $target ) ) {
			$api = plugins_api( 'plugin_information', array( 'slug' => sanitize_key( $target ), 'fields' => array( 'sections' => false ) ) );
			if ( is_wp_error( $api ) ) {
				return self::outcome( $target, false, $api->get_error_message() );
			}
			$package = $api->download_link;
		} elseif ( ! self::is_safe_package_url( $target ) ) {
			return self::outcome( $target, false, 'Paket-URL nicht erlaubt.' );
		}

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $package );

		if ( is_wp_error( $result ) ) {
			return self::outcome( $target, false, $result->get_error_message() );
		}
		if ( true !== $result ) {
			return self::outcome( $target, false, 'Installation fehlgeschlagen.' );
		}

		$installed = $upgrader->plugin_info();
		return self::outcome( $installed ?: $target, true, 'Plugin installiert.' );
	}

	/* ----------------------------------------------------------------- Themes */

	/**
	 * @param string            $action activate|delete|install
	 * @param array<int,string> $targets
	 * @return array<int,array<string,mixed>>
	 */
	public static function themes( $action, array $targets ) {
		self::load_admin_includes();
		$results = array();

		foreach ( $targets as $target ) {
			$target = (string) $target;
			switch ( $action ) {
				case 'activate':
					if ( ! wp_get_theme( $target )->exists() ) {
						$results[] = self::outcome( $target, false, 'Theme nicht gefunden.' );
						break;
					}
					switch_theme( $target );
					$results[] = self::outcome( $target, get_stylesheet() === $target, 'Theme aktiviert.' );
					break;

				case 'delete':
					if ( get_stylesheet() === $target || get_template() === $target ) {
						$results[] = self::outcome( $target, false, 'Aktives Theme (oder dessen Parent) wird nicht gelöscht.' );
						break;
					}
					$res       = delete_theme( $target );
					$results[] = self::outcome( $target, true === $res, is_wp_error( $res ) ? $res->get_error_message() : 'Theme gelöscht.' );
					break;

				case 'install':
					$results[] = self::install_theme( $target );
					break;

				default:
					$results[] = self::outcome( $target, false, 'Unbekannte Aktion: ' . $action );
			}
		}

		return $results;
	}

	/**
	 * @param string $target
	 * @return array<string,mixed>
	 */
	protected static function install_theme( $target ) {
		if ( ! NLC_Options::setting( 'allow_install' ) ) {
			return self::outcome( $target, false, 'Installationen sind auf dieser Seite deaktiviert.' );
		}

		$package = $target;

		if ( ! preg_match( '#^https?://#i', $target ) ) {
			$api = themes_api( 'theme_information', array( 'slug' => sanitize_key( $target ) ) );
			if ( is_wp_error( $api ) ) {
				return self::outcome( $target, false, $api->get_error_message() );
			}
			$package = $api->download_link;
		} elseif ( ! self::is_safe_package_url( $target ) ) {
			return self::outcome( $target, false, 'Paket-URL nicht erlaubt.' );
		}

		$upgrader = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $package );

		if ( is_wp_error( $result ) ) {
			return self::outcome( $target, false, $result->get_error_message() );
		}

		return self::outcome( $target, true === $result, true === $result ? 'Theme installiert.' : 'Installation fehlgeschlagen.' );
	}

	/**
	 * Automatische Updates pro Plugin/Theme umschalten.
	 *
	 * @param string            $kind   plugin|theme
	 * @param array<int,string> $targets
	 * @param bool              $enable
	 * @return array<int,array<string,mixed>>
	 */
	public static function set_auto_update( $kind, array $targets, $enable ) {
		$option  = 'plugin' === $kind ? 'auto_update_plugins' : 'auto_update_themes';
		$current = (array) get_site_option( $option, array() );

		foreach ( $targets as $target ) {
			$target = (string) $target;
			if ( $enable ) {
				$current[] = $target;
			} else {
				$current = array_diff( $current, array( $target ) );
			}
		}

		update_site_option( $option, array_values( array_unique( $current ) ) );

		$results = array();
		foreach ( $targets as $target ) {
			$results[] = self::outcome( (string) $target, true, $enable ? 'Auto-Update aktiviert.' : 'Auto-Update deaktiviert.' );
		}
		return $results;
	}

	/* --------------------------------------------------------------- Benutzer */

	/**
	 * @param array<string,mixed> $args role, search, limit
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_users( array $args = array() ) {
		$query = new WP_User_Query(
			array(
				'role'    => ! empty( $args['role'] ) ? sanitize_key( $args['role'] ) : '',
				'search'  => ! empty( $args['search'] ) ? '*' . sanitize_text_field( $args['search'] ) . '*' : '',
				'number'  => isset( $args['limit'] ) ? min( 500, max( 1, (int) $args['limit'] ) ) : 200,
				'orderby' => 'registered',
				'order'   => 'DESC',
			)
		);

		$out = array();
		foreach ( $query->get_results() as $user ) {
			$out[] = array(
				'id'           => $user->ID,
				'login'        => $user->user_login,
				'email'        => $user->user_email,
				'display_name' => $user->display_name,
				'roles'        => array_values( $user->roles ),
				'registered'   => $user->user_registered,
				'last_login'   => get_user_meta( $user->ID, 'nlc_last_login', true ) ?: null,
			);
		}

		return $out;
	}

	/**
	 * @param string              $action create|update|delete|reset-password
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public static function user_action( $action, array $data ) {
		if ( ! NLC_Options::setting( 'allow_user_mgmt' ) ) {
			return self::outcome( 'user', false, 'Benutzerverwaltung ist auf dieser Seite deaktiviert.' );
		}

		switch ( $action ) {
			case 'create':
				if ( empty( $data['login'] ) || empty( $data['email'] ) ) {
					return self::outcome( 'user', false, 'login und email sind erforderlich.' );
				}
				$password = ! empty( $data['password'] ) ? (string) $data['password'] : wp_generate_password( 24, true, true );
				$user_id  = wp_insert_user(
					array(
						'user_login' => sanitize_user( $data['login'] ),
						'user_email' => sanitize_email( $data['email'] ),
						'user_pass'  => $password,
						'first_name' => isset( $data['first_name'] ) ? sanitize_text_field( $data['first_name'] ) : '',
						'last_name'  => isset( $data['last_name'] ) ? sanitize_text_field( $data['last_name'] ) : '',
						'role'       => isset( $data['role'] ) ? sanitize_key( $data['role'] ) : 'subscriber',
					)
				);
				if ( is_wp_error( $user_id ) ) {
					return self::outcome( 'user', false, $user_id->get_error_message() );
				}
				return array_merge( self::outcome( 'user', true, 'Benutzer angelegt.' ), array( 'user_id' => $user_id ) );

			case 'update':
				$user_id = isset( $data['id'] ) ? (int) $data['id'] : 0;
				if ( ! $user_id || ! get_userdata( $user_id ) ) {
					return self::outcome( 'user', false, 'Benutzer nicht gefunden.' );
				}
				$fields = array( 'ID' => $user_id );
				if ( ! empty( $data['email'] ) ) {
					$fields['user_email'] = sanitize_email( $data['email'] );
				}
				if ( ! empty( $data['role'] ) ) {
					$fields['role'] = sanitize_key( $data['role'] );
				}
				if ( ! empty( $data['password'] ) ) {
					$fields['user_pass'] = (string) $data['password'];
				}
				$res = wp_update_user( $fields );
				return is_wp_error( $res )
					? self::outcome( 'user', false, $res->get_error_message() )
					: self::outcome( 'user', true, 'Benutzer aktualisiert.' );

			case 'delete':
				$user_id = isset( $data['id'] ) ? (int) $data['id'] : 0;
				if ( ! $user_id || ! get_userdata( $user_id ) ) {
					return self::outcome( 'user', false, 'Benutzer nicht gefunden.' );
				}
				if ( ! function_exists( 'wp_delete_user' ) ) {
					require_once ABSPATH . 'wp-admin/includes/user.php';
				}
				$reassign = isset( $data['reassign'] ) ? (int) $data['reassign'] : null;
				$ok       = wp_delete_user( $user_id, $reassign );
				return self::outcome( 'user', (bool) $ok, $ok ? 'Benutzer gelöscht.' : 'Löschen fehlgeschlagen.' );

			case 'reset-password':
				$user_id = isset( $data['id'] ) ? (int) $data['id'] : 0;
				$user    = $user_id ? get_userdata( $user_id ) : null;
				if ( ! $user ) {
					return self::outcome( 'user', false, 'Benutzer nicht gefunden.' );
				}
				$sent = retrieve_password( $user->user_login );
				return is_wp_error( $sent )
					? self::outcome( 'user', false, $sent->get_error_message() )
					: self::outcome( 'user', true, 'Passwort-Reset-Mail verschickt.' );
		}

		return self::outcome( 'user', false, 'Unbekannte Aktion: ' . $action );
	}

	/* --------------------------------------------------------------- Inhalte */

	/**
	 * @param array<string,mixed> $args
	 * @return array<int,array<string,mixed>>
	 */
	public static function list_content( array $args = array() ) {
		$query = new WP_Query(
			array(
				'post_type'      => ! empty( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'post',
				'post_status'    => ! empty( $args['status'] ) ? sanitize_key( $args['status'] ) : 'any',
				's'              => ! empty( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '',
				'posts_per_page' => isset( $args['limit'] ) ? min( 200, max( 1, (int) $args['limit'] ) ) : 50,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$out = array();
		foreach ( $query->posts as $post ) {
			$out[] = array(
				'id'       => $post->ID,
				'title'    => get_the_title( $post ),
				'status'   => $post->post_status,
				'type'     => $post->post_type,
				'author'   => get_the_author_meta( 'display_name', $post->post_author ),
				'modified' => $post->post_modified_gmt,
				'link'     => get_permalink( $post ),
			);
		}

		return $out;
	}

	/**
	 * Legt einen Beitrag/eine Seite an (z. B. für Bulk-Posting aus dem Dashboard).
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public static function create_content( array $data ) {
		if ( ! NLC_Options::setting( 'allow_content' ) ) {
			return self::outcome( 'content', false, 'Inhaltsverwaltung ist auf dieser Seite deaktiviert.' );
		}
		if ( empty( $data['title'] ) ) {
			return self::outcome( 'content', false, 'title ist erforderlich.' );
		}

		$post_id = wp_insert_post(
			array(
				'post_title'   => sanitize_text_field( $data['title'] ),
				'post_content' => isset( $data['content'] ) ? wp_kses_post( $data['content'] ) : '',
				'post_excerpt' => isset( $data['excerpt'] ) ? sanitize_textarea_field( $data['excerpt'] ) : '',
				'post_status'  => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'draft',
				'post_type'    => isset( $data['post_type'] ) ? sanitize_key( $data['post_type'] ) : 'post',
				'post_author'  => isset( $data['author'] ) ? (int) $data['author'] : self::fallback_author(),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return self::outcome( 'content', false, $post_id->get_error_message() );
		}

		if ( ! empty( $data['categories'] ) && is_array( $data['categories'] ) ) {
			wp_set_post_categories( $post_id, array_map( 'intval', $data['categories'] ) );
		}
		if ( ! empty( $data['tags'] ) && is_array( $data['tags'] ) ) {
			wp_set_post_tags( $post_id, array_map( 'sanitize_text_field', $data['tags'] ) );
		}

		return array_merge(
			self::outcome( 'content', true, 'Inhalt angelegt.' ),
			array(
				'post_id' => $post_id,
				'link'    => get_permalink( $post_id ),
			)
		);
	}

	/**
	 * @return int
	 */
	protected static function fallback_author() {
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		return $admins ? (int) $admins[0] : 1;
	}

	/* ----------------------------------------------------------------- Helfer */

	/**
	 * Nur HTTPS-Pakete von wordpress.org oder aus einer explizit erlaubten Quelle.
	 *
	 * @param string $url
	 * @return bool
	 */
	protected static function is_safe_package_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		$host = strtolower( $parts['host'] );

		/**
		 * Zusätzliche erlaubte Hosts für Paketinstallationen (z. B. eigener Premium-Repo-Server).
		 *
		 * @param array<int,string> $hosts
		 */
		$allowed = apply_filters(
			'nlc_allowed_package_hosts',
			array( 'downloads.wordpress.org', 'wordpress.org' )
		);

		foreach ( $allowed as $candidate ) {
			$candidate = strtolower( (string) $candidate );
			if ( $host === $candidate || substr( $host, -strlen( '.' . $candidate ) ) === '.' . $candidate ) {
				return true;
			}
		}

		return false;
	}

	protected static function load_admin_includes() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		if ( ! function_exists( 'themes_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme-install.php';
		}
		if ( ! function_exists( 'delete_theme' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}
		add_filter( 'file_mod_allowed', '__return_true', 99 );
	}

	/**
	 * @param string $target
	 * @param bool   $success
	 * @param string $message
	 * @return array<string,mixed>
	 */
	protected static function outcome( $target, $success, $message ) {
		return array(
			'target'  => $target,
			'success' => (bool) $success,
			'message' => $message,
		);
	}
}
