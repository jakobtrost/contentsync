<?php

namespace Contentsync\Admin\Views\Post_Transfer;

use Contentsync\Admin\Utils\Admin_Render;
use Contentsync\Post_Sync\Synced_Post_Query;
use Contentsync\Post_Transfer\Post_Export;
use Contentsync\Post_Transfer\Post_Transfer_Service;
use Contentsync\Translations\Translation_Manager;
use Contentsync\Utils\Hooks_Base;

defined( 'ABSPATH' ) || exit;

class Post_Export_Admin_Hooks extends Hooks_Base {

	/**
	 * Register admin-only hooks.
	 */
	public function register_admin() {

		// add the 'Export' action for each supported post in the edit screen
		add_filter( 'page_row_actions', array( $this, 'add_export_row_action' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'add_export_row_action' ), 10, 2 );
		add_filter( 'media_row_actions', array( $this, 'add_export_row_action' ), 10, 2 );

		add_action( 'admin_init', array( $this, 'maybe_enable_debug_mode' ) );
	}

	/**
	 * Add export button to row actions
	 *
	 * @param  array   $actions
	 * @param  WP_Post $post
	 * @return array
	 */
	public function add_export_row_action( $actions, $post ) {

		if ( ! Admin_Render::is_current_edit_screen_supported() ) {
			return $actions;
		}

		$actions['contentsync_export'] = sprintf(
			'<a style="cursor:pointer;" onclick="contentSync.postExport.openModal(this);" data-post_id="%s" data-post_title="%s">%s</a>',
			$post->ID,
			esc_attr( ( empty( $post->post_title ) ? 'post' : $post->post_title ) ),
			esc_html__( 'Export', 'contentsync' )
		);

		return $actions;
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
