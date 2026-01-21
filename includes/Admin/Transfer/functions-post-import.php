<?php

namespace Contentsync\Admin\Transfer;

use Contentsync\Utils\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Get the „posts.json“ file contents inside an imported zip archive
 *
 * @param string $filepath  Relative path to the zip including filename.
 *
 * @return mixed            String with error message on failure.
 *                          Array of contents on success.
 */
function get_zip_posts_file_contents( $filepath ) {

	if ( ! file_exists( $filepath ) ) {
		__( 'The ZIP archive could not be found. It may have been moved or deleted.', 'contentsync_hub' );
	} else {
		Logger::add( sprintf( '  - ZIP archive "%s" found.', $filepath ) );
	}

	// open 'posts.json' file inside zip archive
	$zip       = 'zip://' . $filepath . '#posts.json';
	$json_file = file_get_contents( $zip );

	if ( ! $json_file ) {
		return __( 'The ZIP archive does not contain a valid "posts.json" file.', 'contentsync_hub' );
	} else {
		Logger::add( sprintf( '  - file "%s" found.', 'posts.json' ) );
	}

	// decode json data
	$contents = json_decode( $json_file, true );
	if ( $contents === null && json_last_error() !== JSON_ERROR_NONE ) {
		return __( 'The post.json file could not be read.', 'contentsync_hub' );
	} else {
		Logger::add( '  - decoded json.' );
	}

	if ( ! is_array( $contents ) ) {
		return __( 'The posts.json file does not contain any data.', 'contentsync_hub' );
	} else {
		Logger::add( '  - json contains object.' );
	}

	// convert posts back to objects
	foreach ( $contents as $post_id => $post ) {
		$contents[ $post_id ] = (object) $post;
	}

	return $contents;
}
