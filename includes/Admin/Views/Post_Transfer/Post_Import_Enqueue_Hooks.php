<?php

namespace Contentsync\Admin\Views\Post_Transfer;

use Contentsync\Admin\Utils\Admin_Render;
use Contentsync\Admin\Utils\Enqueue_Service;
use Contentsync\Utils\Hooks_Base;

defined( 'ABSPATH' ) || exit;

class Post_Import_Enqueue_Hooks extends Hooks_Base {

	/**
	 * Register admin-only hooks.
	 */
	public function register_admin() {

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Add export button to row actions
	 *
	 * @param  array   $actions
	 * @param  WP_Post $post
	 * @return array
	 */
	public function enqueue_scripts() {

		if ( ! Admin_Render::is_current_edit_screen_supported() || ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		Enqueue_Service::enqueue_admin_script(
			'postImport',
			'Views/Post_Transfer/assets/js/contentSync.postImport.js',
			array(
				'external' => array( 'jquery' ),
				'internal' => array( 'tools', 'Modal', 'RestHandler', 'SnackBar' ),
				'in_footer' => false,
				'inline'   => array(
					'content'  => 'document.addEventListener( \'DOMContentLoaded\', () => contentSync.postImport.init() );',
					'position' => 'after',
				),
			)
		);
	}
}
