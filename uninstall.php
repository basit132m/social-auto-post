<?php
/**
 * Removes everything the plugin stored, when it is deleted (not deactivated).
 *
 * @package SocialAutoPost
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'sap_settings', 'sap_x_app', 'sap_x_token', 'sap_usage', 'sap_log' ) as $option ) {
	delete_option( $option );
}

// Locks are per-post and short-lived, but a crash can strand one.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'sap_lock_%'" );

// Per-post meta, including the tweet ids that drive the Socials block.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_sap\_%'" );

// Any pending retry.
wp_clear_scheduled_hook( 'sap_publish_post' );
