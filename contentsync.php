<?php
/*
Plugin Name:    Content Sync
Description:    Synchronize content on any number of websites and benefit from massive time savings in content management.
Plugin URI:     https://github.com/jakobtrost/contentsync
Author:         Jakob Trost
Author URI:     https://jakobtrost.de
Version:        0.1.0
Text Domain:    contentsync
Domain Path:    /languages/
*/
namespace Contentsync;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'CONTENTSYNC_VERSION' ) ) {
	define( 'CONTENTSYNC_VERSION', '0.1.0' );
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

// require_once __DIR__ . '/includes/Utils/File_Loader_Logger.php';
// Utils\File_Loader_Logger::init();

// Load Composer autoloader for PSR-4 autoloading.
require_once __DIR__ . '/vendor/autoload.php';

// Load the bootstrap class.
new Bootstrap();
