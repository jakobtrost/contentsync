<?php
/**
 * Theme Admin REST Endpoint
 *
 * Handles REST requests for renaming templates and switching global styles
 * or template themes. Combines the former Theme_Rename_Template_Handler,
 * Theme_Switch_Global_Styles_Handler, and Theme_Switch_Template_Handler
 * AJAX handlers.
 *
 * @package Contentsync
 * @subpackage Api\Admin_Endpoints
 */

namespace Contentsync\Api\Admin_Endpoints;

use Contentsync\Posts\Theme_Posts;

defined( 'ABSPATH' ) || exit;

/**
 * Theme Endpoint Class
 */
class Theme_Endpoint extends Admin_Endpoint_Base {

	/**
	 * REST base for this endpoint.
	 *
	 * @var string
	 */
	protected $rest_base = 'theme';

	/**
	 * Param names for the rename-template route.
	 *
	 * @var array
	 */
	private static $rename_route_param_names = array( 'post_id', 'post_title', 'post_name' );

	/**
	 * Param names for the switch-global-styles route.
	 *
	 * @var array
	 */
	private static $switch_global_styles_param_names = array( 'post_id' );

	/**
	 * Param names for the switch-template route.
	 *
	 * @var array
	 */
	private static $switch_template_param_names = array( 'post_id', 'switch_references_in_content' );

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		$all_args = $this->get_endpoint_args();

		// POST /theme/rename-template — params: post_id, post_title, post_name
		$rename_args = array_intersect_key(
			$all_args,
			array_flip( self::$rename_route_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/rename-template',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'rename_template' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $rename_args,
			)
		);

		// POST /theme/switch-global-styles — params: post_id
		$switch_global_args = array_intersect_key(
			$all_args,
			array_flip( self::$switch_global_styles_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/switch-global-styles',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'switch_global_styles' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $switch_global_args,
			)
		);

		// POST /theme/switch-template — params: post_id, switch_references_in_content
		$switch_template_args = array_intersect_key(
			$all_args,
			array_flip( self::$switch_template_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/switch-template',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'switch_template' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $switch_template_args,
			)
		);
	}

	/**
	 * Rename a template (post_title and post_name).
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rename_template( $request ) {
		$post_id    = (int) $request->get_param( 'post_id' );
		$post_title = (string) ( $request->get_param( 'post_title' ) ?? '' );
		$post_name  = (string) ( $request->get_param( 'post_name' ) ?? '' );

		if ( empty( $post_id ) ) {
			return $this->respond( false, __( 'No valid post ID found.', 'contentsync' ), 400 );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return $this->respond( false, __( 'Post not found.', 'contentsync' ), 400 );
		}

		$post->post_title = $post_title;
		$post->post_name  = $post_name;

		$result = wp_update_post( $post, true );

		if ( is_wp_error( $result ) ) {
			return $this->respond( false, $result->get_error_message(), 400 );
		}

		if ( ! $result ) {
			return $this->respond( false, __( 'Template could not be renamed.', 'contentsync' ), 400 );
		}

		return $this->respond( true, __( 'Template was renamed.', 'contentsync' ), true );
	}

	/**
	 * Switch global styles to the given post.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function switch_global_styles( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( empty( $post_id ) ) {
			return $this->respond( false, __( 'No valid post ID found.', 'contentsync' ), 400 );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return $this->respond( false, __( 'Post not found.', 'contentsync' ), 400 );
		}

		$result = Theme_Posts::set_wp_global_styles_theme( $post );

		if ( is_wp_error( $result ) ) {
			return $this->respond( false, $result->get_error_message(), 400 );
		}

		if ( ! $result ) {
			return $this->respond( false, __( 'Styles could not be assigned to the current theme.', 'contentsync' ), 400 );
		}

		return $this->respond( true, __( 'Styles were assigned to the current theme.', 'contentsync' ), true );
	}

	/**
	 * Switch template theme to the given post.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function switch_template( $request ) {
		$post_id                      = (int) $request->get_param( 'post_id' );
		$switch_references_in_content = (bool) ( $request->get_param( 'switch_references_in_content' ) ?? false );

		if ( empty( $post_id ) ) {
			return $this->respond( false, __( 'No valid post ID found.', 'contentsync' ), 400 );
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return $this->respond( false, __( 'Post not found.', 'contentsync' ), 400 );
		}

		$result = Theme_Posts::set_wp_template_theme( $post, $switch_references_in_content );

		if ( is_wp_error( $result ) ) {
			return $this->respond( false, $result->get_error_message(), 400 );
		}

		if ( ! $result ) {
			return $this->respond( false, __( 'Template could not be assigned to the current theme.', 'contentsync' ), 400 );
		}

		return $this->respond( true, __( 'Template was assigned to the current theme.', 'contentsync' ), true );
	}
}
