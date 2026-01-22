<?php
/**
 * Bootstrap class.
 *
 * @package Contentsync
 */
namespace Contentsync;

use Contentsync\Utils\Logger;
use Contentsync\Utils\Hooks_Loader;
use Contentsync\Database\Database_Tables_Hooks;

class Bootstrap {
	public function __construct() {
		register_activation_hook( CONTENTSYNC_PLUGIN_FILE, array( $this, 'contentsync_plugin_activated' ) );
		register_deactivation_hook( CONTENTSYNC_PLUGIN_FILE, array( $this, 'contentsync_plugin_deactivated' ) );

		// Load all hook providers.
		new Hooks_Loader();
	}

	/**
	 * Run on plugin activation.
	 *
	 * This eagerly provisions the custom database tables used by the plugin
	 * so they exist immediately after activation. The `maybe_add_*` helpers
	 * will only create missing tables.
	 */
	public function contentsync_plugin_activated() {
		Logger::add( 'Contentsync plugin activated' );

		( new Database_Tables_Hooks() )->maybe_add_tables();
	}

	/**
	 * Run on plugin deactivation.
	 *
	 * Intentionally minimal for now but kept separate so cleanup logic can
	 * be added here in the future without touching the main plugin loader.
	 */
	public function contentsync_plugin_deactivated() {
		Logger::add( 'Contentsync plugin deactivated' );
	}
}
