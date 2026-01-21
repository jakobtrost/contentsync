<?php
/**
 * Sync Unimport AJAX Handler
 *
 * Handles AJAX requests for unlinking imported posts (making them static).
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax;

use Contentsync\Posts\Sync\Synced_Post_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Sync Unimport Handler Class
 */
class Sync_Unimport_Handler extends Ajax_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'contentsync_unimport' );
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

		$result = Synced_Post_Service::unlink_synced_post( $post_id );

		if ( ! $result ) {
			$this->send_fail( __( 'post could not be made static...', 'contentsync' ) );
			return;
		}

		$this->send_success( __( 'post was successfully made static', 'contentsync' ) );
	}
}

new Sync_Unimport_Handler();
