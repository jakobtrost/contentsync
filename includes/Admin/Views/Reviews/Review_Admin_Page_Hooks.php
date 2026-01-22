<?php
/**
 * Admin screen extensions for clusters and reviews.
 *
 * This file defines the `Review_Admin_Page_Hooks` class, which adds custom admin
 * screens, popups, meta boxes and overlay interfaces to support the
 * post review workflow. It registers menu pages for
 * post reviews, sets up screen options, and enqueues
 * post review‑specific scripts and styles. When extending this class, you
 * can add new UI components for post review management or customise the
 * existing ones.
 */
namespace Contentsync\Admin\Views\Reviews;

use Contentsync\Utils\Hooks_Base;
use Contentsync\Utils\Multisite_Manager;
use Contentsync\Translations\Translation_Manager;
use Contentsync\Admin\Views\Reviews\Post_Review_List_Table;

defined( 'ABSPATH' ) || exit;

class Review_Admin_Page_Hooks extends Hooks_Base {

	const REVIEW_PAGE_POSITION = 14;

	const REVIEW_PAGE_POSITION_NETWORK = 61;

	/**
	 * The Post_Review_List_Table instance.
	 */
	public $Post_Review_List_Table = null;

	/**
	 * Register admin-only hooks.
	 */
	public function register_admin() {
		// add the menu items & pages
		add_action( 'admin_menu', array( $this, 'add_submenu_item' ), self::REVIEW_PAGE_POSITION );
		add_action( 'network_admin_menu', array( $this, 'add_network_submenu_item' ), self::REVIEW_PAGE_POSITION_NETWORK );

		add_filter( 'set-screen-option', array( $this, 'save_screen_options' ), 10, 3 );
	}

	/**
	 * Add a menu item to the WordPress admin menu
	 */
	function add_submenu_item() {

		if ( is_multisite() && ! is_super_admin() ) {
			return;
		}

		$hook = add_submenu_page(
			'contentsync',
			__( 'Reviews', 'contentsync' ), // page title
			__( 'Reviews', 'contentsync' ), // menu title
			'manage_options',
			'contentsync-post-reviews',
			array( $this, 'render_admin_page' ),
			self::REVIEW_PAGE_POSITION // position
		);

		add_action( "load-$hook", array( $this, 'add_screen_options' ) );
	}

	/**
	 * Add a menu item to the WordPress admin menu
	 */
	function add_network_submenu_item() {

		$hook = add_submenu_page(
			'contentsync',
			__( 'Reviews', 'contentsync' ), // page title
			__( 'Reviews', 'contentsync' ), // menu title
			'manage_options',
			'contentsync-post-reviews',
			array( $this, 'render_admin_page' ),
			self::REVIEW_PAGE_POSITION_NETWORK // position
		);

		add_action( "load-$hook", array( $this, 'add_screen_options' ) );
	}

	/**
	 * Set screen options for the admin pages
	 */
	public function add_screen_options() {

		$args = array(
			'label'   => __( 'Post Reviews per page:', 'contentsync' ),
			'default' => 20,
			'option'  => 'per_page',
		);

		add_screen_option( 'per_page', $args );

		$this->Post_Review_List_Table = new Post_Review_List_Table();
	}

	/**
	 * Save the admin screen option
	 */
	public function save_screen_options( $status, $option, $value ) {

		if ( 'per_page' == $option ) {
			return $value;
		}

		return $status;
	}

	/**
	 * Display the custom admin list page
	 */
	public function render_admin_page() {

		if ( ! $this->Post_Review_List_Table ) {
			$this->Post_Review_List_Table = new Post_Review_List_Table();
		}

		$this->Post_Review_List_Table->prepare_items();
		$this->Post_Review_List_Table->render();
	}

	/**
	 * Get the stage posttype data
	 */
	public function get_stage_posttype_data() {

		// get cache
		// if ( get_transient('contentsync_stage_posttype_data') ) {
		// return get_transient('contentsync_stage_posttype_data');
		// }

		$data = array();

		// core posttypes - they are the same on each blog
		$core_posttypes = array(
			'page'             => __( 'Pages', 'contentsync' ),
			'post'             => __( 'Posts', 'contentsync' ),
			'attachment'       => __( 'Attachments', 'contentsync' ),
			'wp_template'      => __( 'WP Templates', 'contentsync' ),
			'wp_template_part' => __( 'Template Parts', 'contentsync' ),
			'wp_block'         => __( 'Blocks', 'contentsync' ),
			'wp_navigation'    => __( 'WP Navigations', 'contentsync' ),
		);

		$blogs = \Contentsync\Connections\Connections_Helper::get_basic_blogs();

		foreach ( $blogs as $blog ) {
			$blog_id = $blog['blog_id'];

			Multisite_Manager::switch_blog( $blog_id );

			// merge core posttypes with dynamic and greyd posttypes
			$posttypes = array_merge( $core_posttypes );

			/**
			 * Check if polylang is active on the blog, but function pll_get_language_code is not available.
			 * this happens when the plugin is not active on the main blog, but on a sub-blog.
			 */
			$translation_tool = Translation_Manager::get_translation_tool();
			if ( $translation_tool == 'polylang' && ! function_exists( 'pll_get_language_code' ) ) {
				$polylang_options     = get_option( 'polylang', array() );
				$translated_posttypes = array_values(
					wp_parse_args(
						isset( $polylang_options['post_types'] ) ? $polylang_options['post_types'] : array(),
						array( 'post', 'page', 'wp_block' ) // default polylang posttypes
					)
				);
			}

			// add merged posttype array
			$data[ $blog_id ]['post_types'] = array();

			foreach ( $posttypes as $post_type => $post_type_title ) {
				if ( ! is_string( $post_type ) ) {
					continue;
				}

				$data[ $blog_id ]['post_types'][ $post_type ]['slug']  = $post_type;
				$data[ $blog_id ]['post_types'][ $post_type ]['title'] = $post_type_title;

				$data[ $blog_id ]['post_types'][ $post_type ]['slug']  = $post_type;
				$data[ $blog_id ]['post_types'][ $post_type ]['title'] = $post_type_title;

				/**
				 * Taxonomies
				 */
				$taxonomies = array();

				$object_taxonomies = get_object_taxonomies( $post_type, 'objects' );
				if ( $object_taxonomies && is_array( $object_taxonomies ) ) {
					foreach ( $object_taxonomies as $taxonomy_slug => $taxonomy ) {
						$taxonomies[ $taxonomy_slug ] = $taxonomy->name;
					}
				}

				// try to register language taxonomy from polylang
				if ( isset( $translated_posttypes ) && in_array( $post_type, $translated_posttypes ) ) {
					register_taxonomy( 'language', $post_type );
					$taxonomies['language'] = 'language';
				}

				/**
				 * Terms
				 */
				foreach ( $taxonomies as $taxonomy_slug ) {
					$terms = get_terms(
						array(
							'taxonomy'         => $taxonomy_slug,
							'hide_empty'       => false,
							'suppress_filters' => true,
							'lang'             => '',
						)
					);
					// debug($terms);
					if ( is_wp_error( $terms ) ) {
						continue;
					}
					$new_terms = array();
					foreach ( $terms as $term ) {
						$new_terms[ $term->slug ] = $term->name;
					}

					$data[ $blog_id ]['post_types'][ $post_type ]['taxonomies'][ $taxonomy_slug ]['terms'] = $new_terms;
				}
			}

			Multisite_Manager::restore_blog();
		}

		// debug($data);

		// set cache (3 minutes
		delete_transient( 'contentsync_stage_posttype_data' );
		// set_transient('contentsync_stage_posttype_data', $data, 180);

		return $data;
	}
}
