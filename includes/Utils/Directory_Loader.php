<?php

namespace Contentsync\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for automatically discovering and loading PSR-4 classes from a directory.
 *
 * This loader scans a specified directory for PHP files and instantiates each class.
 * Child classes should extend this and provide the directory path and namespace.
 */
abstract class Directory_Loader {

	/**
	 * The directory path to scan for classes.
	 *
	 * @var string
	 */
	protected $directory_path;

	/**
	 * The namespace for classes in this directory.
	 *
	 * @var string
	 */
	protected $namespace;

	/**
	 * Constructor - sets up the loader and triggers class loading.
	 *
	 * @param string $directory_path The full path to the directory to scan.
	 * @param string $namespace      The namespace for classes in this directory (e.g., '\Contentsync\Api\Endpoints').
	 */
	public function __construct( $directory_path, $namespace ) {
		$this->directory_path = $directory_path;
		$this->namespace      = $namespace;
		$this->load_all_classes();
	}

	/**
	 * Scan directory and instantiate all classes.
	 */
	private function load_all_classes() {
		if ( ! is_dir( $this->directory_path ) ) {
			return;
		}

		$iterator = new \DirectoryIterator( $this->directory_path );

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && $file->getExtension() === 'php' ) {
				$filename = $file->getFilename();

				$class_name = $this->file_to_class_name( $filename );

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
	 * Convert a filename to a fully qualified PSR-4 class name.
	 *
	 * @param string $filename The filename (e.g., 'Check_Auth.php').
	 *
	 * @return string The fully qualified class name.
	 */
	protected function file_to_class_name( $filename ) {
		// Remove .php extension.
		$class_name = str_replace( '.php', '', $filename );

		// Ensure namespace starts with backslash, then append class name.
		$namespace = ( strpos( $this->namespace, '\\' ) === 0 ) ? $this->namespace : '\\' . $this->namespace;

		return $namespace . '\\' . $class_name;
	}
}
