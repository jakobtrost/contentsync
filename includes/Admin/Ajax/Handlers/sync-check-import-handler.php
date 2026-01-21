<?php
/**
 * Sync Check Import AJAX Handler
 *
 * Handles AJAX requests for checking synced posts before import.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

/**
 * Sync Check Import Handler Class
 */
class Sync_Check_Import_Handler extends Contentsync_Ajax_Handler {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'contentsync_check_synced_post_import' );
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

		$result = \Contentsync\Admin\Transfer\check_synced_post_import( $gid );

		if ( ! $result ) {
			$this->send_fail( __( 'post could not be checked for conflicts.', 'contentsync' ) );
			return;
		}

		$this->send_success( $result );
	}
}

new Sync_Check_Import_Handler();
