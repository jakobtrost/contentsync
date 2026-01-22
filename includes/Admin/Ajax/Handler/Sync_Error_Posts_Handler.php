<?php
/**
 * Sync Error Posts AJAX Handler
 *
 * Handles AJAX requests for checking posts with errors.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax\Handler;

use Contentsync\Posts\Sync\Post_Error_Handler;

defined( 'ABSPATH' ) || exit;

/**
 * Sync Error Posts Handler Class
 */
class Sync_Error_Posts_Handler extends Ajax_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'contentsync_error_posts' );
	}

	/**
	 * Handle the AJAX request
	 *
	 * @param array $data Request data containing mode, blog_id, and post_type.
	 */
	protected function handle( $data ) {
		$mode      = isset( $data['mode'] ) ? strval( $data['mode'] ) : '';
		$blog_id   = isset( $data['blog_id'] ) ? intval( $data['blog_id'] ) : null;
		$post_type = isset( $data['post_type'] ) ? intval( $data['post_type'] ) : null;

		if ( $mode === 'network' ) {
			$posts = Post_Error_Handler::get_network_synced_posts_with_errors( false, array( 'post_type' => $post_type ) );
		} else {
			$posts = Post_Error_Handler::get_synced_posts_of_blog_with_errors( $blog_id, false, array( 'post_type' => $post_type ) );
		}

		if ( empty( $posts ) ) {
			$this->send_fail( __( 'No errors found.', 'contentsync' ) );
			return;
		}

		$return = array_filter(
			$posts,
			function ( $post ) {
				return isset( $post->error ) && ! Post_Error_Handler::is_error_repaired( $post->error );
			}
		);

		if ( empty( $return ) ) {
			$this->send_fail( __( 'No errors found.', 'contentsync' ) );
			return;
		}

		$this->send_success( json_encode( array_values( $return ) ) );
	}
}

new Sync_Error_Posts_Handler();
