<?php
/**
 * Init the Global Connection Endpoints.
 *
 * The different endpoints can be found in the directory '/endpoints'.
 */
namespace Contentsync\Connections;

if ( !defined( 'ABSPATH' ) ) exit;

new Init_Endpoints;
class Init_Endpoints {

	/**
	 * All endpoints
	 */
	protected $endpoints = array(
		"site_name",
		"check_auth",
		"add_connection",
		"posts",
		"posts_connections",
		"connected_posts",
		"posts_meta",
		"distribution-endpoint",
	);

	/**
	 * Class constructor
	 */
	public function __construct() {
		//
		add_action( 'init', array($this, 'init_endpoints') );
	}

	public function init_endpoints() {
		$endpoints = array();
		foreach($this->endpoints as $endpoint) {
			array_push($endpoints, __DIR__."/endpoints/{$endpoint}.php");
		}
		// filter to add endpoints from e.g. global content plugin
		$endpoints = apply_filters('contentsync_endpoints', $endpoints);
		foreach($endpoints as $endpoint) {
			if (file_exists($endpoint)) require_once $endpoint;
		}
	}

}

/**
 * Base class to be extended for connection endpoints
 *
 * @see     WP_REST_Controller
 * @source  /wp-includes/rest-api/endpoints/class-wp-rest-controller.php
 */
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
	protected $gid_regex = "(?P<gid>\d+-\d+(-[a-zA-Z0-9\.\-_]+\.([a-zA-Z0-9\.\-_])*)?)";

	/**
	 * Class constructor
	 */
	public function __construct() {
		add_action('rest_api_init', array( $this, 'register_routes'));
	}

	/**
	 * Register REST API endpoints
	 */
	public function register_routes() {

		register_rest_route($this->namespace, '/'.$this->rest_base, array(
			array(
				'methods'               => $this->method,
				'callback'              => array($this, 'callback'),
				'permission_callback'   => array($this, 'permission_callback'),
			)
		));
	}

	/**
	 * Endpoint callback
	 *
	 * @param WP_REST_Request $request
	 */
	public function callback($request) {
		return $this->respond(false);
	}

	/**
	 * Permission callback
	 *
	 * @param WP_REST_Request $request
	 */
	public function permission_callback($request) {
		if ( !Connections_Helper::is_allowed() ) {
			return new \WP_Error( 'rest_forbidden', esc_html__( 'You are not allowed to use this endpoint.' ), array( 'status' => $this->authorization_status_code() ) );
		}
		return true;
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
		return Connections_Helper::is_allowed() || is_user_logged_in() ? 403 : 401;
	}

	/**
	 * Send response
	 *
	 * @see Connections_Helper::send_response() for details.
	 */
	public function respond( $data, $message="", $success=null, $status=null ) {
		return Connections_Helper::send_response( $data, $message, $success, $status );
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
			'gid' => array(
				'validate_callback' => array($this, 'is_gid')
			),
			'blog_id' => array(
				'validate_callback' => array($this, 'is_number')
			),
			'post_id' => array(
				'validate_callback' => array($this, 'is_number')
			),
			'site_url' => array(
				'validate_callback' => array($this, 'is_string')
			),
			'posts' => array(
				'validate_callback' => array($this, 'is_array_or_object')
			),
			'args' => array(
				'validate_callback' => array($this, 'is_array_or_object')
			),
		);
	}

	public function is_string($value) {
		return is_string($value);
	}

	public function is_number($value) {
		return is_numeric($value);
	}

	public function is_array_or_object($value) {
		return is_array($value) || is_object($value);
	}

	public function is_gid($value) {
		$regex = "(?P<blog_id>\d+)-(?P<post_id>\d+)(-(?P<site_url>((http|https)\:\/\/)?[a-zA-Z0-9\.\/\?\:@\-_=#]+\.[a-zA-Z0-9\&\.\/\?\:@\-_=#%]*))?";
		return preg_match( "/^".$regex."$/", $value);
	}

}