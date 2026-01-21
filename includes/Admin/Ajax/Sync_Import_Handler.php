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

use Contentsync\Admin\Transfer\Post_Conflict_Handler;
use Contentsync\Posts\Sync\Synced_Post_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Sync Import Handler Class
 */
class Sync_Import_Handler extends Ajax_Base {

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
		$conflict_actions = Post_Conflict_Handler::get_conflicting_post_selections( $conflicts );

		$result = Synced_Post_Service::import_synced_post( $gid, $conflict_actions );

		if ( $result !== true ) {
			$this->send_fail( $result );
			return;
		}

		$this->send_success( __( 'post was imported!', 'contentsync' ) );
	}
}

new Sync_Import_Handler();
