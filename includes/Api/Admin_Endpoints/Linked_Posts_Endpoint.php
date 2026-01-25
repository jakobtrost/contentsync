<?php
/**
 * Linked Posts Admin REST Endpoint
 *
 * Handles REST requests for checking synced posts before import (single
 * and bulk), importing synced posts, and unlinking imported posts. Combines
 * the former Sync_Check_Import_Bulk_Handler, Sync_Check_Import_Handler,
 * Sync_Import_Handler, and Sync_Unimport_Handler AJAX handlers.
 *
 * @package Contentsync
 * @subpackage Api\Admin_Endpoints
 */

namespace Contentsync\Api\Admin_Endpoints;

use Contentsync\Admin\Views\Post_Transfer\Post_Conflict_Handler;
use Contentsync\Post_Sync\Synced_Post_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Linked Posts Endpoint Class
 */
class Linked_Posts_Endpoint extends Admin_Endpoint_Base {

	/**
	 * REST base for this endpoint.
	 *
	 * @var string
	 */
	protected $rest_base = 'linked-posts';

	/**
	 * Param names for the check-import-bulk route.
	 *
	 * @var array
	 */
	private static $check_import_bulk_param_names = array( 'posts' );

	/**
	 * Param names for the check-import route.
	 *
	 * @var array
	 */
	private static $check_import_param_names = array( 'gid' );

	/**
	 * Param names for the import route.
	 *
	 * @var array
	 */
	private static $import_route_param_names = array( 'gid', 'form_data' );

	/**
	 * Param names for the unimport route.
	 *
	 * @var array
	 */
	private static $unimport_route_param_names = array( 'post_id' );

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		$all_args = $this->get_endpoint_args();

		// POST /linked-posts/check-import-bulk — params: posts
		$check_bulk_args = array_intersect_key(
			$all_args,
			array_flip( self::$check_import_bulk_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/check-import-bulk',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'check_import_bulk' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $check_bulk_args,
			)
		);

		// POST /linked-posts/check-import — params: gid
		$check_args = array_intersect_key(
			$all_args,
			array_flip( self::$check_import_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/check-import',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'check_import' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $check_args,
			)
		);

		// POST /linked-posts/import — params: gid, form_data (optional)
		$import_args = array_intersect_key(
			$all_args,
			array_flip( self::$import_route_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/import',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'import' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $import_args,
			)
		);

		// POST /linked-posts/unimport — params: post_id
		$unimport_args = array_intersect_key(
			$all_args,
			array_flip( self::$unimport_route_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/unimport',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'unimport' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $unimport_args,
			)
		);
	}

	/**
	 * Check multiple synced posts for import conflicts (bulk).
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check_import_bulk( $request ) {
		$posts = (array) ( $request->get_param( 'posts' ) ?? array() );

		if ( empty( $posts ) ) {
			return $this->respond( false, __( 'global IDs are not defined.', 'contentsync' ), 400 );
		}

		$results = array();
		foreach ( $posts as $post ) {
			if ( ! isset( $post['gid'] ) ) {
				continue;
			}
			$conflict = Post_Conflict_Handler::check_synced_post_import( $post['gid'] );
			if ( $conflict ) {
				$results[] = array(
					'gid'      => $post['gid'],
					'conflict' => $conflict,
				);
			}
		}

		if ( empty( $results ) ) {
			return $this->respond( false, __( 'posts could not be checked for conflicts.', 'contentsync' ), 400 );
		}

		return $this->respond( $results, __( 'Conflicts found.', 'contentsync' ), true );
	}

	/**
	 * Check a single synced post for import conflicts.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function check_import( $request ) {
		$gid = (string) ( $request->get_param( 'gid' ) ?? '' );

		if ( empty( $gid ) ) {
			return $this->respond( false, __( 'global ID is not defined.', 'contentsync' ), 400 );
		}

		$result = Post_Conflict_Handler::check_synced_post_import( $gid );

		if ( ! $result ) {
			return $this->respond( false, __( 'post could not be checked for conflicts.', 'contentsync' ), 400 );
		}

		return $this->respond( $result, '', true );
	}

	/**
	 * Import a synced post with optional conflict resolution.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( $request ) {
		$gid       = (string) ( $request->get_param( 'gid' ) ?? '' );
		$conflicts = (array) ( $request->get_param( 'form_data' ) ?? array() );

		if ( empty( $gid ) ) {
			return $this->respond( false, __( 'global ID is not defined.', 'contentsync' ), 400 );
		}

		$conflict_actions = Post_Conflict_Handler::get_conflicting_post_selections( $conflicts );
		$result           = Synced_Post_Service::import_synced_post( $gid, $conflict_actions );

		if ( $result !== true ) {
			$message = is_wp_error( $result ) ? $result->get_error_message() : (string) $result;
			return $this->respond( false, $message, 400 );
		}

		return $this->respond( true, __( 'post was imported!', 'contentsync' ), true );
	}

	/**
	 * Unlink an imported post (make it static).
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function unimport( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( empty( $post_id ) ) {
			return $this->respond( false, __( 'post_id is not defined.', 'contentsync' ), 400 );
		}

		$result = Synced_Post_Service::unlink_synced_post( $post_id );

		if ( ! $result ) {
			return $this->respond( false, __( 'post could not be made static...', 'contentsync' ), 400 );
		}

		return $this->respond( true, __( 'post was successfully made static', 'contentsync' ), true );
	}
}
