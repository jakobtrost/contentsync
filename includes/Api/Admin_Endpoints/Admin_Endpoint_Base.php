<?php

/**
 * Base class to be extended for connection endpoints
 *
 * @see     WP_REST_Controller
 * @source  /wp-includes/rest-api/endpoints/class-wp-rest-controller.php
 */
namespace Contentsync\Api\Admin_Endpoints;

abstract class Admin_Endpoint_Base {

	/**
	 * The namespace.
	 *
	 * @var string
	 */
	protected $namespace = CONTENTSYNC_REST_NAMESPACE . '/admin';

	/**
	 * Rest base for the current object.
	 *
	 * @var string
	 */
	protected $rest_base;

	/**
	 * Method
	 */
	protected $method = 'POST';

	/**
	 * The capability required to access the current endpoint.
	 *
	 * @var string  Default: 'manage_pages'
	 * @see current_user_can()
	 * @link https://developer.wordpress.org/reference/functions/current_user_can/
	 */
	protected $capability = 'manage_pages';

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

		if ( ! is_user_logged_in() ) {
			return false;
		}

		return current_user_can( $this->capability );
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
		return $this->is_request_allowed() ? 403 : 401;
	}

	/**
	 * Send a simple JSON response.
	 *
	 * @param mixed    $data    Response payload.
	 * @param string   $message Optional. Human-readable message.
	 * @param int|bool $status  Optional. HTTP status code (int) or success flag (bool). Default true.
	 *                         When bool: true => 200, false => 400.
	 * @return \WP_REST_Response
	 */
	public function respond( $data, $message = '', $status = true ) {
		$status_code = is_numeric( $status ) ? absint( $status ) : ( $status ? 200 : 400 );

		if ( empty( $message ) ) {
			$message = ( $status_code >= 200 && $status_code < 300 )
				? __( 'Request successful.', 'contentsync' )
				: __( 'Request failed.', 'contentsync' );
		}

		return new \WP_REST_Response(
			array(
				'status'  => $status_code,
				'message' => $message,
				'data'    => $data,
			),
			$status_code
		);
	}


	/**
	 * =================================================================
	 *                          Validations
	 * =================================================================
	 */

	/**
	 * Possible arguments for admin endpoints.
	 *
	 * Subclasses may override and array_merge with the parent, or pass only
	 * the args they need in their register_routes when passing 'args'.
	 *
	 * @return array Map of param names to validate_callback config.
	 */
	public function get_endpoint_args() {
		return array(
			'gid'                          => array(
				'validate_callback' => array( $this, 'is_gid' ),
			),
			'post_id'                      => array(
				'validate_callback' => array( $this, 'is_number' ),
			),
			'blog_id'                      => array(
				'validate_callback' => array( $this, 'is_number' ),
			),
			'review_id'                    => array(
				'validate_callback' => array( $this, 'is_number' ),
			),
			'posts'                        => array(
				'validate_callback' => array( $this, 'is_array_or_object' ),
			),
			'filename'                     => array(
				'validate_callback' => array( $this, 'is_string' ),
			),
			'conflicts'                    => array(
				'validate_callback' => array( $this, 'is_array_or_object' ),
			),
			'form_data'                    => array(
				'validate_callback' => array( $this, 'is_array_or_object' ),
			),
			'post_title'                   => array(
				'validate_callback' => array( $this, 'is_string' ),
			),
			'post_name'                    => array(
				'validate_callback' => array( $this, 'is_string' ),
			),
			'switch_references_in_content' => array(
				'validate_callback' => array( $this, 'is_bool' ),
			),
			'append_nested'                => array(
				'validate_callback' => array( $this, 'is_bool' ),
			),
			'resolve_menus'                => array(
				'validate_callback' => array( $this, 'is_bool' ),
			),
			'translations'                 => array(
				'validate_callback' => array( $this, 'is_bool' ),
			),
			'post_type'                    => array(
				'validate_callback' => array( $this, 'is_string' ),
			),
			'nested'                       => array(
				'validate_callback' => array( $this, 'is_bool' ),
			),
		);
	}

	/**
	 * @param mixed $value
	 * @return bool
	 */
	public function is_string( $value ) {
		return is_string( $value );
	}

	/**
	 * @param mixed $value
	 * @return bool
	 */
	public function is_number( $value ) {
		return is_numeric( $value );
	}

	/**
	 * @param mixed $value
	 * @return bool
	 */
	public function is_array_or_object( $value ) {
		return is_array( $value ) || is_object( $value );
	}

	/**
	 * @param mixed $value
	 * @return int 1 if matches gid pattern, 0 otherwise.
	 */
	public function is_gid( $value ) {
		$regex = '(?P<blog_id>\d+)-(?P<post_id>\d+)(-(?P<site_url>((http|https)\:\/\/)?[a-zA-Z0-9\.\/\?\:@\-_=#]+\.[a-zA-Z0-9\&\.\/\?\:@\-_=#%]*))?';
		return preg_match( '/^' . $regex . '$/', $value );
	}

	/**
	 * @param mixed $value
	 * @return bool
	 */
	public function is_bool( $value ) {
		if ( is_bool( $value ) ) {
			return true;
		}
		if ( in_array( $value, array( 0, 1, '0', '1' ), true ) ) {
			return true;
		}
		return false;
	}
}
