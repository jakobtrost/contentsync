<?php
/**
 * Sync Check Import Bulk AJAX Handler
 *
 * Handles AJAX requests for checking multiple synced posts before import.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax\Handler;

use Contentsync\Admin\Transfer\Post_Conflict_Handler;

defined( 'ABSPATH' ) || exit;

/**
 * Sync Check Import Bulk Handler Class
 */
class Sync_Check_Import_Bulk_Handler extends Ajax_Base {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'contentsync_check_synced_post_import_bulk' );
	}

	/**
	 * Handle the AJAX request
	 *
	 * @param array $data Request data containing posts array.
	 */
	protected function handle( $data ) {
		$posts = isset( $data['posts'] ) ? (array) $data['posts'] : array();

		if ( empty( $posts ) ) {
			$this->send_fail( __( 'global IDs are not defined.', 'contentsync' ) );
			return;
		}

		$results = array();
		foreach ( $posts as $post ) {
			if ( ! isset( $post['gid'] ) ) {
				continue;
			}
			$conflict = Post_Conflict_Handler::check_synced_post_import( $post['gid'] );
			if ( $conflict ) {
				$results[] = array(
					'gid'      => $post['gid'],
					'conflict' => $conflict,
				);
			}
		}

		if ( empty( $results ) ) {
			$this->send_fail( __( 'posts could not be checked for conflicts.', 'contentsync' ) );
			return;
		}

		$this->send_success( json_encode( $results ) );
	}
}

new Sync_Check_Import_Bulk_Handler();
