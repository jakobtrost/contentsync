<?php
/**
 * Post Import AJAX Handler
 *
 * Handles AJAX requests for importing posts from ZIP files.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax;

use Contentsync\Admin\Transfer\Post_Conflict_Handler;
use WP_Error;
use Contentsync\Posts\Transfer\Post_Import;
use Contentsync\Utils\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Post Import Handler Class
 */
class Post_Import_Handler extends Ajax_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'post_import' );
	}

	/**
	 * Handle the AJAX request
	 *
	 * @param array $data Request data containing filename and conflicts.
	 */
	protected function handle( $data ) {
		set_time_limit( 5000 );

		$filename = isset( $data['filename'] ) ? sanitize_file_name( $data['filename'] ) : '';

		if ( empty( $filename ) ) {
			$this->send_fail( __( 'The file name is empty.', 'contentsync_hub' ) );
			return;
		}

		// Get post data from ZIP file
		$zip_file  = Files::get_wp_content_folder_path( 'tmp' ) . $filename;
		$post_data = Files::get_posts_json_file_contents_from_zip( $zip_file );

		// Error checking
		if ( ! is_array( $post_data ) ) {
			$this->send_fail( $post_data );
			return;
		}

		// Get conflicts with current posts
		$conflicts        = isset( $data['conflicts'] ) ? (array) $data['conflicts'] : array();
		$conflict_actions = Post_Conflict_Handler::get_conflicting_post_selections( $conflicts );

		// Import posts
		$post_import   = new Post_Import(
			$post_data,
			array(
				'zip_file'         => $zip_file,
				'conflict_actions' => $conflict_actions,
			)
		);
		$import_result = $post_import->import_posts();

		if ( is_wp_error( $import_result ) ) {
			$this->send_fail( $import_result->get_error_message() );
			return;
		}

		$this->send_success( sprintf( __( "Post file '%s' has been imported successfully.", 'contentsync_hub' ), $filename ) );
	}
}

// Instantiate the handler
new Post_Import_Handler();
