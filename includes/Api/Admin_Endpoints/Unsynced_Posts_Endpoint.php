<?php
/**
 * Unsynced Posts Admin REST Endpoint
 *
 * Handles REST requests for making posts into synced root posts, overwriting with
 * synced content, and finding similar synced posts. Combines the former
 * Sync_Export_Handler, Sync_Overwrite_Handler, and Sync_Similar_Posts_Handler
 * AJAX handlers.
 *
 * @package Contentsync
 * @subpackage Api\Admin_Endpoints
 */

namespace Contentsync\Api\Admin_Endpoints;

use Contentsync\Post_Sync\Post_Connection_Map;
use Contentsync\Post_Sync\Post_Meta;
use Contentsync\Post_Sync\Synced_Post_Service;
use Contentsync\Post_Sync\Synced_Post_Query;
use Contentsync\Post_Sync\Synced_Post_Utils;
use Contentsync\Utils\Urls;

defined( 'ABSPATH' ) || exit;

/**
 * Unsynced Posts Endpoint Class
 */
class Unsynced_Posts_Endpoint extends Admin_Endpoint_Base {

	/**
	 * REST base for this endpoint.
	 *
	 * @var string
	 */
	protected $rest_base = 'unsynced-posts';

	/**
	 * Param names for the make_root route.
	 *
	 * @var array
	 */
	private static $make_root_route_param_names = array( 'post_id', 'form_data' );

	/**
	 * Param names for the overwrite route.
	 *
	 * @var array
	 */
	private static $overwrite_route_param_names = array( 'post_id', 'gid' );

	/**
	 * Param names for the similar route.
	 *
	 * @var array
	 */
	private static $similar_route_param_names = array( 'post_id' );

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		$all_args = $this->get_endpoint_args();

		// POST /unsynced-posts/make_root — params: post_id, form_data
		$make_root_args = array_intersect_key(
			$all_args,
			array_flip( self::$make_root_route_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/make_root',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'make_root' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $make_root_args,
			)
		);

		// POST /unsynced-posts/overwrite — params: post_id, gid
		$overwrite_args = array_intersect_key(
			$all_args,
			array_flip( self::$overwrite_route_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/overwrite',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'overwrite' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $overwrite_args,
			)
		);

		// POST /unsynced-posts/similar — params: post_id
		$similar_args = array_intersect_key(
			$all_args,
			array_flip( self::$similar_route_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/similar',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'similar' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $similar_args,
			)
		);
	}

	/**
	 * Make a post into a synced root post.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function make_root( $request ) {
		$post_id   = (int) $request->get_param( 'post_id' );
		$form_data = (array) ( $request->get_param( 'form_data' ) ?? array() );

		if ( empty( $post_id ) ) {
			return $this->respond( false, __( 'post_id is not defined.', 'contentsync' ), 400 );
		}

		$args = Post_Meta::get_default_export_options();

		foreach ( $args as $k => $v ) {
			if ( isset( $form_data[ $k ] ) ) {
				$args[ $k ] = true;
			}
		}

		$gid = Synced_Post_Service::make_root_post( $post_id, $args );

		if ( ! $gid ) {
			return $this->respond( false, __( 'post could not be made into a synced root post.', 'contentsync' ), 400 );
		}

		$message = sprintf( __( 'post was made into a synced root post with the global id of %s', 'contentsync' ), $gid );
		return $this->respond( $gid, $message, true );
	}

	/**
	 * Overwrite a post with synced content.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function overwrite( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$gid     = (string) ( $request->get_param( 'gid' ) ?? '' );

		if ( empty( $post_id ) || empty( $gid ) ) {
			return $this->respond( false, __( 'post_id or gid is not defined.', 'contentsync' ), 400 );
		}

		$current_posts = array();
		$synced_post   = Synced_Post_Query::get_synced_post( $gid );

		if ( $synced_post ) {
			$current_posts[ $synced_post->ID ] = array(
				'post_id' => $post_id,
				'action'  => 'replace',
			);
		}

		$result = Synced_Post_Service::import_synced_post( $gid, $current_posts );

		if ( ! $result ) {
			return $this->respond( false, __( 'post could not be overwritten with synced content.', 'contentsync' ), 400 );
		}

		return $this->respond( true, __( 'post was successfully overwritten with synced content.', 'contentsync' ), true );
	}

	/**
	 * Find similar synced posts for a given post.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function similar( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( empty( $post_id ) ) {
			return $this->respond( false, __( 'post_id is not defined.', 'contentsync' ), 400 );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return $this->respond( false, sprintf( __( "Post with ID '%s' could not be found.", 'contentsync' ), $post_id ), 400 );
		}

		$similar_posts = $this->get_similar_synced_posts( $post );

		if ( ! $similar_posts ) {
			return $this->respond( false, __( 'No similar posts found.', 'contentsync' ), 400 );
		}

		return $this->respond( $similar_posts, __( 'Similar posts found.', 'contentsync' ), true );
	}

	/**
	 * Find similar synced posts.
	 *
	 * Criteria:
	 * - not from current blog
	 * - same posttype
	 * - post_name has at least 90% similarity
	 *
	 * @param \WP_Post $post Post object.
	 * @return array Similar posts keyed by gid.
	 */
	private function get_similar_synced_posts( $post ) {
		$found   = array();
		$blog_id = get_current_blog_id();
		$net_url = Urls::get_network_url();

		if ( ! isset( $post->post_name ) ) {
			return $found;
		}

		// Replace ending numbers after a dash (footer-2 becomes footer).
		$regex     = '/\-[0-9]{0,2}$/';
		$post_name = preg_replace( $regex, '', $post->post_name );

		$all_posts = Synced_Post_Query::get_all_synced_posts();

		foreach ( $all_posts as $synced_post ) {
			$synced_post = Synced_Post_Service::new_synced_post( $synced_post );
			$gid         = Post_Meta::get_values( $synced_post, 'synced_post_id' );

			list( $_blog_id, $_post_id, $_net_url ) = Synced_Post_Utils::explode_gid( $gid );

			// Exclude posts from other posttypes.
			if ( $post->post_type !== $synced_post->post_type ) {
				continue;
			}
			// Exclude posts from current blog.
			elseif ( empty( $_net_url ) && $blog_id == $_blog_id ) {
				continue;
			}
			// Exclude if a connection to this site is already established.
			elseif (
				( empty( $_net_url ) && isset( $synced_post->meta['contentsync_connection_map'][ $blog_id ] ) ) ||
				( ! empty( $_net_url ) && isset( $synced_post->meta['contentsync_connection_map'][ $net_url ][ $blog_id ] ) )
			) {
				continue;
			}

			// Check the post_name for similarity.
			$name = preg_replace( $regex, '', $synced_post->post_name );
			similar_text( $post_name, $name, $percent );

			// List if similarity is at least 90%.
			if ( intval( $percent ) >= 90 ) {
				// Make sure to get the post links.
				if ( empty( $synced_post->post_links ) ) {
					if ( ! empty( $_net_url ) ) {
						$synced_post = Synced_Post_Service::new_synced_post( Synced_Post_Query::get_synced_post( $gid ) );
					} else {
						$synced_post->post_links = Post_Connection_Map::get_local_post_links( $_blog_id, $_post_id );
					}
				}

				$found[ $gid ] = $synced_post;
			}
		}

		return apply_filters( 'contentsync_get_similar_synced_posts', $found, $post );
	}
}
