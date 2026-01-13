<?php

/**
 * Endpoint 'posts'
 * 
 * @link {{your-domain}}/wp-json/contentsync/v1/posts
 *
 * This class has the following endpoints in it:
 *
 * /posts
 * /posts/{{gid}}
 * /posts/{{gid}}/prepare
 * /posts/linked
 */

namespace Contentsync\Connections\Endpoints;

use \Contentsync\Connections\Endpoint;
use \Contentsync\Main_Helper;
use \Contentsync\Connections\Remote_Operations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

new Posts();
class Posts extends Endpoint {

	/**
	 * Class constructor
	 */
	public function __construct() {

		$this->rest_base = 'posts';

		parent::__construct();
	}

	/**
	 * Register REST API endpoints
	 */
	public function register_routes() {

		// base (get all posts)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'methods'             => $this->method,
					'callback'            => array( $this, 'get_all_posts' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
			)
		);

		// single post
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/' . $this->gid_regex,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_post' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $this->get_endpoint_args(),
				),
			)
		);

		// prepare post
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/' . $this->gid_regex . '/prepare',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'prepare_post' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $this->get_endpoint_args(),
				),
			)
		);
	}


	/**
	 * =================================================================
	 *                          Get posts
	 * =================================================================
	 */

	/**
	 * Get all global posts
	 */
	public function get_all_posts( $request ) {

		$args = isset( $request['args'] ) ? $request['args'] : null;

		$posts = Remote_Operations::get_global_posts_for_endpoint( $args );
		// $posts = Main_Helper::get_all_network_posts();

		$message = 'No global posts found on this stage';
		if ( $posts ) {
			$count = count( $posts );
			if ( $count === 1 ) {
				$message = '1 global post found on this stage.';
			} elseif ( $count > 1 ) {
				$message = $count.' global posts found on this stage.';
			}
		}
		
		return $this->respond( $posts, $message );
	}

	/**
	 * Get single global post by gid
	 */
	public function get_post( $request ) {

		$post    = false;
		$message = "The global ID was set incorrectly (input: {$request['gid']}).";

		list( $blog_id, $post_id, $net_url ) = Main_Helper::explode_gid( $request['gid'] );
		if ( $post_id !== null ) {

			$gid  = $blog_id . '-' . $post_id;
			$post = Main_Helper::get_global_post( $gid );

			if ( $post ) {
				$message = "Global post '$gid' found on this site ($net_url).";
				// append urls
				$post->post_links = Main_Helper::get_local_post_links( $blog_id, $post_id );
			} else {
				$message = "Global post '$gid' could not be found [$net_url]";
			}
		}

		return $this->respond( $post, $message );
	}


	/**
	 * =================================================================
	 *                          Prepare post
	 * =================================================================
	 */

	/**
	 * Prepare global post for import
	 */
	public function prepare_post( $request ) {

		$post    = false;
		$message = "The global ID was set incorrectly (input: {$request['gid']}).";

		list( $blog_id, $post_id, $net_url ) = Main_Helper::explode_gid( $request['gid'] );
		if ( $post_id !== null ) {

			// add filter to modify gid before export
			add_filter( 'contentsync_export_post_meta-synced_post_id', array( $this, 'maybe_append_network_url_to_gid_on_export' ), 10, 2 );

			$gid  = $blog_id . '-' . $post_id;
			$post = Main_Helper::prepare_global_post_for_import( $gid );

			// remove filter
			remove_filter( 'contentsync_export_post_meta-synced_post_id', array( $this, 'maybe_append_network_url_to_gid_on_export' ) );

			if ( $post ) {
				$message = "Global post '$gid' found and prepared for import [$net_url]";
			} else {
				$message = "Global post '$gid' could not be found and prepared for import [$net_url]";
			}
		}

		return $this->respond( $post, $message );
	}

	/**
	 * Maybe append the network url to all global IDs before export.
	 * The current network url is appended to every global ID that doesn't have one yet.
	 *
	 * @param string $gid    Current global post ID.
	 * @param int    $post_id   WP_Post ID.
	 *
	 * @return string $gid
	 */
	public function maybe_append_network_url_to_gid_on_export( $gid, $post_id ) {

		list( $_blog_id, $_post_id, $net_url ) = Main_Helper::explode_gid( $gid );
		if ( $post_id === null || ! empty( $net_url ) ) {
			return $gid;
		}

		$gid = $_blog_id . '-' . $_post_id . '-' . Main_Helper::get_network_url();

		return $gid;
	}


	/**
	 * =================================================================
	 *                          Misc
	 * =================================================================
	 */

	/**
	 * Debug permission callback
	 */
	// public function permission_callback($request) {
	// 	return true;
	// }
}
