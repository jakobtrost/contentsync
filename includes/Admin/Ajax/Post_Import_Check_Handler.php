<?php
/**
 * Post Import Check AJAX Handler
 *
 * Handles AJAX requests for checking uploaded import files before importing.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax;

use Contentsync\Admin\Transfer\Post_Conflict_Handler;
use Contentsync\Posts\Transfer;
use Contentsync\Utils\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Post Import Check Handler Class
 */
class Post_Import_Check_Handler extends Ajax_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'check_post_import' );
	}

	/**
	 * Handle the AJAX request
	 *
	 * @param array $data Request data (should be empty, file comes from $_FILES).
	 */
	protected function handle( $data ) {
		set_time_limit( 5000 );

		// Get file data from $_FILES
		$file_data = $this->get_file_data();

		if ( ! $file_data ) {
			$this->send_fail( __( 'No file was uploaded.', 'contentsync_hub' ) );
			return;
		}

		// Check for upload errors
		if ( isset( $file_data['error'] ) && $file_data['error'] > 0 ) {
			$file_errors = array(
				1 => sprintf(
					__( "The uploaded file exceeds the server's maximum file limit (max %s MB). The limit is defined in the <u>php.ini</u> file.", 'contentsync_hub' ),
					intval( ini_get( 'upload_max_filesize' ) )
				),
				2 => __( 'The uploaded file exceeds the allowed file size of the html form.', 'contentsync_hub' ),
				3 => __( 'The uploaded file was only partially uploaded.', 'contentsync_hub' ),
				4 => __( 'No file was uploaded.', 'contentsync_hub' ),
				6 => __( 'Missing a temporary folder.', 'contentsync_hub' ),
				7 => __( 'Failed to save the file.', 'contentsync_hub' ),
				8 => __( 'The file was stopped while uploading.', 'contentsync_hub' ),
			);

			$error_message = isset( $file_errors[ $file_data['error'] ] ) ? $file_errors[ $file_data['error'] ] : __( 'Unknown upload error.', 'contentsync_hub' );
			$this->send_fail( $error_message );
			return;
		}

		// File info
		$filename = isset( $file_data['name'] ) ? $file_data['name'] : '';
		$filepath = isset( $file_data['tmp_name'] ) ? $file_data['tmp_name'] : '';
		$filetype = isset( $file_data['type'] ) ? $file_data['type'] : '';

		// Check filetype
		if ( $filetype !== 'application/zip' && $filetype !== 'application/x-zip-compressed' ) {
			$this->send_fail( __( 'Please select a valid ZIP archive.', 'contentsync_hub' ) );
			return;
		}

		// Create tmp zip
		$new_file = Files::get_wp_content_folder_path( 'tmp' ) . $filename;
		$result   = move_uploaded_file( $filepath, $new_file );

		if ( ! $result ) {
			$this->send_fail( __( 'Failed to save the uploaded file.', 'contentsync_hub' ) );
			return;
		}

		// Get post data from ZIP
		$post_data = Files::get_posts_json_file_contents_from_zip( $new_file );

		if ( ! is_array( $post_data ) ) {
			$this->send_fail( $post_data );
			return;
		}

		// Get conflicting posts
		$conflicts = Post_Conflict_Handler::get_conflicting_post_options( $post_data );

		if ( $conflicts ) {
			$return = $conflicts;
		} else {
			// Return file name when no conflicts found
			$return = $filename;
		}

		$this->send_success( $return );
	}

	/**
	 * Get file data from $_FILES
	 *
	 * @return array|false File data or false if not found.
	 */
	private function get_file_data() {
		if ( ! empty( $_FILES['data'] ) ) {
			return $_FILES['data'];
		}

		// Also check for direct file upload
		if ( ! empty( $_FILES['file'] ) ) {
			return $_FILES['file'];
		}

		return false;
	}
}

// Instantiate the handler
new Post_Import_Check_Handler();
