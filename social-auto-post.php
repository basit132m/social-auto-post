<?php
/**
 * Plugin Name:       Social Auto Post
 * Plugin URI:        https://github.com/basit132m/social-auto-post
 * Description:       Automatically shares every post you publish to X (Twitter), then lists the resulting tweet back on the post itself.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            basit132m
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       social-auto-post
 *
 * @package SocialAutoPost
 */

defined( 'ABSPATH' ) || exit;

define( 'SAP_VERSION', '0.1.0' );
define( 'SAP_FILE', __FILE__ );
define( 'SAP_DIR', plugin_dir_path( __FILE__ ) );
define( 'SAP_URL', plugin_dir_url( __FILE__ ) );

require_once SAP_DIR . 'includes/class-sap-settings.php';
require_once SAP_DIR . 'includes/class-sap-crypto.php';
require_once SAP_DIR . 'includes/class-sap-logger.php';
require_once SAP_DIR . 'includes/class-sap-template.php';
require_once SAP_DIR . 'includes/class-sap-x-oauth.php';
require_once SAP_DIR . 'includes/class-sap-x-client.php';
require_once SAP_DIR . 'includes/class-sap-publisher.php';
require_once SAP_DIR . 'includes/class-sap-socials.php';
require_once SAP_DIR . 'includes/admin/class-sap-admin.php';
require_once SAP_DIR . 'includes/admin/class-sap-meta-box.php';

/**
 * Boot the plugin once WordPress has loaded its own pluggable pieces.
 */
function sap_init() {
	SAP_Publisher::instance()->hooks();
	SAP_Socials::instance()->hooks();
	SAP_X_OAuth::instance()->hooks();

	if ( is_admin() ) {
		SAP_Admin::instance()->hooks();
		SAP_Meta_Box::instance()->hooks();
	}
}
add_action( 'plugins_loaded', 'sap_init' );

/**
 * Create the retry schedule on activation.
 *
 * Nothing else is provisioned here: settings fall back to defaults on read, so
 * activating the plugin twice (or restoring a site) can't clobber a config.
 */
function sap_activate() {
	SAP_Logger::log( 'info', 'Plugin activated.' );
}
register_activation_hook( __FILE__, 'sap_activate' );

/**
 * Clear anything scheduled, so a deactivated plugin stops doing work.
 */
function sap_deactivate() {
	$timestamp = wp_next_scheduled( SAP_Publisher::RETRY_HOOK );
	while ( false !== $timestamp ) {
		wp_unschedule_event( $timestamp, SAP_Publisher::RETRY_HOOK );
		$timestamp = wp_next_scheduled( SAP_Publisher::RETRY_HOOK );
	}
	wp_clear_scheduled_hook( SAP_Publisher::RETRY_HOOK );
	SAP_Logger::log( 'info', 'Plugin deactivated; scheduled retries cleared.' );
}
register_deactivation_hook( __FILE__, 'sap_deactivate' );
