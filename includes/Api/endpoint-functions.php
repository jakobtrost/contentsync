<?php

/**
 * Remote operations for connected networks.
 *
 * This file provides functions for interacting with remote networks and
 * sites connected to the Content Sync system. It provides functions
 * to query synced posts across all blogs, build SQL queries for remote
 * searches, fetch posts and metadata from remote networks, and register
 * endpoints used by other parts of the plugin. By centralising remote
 * data access here, the rest of the plugin can work with consistent
 * abstractions rather than dealing with low‑level database details.
 */

namespace Contentsync\Api;

use Contentsync\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if a connection is still active
 *
 * @param array|string $connection_or_site_url
 *
 * @return bool
 */
function check_connection_authentication( $connection_or_site_url ) {
	$response = send_request( $connection_or_site_url, 'check_auth' );
	return 'true' == $response ? true : $response;
}

/**
 * Get the site name of a connection
 *
 * @param array|string $connection_or_site_url
 *
 * @return string|null
 */
function get_site_name( $connection_or_site_url ) {

	$site_name = send_request( $connection_or_site_url, 'site_name' );

	return ! empty( $site_name ) && is_string( $site_name ) ? $site_name : null;
}

/**
 * Get all remote synced posts
 *
 * @param array|string $connection_or_site_url
 *
 * @return mixed
 */
function get_remote_synced_posts( $connection_or_site_url, $query_args = null ) {
	return send_request(
		$connection_or_site_url,
		'posts',
		array(
			'args' => $query_args,
		)
	);
}

/**
 * Get a remote synced post
 *
 * @param array|string $connection_or_site_url
 *
 * @return mixed
 */
function get_remote_synced_post( $connection_or_site_url, $gid ) {
	return send_request(
		$connection_or_site_url,
		'posts/' . prepare_gid_for_url( $gid )
	);
}

/**
 * Prepare a remote synced post for import
 *
 * @param array|string $connection_or_site_url
 *
 * @return mixed
 */
function prepare_remote_synced_post( $connection_or_site_url, $gid ) {
	return send_request(
		$connection_or_site_url,
		'posts/' . prepare_gid_for_url( $gid ) . '/prepare'
	);
}

/**
 * Update a post connection_map of a remote post
 *
 * @param array|string $connection_or_site_url
 * @param string       $gid           Global ID of the root post.
 * @param array        $args          array( 'blog_id' => 'post_id', ... )
 * @param bool         $add           Whether to add or remove the post connection.
 * @param string       $post_site_url Site url (for remote posts).
 *
 * @return mixed
 */
function update_remote_post_connection( $connection_or_site_url, $gid, $args, $add, $post_site_url ) {
	return send_request(
		$connection_or_site_url,
		'posts/' . prepare_gid_for_url( $gid ) . '/connections',
		array(
			'site_url' => $post_site_url,
			'args'     => $args,
		),
		$add ? 'POST' : 'DELETE'
	);
}

/**
 * Get connected posts to a root post from an entire remote network.
 *
 * @since 1.7.5
 *
 * @param array|string $connection_or_site_url
 * @param string       $gid               Global ID of the root post.
 *
 * @return array|false Array of post connections on success, false on failure.
 */
function get_all_remote_connected_posts( $connection_or_site_url, $gid ) {
	return send_request(
		$connection_or_site_url,
		'connected_posts',
		array(
			'gid' => $gid,
		),
		'GET'
	);
}

/**
 * Delete all connected posts of a synced post from a certain connection
 *
 * @see \Contentsync\Posts\Sync\delete_synced_post()
 *
 * @param array|string $connection_or_site_url
 * @param string       $gid               Global ID of the root post with an appended network_url.
 * @param array        $connected_posts    Connections from this network, usually created by get_post_connection_map()
 */
function delete_all_remote_connected_posts( $connection_or_site_url, $gid, $connected_posts ) {
	return send_request(
		$connection_or_site_url,
		'connected_posts',
		array(
			'gid'  => $gid,
			'args' => $connected_posts,
		),
		'DELETE'
	);
}

/**
 * Distribute a Destination_Item to a Remote_Destination.
 *
 * This function calles the endpoint 'distribution/distribute-item' on the remote site.
 *
 * @param array|string             $connection_or_site_url
 * @param Remote_Distribution_Item $remote_distribution_item
 *
 * @return mixed Decoded response data on success, WP_Error on failure.
 */
function distribute_item_to_remote_site( $connection_or_site_url, $remote_distribution_item ) {
	return send_request(
		$connection_or_site_url,
		'distribution/distribute-item',
		array(
			'distribution_item' => json_encode( $remote_distribution_item ),
			'origin'            => \Contentsync\Utils\get_network_url(),
		),
		'POST',
		array(
			'wp_error' => true,
		)
	);
}

/**
 * Update the Remote_Destination object of a Distribution_Item.
 *
 * This function is UNUSED right now, as the background processing is not working
 * inside a rest request. Instead, the update is done directly after the distribution
 * to the local sites.
 *
 * @see Distribution_Endpoint->handle_distribute_item_request()
 *
 * This function calles the endpoint 'distribution/update-item' on the origin site.
 *
 * @param array|string       $connection_or_site_url  The connection or site URL.
 * @param int                $distribution_item_id    The Distribution_Item ID.
 * @param string             $status                  The status of the distribution.
 * @param Remote_Destination $destination             The destination object.
 */
function update_distribution_item( $connection_or_site_url, $distribution_item_id, $status, $destination ) {
	return send_request(
		$connection_or_site_url,
		'distribution/update-item',
		array(
			'distribution_item_id' => $distribution_item_id,
			'destination'          => $destination,
			'status'               => $status,
		),
		'POST'
	);
}



/**
 * =================================================================
 *                          Request & Respond
 * =================================================================
 */

/**
 * Send request to contentsync REST API endpoints
 *
 * @param mixed  $connection_or_site_url Connection array or the site url.
 * @param string $rest_base             Rest path.
 * @param string $body                  Request body (optional).
 * @param string $method                Request method (default GET).
 * @param array  $args                  Additional arguments (optional).
 *     @property string $timeout   Request timeout (default 30).
 *     @property bool   $wp_error  Whether to return WP_Error on failure (default false).
 *
 * @return mixed Decoded response data on success, false or WP_Error on failure.
 */
function send_request( $connection_or_site_url, $rest_base, $body = array(), $method = 'GET', $args = array() ) {

	// get connection
	if ( is_array( $connection_or_site_url ) ) {
		$connection = $connection_or_site_url;
	} else {
		$connection = get_site_connection( $connection_or_site_url );
	}

	// set user auth
	if ( is_array( $connection ) ) {
		$request_url = untrailingslashit( esc_url( $connection['site_url'] ) );
		$headers     = array(
			'Authorization' => 'Basic ' . base64_encode( $connection['user_login'] . ':' . str_rot13( $connection['password'] ) ),
			'Origin'        => \Contentsync\Utils\get_network_url(),
		);
	}
	// try to get data from public endpoint
	else {
		$request_url = untrailingslashit( esc_url( $connection_or_site_url ) );
		$headers     = array(
			'Origin' => \Contentsync\Utils\get_network_url(),
		);
	}

	$request_url = apply_filters( 'contentsync_send_request_url', $request_url, $connection_or_site_url );

	// set request arguments
	$request_args = apply_filters(
		'contentsync_send_request_args',
		array(
			'headers' => $headers,
			'method'  => strtoupper( $method ),
			'timeout' => 30,
			'body'    => $body,
		),
		$request_url,
		$connection_or_site_url
	);

	/**
	 * Handle arguments.
	 *
	 * @since 1.7.0
	 */
	if ( empty( $args ) ) {
		$args = array(
			'wp_error' => false,
		);
	} elseif ( is_string( $args ) || is_int( $args ) ) {
			$request_args['timeout'] = intval( $args );
			$args                    = array(
				'wp_error' => false,
			);
	} elseif ( is_array( $args ) ) {
		if ( isset( $args['timeout'] ) ) {
			$request_args['timeout'] = intval( $args['timeout'] );
			unset( $args['timeout'] );
		}
		$args = wp_parse_args(
			$args,
			array(
				'wp_error' => false,
			)
		);
	}

	// send the request
	// https://developer.wordpress.org/reference/classes/WP_Http/request/
	$response = wp_remote_request(
		"{$request_url}/wp-json/" . CONTENTSYNC_REST_NAMESPACE . "/{$rest_base}",
		$request_args
	);

	return handle_response( $response, $args );
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
function send_response( $data, $message = '', $success = null, $status = null ) {

	$success = $success !== null ? boolval( $success ) : boolval( $data );
	$status  = $status ? absint( $status ) : ( $success ? 200 : 400 );
	$data    = array(
		'message' => empty( $message ) ? 'Your global content request ' . ( $success ? 'was successful.' : 'has failed.' ) : strval( $message ),
		'code'    => $success ? 'gc_success' : 'gc_error',
		'data'    => array(
			'status'       => $status,
			'responseData' => $data,
		),
	);

	return new \WP_REST_Response( $data, $status );
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
function handle_response( $response, $args = array() ) {
	Logger::add( 'handle_response', $response );
	Logger::add( 'handle_response - args', $args );

	// parse arguments
	$args = wp_parse_args(
		$args,
		array(
			'wp_error' => false,
		)
	);

	// $body = json_decode(wp_remote_retrieve_body($response));
	$body = wp_remote_retrieve_body( $response );

	if ( strpos( $body, '{' ) === 0 ) {
		$body = json_decode( $body );
	} elseif ( strpos( $body, '{"message":' ) !== false ) {
		$body = explode( '{"message":', $body, 2 )[1];
		$body = json_decode( '{"message":' . $body );
	}

	$code = wp_remote_retrieve_response_code( $response );

	// error
	if ( $code != 200 ) {
		// debug($response);

		$code    = isset( $body->code ) ? $body->code : $code;
		$message = isset( $body->message ) ? $body->message : wp_remote_retrieve_response_message( $response );

		// check_auth call returns the code if not successfull
		if ( $code === 'rest_not_authorized' || $code === 'rest_not_connected' || $code == 401 ) {
			return $args['wp_error'] ? new \WP_Error( $code, $message ) : $code;
		}

		// check for error message
		if ( isset( $response->errors ) ) {
			$errors = array();
			foreach ( $response->errors as $error => $msg ) {
				$code     = $error;
				$message  = $msg[0];
				$errors[] = "REST API Error: {$message} (code: {$code})";
				Logger::add(
					'REST API Error',
					array(
						'message' => $message,
						'code'    => $code,
					)
				);
			}
			return $args['wp_error'] ? new \WP_Error( $code, implode( "\r\n", $errors ) ) : false;
		}
		return $args['wp_error'] ? new \WP_Error( $code, $message ) : false;
	}
	// request to endpoint successfull
	else {
		$data = isset( $body->data ) ? $body->data : array();
		return isset( $data->responseData ) ? $data->responseData : false;
	}
}



/**
 * =================================================================
 *                          Utility Functions
 * =================================================================
 */

/**
 * Prepare a global ID for inclusion in a URL.
 *
 * Remote REST endpoints expect global IDs (GIDs) to be URL safe. This
 * helper converts any forward slashes in the GID to dashes and then
 * applies `urlencode()` to ensure the string may be safely embedded
 * in a URL path segment. It does not split or validate the GID.
 *
 * @param string $gid The global ID to encode.
 *
 * @return string Encoded GID safe for use in URLs.
 */
function prepare_gid_for_url( $gid ) {
	// list( $blog_id, $post_id, $net_url ) = \Contentsync\Posts\Sync\explode_gid( $gid );
	// $_gid  = $blog_id . '-' . $post_id;
	return urlencode( str_replace( '/', '-', $gid ) );
}
