<?php
/**
 * Post Import Admin REST Endpoint
 *
 * Handles REST requests for checking uploaded import files and importing posts
 * from ZIP files. Combines the former Post_Import_Check_Handler and
 * Post_Import_Handler AJAX handlers.
 *
 * @package Contentsync
 * @subpackage Api\Admin_Endpoints
 */

namespace Contentsync\Api\Admin_Endpoints;

use Contentsync\Post_Transfer\Post_Conflict_Handler;
use Contentsync\Post_Transfer\Post_Import;
use Contentsync\Utils\Files;
use Contentsync\Utils\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Post Import Endpoint Class
 */
class Post_Import_Endpoint extends Admin_Endpoint_Base {

	/**
	 * REST base for this endpoint.
	 *
	 * @var string
	 */
	protected $rest_base = 'post-import';

	/**
	 * Param names for the import route.
	 *
	 * @var array
	 */
	private static $import_route_param_names = array( 'filename', 'conflicts' );

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		// POST /post-import/check — file upload, returns conflicts or filename
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/check',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'check_import' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array(),
			)
		);

		// POST /post-import — params: filename, conflicts
		$import_args = array_intersect_key(
			$this->get_endpoint_args(),
			array_flip( self::$import_route_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'import' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $import_args,
			)
		);
	}

	/**
	 * Check uploaded import file: validate ZIP, extract post data, return conflicts or filename.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check_import( $request ) {
		set_time_limit( 5000 );

		$files = $request->get_file_params();
		$file  = $this->get_uploaded_file( $files );

		if ( ! $file ) {
			return $this->respond( false, __( 'No file was uploaded.', 'contentsync' ), 400 );
		}

		if ( ! empty( $file['error'] ) ) {
			$message = $this->get_upload_error_message( (int) $file['error'] );
			return $this->respond( false, $message, 400 );
		}

		$filetype = $file['type'] ?? '';
		if ( $filetype !== 'application/zip' && $filetype !== 'application/x-zip-compressed' ) {
			return $this->respond( false, __( 'Please select a valid ZIP archive.', 'contentsync' ), 400 );
		}

		$filename = sanitize_file_name( $file['name'] ) ?? '';
		$tmp_name = $file['tmp_name'] ?? '';
		$new_file = Files::get_wp_content_folder_path( 'tmp' ) . $filename;

		if ( ! move_uploaded_file( $tmp_name, $new_file ) ) {
			return $this->respond( false, __( 'Failed to save the uploaded file.', 'contentsync' ), 400 );
		}

		Logger::add( 'new_file', $new_file );
		$post_data = Files::get_posts_json_file_contents_from_zip( $new_file );

		if ( ! is_array( $post_data ) ) {
			return $this->respond( false, is_string( $post_data ) ? $post_data : __( 'Invalid or missing posts.json in ZIP.', 'contentsync' ), 400 );
		}

		$posts = Post_Conflict_Handler::get_import_posts_with_conflicts( $post_data );

		return $this->respond( $posts, '', true );
	}

	/**
	 * Import posts from a previously checked ZIP (by filename) with conflict selections.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( $request ) {
		set_time_limit( 5000 );
		// Logger::add( 'import params', $request->get_params() );

		$filename = sanitize_file_name( $request->get_param( 'filename' ) ?? '' );

		if ( empty( $filename ) ) {
			return $this->respond( false, __( 'The file name is empty.', 'contentsync' ), 400 );
		}

		$zip_file  = Files::get_wp_content_folder_path( 'tmp' ) . $filename;
		$post_data = Files::get_posts_json_file_contents_from_zip( $zip_file );

		if ( ! is_array( $post_data ) ) {
			$message = is_string( $post_data ) ? $post_data : __( 'Invalid or missing posts.json in ZIP.', 'contentsync' );
			return $this->respond( false, $message, 400 );
		}

		/**
		 * Get the conflicts from the request. If done right, the conflicts array will be like this:
		 *
		 * conflicts: [
		 *   0 => array(
		 *     'existing_post_id' => 123,
		 *     'original_post_id' => 456,
		 *     'conflict_action' => 'keep'
		 *   ),
		 *   1 => array(
		 *     'existing_post_id' => 789,
		 *     'original_post_id' => 101,
		 *     'conflict_action' => 'replace'
		 *   )
		 * ]
		 */
		$conflicts = (array) ( $request->get_param( 'conflicts' ) ?? array() );
		foreach ( $conflicts as $conflict ) {
			$original_post_id = $conflict['original_post_id'] ?? 0;
			if ( isset( $post_data[ $original_post_id ] ) ) {
				/**
				 * Set the @property conflict_action for the post in the post_data array
				 *
				 * @see \Contentsync\Post_Transfer\Post_Import::import_posts()
				 *      -> "Get conflicting post and action." section
				 */
				$post_data[ $original_post_id ]->conflict_action = $conflict['conflict_action'];
			}
		}

		$post_import   = new Post_Import(
			$post_data,
			array(
				'zip_file' => $zip_file,
			)
		);
		$import_result = $post_import->import_posts();

		if ( is_wp_error( $import_result ) ) {
			return $this->respond( false, $import_result->get_error_message(), 400 );
		}

		$message = sprintf( __( "Post file '%s' has been imported successfully.", 'contentsync' ), $filename );
		return $this->respond( true, $message, true );
	}

	/**
	 * Get the uploaded file from request file params (supports 'data' or 'file' keys).
	 *
	 * @param array $files Result of $request->get_file_params().
	 * @return array|null File array or null if not found.
	 */
	private function get_uploaded_file( $files ) {
		if ( ! empty( $files['data'] ) && is_array( $files['data'] ) ) {
			return $files['data'];
		}
		if ( ! empty( $files['file'] ) && is_array( $files['file'] ) ) {
			return $files['file'];
		}
		return null;
	}

	/**
	 * Get a translated message for a PHP upload error code.
	 *
	 * @param int $code Upload error code.
	 * @return string
	 */
	private function get_upload_error_message( $code ) {
		$messages = array(
			1 => sprintf(
				__( "The uploaded file exceeds the server's maximum file limit (max %s MB). The limit is defined in the <u>php.ini</u> file.", 'contentsync' ),
				(int) ini_get( 'upload_max_filesize' )
			),
			2 => __( 'The uploaded file exceeds the allowed file size of the html form.', 'contentsync' ),
			3 => __( 'The uploaded file was only partially uploaded.', 'contentsync' ),
			4 => __( 'No file was uploaded.', 'contentsync' ),
			6 => __( 'Missing a temporary folder.', 'contentsync' ),
			7 => __( 'Failed to save the file.', 'contentsync' ),
			8 => __( 'The file was stopped while uploading.', 'contentsync' ),
		);
		return $messages[ $code ] ?? __( 'Unknown upload error.', 'contentsync' );
	}
}
