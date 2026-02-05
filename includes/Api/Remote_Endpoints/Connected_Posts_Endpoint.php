<?php

/**
 * Endpoint 'connected_posts'
 *
 * This class handles all connected posts of a root post
 * that usually comes from another site.
 *
 * @link {{your-domain}}/wp-json/contentsync/v1/connected_posts
 *
 * This class has the following endpoints in it:
 *
 * /connected_posts (GET, POST & DELETE)
 * /connected_posts/export
 */
namespace Contentsync\Api\Remote_Endpoints;

use Contentsync\Post_Sync\Post_Connection_Map;
use Contentsync\Post_Sync\Synced_Post_Service;
use Contentsync\Post_Sync\Synced_Post_Utils;
use Contentsync\Post_Transfer\Post_Import;
use Contentsync\Utils\Multisite_Manager;
use Contentsync\Utils\Urls;

defined( 'ABSPATH' ) || exit;

class Connected_Posts_Endpoint extends Remote_Endpoint_Base {

	/**
	 * Class constructor
	 */
	public function __construct() {

		$this->rest_base = 'connected_posts';

		parent::__construct();
	}

	/**
	 * Register REST API endpoints
	 */
	public function register_routes() {

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				// get connected posts
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_all_connected_posts' ),
					'permission_callback' => '__return_true', // array( $this, 'permission_callback' ),
					'args'                => $this->get_endpoint_args(),
				),
				// import connected posts
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_all_connected_posts' ),
					'permission_callback' => array( $this, 'allow_request_from_same_origin' ),
					'args'                => $this->get_endpoint_args(),
				),
				// delete connected posts
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_connected_posts' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $this->get_endpoint_args(),
				),
			)
		);

		// export post to connections
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/export',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'export_post_to_all_connections' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $this->get_endpoint_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/export_post_to_destination',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'export_post_to_destination' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $this->get_endpoint_args(),
				),
			)
		);
	}

	/**
	 * Store the current request origin in the class
	 */
	public $origin;

	public function get_all_connected_posts( $request ) {

		$response = false;
		$gid      = isset( $request['gid'] ) ? $request['gid'] : null;
		$origin   = $request->get_header( 'Origin' );

		list( $root_blog_id, $root_post_id, $root_net_url ) = Synced_Post_Utils::explode_gid( $gid );

		// something is missing
		if ( empty( $root_post_id ) ) {
			return $this->respond( $response, 'The request arguments were set incorrectly (gid, origin).' );
		}

		// store the origin in the class to use it inside the filter functions
		$this->origin = $origin;

		// modify the gid based on the origin of the request
		$remote_gid = empty( $origin ) ? $gid : $root_blog_id . '-' . $root_post_id . '-' . $origin;

		$message = 'Try to get all connected posts of the gid <b>' . $remote_gid . '</b> on all connected blogs of the network <u>' . Urls::get_network_url() . '</u>';

		$connected_posts = Post_Connection_Map::get_all_local_connections( $remote_gid );

		return $this->respond( $connected_posts, $message, is_array( $connected_posts ) );
	}

	/**
	 * Import & update all connected posts inside this network
	 */
	public function update_all_connected_posts( $request ) {

		$response        = false;
		$gid             = isset( $request['gid'] ) ? $request['gid'] : null;
		$connected_posts = isset( $request['args'] ) ? $request['args'] : null;
		$all_posts       = isset( $request['posts'] ) ? $request['posts'] : null;
		$origin          = $request->get_header( 'Origin' );

		list( $root_blog_id, $root_post_id, $root_net_url ) = Synced_Post_Utils::explode_gid( $gid );

		// something is missing
		if ( empty( $root_post_id ) || ! is_array( $connected_posts ) || ! is_array( $all_posts ) || empty( $origin ) ) {
			return $this->respond( $response, 'The request arguments were set incorrectly (gid, args, posts).' );
		}

		// store the origin in the class to use it inside the filter functions
		$this->origin = $origin;

		// add filter to modify gid before import
		add_filter( 'filter_gid_for_conflict_action', array( $this, 'match_gid_before_import' ), 10, 3 );
		add_filter( 'import_synced_post_meta-synced_post_id', array( $this, 'match_gid_before_import' ), 10, 3 );

		$message = 'Try to update all posts on all connected blogs of the network "' . Urls::get_network_url() . '": ';

		foreach ( $connected_posts as $blog_id => $post_con ) {
			if ( is_numeric( $blog_id ) ) {

				Multisite_Manager::switch_blog( $blog_id );

				$post_id  = $post_con['post_id'];
				$message .= " - check post #$post_id on blog <u>" . get_site_url( $blog_id ) . " (ID: $blog_id)</u>";

				if ( 'linked' === get_post_meta( $post_id, 'synced_post_status', true ) ) {

					/**
					 * Set the root post conflict to 'replace', otherwise it would be skipped.
					 * All other posts with the same gid on the target blog are automatically
					 * skipped via the function 'match_gid_before_import'.
					 */
					$conflict_actions = array(
						$root_post_id => array(
							'existing_post_id' => $post_id,
							'conflict_action'  => 'replace',
							'original_post_id' => $root_post_id,
						),
					);

					/**
					 * We now import all the posts to the target blog.
					 */
					$post_import   = new Post_Import(
						$all_posts,
						array(
							'conflict_actions' => $conflict_actions,
						)
					);
					$import_result = $post_import->import_posts();

					if ( $import_result ) {
						$message .= ' - post successfully imported';
					} else {
						$message .= ' - post could not be imported';
					}
				} else {
					$message .= ' - this is not a linked post';
				}
				Multisite_Manager::restore_blog();
			}
			$response = true;
		}

		// remove filter
		remove_filter( 'filter_gid_for_conflict_action', array( $this, 'match_gid_before_import' ) );
		remove_filter( 'import_synced_post_meta-synced_post_id', array( $this, 'match_gid_before_import' ) );

		return $this->respond( $response, $message );
	}

	/**
	 * Export a single post to all connected sites of a root post.
	 */
	public function export_post_to_all_connections( $request ) {

		$response        = false;
		$gid             = isset( $request['gid'] ) ? $request['gid'] : null;
		$connected_posts = isset( $request['args'] ) ? $request['args'] : null;
		$origin          = $request->get_header( 'Origin' );

		list( $root_blog_id, $root_post_id, $root_net_url ) = Synced_Post_Utils::explode_gid( $gid );

		// something is missing
		if ( empty( $root_post_id ) || ! is_array( $connected_posts ) || empty( $origin ) ) {
			return $this->respond( $response, 'The request arguments were set incorrectly (gid, args, posts).' );
		}

		// modify the gid for import
		$remote_gid = $root_blog_id . '-' . $root_post_id . '-' . $origin;

		$message = "Try to import post $remote_gid on all connected blogs: ";

		foreach ( $connected_posts as $blog_id => $post_con ) {
			if ( is_numeric( $blog_id ) ) {

				Multisite_Manager::switch_blog( $blog_id );

				$result = Synced_Post_Service::import_synced_post( $remote_gid );

				if ( $result === true ) {
					$message .= ' - post successfully imported';
				} else {
					$message .= ' - post could not be imported';
				}
				Multisite_Manager::restore_blog();
			}
			$response = true;
		}

		return $this->respond( $response, $message );
	}

	public function export_post_to_destination( $request ) {

		$response = false;
		$gid      = isset( $request['gid'] ) ? $request['gid'] : null;
		$blog_id  = isset( $request['blog_id'] ) ? $request['blog_id'] : null;
		$origin   = $request->get_header( 'Origin' );

		list( $root_blog_id, $root_post_id ) = Synced_Post_Utils::explode_gid( $gid );

		// something is missing
		if ( empty( $root_post_id ) || empty( $origin ) || empty( $blog_id ) ) {
			return $this->respond( $response, 'The request arguments were set incorrectly (gid, blog_id, posts).' );
		}

		// modify the gid for import
		$remote_gid = $root_blog_id . '-' . $root_post_id . '-' . $origin;

		$message = "Try to import post $remote_gid on connected blogs with id $blog_id: ";

		Multisite_Manager::switch_blog( $blog_id );

		/**
		 * @todo if $request['posts'] isset, use this array to import the post,
		 * otherwise use the synced post.
		 */
		$result = Synced_Post_Service::import_synced_post( $remote_gid );

		if ( $result === true ) {
			$message .= ' - post successfully imported';
		} else {
			$message .= ' - post could not be imported';
		}
		Multisite_Manager::restore_blog();

		$response = true;

		return $this->respond( $response, $message );
	}

	/**
	 * Delete all connected posts from this network
	 */
	public function delete_connected_posts( $request ) {

		$gid             = isset( $request['gid'] ) ? $request['gid'] : null;
		$connected_posts = isset( $request['args'] ) ? $request['args'] : null;

		$response = false;
		$message  = "The global ID was set incorrectly (input: {$gid}).";

		list( $blog_id, $post_id, $net_url ) = Synced_Post_Utils::explode_gid( $gid );
		if ( $post_id !== null && ! empty( $net_url ) ) {

			if ( is_array( $connected_posts ) && count( $connected_posts ) ) {

				$message = "Try to delete connected posts with the gid $gid: ";

				foreach ( $connected_posts as $blog_id => $post_con ) {

					if ( is_numeric( $blog_id ) && get_blog_details( $blog_id, false ) ) {
						Multisite_Manager::switch_blog( $blog_id );
						$result = wp_delete_post( $post_con['post_id'], true );
						if ( $result ) {
							$message .= ' - Post #' . $post_con['post_id'] . " was deleted from the blog $blog_id";
						} else {
							$message .= ' - Post #' . $post_con['post_id'] . " could not be deleted from the blog $blog_id";
						}
						Multisite_Manager::restore_blog();
					}
				}
				$response = true;
			} else {
				$message = 'No connected posts found in the request arguments.';
			}
		}

		return $this->respond( $response, $message );
	}


	/**
	 * =================================================================
	 *                          Helper
	 * =================================================================
	 */

	/**
	 * Filter the gid before import to match conflict actions
	 *
	 * @param string  $gid
	 * @param int     $post_id
	 * @param WP_Post $post
	 *
	 * @return string $gid
	 */
	public function match_gid_before_import( $gid, $post_id, $post ) {
		// echo "\r\n".sprintf( "Match gid before import: %s", $gid );

		$origin  = $this->origin;
		$current = Urls::get_network_url();

		list( $blog_id, $post_id, $net_url ) = Synced_Post_Utils::explode_gid( $gid );
		if ( ! empty( $post_id ) && ! empty( $origin ) ) {

			/**
			 * Matching gids between networks, there are basically 3 scenarios:
			 *
			 * (1) The request comes from the same network:
			 *     The meta-value for the gid in the request currently is:
			 *         '4-20'
			 *     On this network we need to keep it this way:
			 *         '4-20'
			 *     This happends
			 *
			 * (2) The post comes from the request origin network:
			 *     The meta-value for the gid in the request currently is:
			 *         '1-42'
			 *     On this network we need to append the origin network-url to it:
			 *         '1-42-origin.multisite.com'
			 *
			 * (3) The post comes from the current network:
			 *     The meta-value for the gid in the request currently is:
			 *         '4-20-this.multisite.com'
			 *     On this network we need to remove the current network-url from it:
			 *         '4-20'
			 *
			 * (4) The post comes from another network:
			 *     The meta-value for the gid in the request currently is:
			 *         '1-69-third.multisite.com'
			 *     This is fine, it just stays this way:
			 *         '1-69-third.multisite.com'
			 */

			// (1)
			if ( $origin == $current ) {
				$gid = $blog_id . '-' . $post_id;
			}
			// (2)
			elseif ( empty( $net_url ) ) {
				$gid = $blog_id . '-' . $post_id . '-' . $origin;
			}
			// (3)
			elseif ( $net_url == $current ) {
				$gid = $blog_id . '-' . $post_id;
			}
			// (4)
			else {
				// Think about it: Someone coded stackoverflow without stackoverflow.
			}
		}

		// echo "\r\n".sprintf( "New gid: %s", $gid );
		return $gid;
	}

	/**
	 * Allow requests from the same origin.
	 * This is needed to allow requests from the same network.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return bool
	 */
	public function allow_request_from_same_origin( $request ) {

		$origin  = $request->get_header( 'Origin' );
		$current = Urls::get_network_url();

		if ( $current == $origin ) {
			return true;
		} else {
			return parent::permission_callback( $request );
		}
	}

	/**
	 * Debug permission callback
	 */
	// public function permission_callback($request) {
	// return true;
	// }
}
