<?php

namespace Contentsync\Admin\Views\Post_Transfer;

use Contentsync\Utils\Hooks_Base;

defined( 'ABSPATH' ) || exit;

class Post_Import_Enqueue_Hooks extends Hooks_Base {

	/**
	 * Register admin-only hooks.
	 */
	public function register_admin() {

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'add_import_page_title_action' ) );
	}

	/**
	 * Add export button to row actions
	 *
	 * @param  array   $actions
	 * @param  WP_Post $post
	 * @return array
	 */
	public function enqueue_scripts() {

		if ( ! Post_Export_Admin_Hooks::is_current_screen_supported() || ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		wp_register_script(
			'contentSync-postImport',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Views/Post_Transfer/assets/js/contentSync.postImport.js',
			array( 'jquery', 'contentSync-tools', 'contentSync-Modal', 'contentSync-RestHandler', 'contentSync-SnackBar' ),
			CONTENTSYNC_VERSION
		);
		wp_enqueue_script( 'contentSync-postImport' );
	}

	/**
	 * Add import button via javascript
	 * We call this function here to make sure it only appears if the current screen is
	 * supported and the user has the necessary permissions.
	 */
	public function add_import_page_title_action() {

		if ( ! Post_Export_Admin_Hooks::is_current_screen_supported() || ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		wp_add_inline_script(
			'contentSync-postImport',
			'document.addEventListener( \'DOMContentLoaded\', () => contentSync.postImport.init() );',
			'after'
		);
	}
}
