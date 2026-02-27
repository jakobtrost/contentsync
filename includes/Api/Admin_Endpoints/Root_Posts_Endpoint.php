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
use Contentsync\Admin\Views\Distribution\Queue_Admin_Page_Hooks;

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
	 * Register REST API routes
	 */
	public function register_routes() {
		$all_args = $this->get_endpoint_args();

		// POST /root-posts/check-connections — params: post_id
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/check-connections',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'check_connections' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array_intersect_key(
					$all_args,
					array_flip( array( 'post_id' ) )
				),
			)
		);

		// POST /root-posts/unlink — params: gid
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/unlink',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'unlink' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array_intersect_key(
					$all_args,
					array_flip( array( 'gid' ) )
				),
			)
		);

		// POST /root-posts/trash — params: post_id, blog_id (optional)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/trash',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'trash' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array_intersect_key(
					$all_args,
					array_flip( array( 'post_id', 'blog_id' ) )
				),
			)
		);

		// POST /root-posts/delete — params: gid
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/delete',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array_intersect_key(
					$all_args,
					array_flip( array( 'gid' ) )
				),
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
			return $this->respond( false, __( 'Error checking post connections: post_id is not defined.', 'contentsync' ), 400 );
		}

		$result = Post_Connection_Map::check( $post_id );

		if ( ! $result ) {
			return $this->respond( false, __( 'Error checking post connections: some corrupted connections were detected and fixed.', 'contentsync' ), 400 );
		}

		return $this->respond( true, __( 'Error checking post connections: there were no corrupted connections.', 'contentsync' ), true );
	}

	/**
	 * Disable global synchronization for a root post (unlink).
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function unlink( $request ) {
		$gid = (string) ( $request->get_param( 'gid' ) ?? '' );

		if ( empty( $gid ) ) {
			return $this->respond( false, __( 'Error disabling global synchronization: global ID is not defined.', 'contentsync' ), 400 );
		}

		$result = Synced_Post_Service::unlink_root_post( $gid );

		if ( ! $result ) {
			return $this->respond( false, __( 'Error disabling global synchronization: post could not be disabled.', 'contentsync' ), 400 );
		}

		return $this->respond( true, __( 'The global synchronization for the post was disabled successfully.', 'contentsync' ), true );
	}

	/**
	 * Trash a post, optionally on another blog.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function trash( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$blog_id = $request->get_param( 'blog_id' );

		if ( empty( $post_id ) ) {
			return $this->respond( false, __( 'Error moving post to the trash: post_id is not defined.', 'contentsync' ), 400 );
		}

		if ( $blog_id ) {
			Multisite_Manager::switch_blog( (int) $blog_id );
		}

		$result = wp_trash_post( $post_id );

		if ( $blog_id ) {
			Multisite_Manager::restore_blog();
		}

		if ( ! $result ) {
			return $this->respond( false, __( 'Error moving post to the trash: post could not be moved to the trash.', 'contentsync' ), 400 );
		}

		return $this->respond( true, __( 'The post was moved to the trash successfully.', 'contentsync' ), true );
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
			return $this->respond( false, __( 'Error deleting synced post: global ID is not defined.', 'contentsync' ), 400 );
		}

		$result = Synced_Post_Service::delete_root_post_and_connected_posts( $gid );

		if ( ! $result ) {
			return $this->respond( false, __( 'Error deleting synced post: post could not be deleted.', 'contentsync' ), 400 );
		}

		$link = array(
			'text' => __( 'View queue', 'contentsync' ),
			'url'  => Queue_Admin_Page_Hooks::get_queue_admin_url(),
		);

		return $this->respond( $link, __( 'The synced post was scheduled for permanent deletion on all sites successfully. This process may take a few minutes to complete.', 'contentsync' ), true );
	}
}
