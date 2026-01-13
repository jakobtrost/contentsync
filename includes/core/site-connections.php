<?php
/**
 * Helper functions for global connections.
 *
 * The Connections are build upon the WordPress application passwords to
 * ensure secure dommunication between the sites.
 *
 * @see https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/
 */
namespace Contentsync\Site_Connections;

use Contentsync\Main_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the name of the (network) option
 */
const OPTION_NAME = 'gc_connections';

/**
 * Whether logs are echoed.
 * Usually set via function @see enable_logs() or via url parameter ?debug
 * Logs are especiallly usefull when debugging ajax actions.
 *
 * @var bool
 */
$contentsync_site_connections_logs = isset( $_GET['debug'] ) ? true : false;


/**
 * Activate the application password extension
 */
add_filter( 'wp_is_application_passwords_available', '__return_true', 99 );

/**
 * Disable SSL verification (for development purposes)
 */
// add_filter( 'https_ssl_verify', '__return_false' );

/**
 * Is the request from the connection allowed?
 */
function is_allowed() {

	if ( is_multisite() && is_super_admin() ) {
		return true;
	}

	if ( ! is_multisite() && current_user_can( 'manage_options' ) ) {
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
function get_connections() {

	if ( is_multisite() ) {
		$connections = get_network_option( null, OPTION_NAME, array() );
	} else {
		$connections = get_option( OPTION_NAME, array() );
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
function get_connection( $site_url ) {
	global $contentsync_site_connections_logs;

	$site_url = apply_filters( 'contentsync_connection_key', Main_Helper::get_nice_url( $site_url ) );
	if ( $contentsync_site_connections_logs ) {
		echo "\r\n\r\nget_connection: {$site_url}\r\n\r\n";
	}

	$connections = get_connections();
	if ( $contentsync_site_connections_logs ) {
		debug( $connections );
	}
	if ( isset( $connections[ $site_url ] ) ) {
		$connection = $connections[ $site_url ];
	} else {
		$site_url   = Main_Helper::get_nice_url( $site_url );
		$connection = isset( $connections[ $site_url ] ) ? $connections[ $site_url ] : null;
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
function update_connections( $connections = array() ) {

	if ( ! is_array( $connections ) ) {
		$connections = array();
	}

	if ( is_multisite() ) {
		$result = update_network_option( null, OPTION_NAME, $connections );
	} else {
		$result = update_option( OPTION_NAME, $connections );
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
function update_connection( $connection = array() ) {

	if ( ! is_array( $connection ) ) {
		return false;
	}

	$connections = get_connections();
	$site_url    = isset( $connection['site_url'] ) ? Main_Helper::get_nice_url( $connection['site_url'] ) : null;

	if ( empty( $site_url ) ) {
		return false;
	}

	$connections = array_merge(
		$connections,
		array(
			$site_url => $connection,
		)
	);

	if ( is_multisite() ) {
		$result = update_network_option( null, OPTION_NAME, $connections );
	} else {
		$result = update_option( OPTION_NAME, $connections );
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
function add_connection( $connection ) {

	if ( ! is_array( $connection ) || ! isset( $connection['site_url'] ) ) {
		return false;
	}

	$site_url = Main_Helper::get_nice_url( $connection['site_url'] );

	// get connections
	$connections = get_connections();
	// debug( $connections );

	// don't add if it already exists
	if ( isset( $connections[ $site_url ] ) && $connections[ $site_url ] === $connection ) {
		return null;
	}

	// don't add if from this network
	if ( $site_url === get_network_url() ) {
		return null;
	}

	$connections[ $site_url ] = $connection;

	// update the connections
	$result = update_connections( $connections );

	return (bool) $result;
}

/**
 * Delee a connection
 *
 * @param string $site_url
 *
 * @return true|false|null True in success, false on failure, null if it doesn't exist.
 */
function delete_connection( $site_url ) {

	if ( empty( $site_url ) ) {
		return false;
	}

	$site_url = Main_Helper::get_nice_url( $site_url );

	// get connections
	$connections = get_connections();

	// don't delete if it doesn't exist
	if ( ! isset( $connections[ $site_url ] ) ) {
		return null;
	}

	// remove from array
	unset( $connections[ $site_url ] );

	// update the connections
	$result = update_connections( $connections );

	return (bool) $result;
}


/**
 * =================================================================
 *                          Endpoints
 * =================================================================
 */

/**
 * Check if a connection is still active
 *
 * @param array|string $connection_or_site_url
 *
 * @return bool
 */
function check_auth( $connection_or_site_url ) {
	$response = send_request( $connection_or_site_url, 'check_auth' );
	return 'true' == $response ? true : $response;
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
function get_network_url() {
	return Main_Helper::get_nice_url( network_site_url() );
}

/**
 * Enable debug logs
 */
function enable_logs() {
	global $contentsync_site_connections_logs;
	$contentsync_site_connections_logs = true;
}
