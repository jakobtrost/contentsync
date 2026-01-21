<?php
/**
 * Sync Overwrite AJAX Handler
 *
 * Handles AJAX requests for overwriting posts with synced content.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

/**
 * Sync Overwrite Handler Class
 */
class Sync_Overwrite_Handler extends Contentsync_Ajax_Handler {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'contentsync_overwrite' );
	}

	/**
	 * Handle the AJAX request
	 *
	 * @param array $data Request data containing post_id and gid.
	 */
	protected function handle( $data ) {
		$post_id = isset( $data['post_id'] ) ? intval( $data['post_id'] ) : 0;
		$gid     = isset( $data['gid'] ) ? strval( $data['gid'] ) : '';

		if ( empty( $post_id ) || empty( $gid ) ) {
			$this->send_fail( __( 'post_id or gid is not defined.', 'contentsync' ) );
			return;
		}

		$current_posts = array();
		$synced_post   = \Contentsync\Posts\Sync\get_synced_post( $gid );
		
		if ( $synced_post ) {
			$current_posts[ $synced_post->ID ] = array(
				'post_id' => $post_id,
				'action'  => 'replace',
			);
		}

		$result = \Contentsync\Posts\Sync\import_synced_post( $gid, $current_posts );

		if ( ! $result ) {
			$this->send_fail( __( 'post could not be overwritten...', 'contentsync' ) );
			return;
		}

		$this->send_success( __( 'post was successfully overwritten', 'contentsync' ) );
	}
}

new Sync_Overwrite_Handler();
