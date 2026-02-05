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

use Contentsync\Post_Transfer\Post_Conflict_Handler;
use Contentsync\Post_Sync\Synced_Post_Service;
use Contentsync\Post_Sync\Synced_Post_Query;
use Contentsync\Utils\Logger;

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
	private static $import_route_param_names = array( 'gid', 'conflicts' );

	/**
	 * Param names for the unlink route.
	 *
	 * @var array
	 */
	private static $unlink_route_param_names = array( 'post_id' );

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		$all_args = $this->get_endpoint_args();

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

		// POST /linked-posts/unlink — params: post_id
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

		$conflicts = $this->check_synced_post_import( $gid );

		if ( empty( $conflicts ) ) {
			return $this->respond( array(), __( 'No conflicts found. You can safely import the global post.', 'contentsync' ), true );
		}

		return $this->respond( $conflicts, __( 'Attention: Some content in the file already appears to exist on this site. Choose what to do with it.', 'contentsync' ), true );
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

		$all_posts = array();
		foreach ( $posts as $post ) {
			if ( ! isset( $post['gid'] ) ) {
				continue;
			}
			$posts = $this->check_synced_post_import( $post['gid'] );

			$all_posts = array_merge( $all_posts, $posts );
		}

		if ( empty( $all_posts ) ) {
			return $this->respond( false, __( 'posts could not be checked for conflicts.', 'contentsync' ), 400 );
		}

		return $this->respond( $all_posts, __( 'Conflicts found.', 'contentsync' ), true );
	}

	/**
	 * Check synced posts for conflicts on this page
	 *
	 * @param string $gid   Global ID.
	 *
	 * @return Prepared_Post[]   The prepared posts, keyed by ID, with some properties added:
	 *   @property WP_Post $existing_post       The conflicting post, with some additional properties:
	 *      @property int $original_post_id     The original post ID
	 *      @property string $post_link         The link to the conflicting post
	 *      @property string $conflict_action   Optional: Predefined conflict action (skip|replace|keep)
	 *                                          If a post is already synced to this site, the conflict action will be set to 'skip'.
	 *                                          @see \Contentsync\Post_Sync\Synced_Post_Hooks::adjust_conflict_action_on_import_check()
	 *      @property string $conflict_message  Optional: Predefined conflict message
	 *                                          If a post is already synced to this site, the conflict message will be set to 'Already synced.'.
	 *                                          @see \Contentsync\Post_Sync\Synced_Post_Hooks::adjust_conflict_action_on_import_check()
	 */
	public function check_synced_post_import( $gid ) {

		$posts = Synced_Post_Query::prepare_synced_post_for_import( $gid );
		$posts = Post_Conflict_Handler::get_import_posts_with_conflicts( $posts );

		return $posts;
	}

	/**
	 * Import a synced post with optional conflict resolution.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( $request ) {
		$gid = (string) ( $request->get_param( 'gid' ) ?? '' );

		if ( empty( $gid ) ) {
			return $this->respond( false, __( 'global ID is not defined.', 'contentsync' ), 400 );
		}

		/**
		 * Get the conflicts from the request. If done right, the conflicts array will be like this:
		 *
		 * conflicts: [
		 *   0 => array(
		 *     'existing_post_id' => 123,
		 *     'original_post_id' => 456,
		 *     'conflict_action' => 'keep'
		 *   ),
		 *   1 => array(
		 *     'existing_post_id' => 789,
		 *     'original_post_id' => 101,
		 *     'conflict_action' => 'replace'
		 *   )
		 * ]
		 */
		$conflicts = (array) ( $request->get_param( 'conflicts' ) ?? array() );

		// Convert the conflicts array to a keyed array by original post ID.
		$conflict_actions = array();
		foreach ( $conflicts as $conflict ) {
			$conflict_actions[ $conflict['original_post_id'] ] = $conflict;
		}

		$result = Synced_Post_Service::import_synced_post( $gid, $conflict_actions );

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
	public function unlink( $request ) {
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
