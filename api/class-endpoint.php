<?php

/**
 * Base class to be extended for connection endpoints
 *
 * @see     WP_REST_Controller
 * @source  /wp-includes/rest-api/endpoints/class-wp-rest-controller.php
 */
namespace Contentsync\Api;

class Endpoint {

	/**
	 * The namespace.
	 *
	 * @var string
	 */
	protected $namespace = CONTENTSYNC_REST_NAMESPACE;

	/**
	 * Rest base for the current object.
	 *
	 * @var string
	 */
	protected $rest_base;

	/**
	 * Method
	 */
	protected $method = 'GET';

	/**
	 * Regex to validate the Global ID (gid)
	 */
	protected $gid_regex = '(?P<gid>\d+-\d+(-[a-zA-Z0-9\.\-_]+\.([a-zA-Z0-9\.\-_])*)?)';

	/**
	 * Class constructor
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST API endpoints
	 */
	public function register_routes() {

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => $this->method,
					'callback'            => array( $this, 'callback' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
			)
		);
	}

	/**
	 * Endpoint callback
	 *
	 * @param WP_REST_Request $request
	 */
	public function callback( $request ) {
		return $this->respond( false );
	}

	/**
	 * Permission callback
	 *
	 * @param WP_REST_Request $request
	 */
	public function permission_callback( $request ) {
		if ( ! $this->is_request_allowed() ) {
			return new \WP_Error( 'rest_forbidden', esc_html__( 'You are not allowed to use this endpoint.' ), array( 'status' => $this->authorization_status_code() ) );
		}
		return true;
	}

	/**
	 * Is the request from the connection allowed?
	 */
	public function is_request_allowed() {

		if ( is_multisite() && is_super_admin() ) {
			return true;
		}

		if ( ! is_multisite() && current_user_can( 'manage_options' ) ) {
			return true;
		}

		return apply_filters( 'contentsync_is_request_allowed', false );
	}


	/**
	 * =================================================================
	 *                          Respond
	 * =================================================================
	 */

	/**
	 * Sets up the proper HTTP status code for authorization.
	 */
	public function authorization_status_code() {
		return $this->is_request_allowed() || is_user_logged_in() ? 403 : 401;
	}

	/**
	 * Send response
	 *
	 * @see \Contentsync\Api\send_response() for details.
	 */
	public function respond( $data, $message = '', $success = null, $status = null ) {
		return \Contentsync\Api\send_response( $data, $message, $success, $status );
	}


	/**
	 * =================================================================
	 *                          Validations
	 * =================================================================
	 */

	/**
	 * Possible arguments
	 */
	public function get_endpoint_args() {
		return array(
			'gid'      => array(
				'validate_callback' => array( $this, 'is_gid' ),
			),
			'blog_id'  => array(
				'validate_callback' => array( $this, 'is_number' ),
			),
			'post_id'  => array(
				'validate_callback' => array( $this, 'is_number' ),
			),
			'site_url' => array(
				'validate_callback' => array( $this, 'is_string' ),
			),
			'posts'    => array(
				'validate_callback' => array( $this, 'is_array_or_object' ),
			),
			'args'     => array(
				'validate_callback' => array( $this, 'is_array_or_object' ),
			),
		);
	}

	public function is_string( $value ) {
		return is_string( $value );
	}

	public function is_number( $value ) {
		return is_numeric( $value );
	}

	public function is_array_or_object( $value ) {
		return is_array( $value ) || is_object( $value );
	}

	public function is_gid( $value ) {
		$regex = '(?P<blog_id>\d+)-(?P<post_id>\d+)(-(?P<site_url>((http|https)\:\/\/)?[a-zA-Z0-9\.\/\?\:@\-_=#]+\.[a-zA-Z0-9\&\.\/\?\:@\-_=#%]*))?';
		return preg_match( '/^' . $regex . '$/', $value );
	}
}
