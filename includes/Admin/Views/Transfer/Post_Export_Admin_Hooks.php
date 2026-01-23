<?php

namespace Contentsync\Admin\Views\Transfer;

use Contentsync\Admin\Utils\Admin_Render;
use Contentsync\Posts\Sync\Synced_Post_Query;
use Contentsync\Posts\Transfer\Post_Export;
use Contentsync\Posts\Transfer\Post_Transfer_Service;
use Contentsync\Translations\Translation_Manager;
use Contentsync\Utils\Hooks_Base;

defined( 'ABSPATH' ) || exit;

class Post_Export_Admin_Hooks extends Hooks_Base {

	/**
	 * Register admin-only hooks.
	 */
	public function register_admin() {
		// UI
		// add_filter( 'contentsync_overlay_contents', array( $this, 'add_overlay_contents' ) );
		add_filter( 'page_row_actions', array( $this, 'add_export_row_action' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'add_export_row_action' ), 10, 2 );
		add_filter( 'media_row_actions', array( $this, 'add_export_row_action' ), 10, 2 );
		// add_action( 'admin_enqueue_scripts', array( $this, 'add_import_page_title_action' ) );
		// add_action( 'admin_notices', array( $this, 'display_transient_notice' ) );

		// debug
		// add_action( 'admin_init', array( $this, 'maybe_enable_debug_mode' ) );
	}

	/**
	 * Check if current screen supports import/export
	 *
	 * @return bool
	 */
	public static function is_current_screen_supported() {

		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$supported = false;

		$screen = get_current_screen();
		if ( is_object( $screen ) && isset( $screen->base ) ) {
			if ( $screen->base === 'edit' ) {
				$post_types = array_flip( Post_Transfer_Service::get_supported_post_types() );
				if ( isset( $post_types[ $screen->post_type ] ) ) {
					$supported = true;
				}
			} elseif ( $screen->base === 'upload' ) {
				$supported = true;
			}
		}

		/**
		 * Filter to customize whether the current screen supports post export/import functionality.
		 *
		 * This filter allows developers to extend or modify the logic that determines
		 * which admin screens should display post export/import options. It's useful
		 * for adding support to custom post type screens or implementing custom
		 * screen detection logic.
		 *
		 * @filter synced_post_export_is_current_screen_supported
		 *
		 * @param bool  $supported Whether the current screen supports export/import.
		 * @param object $screen   The current WP_Screen object.
		 *
		 * @return bool           Whether the current screen supports export/import.
		 */
		return apply_filters( 'synced_post_export_is_current_screen_supported', $supported, $screen );
	}

	/**
	 * Add overlay contents
	 *
	 * @filter 'contentsync_overlay_contents'
	 *
	 * @param array $contents
	 * @return array $contents
	 */
	public function add_overlay_contents( $contents ) {

		if ( ! self::is_current_screen_supported() ) {
			return $contents;
		}

		$screen = get_current_screen();
		// debug( $screen );

		/**
		 * Export
		 */

		// default options
		$export_options = array(
			'nested' => array(
				'name'    => 'nested',
				'title'   => __( 'Export nested content', 'contentsync' ),
				'descr'   => __( 'Templates, media, etc. are added to the download so that used images, backgrounds, etc. will be displayed correctly on the target website.', 'contentsync' ),
				'checked' => true,
			),
			'menus'  => array(
				'name'    => 'resolve_menus',
				'title'   => __( 'Resolve menus', 'contentsync' ),
				'descr'   => __( 'All menus will be converted to static links.', 'contentsync' ),
				'checked' => true,
			),
		);

		// remove options for media
		if ( $screen->base === 'upload' ) {
			unset( $export_options['nested'], $export_options['menus'] );
		}

		// add option to include translations when translation tool is active
		if ( ! empty( Translation_Manager::get_translation_tool() ) ) {
			$export_options['translations'] = array(
				'name'  => 'translations',
				'title' => __( 'Include translations', 'contentsync' ),
				'descr' => __( 'All translations of the post will be added to the download.', 'contentsync' ),
			);
		}

		// build the form
		$export_form = "<a id='post_export_download'></a><form id='post_export_form' class='" . ( count( $export_options ) ? 'inner_content' : '' ) . "'>";
		foreach ( $export_options as $option ) {
			$name         = $option['name'];
			$checked      = isset( $option['checked'] ) && $option['checked'] ? "checked='checked'" : '';
			$export_form .= "<label>
				<input type='checkbox' id='$name' name='$name' $checked />
				<span>" . $option['title'] . '</span>
				<small>' . $option['descr'] . '</small>
			</label>';
		}
		$export_form .= '</form>';

		// add notices
		if ( $screen->id === 'edit-page' ) {
			$export_form .= Admin_Render::make_admin_info_box(
				array(
					'text'  => __( 'Posts in query loops are not included in the import. Posts and Post Types must be exported separately.', 'contentsync' ),
					'style' => 'info',
				)
			);
		}

		// add the contents
		$contents['post_export'] = array(
			'confirm' => array(
				'title'   => __( 'Export', 'contentsync' ),
				'descr'   => sprintf( __( 'Do you want to export "%s"?', 'contentsync' ), "<b class='replace'></b>" ),
				'content' => $export_form,
				'button'  => __( 'Export now', 'contentsync' ),
			),
			'loading' => array(
				'descr' => __( 'Exporting post.', 'contentsync' ),
			),
			'success' => array(
				'title' => __( 'Export successful', 'contentsync' ),
				'descr' => __( 'Post has been exported.', 'contentsync' ),
			),
			'fail'    => array(
				'title' => __( 'Export failed', 'contentsync' ),
				'descr' => '<span class="replace">' . __( 'The post could not be exported.', 'contentsync' ) . '</span>',
			),
		);

		/**
		 * Import
		 */

		// get form options
		$options = '';
		foreach ( array(
			'skip'    => __( 'Skip', 'contentsync' ),
			'replace' => __( 'Replace', 'contentsync' ),
			'keep'    => __( 'Keep both', 'contentsync' ),
		) as $name => $value ) {
			$options .= "<option value='$name'>$value</option>";
		}
		$options = urlencode( $options );

		// add the contents
		$contents['post_import'] = array(
			'check_file' => array(
				'title'   => __( 'Please wait', 'contentsync' ),
				'descr'   => __( 'The file is being validated.', 'contentsync' ),
				'content' => '<div class="loading"><div class="loader"></div></div><a href="javascript:window.location.href=window.location.href" class="color_light escape">' . __( 'Cancel', 'contentsync' ) . '</a>',
			),
			'confirm'    => array(
				'title'   => __( 'Import', 'contentsync' ),
				'content' => "<form id='post_import_form'>
									<input type='file' name='import_file' id='import_file' title='" . __( 'Select file', 'contentsync' ) . "' accept='zip,application/octet-stream,application/zip,application/x-zip,application/x-zip-compressed' >
									<div class='conflicts'>
										<p>" . __( '<b>Attention:</b> Some content in the file already appears to exist on this site. Choose what to do with it.', 'contentsync' ) . "</p>
										<div class='inner_content' data-options='$options'></div>
									</div>
									<div class='new'>
										<p>" . sprintf( __( 'No conflicts found. Do you want to import the file "%s" now?', 'contentsync' ), "<strong class='post_title'></strong>" ) . '</p>
									</div>
								</form>',
				'button'  => __( 'Import now', 'contentsync' ),
			),
			'loading'    => array(
				'descr' => __( 'Importing post.', 'contentsync' ),
			),
			'reload'     => array(
				'title' => __( 'Import successful', 'contentsync' ),
				'descr' => __( 'Post has been imported.', 'contentsync' ),
			),
			'fail'       => array(
				'title' => __( 'Import failed', 'contentsync' ),
				'descr' => '<span class="replace">' . __( 'The file could not be imported.', 'contentsync' ) . '</span>',
			),
		);

		return $contents;
	}

	/**
	 * Add export button to row actions
	 *
	 * @param  array   $actions
	 * @param  WP_Post $post
	 * @return array
	 */
	public function add_export_row_action( $actions, $post ) {

		if ( self::is_current_screen_supported() ) {
			// $actions['contentsync_export'] = "<a style='cursor:pointer;' onclick='contentSync.postExport.openModal(this);' data-post_id='" . $post->ID . " data-post_title='" . $post->post_title . "'>" . __( 'Export', 'contentsync' ) . '</a>';
			$actions['contentsync_export'] = sprintf(
				'<a style="cursor:pointer;" onclick="contentSync.postExport.openModal(this);" data-post_id="%s" data-post_title="%s">%s</a>',
				$post->ID,
				esc_attr( ( empty( $post->post_title ) ? 'post' : $post->post_title ) ),
				esc_html__( 'Export', 'contentsync' )
			);
		}

		return $actions;
	}

	/**
	 * Add import button via javascript
	 */
	public function add_import_page_title_action() {

		if ( ! self::is_current_screen_supported() || ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		wp_register_script(
			'contentSync-postExport',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Views/Transfer/assets/contentSync.postExport.js',
			array( 'jquery', 'contentSync-utils', 'contentSync-Modal', 'contentSync-AjaxHandler' ),
			CONTENTSYNC_VERSION
		);
		wp_enqueue_script( 'contentSync-postExport' );

		wp_add_inline_script(
			'contentSync-postExport',
			'jQuery(function() {
				contentSync.overlay.addPageTitleAction( "⬇&nbsp;' . __( 'Import', 'contentsync' ) . '", { onclick: "contentSync.postExport.openImport();" } );
			});',
			'after'
		);
	}

	/**
	 * Display an admin notice if the transient 'contentsync_transient_notice' is set
	 */
	public function display_transient_notice() {

		// get transient
		$transient = get_transient( 'contentsync_transient_notice' );

		if ( $transient ) {
			// cut transient into pieces
			$transient = explode( '::', $transient );
			$mode      = $transient[0];
			$msg       = $transient[1];
			// this is my last resort
			Admin_Render::render_admin_notice( $msg, $mode );

			// delete transient
			delete_transient( 'contentsync_transient_notice' );
		}
	}


	/**
	 * =================================================================
	 *                          DEBUG
	 * =================================================================
	 */

	/**
	 * Enable the debug mode via the URl-parameter 'contentsync_export_debug'
	 */
	public function maybe_enable_debug_mode() {

		if ( ! isset( $_GET['contentsync_export_debug'] ) ) {
			return;
		}

		$echo = '';
		ob_start();

		do_action( 'before_contentsync_export_debug' );

		if ( isset( $_GET['post'] ) ) {
			$post_id = $_GET['post'];
			$post    = get_post( $post_id );

			// $meta = get_post_meta( $post_id );
			// var_dump( $meta );
			// foreach($meta as $k => $v) {
			// if ( strpos($k, '_oembed_') === 0 ) {
			// delete_post_meta( $post_id, $k );
			// }
			// }

			$post_export = new Post_Export(
				$post_id,
				array(
					'append_nested' => true,
					'resolve_menus' => true,
					'translations'  => true,
				)
			);
			echo "<hr>\r\n\r\n";

			foreach ( $post_export->get_posts() as $post ) {
				$post->post_content = esc_attr( $post->post_content );
				debug( $post );
			}
			echo "<hr>\r\n\r\n=== R E S P O N S E ===\r\n\r\n";
		} else {
			$posts = Synced_Post_Query::prepare_synced_post_for_import( strval( $_GET['contentsync_export_debug'] ) );
			debug( $posts );
		}

		do_action( 'after_contentsync_export_debug' );

		$echo = ob_get_contents();
		ob_end_clean();
		if ( ! empty( $echo ) ) {
			echo '<pre>' . $echo . '</pre>';
		}

		wp_die();
	}
}
