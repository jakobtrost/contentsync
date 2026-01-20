<?php
/**
 * Sync Export AJAX Handler
 *
 * Handles AJAX requests for making posts synced (exporting to global content).
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sync Export Handler Class
 */
class Sync_Export_Handler extends Contentsync_Ajax_Handler {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'contentsync_export' );
	}

	/**
	 * Handle the AJAX request
	 *
	 * @param array $data Request data containing post_id and form_data.
	 */
	protected function handle( $data ) {
		$post_id = isset( $data['post_id'] ) ? intval( $data['post_id'] ) : 0;

		if ( empty( $post_id ) ) {
			$this->send_fail( __( 'post_id is not defined.', 'contentsync' ) );
			return;
		}

		$args      = \Contentsync\Posts\Sync\get_contentsync_default_export_options();
		$form_data = isset( $data['form_data'] ) ? (array) $data['form_data'] : array();
		
		foreach ( $args as $k => $v ) {
			if ( isset( $form_data[ $k ] ) ) {
				$args[ $k ] = true;
			}
		}

		$gid = \Contentsync\Posts\Sync\make_post_synced( $post_id, $args );

		if ( ! $gid ) {
			$this->send_fail( __( 'post could not be exported globally...', 'contentsync' ) );
			return;
		}

		$this->send_success( sprintf( __( 'post was exported with the global id of %s', 'contentsync' ), $gid ) );
	}
}

// Instantiate the handler
new Sync_Export_Handler();
