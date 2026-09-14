<?php
/**
 * Plugin Name:       NorthLab Child
 * Plugin URI:        https://north-lab.de/
 * Description:       Verbindet diese WordPress-Seite mit dem NorthLab Control Panel. Erlaubt zentrale Updates, Status-Abfragen, Wartung, Sicherheitschecks und Reports.
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            NorthLab
 * Author URI:        https://north-lab.de/
 * License:           GPL-3.0-or-later
 * Text Domain:       north-lab-child
 */

defined( 'ABSPATH' ) || exit;

define( 'NLC_VERSION', '1.3.0' );
define( 'NLC_FILE', __FILE__ );
define( 'NLC_PATH', plugin_dir_path( __FILE__ ) );
define( 'NLC_URL', plugin_dir_url( __FILE__ ) );
define( 'NLC_REST_NS', 'northlab-child/v1' );

require_once NLC_PATH . 'includes/class-nlc-options.php';
require_once NLC_PATH . 'includes/class-nlc-auth.php';
require_once NLC_PATH . 'includes/class-nlc-info.php';
require_once NLC_PATH . 'includes/class-nlc-updates.php';
require_once NLC_PATH . 'includes/class-nlc-actions.php';
require_once NLC_PATH . 'includes/class-nlc-security.php';
require_once NLC_PATH . 'includes/class-nlc-maintenance.php';
require_once NLC_PATH . 'includes/class-nlc-maintenance-mode.php';
require_once NLC_PATH . 'includes/class-nlc-login.php';
require_once NLC_PATH . 'includes/class-nlc-selfupdate.php';
require_once NLC_PATH . 'includes/class-nlc-backup.php';
require_once NLC_PATH . 'includes/class-nlc-rest.php';
require_once NLC_PATH . 'includes/class-nlc-admin.php';

/**
 * Bootstrap.
 */
function nlc_boot() {
	NLC_REST::instance()->hooks();

	$login = new NLC_Login();
	$login->hooks();

	$mmode = new NLC_Maintenance_Mode();
	$mmode->hooks();

	// Nur wenn verbunden - ohne Panel gibt es keine Update-Quelle.
	if ( NLC_Options::is_connected() && NLC_Options::setting( 'allow_self_update' ) ) {
		$selfupdate = new NLC_Selfupdate();
		$selfupdate->hooks();
	}

	if ( is_admin() ) {
		NLC_Admin::instance()->hooks();
	}
}
add_action( 'plugins_loaded', 'nlc_boot' );

/**
 * Stuendlich abgelaufene befristete Konten entfernen.
 */
function nlc_schedule_purge() {
	if ( ! wp_next_scheduled( 'nlc_purge_expired_users' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'nlc_purge_expired_users' );
	}
}
add_action( 'init', 'nlc_schedule_purge' );
add_action( 'nlc_purge_expired_users', array( 'NLC_Actions', 'purge_expired_users' ) );

/**
 * Aktivierung: Verbindungsdaten unangetastet lassen, nur Defaults setzen.
 */
function nlc_activate() {
	NLC_Options::bootstrap_defaults();
	nlc_schedule_purge();
}
register_activation_hook( __FILE__, 'nlc_activate' );

/**
 * Deaktivierung: geplante Aufgabe abmelden, Wartungsmodus beenden.
 *
 * Ohne das zweite bliebe die Seite fuer Besucher gesperrt, obwohl das Plugin
 * gar nicht mehr laeuft und niemand sie wieder freischalten koennte.
 */
function nlc_deactivate() {
	$timestamp = wp_next_scheduled( 'nlc_purge_expired_users' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'nlc_purge_expired_users' );
	}
	NLC_Maintenance_Mode::save( array( 'enabled' => false ) );
}
register_deactivation_hook( __FILE__, 'nlc_deactivate' );
