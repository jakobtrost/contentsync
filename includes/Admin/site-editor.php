<?php

/**
 * Content Sync integration for the Site Editor
 *
 * This file declares the `Site_Editor` class, which integrates the Global
 * Content plugin into WordPress’s block‑based Site Editor. It
 * registers and enqueues editor‑specific scripts and styles so that
 * blocks can display global content indicators and controls. It also
 * exposes a REST API endpoint used by the editor to fetch information
 * about synced posts. Modify this class if you want to add new
 * block‑editor features or customise the integration of synced posts
 * into the Site Editor.
 */

namespace Contentsync\Contents;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

new Site_Editor();
class Site_Editor {

	/**
	 * Init class
	 */
	public function __construct() {

		// enqueue
		add_action( 'enqueue_block_editor_assets', array( $this, 'block_editor_scripts' ), 13 );

		// rest api
		add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
	}

	/**
	 * Enqueue Block Editor Styles & Scripts.
	 */
	public function block_editor_scripts() {

		// editor styles
		wp_register_style(
			'greyd-global-content-site-editor',
			CONTENTSYNC_PLUGIN_URL . '/assets/css/site-editor.css',
			array(),
			CONTENTSYNC_VERSION,
		);
		wp_enqueue_style( 'greyd-global-content-site-editor' );

		// enqueue script
		wp_register_script(
			'greyd-global-content-site-editor',
			CONTENTSYNC_PLUGIN_URL . '/assets/js/site-editor.js',
			array( 'greyd-components', 'wp-data', 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'lodash' ),
			CONTENTSYNC_VERSION,
			true
		);
		wp_enqueue_script( 'greyd-global-content-site-editor' );

		// script translations
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				'greyd-global-content-site-editor',
				'contentsync',
				CONTENTSYNC_PLUGIN_PATH . '/languages'
			);
		}
	}

	/**
	 * Set up Rest API routes.
	 *
	 * @return void
	 */
	public function rest_api_init() {

		register_rest_route(
			'contentsync/v1',
			'/get_post_info',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'get_post_info' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'contentsync/v1',
			'/save_options',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_options' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Save tools via Rest API.
	 *
	 * @param object $request given request.
	 *
	 * @return string
	 */
	public function get_post_info( $request ) {

		if ( $params = $request->get_params() ) {

			if ( ! class_exists( 'Contentsync\Admin' ) ) {
				require_once __DIR__ . '/admin.php';
			}

			$postReference  = isset( $params['postReference'] ) ? $params['postReference'] : 0;
			$post_id        = self::get_numeric_post_id( $postReference );
			$status         = get_post_meta( $post_id, 'synced_post_status', true );
			$gid            = get_post_meta( $post_id, 'synced_post_id', true );
			$connection_map = \Contentsync\get_post_connection_map( $post_id );

			// Get Contentsync options and canonical URL
			$contentsync_export_options = get_post_meta( $post_id, 'contentsync_export_options', true );
			$canonical_url              = get_post_meta( $post_id, 'contentsync_canonical_url', true );
			if ( empty( $canonical_url ) ) {
				$canonical_url = get_permalink( $post_id );
			}

			// Get available options for this post type
			$available_options = Admin::get_contentsync_export_options_for_post( $post_id );
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
						'currentUserCan' => \Contentsync\Admin\current_user_can_edit_synced_posts( $status ),
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
															'blog_id'  => intval( $tmp[0] ),
															'blogname' => $blog['nice'],
															'site_url' => $blog['blog'],
															'is_remote' => true,
														);
													}
												} else {
														return array(
															'blog_id'   => $blog_id,
															'blogname'  => get_blog_details( $blog_id )->blogname,
															'site_url'  => get_site_url( $blog_id ),
															'is_remote' => false,
														);
												}
											},
											$cluster->destination_ids
										);
								}
								return $cluster;
							},
							get_clusters_including_post( $post_id )
						),
						'error'            => \Contentsync\get_post_error( $post_id ),
					) : array() ),
					// linked posts
					( $status === 'linked' ? array(
						'links'     => \Contentsync\get_post_links_by_gid( $gid ),
						'canonical' => esc_attr( get_post_meta( $post_id, 'contentsync_canonical_url', true ) ),
						'error'     => \Contentsync\get_post_error( $post_id ),
					) : array() ),
				),
				'notice' => Admin::get_global_notice_content( $post_id, 'site_editor' ),
			);

			return json_encode(
				array(
					'status'  => 200,
					'message' => 'Post data retrieved',
					'data'    => $data,
				)
			);
		}

		return json_encode(
			array(
				'status'  => 400,
				'message' => 'Could get post infos',
			)
		);
	}

	/**
	 * Save Contentsync options via Rest API.
	 *
	 * @param object $request given request.
	 *
	 * @return string
	 */
	public function save_options( $request ) {

		if ( $params = $request->get_params() ) {

			$post_id       = isset( $params['post_id'] ) ? intval( $params['post_id'] ) : 0;
			$options       = isset( $params['options'] ) ? $params['options'] : array();
			$canonical_url = isset( $params['canonical_url'] ) ? esc_url_raw( $params['canonical_url'] ) : '';

			if ( $post_id <= 0 ) {
				return json_encode(
					array(
						'status'  => 400,
						'message' => 'Invalid post ID',
					)
				);
			}

			// Check if user can edit this post
			if ( ! \Contentsync\Admin\current_user_can_edit_synced_posts( 'root' ) ) {
				return json_encode(
					array(
						'status'  => 403,
						'message' => 'Permission denied',
					)
				);
			}

			// Save Contentsync options
			if ( ! empty( $options ) ) {
				update_post_meta( $post_id, 'contentsync_export_options', $options );
			}

			// Save canonical URL
			if ( ! empty( $canonical_url ) ) {
				update_post_meta( $post_id, 'contentsync_canonical_url', $canonical_url );
			}

			return json_encode(
				array(
					'status'  => 200,
					'message' => 'Options saved successfully',
					'data'    => array(
						'options'       => $options,
						'canonical_url' => $canonical_url,
					),
				)
			);
		}

		return json_encode(
			array(
				'status'  => 400,
				'message' => 'Invalid parameters',
			)
		);
	}

	/**
	 * Get the numeric post id for a given site editor post id.
	 */
	public static function get_numeric_post_id( $site_editor_post_id ) {

		if ( is_numeric( $site_editor_post_id ) ) {
			return $site_editor_post_id;
		}

		if ( ! is_string( $site_editor_post_id ) ) {
			return 0;
		}

		$parts = explode( '//', $site_editor_post_id );
		if ( count( $parts ) === 2 ) {

			$posts = \Contentsync\get_unfiltered_posts(
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
	 * Get the site editor post id for a given post, eg. 'greyd-theme//404'.
	 *
	 * @param mixed $post          Post object or post id
	 *
	 * @return string|int|null     String for wp_template and wp_template_part
	 *                             Int for wp_navigation, wp_block and page.
	 *                             Null for all other post types.
	 */
	public static function get_site_editor_post_id( $post ) {

		if ( ! is_object( $post ) ) {
			$post = get_post( $post );
		}

		if ( ! is_a( $post, 'WP_Post' ) ) {
			return null;
		}

		switch ( $post->post_type ) {
			case 'wp_template':
			case 'wp_template_part':
				// greyd-theme//footer, greyd-theme//404 ...
				return \Contentsync\get_wp_template_theme( $post ) . '//' . $post->post_name;

			case 'wp_navigation':
			case 'wp_block':
			case 'page':
				return $post->ID;

			default:
				// all other post types do not support the site editor
				return null;
		}
	}
}
