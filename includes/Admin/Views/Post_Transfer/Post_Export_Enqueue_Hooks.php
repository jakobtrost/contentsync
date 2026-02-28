<?php

namespace Contentsync\Admin\Views\Post_Transfer;

use Contentsync\Admin\Utils\Admin_Render;
use Contentsync\Admin\Utils\Enqueue_Service;
use Contentsync\Utils\Hooks_Base;

defined( 'ABSPATH' ) || exit;

class Post_Export_Enqueue_Hooks extends Hooks_Base {

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

		if ( ! Admin_Render::is_current_edit_screen_supported() ) {
			return;
		}

		Enqueue_Service::enqueue_admin_script(
			'postExport',
			'Views/Post_Transfer/assets/js/contentSync.postExport.js',
			array(
				'internal' => array( 'tools', 'Modal', 'RestHandler', 'SnackBar' ),
			)
		);
	}
}
