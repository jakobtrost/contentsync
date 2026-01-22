<?php
/**
 * Post Export AJAX Handler
 *
 * Handles AJAX requests for exporting posts to ZIP files.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax\Handler;

use Contentsync\Posts\Transfer\Post_Export;

defined( 'ABSPATH' ) || exit;

/**
 * Post Export Handler Class
 */
class Post_Export_Handler extends Ajax_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'post_export' );
	}

	/**
	 * Handle the AJAX request
	 *
	 * @param array $data Request data containing post_id and export options.
	 */
	protected function handle( $data ) {
		$post_id = isset( $data['post_id'] ) ? intval( $data['post_id'] ) : 0;

		if ( empty( $post_id ) ) {
			$this->send_fail( __( 'No valid post ID could be found.', 'contentsync_hub' ) );
			return;
		}

		// Build export arguments
		$args = array(
			'append_nested' => isset( $data['nested'] ) || isset( $data['append_nested'] ) ? true : false,
			'resolve_menus' => isset( $data['resolve_menus'] ) ? true : false,
			'translations'  => isset( $data['translations'] ) ? true : false,
		);

		// Export post
		$filepath = ( new Post_Export( $post_id, $args ) )->export_to_zip();

		if ( ! $filepath ) {
			$this->send_fail( __( 'The export file could not be written.', 'contentsync_hub' ) );
			return;
		}

		// Convert file path to URL path
		$url_path = $this->convert_wp_content_dir_to_path( $filepath );

		$this->send_success( $url_path );
	}

	/**
	 * Convert WP_CONTENT_DIR path to URL path
	 *
	 * @param string $filepath Full file path.
	 * @return string URL path.
	 */
	private function convert_wp_content_dir_to_path( $filepath ) {
		// Replace WP_CONTENT_DIR with WP_CONTENT_URL
		$url_path = str_replace( WP_CONTENT_DIR, WP_CONTENT_URL, $filepath );
		// Ensure forward slashes
		$url_path = str_replace( '\\', '/', $url_path );
		return $url_path;
	}
}

// Instantiate the handler
new Post_Export_Handler();
