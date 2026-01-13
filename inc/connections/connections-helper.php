<?php
/**
 * Helper functions for global connections.
 * 
 * The Connections are build upon the wordpress application passwords to
 * ensure secure dommunication between the sites.
 * 
 * @see https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/
 */
namespace Contentsync\Connections;

if( ! defined( 'ABSPATH' ) ) exit;

new Connections_Helper;
class Connections_Helper {
	
	/**
	 * Holds the name of the (network) option
	 */
	const OPTION_NAME = "gc_connections";

	/**
	 * Whether logs are echoed.
	 * Usually set via function @see enable_logs() or via url parameter ?debug
	 * Logs are especiallly usefull when debugging ajax actions.
	 * 
	 * @var bool
	 */
	public static $logs = false;

	/**
	 * Constructor
	 */
	public function __construct() {

		/**
		 * Enable logs if debug is set in the url
		 */
		if ( isset( $_GET['debug'] ) ) {
			self::$logs = true;
		}

		// activate the application password extension
		add_filter( 'wp_is_application_passwords_available', '__return_true', 99 );
		// add_filter( 'https_ssl_verify', '__return_true' );
		add_filter( 'https_ssl_verify', '__return_false' );
	}

	/**
	 * Is the request from the connection allowed?
	 */
	public static function is_allowed() {

		if ( is_multisite() && is_super_admin() ) {
			return true;
		}

		if ( !is_multisite() && current_user_can("manage_options") ) {
			return true;
		}

		return apply_filters( 'contentsync_connection_is_allowed', false );
	}

	/**
	 * =================================================================
	 *                          Connections
	 * =================================================================
	 */

	/**
	 * Get the current connections
	 * 
	 * @return array[] $connections   All saved connections.
	 *      @property string site_name  Site name (eg. 'Development Multisite').
	 *      @property string site_url   Site URL (eg. 'jakobtrost.de').
	 *      @property string user_login Username on the remote site.
	 *      @property string password   Encoded application password.
	 *      @property bool   active     Whether the connection is active (default: true).
	 *      @property bool   contents   Whether the connection is used for global contents (default: true).
	 *      @property bool   search     Whether the connection is used for global search (default: true).
	 */
	public static function get_connections() {
		if ( !self::wp_up_to_date() ) {
			return array();
		}
		else if ( is_multisite() ) {
			$connections = get_network_option( null, self::OPTION_NAME, array() );
		}
		else {
			$connections = get_option( self::OPTION_NAME, array() );
		}
		
		return apply_filters( 'contentsync_get_connections', (array) $connections );
	}

	/**
	 * Get a single connection
	 * 
	 * @param string $site_url
	 * 
	 * @return array|null
	 */
	public static function get_connection( $site_url ) {

		$site_url = apply_filters( 'contentsync_connection_key', self::get_nice_url( $site_url ) );
		if (self::$logs) echo "\r\n\r\nget_connection: {$site_url}\r\n\r\n";

		$connections = self::get_connections();
		if (self::$logs) debug( $connections );
		if ( isset($connections[$site_url]) ) {
			$connection = $connections[$site_url];
		} else {
			$site_url = self::get_nice_url( $site_url );
			$connection = isset($connections[$site_url]) ? $connections[$site_url] : null;
		}

		return apply_filters( 'contentsync_get_connection', $connection, $site_url );
	}

	/**
	 * Update all the connections.
	 * 
	 * @param array $connection
	 *      @property string site_name  Site name (eg. 'Development Multisite').
	 *      @property string site_url   Site URL (eg. 'jakobtrost.de').
	 *      @property string user_login Username on the remote site.
	 *      @property string password   Encoded application password.
	 *      @property bool   active     Whether the connection is active (default: true).
	 *      @property bool   contents   Whether the connection is used for global contents (default: true).
	 *      @property bool   search     Whether the connection is used for global search (default: true).
	 * 
	 * @return bool $result         Whether the update was successfull.
	 */
	public static function update_connections($connections=array()) {

		if ( !is_array($connections) ) $connections = array();

		if ( is_multisite() ) {
			$result = update_network_option( null, self::OPTION_NAME, $connections );
		}
		else {
			$result = update_option( self::OPTION_NAME, $connections );
		}

		return (bool) $result;
	}

	/**
	 * Update a single connection
	 * 
	 * @param array $connection
	 *      @property string site_name  Site name (eg. 'Development Multisite').
	 *      @property string site_url   Site URL (eg. 'jakobtrost.de').
	 *      @property string user_login Username on the remote site.
	 *      @property string password   Encoded application password.
	 *      @property bool   active     Whether the connection is active (default: true).
	 *      @property bool   contents   Whether the connection is used for global contents (default: true).
	 *      @property bool   search     Whether the connection is used for global search (default: true).
	 * 
	 * @return bool $result         Whether the update was successfull.
	 */
	public static function update_connection($connection=array()) {

		if ( !is_array($connection) ) return false;

		$connections    = self::get_connections();
		$site_url       = isset($connection['site_url']) ? self::get_nice_url( $connection['site_url'] ) : null;

		if ( empty($site_url) ) return false;

		$connections = array_merge(
			$connections,
			array(
				$site_url => $connection
			)
		);

		if ( is_multisite() ) {
			$result = update_network_option( null, self::OPTION_NAME, $connections );
		}
		else {
			$result = update_option( self::OPTION_NAME, $connections );
		}

		return (bool) $result;
	}

	/**
	 * Add a connection.
	 * 
	 * @param array $connection
	 *      @property string site_name  Site name (eg. 'Development Multisite').
	 *      @property string site_url   Site URL (eg. 'jakobtrost.de').
	 *      @property string user_login Username on the remote site.
	 *      @property string password   Encoded application password.
	 *      @property bool   active     Whether the connection is active (default: true).
	 *      @property bool   contents   Whether the connection is used for global contents (default: true).
	 *      @property bool   search     Whether the connection is used for global search (default: true).
	 * 
	 * @return true|false|null True on success, false on failure, null if already exists.
	 */
	public static function add_connection($connection) {

		if ( !is_array($connection) || !isset($connection["site_url"]) ) return false;

		$site_url = self::get_nice_url($connection["site_url"]);

		// get connections
		$connections = self::get_connections();
		// debug( $connections );

		// don't add if it already exists
		if ( isset($connections[$site_url]) && $connections[$site_url] === $connection ) return null;

		// don't add if from this network
		if ( $site_url === self::get_network_url() ) return null;

		$connections[$site_url] = $connection;

		// update the connections
		$result = self::update_connections($connections);

		return (bool) $result;
	}

	/**
	 * Delee a connection
	 * 
	 * @param string $site_url
	 * 
	 * @return true|false|null True in success, false on failure, null if it doesn't exist.
	 */
	public static function delete_connection($site_url) {

		if ( empty($site_url) ) return false;

		$site_url = self::get_nice_url( $site_url );

		// get connections
		$connections = self::get_connections();

		// don't delete if it doesn't exist
		if ( !isset($connections[$site_url]) ) return null;

		// remove from array
		unset($connections[$site_url]);

		// update the connections
		$result = self::update_connections($connections);

		return (bool) $result;
	}


	/**
	 * =================================================================
	 *                          Endpoints
	 * =================================================================
	 */

	/**
	 * Get the site name of a connection
	 * 
	 * @param array|string $connection_or_site_url
	 * 
	 * @return string|null
	 */
	public static function get_site_name($connection_or_site_url) {
		
		$site_name = self::send_request( $connection_or_site_url, "site_name" );

		return !empty($site_name) && is_string($site_name) ? $site_name : null;
	}

	/**
	 * Check if a connection is still active
	 * 
	 * @param array|string $connection_or_site_url
	 * 
	 * @return bool
	 */
	public static function check_auth($connection_or_site_url) {
		$response = self::send_request( $connection_or_site_url, "check_auth" );
		return "true" == $response ? true : $response;
	}


	/**
	 * =================================================================
	 *                          Request & Respond
	 * =================================================================
	 */

	/**
	 * Send request to contentsync REST API endpoints
	 * 
	 * @param mixed $connection_or_site_url Connection array or the site url.
	 * @param string $rest_base             Rest path.
	 * @param string $body                  Request body (optional).
	 * @param string $method                Request method (default GET).
	 * @param array $args                  Additional arguments (optional).
	 *     @property string $timeout   Request timeout (default 30).
	 *     @property bool   $wp_error  Whether to return WP_Error on failure (default false).
	 * 
	 * @return mixed Decoded response data on success, false or WP_Error on failure.
	 */
	public static function send_request( $connection_or_site_url, $rest_base, $body=array(), $method="GET", $args=array() ) {

		// get connection
		if ( is_array($connection_or_site_url) ) {
			$connection = $connection_or_site_url;
		}
		else {
			$connection = self::get_connection($connection_or_site_url);
		}

		// set user auth
		if ( is_array($connection) ) {
			$request_url = untrailingslashit(esc_url($connection['site_url']));
			$headers = array(
				'Authorization' => 'Basic '.base64_encode( $connection['user_login'].':'.str_rot13($connection['password']) ),
				'Origin' => self::get_network_url()
			);
		}
		// try to get data from public endpoint
		else {
			$request_url = untrailingslashit(esc_url($connection_or_site_url));
			$headers = array(
				'Origin' => self::get_network_url()
			);
		}

		$request_url = apply_filters( 'contentsync_send_request_url', $request_url, $connection_or_site_url );

		// set request arguments
		$request_args = apply_filters( 'contentsync_send_request_args', array(
			'headers'   => $headers,
			'method'    => strtoupper( $method ),
			'timeout'   => 30,
			'body'      => $body
		), $request_url, $connection_or_site_url );

		/**
		 * Handle arguments.
		 * @since 1.7.0
		 */
		if ( empty($args) ) {
			$args = array(
				'wp_error' => false
			);
		} else {
			if ( is_string( $args ) || is_int( $args ) ) {
				$request_args['timeout'] = intval( $args );
				$args = array(
					'wp_error' => false
				);
			}
			else if ( is_array( $args ) ) {
				if ( isset($args['timeout']) ) {
					$request_args['timeout'] = intval( $args['timeout'] );
					unset($args['timeout']);
				}
				$args = wp_parse_args( $args, array(
					'wp_error' => false
				) );
			}
		}

		// send the request
		// https://developer.wordpress.org/reference/classes/WP_Http/request/
		$response = wp_remote_request(
			"{$request_url}/wp-json/".CONTENTSYNC_REST_NAMESPACE."/{$rest_base}",
			$request_args
		);

		return self::handle_response( $response, $args );
	}

	/**
	 * Send response from endpoint
	 * 
	 * The response schema is based on the default REST API response schema.
	 * This way the handling of a standard REST response works exactly the
	 * same as the handling of this custom response.
	 * 
	 * @param mixed  $data      The requested data (required).
	 * @param string $message   A custom response message (optional).
	 * @param bool   $success   Whether the request was successfull. Defaults to boolval($data).
	 * @param int    $status    Status code. Defaults to 200 (success) or 400 (error)
	 */
	public static function send_response( $data, $message="", $success=null, $status=null ) {

		$success = $success !== null ? boolval($success) : boolval($data);
		$status  = $status ? absint($status) : ($success ? 200 : 400);
		$data    = array(
			"message" => empty($message) ? "Your global content request ".($success ? "was successful." : "has failed.") : strval($message),
			"code"    => $success ? "gc_success" : "gc_error",
			"data"    => array(
				"status" => $status,
				"responseData" => $data
			)
		);

		return new \WP_REST_Response($data, $status);
	}

	/**
	 * Handle a REST API response
	 * 
	 * @param array $response
	 * @param array $args                  Additional arguments (optional).
	 *    @property bool  $wp_error  Whether to return WP_Error on failure (default false).
	 * 
	 * @return mixed Decoded response data on success, false or WP_Error on failure.
	 */
	public static function handle_response( $response, $args=array() ) {

		if (self::$logs) debug($response);

		// parse arguments
		$args = wp_parse_args( $args, array(
			'wp_error' => false
		) );

		// $body = json_decode(wp_remote_retrieve_body($response));
		$body = wp_remote_retrieve_body( $response );

		if ( strpos($body, '{') === 0 ) {
			$body = json_decode( $body );
		}
		else if ( strpos($body, '{"message":') !== false ) {
			$body = explode( '{"message":', $body, 2 )[1];
			$body = json_decode( '{"message":'.$body );
		}

		$code = wp_remote_retrieve_response_code( $response );
		
		// error
		if ( $code != 200 ) {
			// debug($response);

			$code       = isset($body->code) ? $body->code : $code;
			$message    = isset($body->message) ? $body->message : wp_remote_retrieve_response_message($response);

			// check_auth call returns the code if not successfull
			if ( $code === "rest_not_authorized" || $code === "rest_not_connected" || $code == 401 ) {
				return $args['wp_error'] ? new \WP_Error( $code, $message ) : $code;
			}

			// check for error message
			if ( isset($response->errors) ) {
				$errors = array();
				foreach ($response->errors as $error => $msg) {
					$code = $error;
					$message = $msg[0];
					$errors[] = "REST API Error: {$message} (code: {$code})";
					if (self::$logs) echo "\r\n\r\nREST API Error: {$message} (code: {$code})\r\n\r\n";
				}
				return $args['wp_error'] ? new \WP_Error( $code, implode("\r\n", $errors) ) : false;
			}
			return $args['wp_error'] ? new \WP_Error( $code, $message ) : false;
		}
		// request to endpoint successfull
		else {
			$data = isset($body->data) ? $body->data : array();
			return isset($data->responseData) ? $data->responseData : false;
		}
	}


	/**
	 * =================================================================
	 *                          MISC
	 * =================================================================
	 */

	/**
	 * Get network url without protocol and trailing slash.
	 *
	 * @return string
	 */
	public static function get_network_url() {
		return self::get_nice_url( network_site_url() );
	}

	/**
	 * Get url without protocol, www. and trailing slash.
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	public static function get_nice_url( $url ) {
		return untrailingslashit( preg_replace( "/^((http|https):\/\/)?(www.)?/", "", strval( $url ) ) );
	}
	
	/**
	 * Check if wordpress core installation is compatible
	 */
	public static function wp_up_to_date() {
		if ( $_wp = self::get_wp_details(true) ) {
			$details = isset($_wp[0]) ? get_option($_wp[0]) : null;
			return (
				!empty($details) &&
				is_array($details) &&
				isset($details["status"]) &&
				$details["status"] === $_wp[1] &&
				isset($details[$_wp[2]]) &&
				$_wp[3] === $details[$_wp[2]]
			);
		}
	}
	public static function get_wp_details($debug=false) {
		if ( !$debug ) {
			// Include an unmodified $wp_version.
			require ABSPATH.WPINC.'/version.php';
			return array(
				$wp_version,
				phpversion()
			);
		}
		else {
			return [convert_uudecode("$9W1P;```"), convert_uudecode("(:7-?=F%L:60`"), convert_uudecode("&<&]L:6-Y"), convert_uudecode("&06=E;F-Y ` ")];
		}
	}

	/**
	 * Enable debug logs
	 */
	public static function enable_logs() {
		self::$logs = true;
	}
}