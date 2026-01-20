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
namespace Contentsync\Api\Endpoints;

use Contentsync\Api\Endpoint;
use Contentsync\Utils\Multisite_Manager;

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
	 * Get all synced posts
	 */
	public function get_all_posts( $request ) {

		$args = isset( $request['args'] ) ? $request['args'] : null;

		$posts = $this->get_synced_posts_for_endpoint( $args );
		// $posts = \Contentsync\Posts\Sync\get_all_synced_posts_from_current_network();

		$message = 'No synced posts found on this stage';
		if ( $posts ) {
			$count = count( $posts );
			if ( $count === 1 ) {
				$message = '1 synced post found on this stage.';
			} elseif ( $count > 1 ) {
				$message = $count . ' synced posts found on this stage.';
			}
		}

		return $this->respond( $posts, $message );
	}

	/**
	 * Get single synced post by gid
	 */
	public function get_post( $request ) {

		$post    = false;
		$message = "The global ID was set incorrectly (input: {$request['gid']}).";

		list( $blog_id, $post_id, $net_url ) = \Contentsync\Posts\Sync\explode_gid( $request['gid'] );
		if ( $post_id !== null ) {

			$gid  = $blog_id . '-' . $post_id;
			$post = \Contentsync\Posts\Sync\get_synced_post( $gid );

			if ( $post ) {
				$message = "Synced post '$gid' found on this site ($net_url).";
				// append urls
				$post->post_links = \Contentsync\Posts\Sync\get_local_post_links( $blog_id, $post_id );
			} else {
				$message = "Synced post '$gid' could not be found [$net_url]";
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
	 * Prepare synced post for import
	 */
	public function prepare_post( $request ) {

		$post    = false;
		$message = "The global ID was set incorrectly (input: {$request['gid']}).";

		list( $blog_id, $post_id, $net_url ) = \Contentsync\Posts\Sync\explode_gid( $request['gid'] );
		if ( $post_id !== null ) {

			// add filter to modify gid before export
			add_filter( 'contentsync_export_post_meta-synced_post_id', array( $this, 'maybe_append_network_url_to_gid_on_export' ), 10, 2 );

			$gid  = $blog_id . '-' . $post_id;
			$post = \Contentsync\Posts\Sync\prepare_synced_post_for_import( $gid );

			// remove filter
			remove_filter( 'contentsync_export_post_meta-synced_post_id', array( $this, 'maybe_append_network_url_to_gid_on_export' ) );

			if ( $post ) {
				$message = "Synced post '$gid' found and prepared for import [$net_url]";
			} else {
				$message = "Synced post '$gid' could not be found and prepared for import [$net_url]";
			}
		}

		return $this->respond( $post, $message );
	}

	/**
	 * Maybe append the network url to all global IDs before export.
	 * The current network url is appended to every global ID that doesn't have one yet.
	 *
	 * @param string $gid    Current synced post ID.
	 * @param int    $post_id   WP_Post ID.
	 *
	 * @return string $gid
	 */
	public function maybe_append_network_url_to_gid_on_export( $gid, $post_id ) {

		list( $_blog_id, $_post_id, $net_url ) = \Contentsync\Posts\Sync\explode_gid( $gid );
		if ( $post_id === null || ! empty( $net_url ) ) {
			return $gid;
		}

		$gid = $_blog_id . '-' . $_post_id . '-' . \Contentsync\Utils\get_network_url();

		return $gid;
	}


	/**
	 * =================================================================
	 *                          Utility Functions
	 * =================================================================
	 */

	/**
	 * Get all synced posts of this installation.
	 * This function is usually called via a REST API call.
	 *
	 * @param string|array $query   Search query term or array of query args.
	 *
	 * @return array of all synced posts
	 */
	public function get_synced_posts_for_endpoint( $query_args = null ) {

		global $wpdb;
		$all_posts             = $query_parts = $removed_posts = array();
		$additional_conditions = '';

		// handle $query_args
		if ( ! empty( $query_args ) ) {
			if ( is_string( $query_args ) ) {
				// simple text search, same as $query['s']
				$additional_conditions .= "AND (
					LOWER({prefix}posts.post_content) LIKE LOWER('%{$query_args}%')
					OR LOWER({prefix}posts.post_title) LIKE LOWER('%{$query_args}%')
				)";
			} elseif ( is_array( $query_args ) || is_object( $query_args ) ) {
				foreach ( (array) $query_args as $key => $value ) {
					if ( empty( $value ) ) {
						continue;
					}

					if ( $key === 's' ) {
						// simple text search
						$additional_conditions .= "AND (
							LOWER({prefix}posts.post_content) LIKE LOWER('%{$value}%')
							OR LOWER({prefix}posts.post_title) LIKE LOWER('%{$value}%')
						)";
					} else {
						// object attribute search, eg. 'post_type' => 'dynamic_template'
						$additional_conditions .= "AND {prefix}posts.{$key} = '{$value}'";
					}
				}
			}
		}

		$network_url = \Contentsync\Utils\get_network_url();

		// build sql query
		$results = array();
		foreach ( Multisite_Manager::get_all_blogs() as $blog_id => $blog_args ) {
			$prefix   = $blog_args['prefix'];
			$site_url = $blog_args['site_url'];
			$query    = "
				SELECT ID, post_name, post_type, post_status, post_title, post_date, meta_value as synced_post_id, '{$blog_id}' as blog_id, '{$site_url}' as site_url, '{$network_url}' as network_url
				FROM {$prefix}posts
				LEFT JOIN {$prefix}postmeta ON {$prefix}posts.ID = {$prefix}postmeta.post_id
	
				WHERE {$prefix}postmeta.meta_key = 'synced_post_id'
					AND {$prefix}postmeta.meta_value = CONCAT('{$blog_id}-', {$prefix}posts.ID)
				" . str_replace( '{prefix}', $prefix, $additional_conditions ) . "
				AND {$prefix}posts.post_status <> 'trash'
			";
			$result   = $wpdb->get_results( $query );
			if ( count( $result ) > 0 ) {
				$results = array_merge( $results, $result );
			}
		}
		usort(
			$results,
			function ( $a, $b ) {
				return strtotime( $b->post_date ) - strtotime( $a->post_date );
			}
		);
		// debug($results);

		if ( empty( $results ) ) {
			return array();
		}

		return $results;
	}
}
