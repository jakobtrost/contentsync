<?php

namespace Contentsync\Posts\Transfer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class File_Handler {

	/**
	 * Holds the relative path to the export folder.
	 *
	 * @var string
	 */
	private static $base_path = null;

	/**
	 * Get path to the export folder. Use this path to write files.
	 *
	 * @param string $folder Folder inside wp-content/backup/posts/
	 *
	 * @return string $path
	 */
	public static function get_export_file_path( $folder = '' ) {

		// get basic path from var
		if ( self::$base_path ) {
			$path = self::$base_path;
		}
		// init basic path
		else {
			$path = WP_CONTENT_DIR . '/backup';

			if ( ! file_exists( $path ) ) {
				Logger::add( sprintf( '  - create folder "%s".', $path ) );
				mkdir( $path, 0755, true );
			}
			$path .= '/posts';
			if ( ! file_exists( $path ) ) {
				Logger::add( sprintf( '  - create folder "%s".', $path ) );
				mkdir( $path, 0755, true );
			}
			$path .= '/';

			// save in var
			self::$base_path = $path;
		}

		// get directory
		if ( ! empty( $folder ) ) {
			$path .= $folder;
			if ( ! file_exists( $path ) ) {
				Logger::add( sprintf( '  - create folder "%s".', $path ) );
				mkdir( $path, 0755, true );
			}
		}

		$path .= '/';
		return $path;
	}
}
