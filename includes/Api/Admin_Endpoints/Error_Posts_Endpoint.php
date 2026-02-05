<?php
/**
 * Error Posts Admin REST Endpoint
 *
 * Handles REST requests for listing synced posts with errors and for
 * repairing posts. Combines the former Sync_Error_Posts_Handler and
 * Sync_Repair_Handler AJAX handlers.
 *
 * @package Contentsync
 * @subpackage Api\Admin_Endpoints
 */

namespace Contentsync\Api\Admin_Endpoints;

use Contentsync\Post_Sync\Post_Error_Handler;

defined( 'ABSPATH' ) || exit;

/**
 * Error Posts Endpoint Class
 */
class Error_Posts_Endpoint extends Admin_Endpoint_Base {

	/**
	 * REST base for this endpoint.
	 *
	 * @var string
	 */
	protected $rest_base = 'error-posts';

	/**
	 * Param names for the list route.
	 *
	 * @var array
	 */
	private static $list_route_param_names = array( 'mode', 'blog_id', 'post_type' );

	/**
	 * Param names for the repair route.
	 *
	 * @var array
	 */
	private static $repair_route_param_names = array( 'post_id', 'blog_id' );

	/**
	 * Get endpoint args, including mode for the list route.
	 *
	 * @return array
	 */
	public function get_endpoint_args() {
		$args         = parent::get_endpoint_args();
		$args['mode'] = array(
			'validate_callback' => array( $this, 'is_string' ),
		);
		return $args;
	}

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		$all_args = $this->get_endpoint_args();

		// POST /error-posts/list — params: mode, blog_id (optional), post_type (optional)
		$list_args = array_intersect_key(
			$all_args,
			array_flip( self::$list_route_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/list',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'list_posts' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $list_args,
			)
		);

		// POST /error-posts/repair — params: post_id, blog_id (optional)
		$repair_args = array_intersect_key(
			$all_args,
			array_flip( self::$repair_route_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/repair',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'repair' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $repair_args,
			)
		);
	}

	/**
	 * List synced posts with (unrepaired) errors.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_posts( $request ) {
		$mode      = (string) ( $request->get_param( 'mode' ) ?? '' );
		$blog_id   = $request->get_param( 'blog_id' );
		$post_type = $request->get_param( 'post_type' );

		$query_args = array();
		if ( $post_type !== null && $post_type !== '' ) {
			$query_args['post_type'] = $post_type;
		}

		if ( $mode === 'network' ) {
			$posts = Post_Error_Handler::get_network_synced_posts_with_errors( false, $query_args );
		} else {
			$posts = Post_Error_Handler::get_synced_posts_of_blog_with_errors( $blog_id ? (int) $blog_id : 0, false, $query_args );
		}

		if ( empty( $posts ) ) {
			return $this->respond( false, __( 'No errors found.', 'contentsync' ), 400 );
		}

		$return = array_filter(
			$posts,
			function ( $post ) {
				return isset( $post->error ) && ! Post_Error_Handler::is_error_repaired( $post->error );
			}
		);

		if ( empty( $return ) ) {
			return $this->respond( false, __( 'No errors found.', 'contentsync' ), 400 );
		}

		return $this->respond( array_values( $return ), '', true );
	}

	/**
	 * Repair a post with errors.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function repair( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$blog_id = $request->get_param( 'blog_id' );

		if ( empty( $post_id ) ) {
			return $this->respond( false, __( 'post_id is not defined.', 'contentsync' ), 400 );
		}

		$error = Post_Error_Handler::repair_post( $post_id, $blog_id ? (int) $blog_id : null, true );

		if ( ! $error ) {
			return $this->respond( false, __( 'post has no error.', 'contentsync' ), 400 );
		}

		$log = Post_Error_Handler::get_error_repaired_log( $error );

		if ( Post_Error_Handler::is_error_repaired( $error ) ) {
			return $this->respond( $log, __( 'post was successfully repaired', 'contentsync' ), true );
		}

		$message = is_object( $error ) && isset( $error->message ) ? $error->message : __( 'Repair failed.', 'contentsync' );
		return $this->respond( false, $message, 400 );
	}
}
