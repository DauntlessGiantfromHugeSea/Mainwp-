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
);

foreach ( $nlc_options as $nlc_option ) {
	delete_option( $nlc_option );
}

delete_transient( 'nlc_uploads_size' );
