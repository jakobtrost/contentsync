<?php

namespace Contentsync\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Automatically discovers and loads all hook provider classes.
 *
 * This loader scans the includes/ directory for files matching *_Hooks.php
 * and instantiates each class. Since hook provider classes extend Hooks_Base
 * and register their hooks in the constructor, this ensures all hooks are
 * registered without manual instantiation.
 */
class Hooks_Loader {

	/**
	 * Constructor - triggers hook loading.
	 */
	public function __construct() {
		$this->load_all_hooks();
	}

	/**
	 * Scan includes/ directory and instantiate all hook provider classes.
	 */
	private function load_all_hooks() {
		$includes_dir = CONTENTSYNC_PLUGIN_PATH . '/includes';
		$iterator     = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $includes_dir )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && preg_match( '/_Hooks\.php$/', $file->getFilename() ) ) {
				$class_name = $this->file_to_class_name( $file->getPathname(), $includes_dir );

				// Skip abstract base class, instantiate concrete hooks.
				if ( $class_name !== 'Contentsync\\Utils\\Hooks_Base' && class_exists( $class_name ) ) {
					new $class_name();
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

		return 'Contentsync\\' . $class_name;
	}
}
