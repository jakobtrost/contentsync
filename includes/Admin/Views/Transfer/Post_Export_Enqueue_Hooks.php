<?php

namespace Contentsync\Admin\Views\Transfer;

use Contentsync\Utils\Hooks_Base;

defined( 'ABSPATH' ) || exit;

class Post_Export_Enqueue_Hooks extends Hooks_Base {

	/**
	 * Register admin-only hooks.
	 */
	public function register_admin() {

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// add_action( 'admin_enqueue_scripts', array( $this, 'add_import_page_title_action' ) );
	}

	/**
	 * Add export button to row actions
	 *
	 * @param  array   $actions
	 * @param  WP_Post $post
	 * @return array
	 */
	public function enqueue_scripts() {

		if ( ! self::is_current_screen_supported() ) {
			return;
		}

		wp_register_script(
			'contentSync-postExport',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Views/Transfer/assets/contentSync.postExport.js',
			array( 'contentSync-utils', 'contentSync-Modal', 'contentSync-AjaxHandler', 'contentSync-SnackBar' ),
			CONTENTSYNC_VERSION,
			true
		);
		wp_enqueue_script( 'contentSync-postExport' );
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
			array( 'jquery', 'contentSync-utils', 'contentSync-Modal', 'contentSync-AjaxHandler', 'contentSync-SnackBar' ),
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
}
