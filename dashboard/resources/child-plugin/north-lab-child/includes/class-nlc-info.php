<?php
defined( 'ABSPATH' ) || exit;

/**
 * Sammelt den kompletten Zustand der Seite für das Dashboard.
 */
class NLC_Info {

	/**
	 * Vollständiger Statusbericht.
	 *
	 * @param bool $force_check Update-Caches vorher leeren.
	 * @return array<string,mixed>
	 */
	public static function collect( $force_check = false ) {
		if ( $force_check ) {
			NLC_Updates::refresh_transients();
		}

		return array(
			'child_version' => NLC_VERSION,
			'generated_at'  => gmdate( 'c' ),
			'capabilities'  => self::capabilities(),
			'site'          => self::site(),
			'environment'   => self::environment(),
			'updates'       => NLC_Updates::available(),
			'plugins'       => self::plugins(),
			'themes'        => self::themes(),
			'users'         => self::user_summary(),
			'content'       => self::content_summary(),
			'health'        => self::health(),
			'security'      => NLC_Security::scan(),
		);
	}

	/**
	 * Welche Freigaben auf dieser Seite gesetzt sind.
	 *
	 * Damit kann das Panel sagen, warum etwas nicht geht, statt nur dass es
	 * nicht geht. Die IP-Liste bleibt draussen — die geht das Panel nichts an.
	 *
	 * @return array<string,bool>
	 */
	public static function capabilities() {
		$settings = NLC_Options::settings();
		$out      = array();

		foreach ( NLC_Options::default_settings() as $key => $default ) {
			if ( is_bool( $default ) ) {
				$out[ $key ] = ! empty( $settings[ $key ] );
			}
		}

		return $out;
	}

	/**
	 * Schlanke Variante für häufige Heartbeats.
	 *
	 * @return array<string,mixed>
	 */
	public static function ping() {
		$updates = NLC_Updates::available();

		return array(
			'child_version' => NLC_VERSION,
			'generated_at'  => gmdate( 'c' ),
			'wp_version'    => get_bloginfo( 'version' ),
			'php_version'   => PHP_VERSION,
			'update_counts' => array(
				'core'         => count( $updates['core'] ),
				'plugins'      => count( $updates['plugins'] ),
				'themes'       => count( $updates['themes'] ),
				'translations' => (int) $updates['translations'],
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function site() {
		return array(
			'name'         => get_bloginfo( 'name' ),
			'description'  => get_bloginfo( 'description' ),
			'home_url'     => home_url(),
			'site_url'     => site_url(),
			'admin_url'    => admin_url(),
			'rest_url'     => rest_url(),
			'language'     => get_locale(),
			'timezone'     => wp_timezone_string(),
			'is_multisite' => is_multisite(),
			'is_ssl'       => is_ssl(),
			'public'       => (int) get_option( 'blog_public' ),
			'permalinks'   => get_option( 'permalink_structure' ),
			'theme'        => self::active_theme(),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	protected static function active_theme() {
		$theme = wp_get_theme();
		return array(
			'name'       => $theme->get( 'Name' ),
			'stylesheet' => $theme->get_stylesheet(),
			'version'    => $theme->get( 'Version' ),
			'is_child'   => (bool) $theme->parent(),
			'parent'     => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function environment() {
		global $wpdb;

		return array(
			'wp_version'        => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'mysql_version'     => $wpdb->db_version(),
			'server_software'   => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'memory_limit'      => WP_MEMORY_LIMIT,
			'max_memory_limit'  => WP_MAX_MEMORY_LIMIT,
			'php_max_execution' => (int) ini_get( 'max_execution_time' ),
			'php_post_max_size' => ini_get( 'post_max_size' ),
			'php_upload_max'    => ini_get( 'upload_max_filesize' ),
			'php_extensions'    => self::relevant_extensions(),
			'debug_mode'        => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_cron_disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'auto_update_core'  => self::core_auto_update_setting(),
			'db_size_mb'        => self::database_size_mb(),
			'uploads_size_mb'   => self::uploads_size_mb(),
			'disk_free_mb'      => self::disk_free_mb(),
		);
	}

	/**
	 * @return array<string,bool>
	 */
	protected static function relevant_extensions() {
		$wanted = array( 'openssl', 'curl', 'json', 'mbstring', 'zip', 'gd', 'imagick', 'intl', 'sodium' );
		$out    = array();
		foreach ( $wanted as $ext ) {
			$out[ $ext ] = extension_loaded( $ext );
		}
		return $out;
	}

	/**
	 * @return string
	 */
	protected static function core_auto_update_setting() {
		if ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
			return is_bool( WP_AUTO_UPDATE_CORE ) ? ( WP_AUTO_UPDATE_CORE ? 'all' : 'off' ) : (string) WP_AUTO_UPDATE_CORE;
		}
		return 'minor';
	}

	/**
	 * @return float
	 */
	protected static function database_size_mb() {
		global $wpdb;
		$size = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s',
				DB_NAME
			)
		);
		return $size ? round( ( (float) $size ) / 1048576, 2 ) : 0.0;
	}

	/**
	 * Größe des Upload-Verzeichnisses, gecacht (teure Operation).
	 *
	 * @return float
	 */
	protected static function uploads_size_mb() {
		$cached = get_transient( 'nlc_uploads_size' );
		if ( false !== $cached ) {
			return (float) $cached;
		}

		$dir = wp_upload_dir();
		if ( ! empty( $dir['error'] ) || ! is_dir( $dir['basedir'] ) ) {
			return 0.0;
		}

		$bytes = 0;
		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir['basedir'], FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$bytes += $file->getSize();
				}
			}
		} catch ( Exception $e ) {
			return 0.0;
		}

		$mb = round( $bytes / 1048576, 2 );
		set_transient( 'nlc_uploads_size', $mb, 6 * HOUR_IN_SECONDS );
		return $mb;
	}

	/**
	 * @return float
	 */
	protected static function disk_free_mb() {
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( ABSPATH ) : false;
		return $free ? round( ( (float) $free ) / 1048576, 2 ) : 0.0;
	}

	/**
	 * Alle installierten Plugins inkl. Update-Status.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all       = get_plugins();
		$updates   = get_site_transient( 'update_plugins' );
		$pending   = ( $updates && ! empty( $updates->response ) ) ? $updates->response : array();
		$auto      = (array) get_site_option( 'auto_update_plugins', array() );
		$collected = array();

		foreach ( $all as $file => $data ) {
			$collected[] = array(
				'file'         => $file,
				'slug'         => dirname( $file ) !== '.' ? dirname( $file ) : basename( $file, '.php' ),
				'name'         => $data['Name'],
				'version'      => $data['Version'],
				'author'       => wp_strip_all_tags( $data['Author'] ),
				'plugin_uri'   => $data['PluginURI'],
				'active'       => is_plugin_active( $file ),
				'network_only' => ! empty( $data['Network'] ),
				'auto_update'  => in_array( $file, $auto, true ),
				'new_version'  => isset( $pending[ $file ]->new_version ) ? $pending[ $file ]->new_version : null,
			);
		}

		return $collected;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function themes() {
		$all       = wp_get_themes();
		$updates   = get_site_transient( 'update_themes' );
		$pending   = ( $updates && ! empty( $updates->response ) ) ? $updates->response : array();
		$auto      = (array) get_site_option( 'auto_update_themes', array() );
		$active    = get_stylesheet();
		$collected = array();

		foreach ( $all as $stylesheet => $theme ) {
			$collected[] = array(
				'stylesheet'  => $stylesheet,
				'name'        => $theme->get( 'Name' ),
				'version'     => $theme->get( 'Version' ),
				'author'      => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
				'active'      => $stylesheet === $active,
				'parent'      => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
				'auto_update' => in_array( $stylesheet, $auto, true ),
				'new_version' => isset( $pending[ $stylesheet ]['new_version'] ) ? $pending[ $stylesheet ]['new_version'] : null,
			);
		}

		return $collected;
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function user_summary() {
		$counts = count_users();
		return array(
			'total'    => (int) $counts['total_users'],
			'by_role'  => array_map( 'intval', (array) $counts['avail_roles'] ),
			'admins'   => isset( $counts['avail_roles']['administrator'] ) ? (int) $counts['avail_roles']['administrator'] : 0,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function content_summary() {
		$posts    = wp_count_posts( 'post' );
		$pages    = wp_count_posts( 'page' );
		$comments = wp_count_comments();

		return array(
			'posts'            => isset( $posts->publish ) ? (int) $posts->publish : 0,
			'drafts'           => isset( $posts->draft ) ? (int) $posts->draft : 0,
			'pages'            => isset( $pages->publish ) ? (int) $pages->publish : 0,
			'comments'         => (int) $comments->total_comments,
			'comments_pending' => (int) $comments->moderated,
			'comments_spam'    => (int) $comments->spam,
			'comments_trash'   => (int) $comments->trash,
			'revisions'        => self::count_revisions(),
			'attachments'      => (int) wp_count_posts( 'attachment' )->inherit,
		);
	}

	/**
	 * @return int
	 */
	protected static function count_revisions() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
	}

	/**
	 * Ausgewählte Ergebnisse aus WP Site Health.
	 *
	 * @return array<string,mixed>
	 */
	public static function health() {
		$last_cron = (array) _get_cron_array();

		return array(
			'https_status'      => is_ssl() ? 'ok' : 'no-ssl',
			'cron_entries'      => count( $last_cron ),
			'search_engines'    => (int) get_option( 'blog_public' ) === 1 ? 'indexable' : 'blocked',
			'inactive_plugins'  => self::count_inactive_plugins(),
			'inactive_themes'   => max( 0, count( wp_get_themes() ) - 1 ),
			'php_supported'     => version_compare( PHP_VERSION, '8.1', '>=' ),
			'object_cache'      => wp_using_ext_object_cache(),
		);
	}

	/**
	 * @return int
	 */
	protected static function count_inactive_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$inactive = 0;
		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( ! is_plugin_active( $file ) ) {
				$inactive++;
			}
		}
		return $inactive;
	}
}
