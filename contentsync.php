<?php
/*
Plugin Name:    Content Sync
Description:    Synchronize content on any number of websites and benefit from massive time savings in content management.
Plugin URI:     https://github.com/jakobtrost/contentsync
Author:         Jakob Trost
Author URI:     https://jakobtrost.de
Version:        0.1
Text Domain:    contentsync
Domain Path:    /languages/
*/
namespace Contentsync;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'CONTENTSYNC_VERSION' ) ) {
	define( 'CONTENTSYNC_VERSION', '2.17.4' );
}

if ( ! defined( 'CONTENTSYNC_PLUGIN_PATH' ) ) {
	define( 'CONTENTSYNC_PLUGIN_PATH', __DIR__ );
}

if ( ! defined( 'CONTENTSYNC_PLUGIN_FILE' ) ) {
	define( 'CONTENTSYNC_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'CONTENTSYNC_PLUGIN_URL' ) ) {
	define( 'CONTENTSYNC_PLUGIN_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) );
}

if ( ! defined( 'CONTENTSYNC_REST_NAMESPACE' ) ) {
	define( 'CONTENTSYNC_REST_NAMESPACE', 'contentsync/v1' );
}

/**
 * Fallback functions for single site installations
 */
if ( ! is_multisite() ) {
	if ( ! function_exists( 'switch_to_blog' ) ) {
		function switch_to_blog( $blog_id = '' ) { }
	}
	if ( ! function_exists( 'restore_current_blog' ) ) {
		function restore_current_blog() { }
	}
	if ( ! function_exists( 'network_site_url' ) ) {
		function network_site_url() {
			return site_url();
		}
	}
	if ( ! function_exists( 'get_blog_permalink' ) ) {
		function get_blog_permalink( $blog_id, $post_id ) {
			return get_permalink( $post_id );
		}
	}
}

/**
 * Run on plugin activation.
 *
 * This eagerly provisions the custom database tables used by the plugin
 * so they exist immediately after activation. The `maybe_add_*` helpers
 * will only create missing tables.
 */
function contentsync_plugin_activated() {
	( new \Contentsync\Database\Database_Tables_Hooks() )->maybe_add_tables();
}

\register_activation_hook( CONTENTSYNC_PLUGIN_FILE, __NAMESPACE__ . '\contentsync_plugin_activated' );

/**
 * Run on plugin deactivation.
 *
 * Intentionally minimal for now but kept separate so cleanup logic can
 * be added here in the future without touching the main plugin loader.
 */
function contentsync_plugin_deactivated() {
	// for future use
}

\register_deactivation_hook( CONTENTSYNC_PLUGIN_FILE, __NAMESPACE__ . '\contentsync_plugin_deactivated' );

// Load Composer autoloader for PSR-4 autoloading.
require_once CONTENTSYNC_PLUGIN_PATH . '/vendor/autoload.php';

// Load all hook providers.
new \Contentsync\Utils\Hooks_Loader();
