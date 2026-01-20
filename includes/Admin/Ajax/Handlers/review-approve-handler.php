<?php
/**
 * Review Approve AJAX Handler
 *
 * Handles AJAX requests for approving post reviews.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Review Approve Handler Class
 */
class Review_Approve_Handler extends Contentsync_Ajax_Handler {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'contentsync_review_approve' );
	}

	/**
	 * Handle the AJAX request
	 *
	 * @param array $data Request data containing review_id and post_id.
	 */
	protected function handle( $data ) {
		$review_id = isset( $data['review_id'] ) ? intval( $data['review_id'] ) : 0;
		$post_id   = isset( $data['post_id'] ) ? intval( $data['post_id'] ) : 0;

		$result = \Contentsync\Reviews\approve_post_review( $review_id, $post_id );

		if ( ! $result ) {
			$this->send_fail( __( 'review could not be approved.', 'contentsync' ) );
			return;
		}

		$this->send_success( __( 'review was approved.', 'contentsync' ) );
	}
}

new Review_Approve_Handler();
