<?php
/**
 * Sync Unexport AJAX Handler
 *
 * Handles AJAX requests for unlinking synced posts.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync Unexport Handler Class
 */
class Sync_Unexport_Handler extends Contentsync_Ajax_Handler {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'contentsync_unexport' );
	}

	/**
	 * Handle the AJAX request
	 *
	 * @param array $data Request data containing gid.
	 */
	protected function handle( $data ) {
		$gid = isset( $data['gid'] ) ? sanitize_text_field( $data['gid'] ) : '';

		if ( empty( $gid ) ) {
			$this->send_fail( __( 'global ID is not defined.', 'contentsync' ) );
			return;
		}

		$result = \Contentsync\Posts\Sync\unlink_synced_root_post( $gid );

		if ( ! $result ) {
			$this->send_fail( __( 'exported post could not be unlinked globally...', 'contentsync' ) );
			return;
		}

		$this->send_success( __( 'post was unlinked and the synced post was removed', 'contentsync' ) );
	}
}

// Instantiate the handler
new Sync_Unexport_Handler();
