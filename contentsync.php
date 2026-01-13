<?php
/*
Plugin Name:    Content Sync
Description:    Synchronize content and elements on any number of websites and benefit from massive time savings in content management.
Plugin URI:     https://jakobtrost.de
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

/**
 * --------------------------------------------------------------
 * Define plugin constants
 * --------------------------------------------------------------
 */
if ( !defined( 'CONTENTSYNC_VERSION' ) ) {
	define( 'CONTENTSYNC_VERSION', '2.17.4' );
}

if ( !defined( 'CONTENTSYNC_PLUGIN_PATH' ) ) {
	define( 'CONTENTSYNC_PLUGIN_PATH', __DIR__ );
}

if ( !defined( 'CONTENTSYNC_PLUGIN_FILE' ) ) {
	define( 'CONTENTSYNC_PLUGIN_FILE', __FILE__ );
}

if ( !defined( 'CONTENTSYNC_PLUGIN_URL' ) ) {
	define( 'CONTENTSYNC_PLUGIN_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) );
}

if ( ! defined( 'CONTENTSYNC_REST_NAMESPACE' ) ) {
	define( 'CONTENTSYNC_REST_NAMESPACE', 'contentsync/v1' );
}

/**
 * --------------------------------------------------------------
 * Manage plugin activation and deactivation
 * --------------------------------------------------------------
 */
function contentsync_plugin_activated() {
	// for future use
}
register_activation_hook( CONTENTSYNC_PLUGIN_FILE, 'contentsync_plugin_activated' );

function contentsync_plugin_deactivated() {
	// for future use
}
register_deactivation_hook( CONTENTSYNC_PLUGIN_FILE, 'contentsync_plugin_deactivated' );


/**
 * --------------------------------------------------------------
 * Manage fallback functions for single site installations
 * --------------------------------------------------------------
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
 * --------------------------------------------------------------
 * Load plugin files
 * --------------------------------------------------------------
 */

// general
include_once __DIR__ . '/inc/class-main-helper.php';
include_once __DIR__ . '/inc/class-synced-post.php';

// load frontend extensions
require_once __DIR__ . '/inc/contents/frontend.php';

// post export
include_once __DIR__ . '/inc/post-export/init.php';

// connection endpoints
include_once __DIR__ . '/inc/connections/init.php';

// contents
include_once __DIR__ . '/inc/contents/actions.php';
include_once __DIR__ . '/inc/contents/trigger.php';
include_once __DIR__ . '/inc/contents/site-editor.php';

// queue
include_once __DIR__ . '/inc/distribution/init.php';

// clusters
include_once __DIR__ . '/inc/cluster/init.php';

// backend files
if ( is_admin() ) {

	// contents
	include_once __DIR__ . '/enqueue.php';
	include_once __DIR__ . '/inc/contents/global-list-table.php';
	include_once __DIR__ . '/inc/contents/admin.php';
	include_once __DIR__ . '/inc/contents/ajax.php';

	// clusters
	include_once __DIR__ . '/inc/cluster/admin.php';
}
