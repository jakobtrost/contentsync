<?php
/**
 * Editor Admin REST Endpoint
 *
 * Handles REST requests for the Site Editor: get post data and save options.
 * Formerly Rest_Api_Hooks in Admin/Views/Block_Editor.
 *
 * @package Contentsync
 * @subpackage Api\Admin_Endpoints
 */

namespace Contentsync\Api\Admin_Endpoints;

use Contentsync\Post_Sync\Post_Error_Handler;
use Contentsync\Cluster\Cluster_Service;
use Contentsync\Utils\Post_Query;
use Contentsync\Post_Sync\Post_Connection_Map;
use Contentsync\Post_Sync\Synced_Post_Service;
use Contentsync\Theme_Posts\Theme_Posts_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Editor Endpoint Class
 */
class Editor_Endpoint extends Admin_Endpoint_Base {

	/**
	 * REST base for this endpoint.
	 *
	 * @var string
	 */
	protected $rest_base = 'editor';

	/**
	 * Param names for the get-post-data route.
	 *
	 * @var array
	 */
	private static $get_post_data_param_names = array( 'postReference' );

	/**
	 * Param names for the save_options route.
	 *
	 * @var array
	 */
	private static $save_options_param_names = array( 'post_id', 'options', 'canonical_url' );

	/**
	 * Possible arguments for editor endpoints.
	 *
	 * @return array Map of param names to validate_callback config.
	 */
	public function get_endpoint_args() {
		return array_merge(
			parent::get_endpoint_args(),
			array(
				'postReference' => array(
					'validate_callback' => array( $this, 'is_post_reference' ),
				),
				'options'       => array(
					'validate_callback' => array( $this, 'is_array_or_object' ),
				),
				'canonical_url' => array(
					'validate_callback' => array( $this, 'is_string' ),
				),
			)
		);
	}

	/**
	 * Accept numeric id or string (e.g. theme//slug for site editor).
	 *
	 * @param mixed $value
	 * @return bool
	 */
	public function is_post_reference( $value ) {
		if ( is_numeric( $value ) ) {
			return true;
		}
		return is_string( $value ) && $value !== '';
	}

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		$all_args = $this->get_endpoint_args();

		$get_post_data_args = array_intersect_key(
			$all_args,
			array_flip( self::$get_post_data_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/get-post-data',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'get_post_data' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $get_post_data_args,
			)
		);

		$save_options_args = array_intersect_key(
			$all_args,
			array_flip( self::$save_options_param_names )
		);
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/save-options',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'save_options' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => $save_options_args,
			)
		);
	}

	/**
	 * Get contentsync post data via REST API.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_post_data( $request ) {
		$params         = $request->get_params();
		$post_reference = isset( $params['postReference'] ) ? $params['postReference'] : 0;

		if ( $post_reference === '' || $post_reference === null ) {
			return $this->respond( false, __( 'Could not get post infos.', 'contentsync' ), 400 );
		}

		if ( ! class_exists( 'Contentsync\Admin\Admin' ) ) {
			require_once CONTENTSYNC_PLUGIN_PATH . '/includes/Admin/Views/admin.php';
		}

		$post_id        = $this->get_numeric_post_id( $post_reference );
		$status         = get_post_meta( $post_id, 'synced_post_status', true );
		$gid            = get_post_meta( $post_id, 'synced_post_id', true );
		$connection_map = Post_Connection_Map::get( $post_id );

		// Get Contentsync options and canonical URL
		$contentsync_export_options = get_post_meta( $post_id, 'contentsync_export_options', true );
		$canonical_url              = get_post_meta( $post_id, 'contentsync_canonical_url', true );
		if ( empty( $canonical_url ) ) {
			$canonical_url = get_permalink( $post_id );
		}

		// Get available options for this post type
		$available_options = \Contentsync\Admin\Admin::get_contentsync_export_options_for_post( $post_id );
		$default_options   = array();
		foreach ( $available_options as $key => $option ) {
			$default_options[ $option['name'] ] = isset( $option['checked'] ) ? $option['checked'] : false;
		}

		// Merge with saved options
		$contentsync_export_options = array_merge( $default_options, $contentsync_export_options ?: array() );

		$data = array(
			'post'   => array_merge(
				// all posts
				array(
					'id'             => $post_id,
					'title'          => get_the_title( $post_id ),
					'gid'            => $gid,
					'status'         => $status,
					'currentUserCan' => Synced_Post_Service::current_user_can_edit_synced_posts( $status ),
				),
				// root posts
				( $status === 'root' ? array(
					'connectionMap'    => $connection_map,
					'options'          => $contentsync_export_options ?: array(),
					'canonicalUrl'     => $canonical_url,
					'availableOptions' => $available_options,
					'cluster'          => array_map(
						function ( $cluster ) use ( $connection_map ) {
							if ( isset( $cluster->destination_ids ) && is_array( $cluster->destination_ids ) ) {
								$cluster->destination_ids = array_map(
									function ( $blog_id ) use ( $connection_map ) {
										if ( strpos( $blog_id, '|' ) == ! false ) {
											$tmp        = explode( '|', $blog_id );
											$connection = isset( $connection_map[ $tmp[1] ] ) ? $connection_map[ $tmp[1] ] : array();
											$blog       = isset( $connection[ intval( $tmp[0] ) ] ) ? $connection[ intval( $tmp[0] ) ] : array();

											if ( isset( $blog['blog'] ) ) {
												return array(
													'blog_id'   => intval( $tmp[0] ),
													'blogname'  => $blog['nice'],
													'site_url'  => $blog['blog'],
													'is_remote' => true,
												);
											}
										} else {
											return array(
												'blog_id'  => $blog_id,
												'blogname' => get_blog_details( $blog_id )->blogname,
												'site_url' => get_site_url( $blog_id ),
												'is_remote' => false,
											);
										}
									},
									$cluster->destination_ids
								);
							}
							return $cluster;
						},
						Cluster_Service::get_clusters_including_post( $post_id )
					),
					'error'            => Post_Error_Handler::get_post_error( $post_id ),
				) : array() ),
				// linked posts
				( $status === 'linked' ? array(
					'links'     => Post_Connection_Map::get_links_by_gid( $gid ),
					'canonical' => esc_attr( get_post_meta( $post_id, 'contentsync_canonical_url', true ) ),
					'error'     => Post_Error_Handler::get_post_error( $post_id ),
				) : array() ),
			),
			'notice' => \Contentsync\Admin\Admin::get_global_notice_content( $post_id, 'site_editor' ),
		);

		return $this->respond( $data, __( 'Post data retrieved.', 'contentsync' ), 200 );
	}

	/**
	 * Save Contentsync options via REST API.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_options( $request ) {
		$params = $request->get_params();

		$post_id       = isset( $params['post_id'] ) ? intval( $params['post_id'] ) : 0;
		$options       = isset( $params['options'] ) ? $params['options'] : array();
		$canonical_url = isset( $params['canonical_url'] ) ? esc_url_raw( $params['canonical_url'] ) : '';

		if ( $post_id <= 0 ) {
			return $this->respond( false, __( 'Invalid post ID.', 'contentsync' ), 400 );
		}

		if ( ! Synced_Post_Service::current_user_can_edit_synced_posts( 'root' ) ) {
			return $this->respond( false, __( 'Permission denied.', 'contentsync' ), 403 );
		}

		if ( ! empty( $options ) ) {
			update_post_meta( $post_id, 'contentsync_export_options', $options );
		}

		if ( ! empty( $canonical_url ) ) {
			update_post_meta( $post_id, 'contentsync_canonical_url', $canonical_url );
		}

		return $this->respond(
			array(
				'options'       => $options,
				'canonical_url' => $canonical_url,
			),
			__( 'Options saved successfully.', 'contentsync' ),
			200
		);
	}

	/**
	 * Get the numeric post id for a given site editor post id.
	 *
	 * @param int|string $site_editor_post_id Post ID or theme//slug string.
	 * @return int
	 */
	public function get_numeric_post_id( $site_editor_post_id ) {
		if ( is_numeric( $site_editor_post_id ) ) {
			return (int) $site_editor_post_id;
		}

		if ( ! is_string( $site_editor_post_id ) ) {
			return 0;
		}

		$parts = explode( '//', $site_editor_post_id );
		if ( count( $parts ) === 2 ) {
			$posts = Post_Query::get_unfiltered_posts(
				array(
					'name'        => $parts[1],
					'post_type'   => array( 'wp_template', 'wp_template_part' ),
					'post_status' => 'any',
					'numberposts' => 1,
					'tax_query'   => array(
						array(
							'taxonomy' => 'wp_theme',
							'field'    => 'slug',
							'terms'    => array( $parts[0] ),
						),
					),
				)
			);

			if ( ! empty( $posts ) ) {
				return $posts[0]->ID;
			}
		}

		return 0;
	}

	/**
	 * Get the site editor post id for a given post, e.g. 'greyd-theme//404'.
	 *
	 * @param \WP_Post|int $post Post object or post id.
	 * @return string|int|null String for wp_template and wp_template_part;
	 *                         int for wp_navigation, wp_block and page;
	 *                         null for all other post types.
	 */
	public function get_site_editor_post_id( $post ) {
		if ( ! is_object( $post ) ) {
			$post = get_post( $post );
		}

		if ( ! is_a( $post, 'WP_Post' ) ) {
			return null;
		}

		switch ( $post->post_type ) {
			case 'wp_template':
			case 'wp_template_part':
				return Theme_Posts_Service::get_wp_template_theme( $post ) . '//' . $post->post_name;

			case 'wp_navigation':
			case 'wp_block':
			case 'page':
				return $post->ID;

			default:
				return null;
		}
	}
}
