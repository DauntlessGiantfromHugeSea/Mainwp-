<?php
/**
 * Räumt die Optionen des Child-Plugins bei Deinstallation auf.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$nlc_options = array(
	'nlc_connection',
	'nlc_connect_code',
	'nlc_settings',
	'nlc_last_contact',
	'nlc_hide_generator',
	'nlc_disable_xmlrpc',
	'nlc_maintenance_mode',
);

foreach ( $nlc_options as $nlc_option ) {
	delete_option( $nlc_option );
}

delete_transient( 'nlc_uploads_size' );
delete_transient( 'nlc_update_manifest' );

// Befristungen aufheben, damit kein Cron eines anderen Plugins spaeter Konten loescht.
delete_metadata( 'user', 0, 'nlc_expires_at', '', true );
delete_metadata( 'user', 0, 'nlc_from_panel', '', true );

$nlc_purge = wp_next_scheduled( 'nlc_purge_expired_users' );
if ( $nlc_purge ) {
	wp_unschedule_event( $nlc_purge, 'nlc_purge_expired_users' );
}
