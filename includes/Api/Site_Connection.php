<?php
/**
 * Helper functions for global connections.
 *
 * The Connections are build upon the WordPress application passwords to
 * ensure secure dommunication between the sites.
 *
 * @see https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/
 */
namespace Contentsync\Api;

use Contentsync\Utils\Logger;
use Contentsync\Utils\Urls;

defined( 'ABSPATH' ) || exit;

class Site_Connection {

	/**
	 * Get the name of the site connections option
	 *
	 * @return string
	 */
	public static function get_option_name() {
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
	public static function get_all() {

		if ( is_multisite() ) {
			$connections = get_network_option( null, self::get_option_name(), array() );
		} else {
			$connections = get_option( self::get_option_name(), array() );
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
	public static function get( $site_url ) {

		$site_url = apply_filters( 'contentsync_connection_key', Urls::get_nice_url( $site_url ) );
		Logger::add( 'get_site_connection', $site_url );

		$connections = self::get_all();
		Logger::add( 'get_site_connections', $connections );
		if ( isset( $connections[ $site_url ] ) ) {
			$connection = $connections[ $site_url ];
		} else {
			$site_url   = Urls::get_nice_url( $site_url );
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
	public static function update_all( $connections = array() ) {

		if ( ! is_array( $connections ) ) {
			$connections = array();
		}

		if ( is_multisite() ) {
			$result = update_network_option( null, self::get_option_name(), $connections );
		} else {
			$result = update_option( self::get_option_name(), $connections );
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
	public static function update( $connection = array() ) {

		if ( ! is_array( $connection ) ) {
			return false;
		}

		$connections = self::get_all();
		$site_url    = isset( $connection['site_url'] ) ? Urls::get_nice_url( $connection['site_url'] ) : null;

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
			$result = update_network_option( null, self::get_option_name(), $connections );
		} else {
			$result = update_option( self::get_option_name(), $connections );
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
	public static function add( $connection ) {

		if ( ! is_array( $connection ) || ! isset( $connection['site_url'] ) ) {
			return false;
		}

		$site_url = Urls::get_nice_url( $connection['site_url'] );

		// get connections
		$connections = self::get_all();

		// don't add if it already exists
		if ( isset( $connections[ $site_url ] ) && $connections[ $site_url ] === $connection ) {
			return null;
		}

		// don't add if from this network
		if ( $site_url === Urls::get_network_url() ) {
			return null;
		}

		$connections[ $site_url ] = $connection;

		// update the connections
		$result = self::update_all( $connections );

		return (bool) $result;
	}

	/**
	 * Delete a connection
	 *
	 * @param string $site_url
	 *
	 * @return true|false|null True in success, false on failure, null if it doesn't exist.
	 */
	public static function delete( $site_url ) {

		if ( empty( $site_url ) ) {
			return false;
		}

		$site_url = Urls::get_nice_url( $site_url );

		// get connections
		$connections = self::get_all();

		// don't delete if it doesn't exist
		if ( ! isset( $connections[ $site_url ] ) ) {
			return null;
		}

		// remove from array
		unset( $connections[ $site_url ] );

		// update the connections
		$result = self::update_all( $connections );

		return (bool) $result;
	}
}
