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

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
 * Run on plugin activation.
 *
 * This eagerly provisions the custom database tables used by the plugin
 * so they exist immediately after activation. The `maybe_add_*` helpers
 * will only create missing tables.
 */
function contentsync_plugin_activated() {
	// Ensure DB table helpers are available.
	require_once CONTENTSYNC_PLUGIN_PATH . '/includes/DB/database-tables.php';

	// Call DB helpers in their namespace.
	\DB\maybe_add_cluster_table();
	\DB\maybe_add_post_reviews_table();
	\DB\maybe_add_cluster_content_conditions_table();
	\DB\maybe_add_queue_distribution_items_table();
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
