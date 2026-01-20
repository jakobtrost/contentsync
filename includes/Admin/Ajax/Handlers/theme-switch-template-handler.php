<?php
/**
 * Theme Switch Template AJAX Handler
 *
 * Handles AJAX requests for switching template themes.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Theme Switch Template Handler Class
 */
class Theme_Switch_Template_Handler extends Contentsync_Ajax_Handler {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'switch_template_theme' );
	}

	/**
	 * Handle the AJAX request
	 *
	 * @param array $data Request data containing post_id and switch_references_in_content.
	 */
	protected function handle( $data ) {
		$post_id = isset( $data['post_id'] ) ? intval( $data['post_id'] ) : 0;
		$switch_references_in_content = isset( $data['switch_references_in_content'] ) ? (bool) $data['switch_references_in_content'] : false;

		if ( empty( $post_id ) ) {
			$this->send_fail( __( 'No valid post ID found.', 'contentsync_hub' ) );
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			$this->send_fail( __( 'Post not found.', 'contentsync_hub' ) );
			return;
		}

		$result = \Contentsync\Posts\set_wp_template_theme( $post, $switch_references_in_content );

		if ( is_wp_error( $result ) ) {
			$this->send_fail( $result->get_error_message() );
			return;
		}

		if ( ! $result ) {
			$this->send_fail( __( 'Template could not be assigned to the current theme.', 'contentsync_hub' ) );
			return;
		}

		$this->send_success( __( 'Template was assigned to the current theme.', 'contentsync_hub' ) );
	}
}

// Instantiate the handler
new Theme_Switch_Template_Handler();
