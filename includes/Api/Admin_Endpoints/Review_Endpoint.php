<?php
/**
 * Review Admin REST Endpoint
 *
 * Handles REST requests for approving, denying, and reverting post reviews.
 * Combines the former Review_Approve_Handler, Review_Deny_Handler, and
 * Review_Revert_Handler AJAX handlers.
 *
 * @package Contentsync
 * @subpackage Api\Admin_Endpoints
 */

namespace Contentsync\Api\Admin_Endpoints;

use Contentsync\Reviews\Post_Review_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Review Endpoint Class
 */
class Review_Endpoint extends Admin_Endpoint_Base {

	/**
	 * REST base for this endpoint.
	 *
	 * @var string
	 */
	protected $rest_base = 'review';

	/**
	 * Param names for the approve route.
	 *
	 * @var array
	 */
	private static $approve_route_param_names = array( 'review_id', 'post_id' );

	/**
	 * Param names for the deny and revert routes.
	 *
	 * @var array
	 */
	private static $deny_revert_route_param_names = array( 'review_id', 'post_id', 'message' );

	/**
	 * Get endpoint args, including message for deny/revert.
	 *
	 * @return array
	 */
	public function get_endpoint_args() {
		$args            = parent::get_endpoint_args();
		$args['message'] = array(
			'validate_callback' => array( $this, 'is_string' ),
		);
		return $args;
	}

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		$all_args = $this->get_endpoint_args();

		// POST /review/approve — params: review_id, post_id
		$approve_args = array_intersect_key(
			$all_args,
			array_flip( self::$approve_route_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/approve',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'approve' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $approve_args,
			)
		);

		// POST /review/deny — params: review_id, post_id, message
		$deny_args = array_intersect_key(
			$all_args,
			array_flip( self::$deny_revert_route_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/deny',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'deny' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $deny_args,
			)
		);

		// POST /review/revert — params: review_id, post_id, message
		$revert_args = array_intersect_key(
			$all_args,
			array_flip( self::$deny_revert_route_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/revert',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'revert' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $revert_args,
			)
		);
	}

	/**
	 * Approve a post review.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function approve( $request ) {
		$review_id = (int) $request->get_param( 'review_id' );
		$post_id   = (int) $request->get_param( 'post_id' );

		$result = Post_Review_Service::approve_post_review( $review_id, $post_id );

		if ( ! $result ) {
			return $this->respond( false, __( 'Review could not be approved.', 'contentsync' ), 400 );
		}

		return $this->respond( true, __( 'Review was approved.', 'contentsync' ), true );
	}

	/**
	 * Deny a post review.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function deny( $request ) {
		$review_id = (int) $request->get_param( 'review_id' );
		$post_id   = (int) $request->get_param( 'post_id' );
		$message   = (string) ( $request->get_param( 'message' ) ?? '' );

		$result = Post_Review_Service::deny_post_review( $review_id, $post_id, $message );

		if ( ! $result ) {
			return $this->respond( false, __( 'Review could not be denied.', 'contentsync' ), 400 );
		}

		return $this->respond( true, __( 'Review was denied.', 'contentsync' ), true );
	}

	/**
	 * Revert a post review.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revert( $request ) {
		$review_id = (int) $request->get_param( 'review_id' );
		$post_id   = (int) $request->get_param( 'post_id' );
		$message   = (string) ( $request->get_param( 'message' ) ?? '' );

		$result = Post_Review_Service::revert_post_review( $review_id, $post_id, $message );

		if ( ! $result ) {
			return $this->respond( false, __( 'Review could not be reverted.', 'contentsync' ), 400 );
		}

		return $this->respond( true, __( 'Review was reverted.', 'contentsync' ), true );
	}
}
