<?php
/**
 * Bootstrap class.
 *
 * @package Contentsync
 */
namespace Contentsync;

use Contentsync\Utils\Logger;
use Contentsync\Database\Database_Tables_Hooks;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

class Bootstrap {

	public function __construct() {

		register_activation_hook( CONTENTSYNC_PLUGIN_FILE, array( $this, 'contentsync_plugin_activated' ) );
		register_deactivation_hook( CONTENTSYNC_PLUGIN_FILE, array( $this, 'contentsync_plugin_deactivated' ) );

		// Initialize all loader classes (which will load their respective resources).
		$this->init_loaders();

		// Initialize plugin update checker.
		$this->init_update_checker();
	}

	/**
	 * Run on plugin activation.
	 *
	 * This eagerly provisions the custom database tables used by the plugin
	 * so they exist immediately after activation. The `maybe_add_*` helpers
	 * will only create missing tables.
	 */
	public function contentsync_plugin_activated() {
		( new Database_Tables_Hooks() )->maybe_add_tables();
	}

	/**
	 * Run on plugin deactivation.
	 *
	 * Intentionally minimal for now but kept separate so cleanup logic can
	 * be added here in the future without touching the main plugin loader.
	 */
	public function contentsync_plugin_deactivated() {
		// Silent for now.
	}

	/**
	 * Scan includes/ directory and instantiate all loader classes.
	 */
	private function init_loaders() {
		$includes_dir = CONTENTSYNC_PLUGIN_PATH . '/includes';
		$iterator     = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $includes_dir )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && preg_match( '/_Loader\.php$/', $file->getFilename() ) ) {
				$class_name = $this->file_to_class_name( $file->getPathname(), $includes_dir );

				// Skip abstract classes, instantiate concrete loaders.
				if ( class_exists( $class_name ) ) {
					$reflection = new \ReflectionClass( $class_name );
					if ( ! $reflection->isAbstract() ) {
						new $class_name();
					}
				}
			}
		}
	}

	/**
	 * Convert a file path to a fully qualified PSR-4 class name.
	 *
	 * @param string $file_path    The full path to the file.
	 * @param string $includes_dir The includes directory path.
	 *
	 * @return string The fully qualified class name.
	 */
	private function file_to_class_name( $file_path, $includes_dir ) {
		// Remove includes dir prefix and .php extension.
		$relative = str_replace( $includes_dir . '/', '', $file_path );
		$relative = str_replace( '.php', '', $relative );

		// Convert path separators to namespace separators.
		$class_name = str_replace( '/', '\\', $relative );

		return '\Contentsync\\' . $class_name;
	}

	/**
	 * Initialize the plugin update checker for GitHub integration.
	 *
	 * This sets up automatic update notifications and one-click upgrades
	 * from the GitHub repository.
	 *
	 * @link https://github.com/YahnisElsts/plugin-update-checker
	 */
	private function init_update_checker() {

		$update_checker = PucFactory::buildUpdateChecker(
			'https://github.com/jakobtrost/contentsync/',
			CONTENTSYNC_PLUGIN_FILE,
			'contentsync'
		);

		// Optional: Set the branch that contains the stable release.
		// Uncomment and modify if you want to use a specific branch instead of releases/tags.
		$update_checker->setBranch( 'main' );

		// Optional: Enable release assets if you want to use custom release assets.
		// Uncomment if you want to use release assets instead of the default zip.
		// $update_checker->getVcsApi()->enableReleaseAssets();
	}
}
