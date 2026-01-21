<?php
/**
 * Sync Update Connections AJAX Handler
 *
 * Handles AJAX requests for updating site connection options.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax;

use Contentsync\Distribution\Site_Connection;

defined( 'ABSPATH' ) || exit;

/**
 * Sync Update Connections Handler Class
 */
class Sync_Update_Connections_Handler extends Contentsync_Ajax_Handler {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'contentsync_update_site_connections' );
	}

	/**
	 * Handle the AJAX request
	 *
	 * @param array $data Request data containing site_url, contents, and search.
	 */
	protected function handle( $data ) {
		$site_url   = isset( $data['site_url'] ) ? sanitize_text_field( $data['site_url'] ) : '';
		$contents   = isset( $data['contents'] ) ? ( $data['contents'] === 'true' || $data['contents'] === true ) : true;
		$search     = isset( $data['search'] ) ? ( $data['search'] === 'true' || $data['search'] === true ) : true;

		if ( empty( $site_url ) ) {
			$this->send_fail( __( 'site_url is not defined.', 'contentsync' ) );
			return;
		}

		$connection = Site_Connection::get( $site_url );

		if ( ! $connection ) {
			$this->send_fail( __( 'connection options could not be saved.', 'contentsync' ) );
			return;
		}

		$connection['contents'] = $contents;
		$connection['search']   = $search;
		$result                 = Site_Connection::update( $connection );

		if ( ! $result ) {
			$this->send_fail( __( 'connection options could not be saved.', 'contentsync' ) );
			return;
		}

		$this->send_success( __( 'connection options successfully saved.', 'contentsync' ) );
	}
}

new Sync_Update_Connections_Handler();
