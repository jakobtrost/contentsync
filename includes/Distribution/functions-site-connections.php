<?php
/**
 * Helper functions for global connections.
 *
 * The Connections are build upon the WordPress application passwords to
 * ensure secure dommunication between the sites.
 *
 * @see https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/
 */
namespace Contentsync\Distribution;

use Contentsync\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activate the application password extension
 */
add_filter( 'wp_is_application_passwords_available', '__return_true', 99 );

/**
 * Get the name of the site connections option
 *
 * @return string
 */
function get_site_connections_option_name() {
	return 'contentsync_site_connections';
}

/**
 * Get the current connections
 *
 * @return array[] $connections   All saved connections.
 *      @property string site_name  Site name (eg. 'Development Multisite').
 *      @property string site_url   Site URL (eg. 'jakobtrost.de').
 *      @property string user_login Username on the remote site.
 *      @property string password   Encoded application password.
 *      @property bool   active     Whether the connection is active (default: true).
 */
function get_site_connections() {

	if ( is_multisite() ) {
		$connections = get_network_option( null, get_site_connections_option_name(), array() );
	} else {
		$connections = get_option( get_site_connections_option_name(), array() );
	}

	return apply_filters( 'contentsync_get_site_connections', (array) $connections );
}

/**
 * Get a single connection
 *
 * @param string $site_url
 *
 * @return array|null
 */
function get_site_connection( $site_url ) {

	$site_url = apply_filters( 'contentsync_connection_key', \Contentsync\get_nice_url( $site_url ) );
	Logger::add( 'get_site_connection', $site_url );

	$connections = get_site_connections();
	Logger::add( 'get_site_connections', $connections );
	if ( isset( $connections[ $site_url ] ) ) {
		$connection = $connections[ $site_url ];
	} else {
		$site_url   = \Contentsync\get_nice_url( $site_url );
		$connection = isset( $connections[ $site_url ] ) ? $connections[ $site_url ] : null;
	}

	return apply_filters( 'contentsync_get_site_connection', $connection, $site_url );
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
 *
 * @return bool $result         Whether the update was successfull.
 */
function update_site_connections( $connections = array() ) {

	if ( ! is_array( $connections ) ) {
		$connections = array();
	}

	if ( is_multisite() ) {
		$result = update_network_option( null, get_site_connections_option_name(), $connections );
	} else {
		$result = update_option( get_site_connections_option_name(), $connections );
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
 *
 * @return bool $result         Whether the update was successfull.
 */
function update_site_connection( $connection = array() ) {

	if ( ! is_array( $connection ) ) {
		return false;
	}

	$connections = get_site_connections();
	$site_url    = isset( $connection['site_url'] ) ? \Contentsync\get_nice_url( $connection['site_url'] ) : null;

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
		$result = update_network_option( null, get_site_connections_option_name(), $connections );
	} else {
		$result = update_option( get_site_connections_option_name(), $connections );
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
 *
 * @return true|false|null True on success, false on failure, null if already exists.
 */
function add_site_connection( $connection ) {

	if ( ! is_array( $connection ) || ! isset( $connection['site_url'] ) ) {
		return false;
	}

	$site_url = \Contentsync\get_nice_url( $connection['site_url'] );

	// get connections
	$connections = get_site_connections();
	// debug( $connections );

	// don't add if it already exists
	if ( isset( $connections[ $site_url ] ) && $connections[ $site_url ] === $connection ) {
		return null;
	}

	// don't add if from this network
	if ( $site_url === \Contentsync\get_network_url() ) {
		return null;
	}

	$connections[ $site_url ] = $connection;

	// update the connections
	$result = update_site_connections( $connections );

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

	$site_url = \Contentsync\get_nice_url( $site_url );

	// get connections
	$connections = get_site_connections();

	// don't delete if it doesn't exist
	if ( ! isset( $connections[ $site_url ] ) ) {
		return null;
	}

	// remove from array
	unset( $connections[ $site_url ] );

	// update the connections
	$result = update_site_connections( $connections );

	return (bool) $result;
}
