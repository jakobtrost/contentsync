<?php
/**
 * Endpoint 'add_site_connection'
 *
 * @link {{your-domain}}/wp-json/contentsync/v1/add_site_connection
 */
namespace Contentsync\Api\Endpoints;

use Contentsync\Api\Endpoint;
use Contentsync\Utils\Urls;

defined( 'ABSPATH' ) || exit;

new Add_Connection();
class Add_Connection extends Endpoint {

	/**
	 * Class constructor
	 */
	public function __construct() {

		$this->rest_base = 'add_site_connection';

		parent::__construct();
	}

	/**
	 * Register REST API endpoints
	 */
	public function register_routes() {

		// export post to connections
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_site_connection' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $this->get_endpoint_args(),
				),
			)
		);
	}

	/**
	 * Endpoint callback
	 *
	 * @param WP_REST_Request $request
	 */
	public function add_site_connection( $request ) {

		$request_url    = $request->get_header( 'Origin' );
		$current_url    = Urls::get_network_url();
		$new_connection = $request->get_param( 'connection' );

		if ( ! $new_connection ) {
			return new \WP_Error( 'rest_no_connection', esc_html__( 'No connection data provided.' ), array( 'status' => 400 ) );
		}

		// If the connection to the current site doesn't exist, create it.
		$result = \Contentsync\Posts\Sync\add_site_connection( $new_connection );

		if ( $result === null ) {
			return new \WP_Error( 'connection_not_added', 'Connection already exists', array( 'status' => 400 ) );
		} elseif ( ! $result ) {
			return new \WP_Error( 'connection_not_added', 'Connection could not be added', array( 'status' => 500 ) );
		}

		return $this->respond( $new_connection );
	}

	/**
	 * Check if the user is logged in to verify the connection
	 */
	public function permission_callback( $request ) {
		if ( $this->is_request_allowed() ) {
			return true;
		} else {
			return new \WP_Error( 'rest_not_authorized', esc_html__( 'You do not have the correct admin credentials to use this endpoint.' ), array( 'status' => $this->authorization_status_code() ) );
		}
	}
}
