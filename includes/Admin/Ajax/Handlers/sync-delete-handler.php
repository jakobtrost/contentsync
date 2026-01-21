<?php
/**
 * Sync Delete AJAX Handler
 *
 * Handles AJAX requests for deleting synced posts.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

/**
 * Sync Delete Handler Class
 */
class Sync_Delete_Handler extends Contentsync_Ajax_Handler {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'contentsync_delete' );
	}

	/**
	 * Handle the AJAX request
	 *
	 * @param array $data Request data containing gid.
	 */
	protected function handle( $data ) {
		$gid = isset( $data['gid'] ) ? strval( $data['gid'] ) : '';

		if ( empty( $gid ) ) {
			$this->send_fail( __( 'global ID is not defined.', 'contentsync' ) );
			return;
		}

		$result = \Contentsync\Posts\Sync\delete_synced_post( $gid );

		if ( ! $result ) {
			$this->send_fail( __( 'post could not be deleted...', 'contentsync' ) );
			return;
		}

		$this->send_success( __( 'post was successfully deleted', 'contentsync' ) );
	}
}

new Sync_Delete_Handler();
