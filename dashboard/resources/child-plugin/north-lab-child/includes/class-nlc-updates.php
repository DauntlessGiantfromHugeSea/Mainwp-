<?php
defined( 'ABSPATH' ) || exit;

/**
 * Update-Erkennung und -Ausführung auf der Child-Seite.
 */
class NLC_Updates {

	/**
	 * Update-Transients invalidieren und neu aufbauen.
	 */
	public static function refresh_transients() {
		if ( ! function_exists( 'wp_version_check' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! function_exists( 'get_plugin_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		delete_site_transient( 'update_core' );
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );

		wp_version_check( array(), true );
		wp_update_plugins();
		wp_update_themes();
	}

	/**
	 * Alle verfügbaren Updates in normalisierter Form.
	 *
	 * @return array{core:array,plugins:array,themes:array,translations:int}
	 */
	public static function available() {
		return array(
			'core'         => self::core_updates(),
			'plugins'      => self::plugin_updates(),
			'themes'       => self::theme_updates(),
			'translations' => self::translation_count(),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function core_updates() {
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		$out     = array();
		$updates = get_core_updates();
		if ( ! is_array( $updates ) ) {
			return $out;
		}

		foreach ( $updates as $update ) {
			if ( ! isset( $update->response ) || 'upgrade' !== $update->response ) {
				continue;
			}
			$out[] = array(
				'type'            => 'core',
				'slug'            => 'wordpress',
				'name'            => 'WordPress',
				'current_version' => get_bloginfo( 'version' ),
				'new_version'     => $update->current,
				'locale'          => isset( $update->locale ) ? $update->locale : get_locale(),
				'partial'         => isset( $update->packages->partial ) && $update->packages->partial,
			);
		}

		return $out;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function plugin_updates() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$out     = array();
		$data    = get_site_transient( 'update_plugins' );
		$all     = get_plugins();
		$pending = ( $data && ! empty( $data->response ) ) ? $data->response : array();

		foreach ( $pending as $file => $info ) {
			if ( ! isset( $all[ $file ] ) ) {
				continue;
			}
			$out[] = array(
				'type'            => 'plugin',
				'slug'            => $file,
				'name'            => $all[ $file ]['Name'],
				'current_version' => $all[ $file ]['Version'],
				'new_version'     => isset( $info->new_version ) ? $info->new_version : '',
				'active'          => is_plugin_active( $file ),
				'tested'          => isset( $info->tested ) ? $info->tested : null,
				'requires_php'    => isset( $info->requires_php ) ? $info->requires_php : null,
			);
		}

		return $out;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function theme_updates() {
		$out     = array();
		$data    = get_site_transient( 'update_themes' );
		$pending = ( $data && ! empty( $data->response ) ) ? $data->response : array();

		foreach ( $pending as $stylesheet => $info ) {
			$theme = wp_get_theme( $stylesheet );
			if ( ! $theme->exists() ) {
				continue;
			}
			$out[] = array(
				'type'            => 'theme',
				'slug'            => $stylesheet,
				'name'            => $theme->get( 'Name' ),
				'current_version' => $theme->get( 'Version' ),
				'new_version'     => isset( $info['new_version'] ) ? $info['new_version'] : '',
				'active'          => get_stylesheet() === $stylesheet,
			);
		}

		return $out;
	}

	/**
	 * @return int
	 */
	public static function translation_count() {
		if ( ! function_exists( 'wp_get_translation_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		return count( (array) wp_get_translation_updates() );
	}

	/**
	 * Führt eine Liste von Updates aus.
	 *
	 * @param array<int,array{type:string,slug:string}> $items
	 * @return array<int,array<string,mixed>> Ergebnis pro Eintrag.
	 */
	public static function run( array $items ) {
		self::load_upgrader();

		$results = array();
		$plugins = array();
		$themes  = array();
		$core    = false;
		$trans   = false;

		foreach ( $items as $item ) {
			$type = isset( $item['type'] ) ? (string) $item['type'] : '';
			$slug = isset( $item['slug'] ) ? (string) $item['slug'] : '';

			switch ( $type ) {
				case 'core':
					$core = true;
					break;
				case 'plugin':
					if ( '' !== $slug ) {
						$plugins[] = $slug;
					}
					break;
				case 'theme':
					if ( '' !== $slug ) {
						$themes[] = $slug;
					}
					break;
				case 'translation':
					$trans = true;
					break;
			}
		}

		// Reihenfolge: erst Core, dann Plugins/Themes — so wie WordPress es selbst tut.
		if ( $core ) {
			$results[] = self::update_core();
		}
		if ( $plugins ) {
			$results = array_merge( $results, self::update_plugins( array_unique( $plugins ) ) );
		}
		if ( $themes ) {
			$results = array_merge( $results, self::update_themes( array_unique( $themes ) ) );
		}
		if ( $trans ) {
			$results[] = self::update_translations();
		}

		self::refresh_transients();

		return $results;
	}

	/**
	 * Lädt die Upgrader-Klassen von WordPress.
	 */
	protected static function load_upgrader() {
		if ( ! class_exists( 'WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'get_core_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		if ( ! function_exists( 'themes_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme-install.php';
		}
		if ( ! function_exists( 'wp_get_translation_updates' ) ) {
			require_once ABSPATH . 'wp-admin/includes/update.php';
		}

		// Filesystem-Prompts unterdrücken; wir laufen ohne UI.
		add_filter( 'file_mod_allowed', '__return_true', 99 );
	}

	/**
	 * @return array<string,mixed>
	 */
	protected static function update_core() {
		$updates = get_core_updates();
		if ( empty( $updates ) || ! isset( $updates[0] ) || 'upgrade' !== $updates[0]->response ) {
			return self::result( 'core', 'wordpress', false, 'Kein Core-Update verfügbar.' );
		}

		$from = get_bloginfo( 'version' );

		$upgrader = new Core_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $updates[0] );

		if ( is_wp_error( $result ) ) {
			return self::result( 'core', 'wordpress', false, $result->get_error_message() );
		}

		return self::result( 'core', 'wordpress', true, 'WordPress aktualisiert.', $from, $updates[0]->current );
	}

	/**
	 * @param array<int,string> $files
	 * @return array<int,array<string,mixed>>
	 */
	protected static function update_plugins( array $files ) {
		$before = get_plugins();
		$active = array();
		foreach ( $files as $file ) {
			if ( is_plugin_active( $file ) ) {
				$active[] = $file;
			}
		}

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$outcome  = $upgrader->bulk_upgrade( $files );

		// Aktive Plugins bleiben aktiv — bulk_upgrade deaktiviert sie normalerweise nicht,
		// aber bei Ordner-Umbenennungen kann es passieren.
		foreach ( $active as $file ) {
			if ( ! is_plugin_active( $file ) && file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
				activate_plugin( $file );
			}
		}

		$after   = get_plugins();
		$results = array();

		foreach ( $files as $file ) {
			$name = isset( $before[ $file ]['Name'] ) ? $before[ $file ]['Name'] : $file;
			$from = isset( $before[ $file ]['Version'] ) ? $before[ $file ]['Version'] : null;
			$to   = isset( $after[ $file ]['Version'] ) ? $after[ $file ]['Version'] : null;

			if ( ! is_array( $outcome ) || ! array_key_exists( $file, $outcome ) ) {
				$results[] = self::result( 'plugin', $file, false, 'Upgrader lieferte kein Ergebnis.', $from, $to, $name );
				continue;
			}

			$single = $outcome[ $file ];
			if ( is_wp_error( $single ) ) {
				$results[] = self::result( 'plugin', $file, false, $single->get_error_message(), $from, $to, $name );
			} elseif ( false === $single || null === $single ) {
				$results[] = self::result( 'plugin', $file, false, 'Update fehlgeschlagen oder übersprungen.', $from, $to, $name );
			} else {
				$results[] = self::result( 'plugin', $file, true, 'Plugin aktualisiert.', $from, $to, $name );
			}
		}

		return $results;
	}

	/**
	 * @param array<int,string> $stylesheets
	 * @return array<int,array<string,mixed>>
	 */
	protected static function update_themes( array $stylesheets ) {
		$before = array();
		foreach ( $stylesheets as $stylesheet ) {
			$theme                  = wp_get_theme( $stylesheet );
			$before[ $stylesheet ] = array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
			);
		}

		$upgrader = new Theme_Upgrader( new Automatic_Upgrader_Skin() );
		$outcome  = $upgrader->bulk_upgrade( $stylesheets );

		$results = array();
		foreach ( $stylesheets as $stylesheet ) {
			wp_clean_themes_cache();
			$to   = wp_get_theme( $stylesheet )->get( 'Version' );
			$name = $before[ $stylesheet ]['name'];
			$from = $before[ $stylesheet ]['version'];

			if ( ! is_array( $outcome ) || ! array_key_exists( $stylesheet, $outcome ) ) {
				$results[] = self::result( 'theme', $stylesheet, false, 'Upgrader lieferte kein Ergebnis.', $from, $to, $name );
				continue;
			}

			$single = $outcome[ $stylesheet ];
			if ( is_wp_error( $single ) ) {
				$results[] = self::result( 'theme', $stylesheet, false, $single->get_error_message(), $from, $to, $name );
			} elseif ( false === $single || null === $single ) {
				$results[] = self::result( 'theme', $stylesheet, false, 'Update fehlgeschlagen oder übersprungen.', $from, $to, $name );
			} else {
				$results[] = self::result( 'theme', $stylesheet, true, 'Theme aktualisiert.', $from, $to, $name );
			}
		}

		return $results;
	}

	/**
	 * @return array<string,mixed>
	 */
	protected static function update_translations() {
		$pending = wp_get_translation_updates();
		if ( empty( $pending ) ) {
			return self::result( 'translation', 'all', false, 'Keine Übersetzungs-Updates verfügbar.' );
		}

		$upgrader = new Language_Pack_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->bulk_upgrade();

		if ( is_wp_error( $result ) ) {
			return self::result( 'translation', 'all', false, $result->get_error_message() );
		}

		return self::result( 'translation', 'all', true, sprintf( '%d Übersetzungspaket(e) aktualisiert.', count( $pending ) ) );
	}

	/**
	 * Einheitliches Ergebnisobjekt.
	 *
	 * @param string      $type
	 * @param string      $slug
	 * @param bool        $success
	 * @param string      $message
	 * @param string|null $from
	 * @param string|null $to
	 * @param string|null $name
	 * @return array<string,mixed>
	 */
	protected static function result( $type, $slug, $success, $message, $from = null, $to = null, $name = null ) {
		return array(
			'type'         => $type,
			'slug'         => $slug,
			'name'         => $name ?: $slug,
			'success'      => (bool) $success,
			'message'      => $message,
			'from_version' => $from,
			'to_version'   => $to,
		);
	}
}
