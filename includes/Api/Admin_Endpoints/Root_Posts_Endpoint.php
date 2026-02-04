<?php
/**
 * Root Posts Admin REST Endpoint
 *
 * Handles REST requests for checking post connections, deleting and
 * trashing synced posts, and unlinking root posts. Combines the former
 * Sync_Check_Connections_Handler, Sync_Delete_Handler, Sync_Trash_Handler,
 * and Sync_Unexport_Handler AJAX handlers.
 *
 * @package Contentsync
 * @subpackage Api\Admin_Endpoints
 */

namespace Contentsync\Api\Admin_Endpoints;

use Contentsync\Post_Sync\Post_Connection_Map;
use Contentsync\Post_Sync\Synced_Post_Service;
use Contentsync\Utils\Multisite_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Root Posts Endpoint Class
 */
class Root_Posts_Endpoint extends Admin_Endpoint_Base {

	/**
	 * REST base for this endpoint.
	 *
	 * @var string
	 */
	protected $rest_base = 'root-posts';

	/**
	 * Param names for the check-connections route.
	 *
	 * @var array
	 */
	private static $check_connections_param_names = array( 'post_id' );

	/**
	 * Param names for the delete route.
	 *
	 * @var array
	 */
	private static $delete_route_param_names = array( 'gid' );

	/**
	 * Param names for the trash route.
	 *
	 * @var array
	 */
	private static $trash_route_param_names = array( 'post_id', 'blog_id' );

	/**
	 * Param names for the unlink route.
	 *
	 * @var array
	 */
	private static $unlink_route_param_names = array( 'gid' );

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		$all_args = $this->get_endpoint_args();

		// POST /root-posts/check-connections — params: post_id
		$check_args = array_intersect_key(
			$all_args,
			array_flip( self::$check_connections_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/check-connections',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'check_connections' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $check_args,
			)
		);

		// POST /root-posts/delete — params: gid
		$delete_args = array_intersect_key(
			$all_args,
			array_flip( self::$delete_route_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/delete',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $delete_args,
			)
		);

		// POST /root-posts/trash — params: post_id, blog_id (optional)
		$trash_args = array_intersect_key(
			$all_args,
			array_flip( self::$trash_route_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/trash',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'trash' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $trash_args,
			)
		);

		// POST /root-posts/unlink — params: gid
		$unlink_args = array_intersect_key(
			$all_args,
			array_flip( self::$unlink_route_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/unlink',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'unlink' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $unlink_args,
			)
		);
	}

	/**
	 * Check and repair post connections.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check_connections( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( empty( $post_id ) ) {
			return $this->respond( false, __( 'post_id is not defined.', 'contentsync' ), 400 );
		}

		$result = Post_Connection_Map::check( $post_id );

		if ( ! $result ) {
			return $this->respond( false, __( 'some corrupted connections were detected and fixed.', 'contentsync' ), 400 );
		}

		return $this->respond( true, __( 'there were no corrupted connections.', 'contentsync' ), true );
	}

	/**
	 * Delete root post and all connected posts.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete( $request ) {
		$gid = (string) ( $request->get_param( 'gid' ) ?? '' );

		if ( empty( $gid ) ) {
			return $this->respond( false, __( 'global ID is not defined.', 'contentsync' ), 400 );
		}

		$result = Synced_Post_Service::delete_root_post_and_connected_posts( $gid );

		if ( ! $result ) {
			return $this->respond( false, __( 'post could not be deleted...', 'contentsync' ), 400 );
		}

		return $this->respond( true, __( 'post was successfully deleted', 'contentsync' ), true );
	}

	/**
	 * Trash a post, optionally in another blog.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function trash( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$blog_id = $request->get_param( 'blog_id' );

		if ( empty( $post_id ) ) {
			return $this->respond( false, __( 'post_id is not defined.', 'contentsync' ), 400 );
		}

		if ( $blog_id ) {
			Multisite_Manager::switch_blog( (int) $blog_id );
		}

		$result = wp_trash_post( $post_id );

		if ( $blog_id ) {
			Multisite_Manager::restore_blog();
		}

		if ( ! $result ) {
			return $this->respond( false, __( 'post could not be trashed...', 'contentsync' ), 400 );
		}

		return $this->respond( true, __( 'post was successfully trashed', 'contentsync' ), true );
	}

	/**
	 * Unlink root post (unlink).
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function unlink( $request ) {
		$gid = (string) ( $request->get_param( 'gid' ) ?? '' );

		if ( empty( $gid ) ) {
			return $this->respond( false, __( 'global ID is not defined.', 'contentsync' ), 400 );
		}

		$result = Synced_Post_Service::unlink_root_post( $gid );

		if ( ! $result ) {
			return $this->respond( false, __( 'exported post could not be unlinked globally...', 'contentsync' ), 400 );
		}

		return $this->respond( true, __( 'post was unlinked and the synced post was removed', 'contentsync' ), true );
	}
}
