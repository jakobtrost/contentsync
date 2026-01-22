<?php
/**
 * Theme Rename Template AJAX Handler
 *
 * Handles AJAX requests for renaming templates.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax\Handler;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Theme Rename Template Handler Class
 */
class Theme_Rename_Template_Handler extends Ajax_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'rename_template' );
	}

	/**
	 * Handle the AJAX request
	 *
	 * @param array $data Request data containing post_id, post_title, and post_name.
	 */
	protected function handle( $data ) {
		$post_id    = isset( $data['post_id'] ) ? intval( $data['post_id'] ) : 0;
		$post_title = isset( $data['post_title'] ) ? sanitize_text_field( $data['post_title'] ) : '';
		$post_name  = isset( $data['post_name'] ) ? sanitize_title( $data['post_name'] ) : '';

		if ( empty( $post_id ) ) {
			$this->send_fail( __( 'No valid post ID found.', 'contentsync_hub' ) );
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			$this->send_fail( __( 'Post not found.', 'contentsync_hub' ) );
			return;
		}

		// Update post title and name
		$post->post_title = $post_title;
		$post->post_name  = $post_name;

		$result = wp_update_post( $post, true );

		if ( is_wp_error( $result ) ) {
			$this->send_fail( $result->get_error_message() );
			return;
		}

		if ( ! $result ) {
			$this->send_fail( __( 'Template could not be renamed.', 'contentsync_hub' ) );
			return;
		}

		$this->send_success( __( 'Template was renamed.', 'contentsync_hub' ) );
	}
}

// Instantiate the handler
new Theme_Rename_Template_Handler();
