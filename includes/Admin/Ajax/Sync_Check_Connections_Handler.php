<?php
/**
 * Sync Check Connections AJAX Handler
 *
 * Handles AJAX requests for checking post connections.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax;

use Contentsync\Posts\Sync\Post_Connection_Map;

defined( 'ABSPATH' ) || exit;

/**
 * Sync Check Connections Handler Class
 */
class Sync_Check_Connections_Handler extends Ajax_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'contentsync_check_post_connections' );
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

		$result = Post_Connection_Map::check( $post_id );

		if ( ! $result ) {
			$this->send_fail( __( 'some corrupted connections were detected and fixed.', 'contentsync' ) );
			return;
		}

		$this->send_success( __( 'there were no corrupted connections.', 'contentsync' ) );
	}
}

new Sync_Check_Connections_Handler();
