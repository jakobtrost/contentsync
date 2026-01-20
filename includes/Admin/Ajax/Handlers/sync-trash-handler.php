<?php
/**
 * Sync Trash AJAX Handler
 *
 * Handles AJAX requests for trashing posts.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax;

use Contentsync\Utils\Multisite_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync Trash Handler Class
 */
class Sync_Trash_Handler extends Contentsync_Ajax_Handler {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'contentsync_trash' );
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

		if ( $blog_id ) {
			Multisite_Manager::switch_blog( $blog_id );
		}
		
		$result = wp_trash_post( $post_id );
		
		if ( $blog_id ) {
			Multisite_Manager::restore_blog();
		}

		if ( ! $result ) {
			$this->send_fail( __( 'post could not be trashed...', 'contentsync' ) );
			return;
		}

		$this->send_success( __( 'post was successfully trashed', 'contentsync' ) );
	}
}

new Sync_Trash_Handler();
