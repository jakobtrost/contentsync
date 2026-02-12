<?php

/**
 * Content Sync Block Editor Hooks
 *
 * This class handles hooks for the Block Editor integration.
 */

namespace Contentsync\Admin\Views\Post_Sync;

use Contentsync\Utils\Hooks_Base;
use Contentsync\Admin\Utils\Enqueue_Service;
use Contentsync\Admin\Utils\Admin_Render;

defined( 'ABSPATH' ) || exit;

class Block_Editor_Hooks extends Hooks_Base {

	/**
	 * Register admin-only hooks.
	 */
	public function register_admin() {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ), 13 );
	}

	/**
	 * Enqueue Block Editor Styles & Scripts.
	 */
	public function enqueue_assets() {

		if ( ! Admin_Render::is_current_post_screen_supported() ) {
			return;
		}

		/**
		 * Styles
		 */

		Enqueue_Service::enqueue_admin_style(
			'post_edit_screen',
			'Views/Post_Sync/assets/css/post-edit-screen.css'
		);

		Enqueue_Service::enqueue_admin_style(
			'block_editor_controls',
			'Views/Post_Sync/assets/css/block-editor-controls.css'
		);

		/**
		 * Scripts
		 */

		Enqueue_Service::enqueue_build_script(
			'block_editor_tools',
			'Views/Post_Sync/assets/js/src/contentSync.blockEditorTools.jsx',
			array(
				'external'     => array( 'wp-data', 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'lodash' ),
				'localization' => array(
					'var'    => 'contentSyncEditorData',
					'values' => array( 'restBasePath' => CONTENTSYNC_REST_NAMESPACE . '/admin' ),
				),
			)
		);

		Enqueue_Service::enqueue_build_script(
			'blockEditorPlugin',
			'Views/Post_Sync/assets/js/src/blockEditorPlugin.jsx',
			array(
				'external' => array( 'wp-data', 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'lodash' ),
			)
		);

		/**
		 * Modal scripts
		 */

		Enqueue_Service::enqueue_admin_script(
			'makeRoot',
			'Views/Post_Sync/assets/js/contentSync.makeRoot.js',
			array(
				'internal' => array( 'tools', 'Modal', 'RestHandler', 'SnackBar' ),
			)
		);

		Enqueue_Service::enqueue_admin_script(
			'unlinkRootPost',
			'Views/Post_Sync/assets/js/contentSync.unlinkRootPost.js',
			array(
				'internal' => array( 'block_editor_tools', 'Modal', 'RestHandler', 'SnackBar' ),
			)
		);

		Enqueue_Service::enqueue_admin_script(
			'unlinkLinkedPost',
			'Views/Post_Sync/assets/js/contentSync.unlinkLinkedPost.js',
			array(
				'internal' => array( 'block_editor_tools', 'Modal', 'RestHandler', 'SnackBar' ),
			)
		);

		Enqueue_Service::enqueue_admin_script(
			'overwriteLocalPost',
			'Views/Post_Sync/assets/js/contentSync.overwriteLocalPost.js',
			array(
				'internal' => array( 'block_editor_tools', 'Modal', 'RestHandler', 'SnackBar' ),
			)
		);
	}
}
