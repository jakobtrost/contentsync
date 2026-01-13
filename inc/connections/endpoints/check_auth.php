<?php
/**
 * Endpoint 'check_auth'
 * 
 * @link {{your-domain}}/wp-json/contentsync/v1/check_auth
 */
namespace Contentsync\Connections\Endpoints;

use \Contentsync\Connections\Connections_Helper;

if(!defined('ABSPATH')) exit;

new Check_Auth;
class Check_Auth extends \Contentsync\Connections\Endpoint {

	/**
	 * Class constructor
	 */
	public function __construct() {

		$this->rest_base = "check_auth";

		parent::__construct();
	}

	/**
	 * Endpoint callback
	 * 
	 * @param WP_REST_Request $request
	 */
	public function callback($request) {
		$request_url = $request->get_header("Origin");
		$current_url = Connections_Helper::get_network_url();
		return $this->respond("true", "The connection from $request_url (current site) to $current_url (remote site) is active.");
	}

	/**
	 * Check if the user is logged in to verify the connection
	 */
	public function permission_callback($request) {
		if ( Connections_Helper::is_allowed() ) {
			$origin = $request->get_header("Origin");
			if ( $origin && Connections_Helper::get_connection($origin) ) {
				return true;
			} else {
				return new \WP_Error( 'rest_not_connected', esc_html__( 'You do have the correct admin credentials, but the connection is not setup both ways.' ), array( 'status' => $this->authorization_status_code() ) );
			}
		} else {
			return new \WP_Error( 'rest_not_authorized', esc_html__( 'You do not have the correct admin credentials to use this endpoint.' ), array( 'status' => $this->authorization_status_code() ) );
		}
	}
}