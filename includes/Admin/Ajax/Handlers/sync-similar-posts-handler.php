<?php
/**
 * Sync Similar Posts AJAX Handler
 *
 * Handles AJAX requests for finding similar synced posts.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync Similar Posts Handler Class
 */
class Sync_Similar_Posts_Handler extends Contentsync_Ajax_Handler {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'contentsync_similar_posts' );
	}

	/**
	 * Handle the AJAX request
	 *
	 * @param array $data Request data containing post_id.
	 */
	protected function handle( $data ) {
		$post_id = isset( $data['post_id'] ) ? intval( $data['post_id'] ) : 0;

		if ( empty( $post_id ) ) {
			$this->send_fail( __( 'post_id is not defined.', 'contentsync' ) );
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			$this->send_fail( sprintf( __( "Post with ID '%s' could not be found.", 'contentsync' ), $post_id ) );
			return;
		}

		$similar_posts = \Contentsync\Admin\get_similar_synced_posts( $post );

		if ( ! $similar_posts ) {
			$this->send_fail( __( 'No similar posts found.', 'contentsync' ) );
			return;
		}

		$this->send_success( json_encode( $similar_posts ) );
	}
}

new Sync_Similar_Posts_Handler();
