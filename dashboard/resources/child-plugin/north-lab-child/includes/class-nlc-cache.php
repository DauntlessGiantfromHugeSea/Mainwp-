<?php
defined( 'ABSPATH' ) || exit;

/**
 * Seiten-Caches leeren.
 *
 * Der Wartungsmodus greift in "template_redirect" — ein Seiten-Cache liefert
 * sein fertiges HTML aber aus, bevor WordPress ueberhaupt soweit kommt. Ohne
 * Leeren bleibt die Seite dann sichtbar, obwohl alles richtig eingestellt ist.
 * Dasselbe gilt beim Abschalten: sonst bliebe die Wartungsseite stehen.
 *
 * Bewusst nur aufrufen, was das jeweilige Plugin selbst anbietet. Niemand hier
 * loescht Dateien auf Verdacht.
 */
class NLC_Cache {

	/**
	 * Alles leeren, was sich finden laesst.
	 *
	 * @return array<int,string> Namen der Caches, die tatsaechlich geleert wurden.
	 */
	public static function flush() {
		$geleert = array();

		// Object-Cache (Redis, Memcached, APCu ...). Kein Seiten-Cache, aber
		// dort liegen die Optionen — ohne das liest ein zweiter Webserver-Prozess
		// unter Umstaenden noch den alten Zustand.
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
			$geleert[] = 'Object-Cache';
		}

		// Funktionen, die die Plugins global bereitstellen.
		$funktionen = array(
			'rocket_clean_domain'       => 'WP Rocket',
			'w3tc_flush_all'            => 'W3 Total Cache',
			'wp_cache_clear_cache'      => 'WP Super Cache',
			'sg_cachepress_purge_cache' => 'SiteGround Optimizer',
			'wpfc_clear_all_cache'      => 'WP Fastest Cache',
		);

		foreach ( $funktionen as $funktion => $name ) {
			if ( function_exists( $funktion ) ) {
				@call_user_func( $funktion );
				$geleert[] = $name;
			}
		}

		// Plugins, die nur auf eine Aktion hoeren. do_action laeuft ins Leere,
		// wenn niemand zuhoert — darum vorher fragen, sonst meldeten wir das
		// Leeren eines Caches, den es gar nicht gibt.
		$aktionen = array(
			'litespeed_purge_all'               => 'LiteSpeed Cache',
			'cache_enabler_clear_complete_cache' => 'Cache Enabler',
			'breeze_clear_all_cache'            => 'Breeze',
			'rt_nginx_helper_purge_all'         => 'Nginx Helper',
			'wphb_clear_page_cache'             => 'Hummingbird',
			'kinsta_cache_purge'                => 'Kinsta Cache',
			'swcfpc_purge_cache'                => 'Super Page Cache (Cloudflare)',
		);

		foreach ( $aktionen as $aktion => $name ) {
			if ( has_action( $aktion ) ) {
				do_action( $aktion );
				$geleert[] = $name;
			}
		}

		// Autoptimize haengt den Cache an eine Klasse.
		if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
			@call_user_func( array( 'autoptimizeCache', 'clearall' ) );
			$geleert[] = 'Autoptimize';
		}

		// WP Fastest Cache in der Variante ueber das globale Objekt.
		if ( ! in_array( 'WP Fastest Cache', $geleert, true )
			&& isset( $GLOBALS['wp_fastest_cache'] )
			&& method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
			@$GLOBALS['wp_fastest_cache']->deleteCache( true );
			$geleert[] = 'WP Fastest Cache';
		}

		/**
		 * Fuer alles, was hier nicht steht.
		 *
		 * @param array<int,string> $geleert
		 */
		do_action( 'nlc_flush_caches' );

		return $geleert;
	}

	/**
	 * Welche Seiten-Caches ueberhaupt aktiv sind.
	 *
	 * Wird dem Panel gemeldet, damit es bei einer Seite, die trotz
	 * Wartungsmodus weiter ausgeliefert wird, den Verdacht benennen kann.
	 *
	 * @return array<int,string>
	 */
	public static function detected() {
		$gefunden = array();

		if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
			$gefunden[] = 'WP_CACHE ist in der wp-config.php aktiv';
		}

		$marker = array(
			'WP Rocket'           => 'rocket_clean_domain',
			'W3 Total Cache'      => 'w3tc_flush_all',
			'WP Super Cache'      => 'wp_cache_clear_cache',
			'SiteGround Optimizer' => 'sg_cachepress_purge_cache',
		);

		foreach ( $marker as $name => $funktion ) {
			if ( function_exists( $funktion ) ) {
				$gefunden[] = $name;
			}
		}

		$aktionen = array(
			'LiteSpeed Cache' => 'litespeed_purge_all',
			'Cache Enabler'   => 'cache_enabler_clear_complete_cache',
			'Breeze'          => 'breeze_clear_all_cache',
			'Nginx Helper'    => 'rt_nginx_helper_purge_all',
		);

		foreach ( $aktionen as $name => $aktion ) {
			if ( has_action( $aktion ) ) {
				$gefunden[] = $name;
			}
		}

		return $gefunden;
	}
}
