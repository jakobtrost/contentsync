<?php
/**
 * Sync Import AJAX Handler
 *
 * Handles AJAX requests for importing synced posts.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

/**
 * Sync Import Handler Class
 */
class Sync_Import_Handler extends Contentsync_Ajax_Handler {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'contentsync_import' );
	}

	/**
	 * Handle the AJAX request
	 *
	 * @param array $data Request data containing gid and form_data.
	 */
	protected function handle( $data ) {
		$gid = isset( $data['gid'] ) ? strval( $data['gid'] ) : '';

		if ( empty( $gid ) ) {
			$this->send_fail( __( 'global ID is not defined.', 'contentsync' ) );
			return;
		}

		// Get conflicts with current posts
		$conflicts        = isset( $data['form_data'] ) ? (array) $data['form_data'] : array();
		$conflict_actions = \Contentsync\Admin\Main_Helper::call_post_export_func( 'get_conflicting_post_selections', $conflicts );

		$result = \Contentsync\Posts\Sync\import_synced_post( $gid, $conflict_actions );

		if ( $result !== true ) {
			$this->send_fail( $result );
			return;
		}

		$this->send_success( __( 'post was imported!', 'contentsync' ) );
	}
}

new Sync_Import_Handler();
