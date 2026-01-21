<?php
/**
 * Review Revert AJAX Handler
 *
 * Handles AJAX requests for reverting post reviews.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax;

use Contentsync\Reviews\Post_Review_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Review Revert Handler Class
 */
class Review_Revert_Handler extends Ajax_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'contentsync_review_revert' );
	}

	/**
	 * Handle the AJAX request
	 *
	 * @param array $data Request data containing review_id, post_id, and message.
	 */
	protected function handle( $data ) {
		$review_id = isset( $data['review_id'] ) ? intval( $data['review_id'] ) : 0;
		$post_id   = isset( $data['post_id'] ) ? intval( $data['post_id'] ) : 0;
		$message   = isset( $data['message'] ) ? sanitize_textarea_field( $data['message'] ) : '';

		$result = Post_Review_Service::revert_post_review( $review_id, $post_id, $message );

		if ( ! $result ) {
			$this->send_fail( __( 'review could not be reverted.', 'contentsync' ) );
			return;
		}

		$this->send_success( __( 'review was reverted.', 'contentsync' ) );
	}
}

new Review_Revert_Handler();
