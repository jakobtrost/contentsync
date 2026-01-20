<?php
/**
 * Sync Repair AJAX Handler
 *
 * Handles AJAX requests for repairing posts with errors.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync Repair Handler Class
 */
class Sync_Repair_Handler extends Contentsync_Ajax_Handler {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'contentsync_repair' );
	}

	/**
	 * Handle the AJAX request
	 *
	 * @param array $data Request data containing post_id and optional blog_id.
	 */
	protected function handle( $data ) {
		$post_id = isset( $data['post_id'] ) ? intval( $data['post_id'] ) : 0;
		$blog_id = isset( $data['blog_id'] ) ? intval( $data['blog_id'] ) : null;

		if ( empty( $post_id ) ) {
			$this->send_fail( __( 'post_id is not defined.', 'contentsync' ) );
			return;
		}

		$error = \Contentsync\Admin\repair_post( $post_id, $blog_id, true );

		if ( ! $error ) {
			$this->send_fail( __( 'post has no error.', 'contentsync' ) );
			return;
		}

		// Output repair log
		echo \Contentsync\Admin\get_error_repaired_log( $error );

		if ( \Contentsync\Admin\is_error_repaired( $error ) ) {
			$this->send_success( __( 'post was successfully repaired', 'contentsync' ) );
		} else {
			$this->send_fail( $error->message );
		}
	}
}

new Sync_Repair_Handler();
