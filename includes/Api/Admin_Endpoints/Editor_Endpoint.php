<?php
/**
 * Editor Admin REST Endpoint
 *
 * Handles REST requests for the Block & Site Editor: get post data and save options.
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
use Contentsync\Admin\Views\Post_Sync\Classic_Editor_Hooks;
use Contentsync\Utils\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Block & Site Editor Endpoint Class
 */
class Editor_Endpoint extends Admin_Endpoint_Base {

	/**
	 * REST base for this endpoint.
	 *
	 * @var string
	 */
	protected $rest_base = 'editor';

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
	 * Register REST API routes
	 */
	public function register_routes() {
		$all_args = $this->get_endpoint_args();

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/get-post-data',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'get_post_data' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array_intersect_key(
					$all_args,
					array_flip( array( 'postReference' ) )
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/save-options',
			array(
				'methods'             => $this->method,
				'callback'            => array( $this, 'save_options' ),
				'permission_callback' => array( $this, 'permission_callback' ),
				'args'                => array_intersect_key(
					$all_args,
					array_flip( array( 'post_id', 'options', 'canonical_url' ) )
				),
			)
		);
	}

	/**
	 * Get contentsync post data via REST API.
	 *
	 * Fetches synced post metadata and status-dependent data for the Block/Site Editor UI.
	 * The response data structure depends on the post's synced status (root, linked, or unsynced).
	 *
	 * @param \WP_REST_Request $request Full request object.
	 *
	 * @return \WP_REST_Response Response object with post data and notice.
	 *     @property array  post   Contentsync metadata for the current post @see build_post_data()
	 *     @property array  notice Notice to show in the editor @see Classic_Editor_Hooks::get_global_notice_content()
	 */
	public function get_post_data( $request ) {
		$params         = $request->get_params();
		$post_reference = isset( $params['postReference'] ) ? $params['postReference'] : 0;

		if ( $post_reference === '' || $post_reference === null ) {
			return $this->respond( false, __( 'Could not get post infos.', 'contentsync' ), 400 );
		}

		$post_id = $this->get_numeric_post_id( $post_reference );
		if ( $post_id <= 0 ) {
			return $this->respond( false, __( 'Could not get post infos.', 'contentsync' ), 400 );
		}

		$data = array(
			'post'   => $this->build_post_data( $post_id ),
			'notice' => Classic_Editor_Hooks::get_global_notice_content( $post_id, 'site_editor' ),
		);

		return $this->respond( $data, __( 'Post data retrieved.', 'contentsync' ), 200 );
	}

	/**
	 * Build post data for a given post id.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array Contentsync metadata for the current post.
	 *     @property int    id               WordPress post ID.
	 *     @property string title            Post title.
	 *     @property string gid              Global synced post ID. Empty for unsynced posts.
	 *     @property string status           Synced status – 'root' (source), 'linked' (synced copy), or empty (unsynced).
	 *     @property bool   currentUserCan   Whether the current user may edit this synced post.
	 *     --- GLOBAL POST ONLY ---
	 *     @property string canonicalUrl     Canonical URL chosen for the post.
	 *     @property mixed  error            Post error object if any; null/false when no error.
	 *     --- ROOT POST ONLY ---
	 *     @property array  connectionMap    Map of remote connections and their destination blogs.
	 *     @property array  options          Export options (e.g. append_nested, resolve_menus) for this post.
	 *     @property array  availableOptions Export option definitions for this post type.
	 *     @property array  cluster          Clusters containing this post, with destination info.
	 *     --- LINKED POST ONLY ---
	 *     @property array  links            Links to the root post (edit, blog, nice, etc.).
	 */
	public function build_post_data( $post_id ) {
		$status         = get_post_meta( $post_id, 'synced_post_status', true );
		$gid            = get_post_meta( $post_id, 'synced_post_id', true );
		$connection_map = Post_Connection_Map::get( $post_id );

		$canonical_url = get_post_meta( $post_id, 'contentsync_canonical_url', true );
		if ( empty( $canonical_url ) ) {
			$canonical_url = get_permalink( $post_id );
		}

		if ( ! class_exists( 'Contentsync\Admin\Admin' ) ) {
			require_once CONTENTSYNC_PLUGIN_PATH . '/includes/Admin/Views/admin.php';
		}
		$available_options = \Contentsync\Admin\Admin::get_contentsync_export_options_for_post( $post_id );
		$export_options    = $this->get_merged_export_options_for_post( $post_id, $available_options );

		$post = array_merge(
			$this->build_base_post_data( $post_id, $gid, $status ),
			$status === 'root' ? $this->build_root_post_data( $post_id, $connection_map, $export_options, $canonical_url, $available_options ) : array(),
			$status === 'linked' ? $this->build_linked_post_data( $post_id, $gid ) : array(),
		);
	}

	/**
	 * Build base post data (always present).
	 *
	 * Returns the core properties included for every post regardless of synced status.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $gid     Synced post ID (GID).
	 * @param string $status  Synced post status (root, linked, etc.).
	 *
	 * @return array Base post properties, always present.
	 *     @property int    id             WordPress post ID.
	 *     @property string title          Post title.
	 *     @property string gid            Global synced post ID. Empty for unsynced posts.
	 *     @property string status         'root' (source), 'linked' (synced copy), or empty (unsynced).
	 *     @property bool   currentUserCan Whether the current user may edit this synced post.
	 */
	public function build_base_post_data( $post_id, $gid, $status ) {
		return array(
			'id'             => $post_id,
			'title'          => get_the_title( $post_id ),
			'gid'            => $gid,
			'status'         => $status,
			'currentUserCan' => Synced_Post_Service::current_user_can_edit_synced_posts( $status ),
		);
	}

	/**
	 * Build root-specific post data.
	 *
	 * Returns properties added only when the post is a synced root (source) post.
	 *
	 * @param int    $post_id           Post ID.
	 * @param array  $connection_map    Post connection map.
	 * @param array  $export_options    Merged export options.
	 * @param string $canonical_url     Canonical URL.
	 * @param array  $available_options  Available options for this post type.
	 *
	 * @return array Root-only post properties.
	 *     @property array  connectionMap    Map of connection keys to destination blogs. Used to
	 *                                        resolve remote clusters and display connection info.
	 *     @property array  options          Merged export options (defaults + saved). Keys like
	 *                                        append_nested, resolve_menus, translations. Drives export behavior.
	 *     @property string canonicalUrl     Canonical URL for the post. Fallback: permalink.
	 *     @property array  availableOptions Export option definitions for this post type (name, label, checked).
	 *     @property array  cluster          Clusters that include this post. Each has destination_ids
	 *                                        resolved to { blog_id, blogname, site_url, is_remote }.
	 *     @property mixed  error            Post error from Post_Error_Handler, or null when no error.
	 */
	public function build_root_post_data( $post_id, array $connection_map, array $export_options, $canonical_url, array $available_options ) {
		$clusters = Cluster_Service::get_clusters_including_post( $post_id );

		return array(
			'connectionMap'    => $connection_map,
			'options'          => $export_options ?: array(),
			'canonicalUrl'     => $canonical_url,
			'availableOptions' => $available_options,
			'cluster'          => $this->build_clusters_with_blog_info( $connection_map, $clusters ),
			'error'            => Post_Error_Handler::get_post_error( $post_id ),
		);
	}

	/**
	 * Build linked-specific post data.
	 *
	 * Returns properties added only when the post is a linked (synced copy) post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $gid     Synced post ID (GID).
	 *
	 * @return array Linked-only post properties.
	 *     @property array  links        Links to the root post. Typically edit, blog, nice. From
	 *                                   Post_Connection_Map::get_links_by_gid(). Empty if root not found.
	 *     @property string canonicalUrl Escaped canonical URL of the root post. Used for display/reference.
	 *     @property mixed  error        Post error from Post_Error_Handler, or null when no error.
	 */
	public function build_linked_post_data( $post_id, $gid ) {
		return array(
			'links'        => Post_Connection_Map::get_links_by_gid( $gid ),
			'canonicalUrl' => esc_attr( get_post_meta( $post_id, 'contentsync_canonical_url', true ) ),
			'error'        => Post_Error_Handler::get_post_error( $post_id ),
		);
	}

	/**
	 * Build clusters with destination_ids transformed to blog info arrays.
	 *
	 * @param array $connection_map Post connection map.
	 * @param array $clusters        Raw clusters from Cluster_Service::get_clusters_including_post().
	 *
	 * @return array Clusters with destination_ids as { blog_id, blogname, site_url, is_remote }.
	 */
	public function build_clusters_with_blog_info( array $connection_map, array $clusters ) {
		return array_map(
			function ( $cluster ) use ( $connection_map ) {
				if ( isset( $cluster->destination_ids ) && is_array( $cluster->destination_ids ) ) {
					$cluster->destination_ids = array_map(
						function ( $blog_id ) use ( $connection_map ) {
							return $this->resolve_destination_blog_info( $blog_id, $connection_map );
						},
						$cluster->destination_ids
					);
				}
				return $cluster;
			},
			$clusters
		);
	}

	/**
	 * Resolve a destination ID (blog_id or "blog_id|connection_key") to blog info array.
	 *
	 * @param string $blog_id        Destination ID, possibly in "blog_id|connection_key" format.
	 * @param array  $connection_map Post connection map.
	 *
	 * @return array
	 *     @property int    blog_id   Destination blog ID.
	 *     @property string blogname  Destination blog name.
	 *     @property string site_url  Destination blog site URL.
	 *     @property bool   is_remote Whether the destination blog is remote.
	 */
	public function resolve_destination_blog_info( $blog_id, array $connection_map ) {
		if ( strpos( (string) $blog_id, '|' ) !== false ) {
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
			return null;
		}

		return array(
			'blog_id'   => $blog_id,
			'blogname'  => get_blog_details( $blog_id )->blogname,
			'site_url'  => get_site_url( $blog_id ),
			'is_remote' => false,
		);
	}

	/**
	 * Get merged export options (defaults + saved) for a post.
	 *
	 * @param int   $post_id           Post ID.
	 * @param array $available_options Options from Admin::get_contentsync_export_options_for_post().
	 *
	 * @return array
	 */
	public function get_merged_export_options_for_post( $post_id, array $available_options ) {
		$default_options = array();
		foreach ( $available_options as $option ) {
			$default_options[ $option['name'] ] = isset( $option['checked'] ) ? $option['checked'] : false;
		}

		$saved_options = get_post_meta( $post_id, 'contentsync_export_options', true );

		return array_merge( $default_options, $saved_options ?: array() );
	}

	/**
	 * Accept numeric id or string (e.g. theme//slug for site editor).
	 *
	 * @param mixed $value
	 *
	 * @return bool
	 */
	public function is_post_reference( $value ) {
		if ( is_numeric( $value ) ) {
			return true;
		}
		return is_string( $value ) && $value !== '';
	}

	/**
	 * Get the numeric post id for a given site editor post id.
	 *
	 * @param int|string $site_editor_post_id Post ID or theme//slug string.
	 *
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
	 *
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

	/**
	 * Save Contentsync options via REST API.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 *
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
}
