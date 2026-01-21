<?php

/**
 * Endpoint 'posts/connections'
 *
 * This class handles the post meta 'contentsync_connection_map' of a
 * synced post where the root post is usually on this site.
 *
 * @link {{your-domain}}/wp-json/contentsync/v1/posts
 *
 * This class has the following endpoints in it:
 *
 * /posts/{{gid}}/connections (GET, POST & DELETE)
 */
namespace Contentsync\Api\Endpoints;

use Contentsync\Api\Endpoint;
use Contentsync\Utils\Multisite_Manager;

defined( 'ABSPATH' ) || exit;

new Posts_Connections();
class Posts_Connections extends Endpoint {

	/**
	 * Class constructor
	 */
	public function __construct() {

		$this->rest_base = 'posts/' . $this->gid_regex . '/connections';

		parent::__construct();
	}

	/**
	 * Register REST API endpoints
	 */
	public function register_routes() {

		// connections
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_post_connection_map' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $this->get_endpoint_args(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_post_connection_to_connection_map' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $this->get_endpoint_args(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove_post_connection_from_connection_map' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $this->get_endpoint_args(),
				),
			)
		);
	}


	/**
	 * =================================================================
	 *                          Endpoint callbacks
	 * =================================================================
	 */

	/**
	 * Get connections of a synced post
	 */
	public function get_post_connection_map( $request ) {

		$connection_map = false;
		$message        = "The global ID was set incorrectly (input: {$request['gid']}).";

		list( $blog_id, $post_id, $net_url ) = \Contentsync\Posts\Sync\explode_gid( $request['gid'] );
		if ( $post_id !== null ) {
			Multisite_Manager::switch_blog( $blog_id );
			$connection_map = \Contentsync\Posts\Sync\get_post_connection_map( $post_id );
			Multisite_Manager::restore_blog();

			if ( $connection_map ) {
				$message = "Post connections for post '$gid' found [$net_url]";
			} else {
				$message = "Post connections for post '$gid' could not be found [$net_url]";
			}
		}

		return $this->respond( $connection_map, $message );
	}

	/**
	 * Add connection to a synced post
	 */
	public function add_post_connection_to_connection_map( $request ) {

		$result  = false;
		$message = "The global ID was set incorrectly (input: {$request['gid']}).";

		list( $_blog_id, $_post_id, $net_url ) = \Contentsync\Posts\Sync\explode_gid( $request['gid'] );
		if ( $_post_id !== null ) {
			$gid      = $_blog_id . '-' . $_post_id; // remove the remote url as it is this site
			$site_url = isset( $request['site_url'] ) ? esc_attr( $request['site_url'] ) : null;
			$args     = isset( $request['args'] ) ? (array) $request['args'] : null;

			if ( empty( $site_url ) || empty( $args ) ) {
				return $this->respond( false, "Could not add post connection. Request arguments 'site_url' or 'args' were empty [$net_url]" );
			}

			$result = \Contentsync\Posts\Sync\add_or_remove_post_connection_from_connection_map( $gid, $args, true, $site_url );
			if ( $result ) {
				$message = "Post connection for the post '$gid' to the domain '$site_url' was set successfully [$net_url]";
			} else {
				$message = "Post connection for the post '$gid' to the domain '$site_url' could not be set [$net_url]";
			}
		}

		return $this->respond( $result, $message );
	}

	/**
	 * Remove connection from a synced post
	 */
	public function remove_post_connection_from_connection_map( $request ) {

		$result  = false;
		$message = "The global ID was set incorrectly (input: {$request['gid']}).";

		list( $_blog_id, $_post_id, $net_url ) = \Contentsync\Posts\Sync\explode_gid( $request['gid'] );
		if ( $_post_id !== null ) {
			$gid      = $_blog_id . '-' . $_post_id; // remove the remote url as it is this site
			$site_url = isset( $request['site_url'] ) ? esc_attr( $request['site_url'] ) : null;
			$args     = isset( $request['args'] ) ? (array) $request['args'] : null;

			if ( empty( $site_url ) || empty( $args ) ) {
				return $this->respond( false, "Could not remove post connection. Request arguments 'site_url' or 'args' were empty [$net_url]" );
			}

			$result = \Contentsync\Posts\Sync\add_or_remove_post_connection_from_connection_map( $gid, $args, false, $site_url );
			if ( $result ) {
				$message = "Post connection for the post '$gid' to the domain '$site_url' was removed successfully [$net_url]";
			} else {
				$message = "Post connection for the post '$gid' to the domain '$site_url' could not be removed [$net_url] " . json_encode( $args );
			}
		}

		return $this->respond( $result, $message );
	}


	/**
	 * =================================================================
	 *                          Helper
	 * =================================================================
	 */

	/**
	 * Debug permission callback
	 */
	// public function permission_callback($request) {
	// return true;
	// }
}
