<?php

/**
 * Content Sync Block Editor Hooks
 *
 * This class handles hooks for the Block Editor integration.
 */

namespace Contentsync\Admin\Views\Post_Sync\Editor;

use Contentsync\Utils\Hooks_Base;
use Contentsync\Admin\Utils\Build_Scripts;
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

		wp_register_style(
			'contentsync-block-editor-controls',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Views/Post_Sync/Editor/assets/css/block-editor-controls.css',
			array(),
			CONTENTSYNC_VERSION,
		);
		wp_enqueue_style( 'contentsync-block-editor-controls' );

		wp_register_style(
			'contentsync-block-editor-notice',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Views/Post_Sync/Editor/assets/css/editor-notice.css',
			array(),
			CONTENTSYNC_VERSION,
		);
		wp_enqueue_style( 'contentsync-block-editor-notice' );

		/**
		 * Scripts
		 */

		Build_Scripts::enqueue_build_script(
			'contentSync-blockEditorTools',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Views/Post_Sync/Editor/assets/js/src/contentSync.blockEditorTools.jsx',
			array( 'wp-data', 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'lodash' ),
			true
		);

		wp_localize_script(
			'contentSync-blockEditorTools',
			'contentSyncEditorData',
			array(
				'restBasePath' => CONTENTSYNC_REST_NAMESPACE . '/admin',
			)
		);

		Build_Scripts::enqueue_build_script(
			'contentSync-blockEditorPlugin',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Views/Post_Sync/Editor/assets/js/src/blockEditorPlugin.jsx',
			array( 'wp-data', 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'lodash' ),
			true
		);

		// // script translations
		// if ( function_exists( 'wp_set_script_translations' ) ) {
		// wp_set_script_translations(
		// 'contentsync-site-editor-plugin',
		// 'contentsync',
		// CONTENTSYNC_PLUGIN_PATH . '/languages'
		// );
		// }

		/**
		 * Modal scripts
		 */
		wp_register_script(
			'contentSync-makeRoot',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Views/Post_Sync/assets/js/contentSync.makeRoot.js',
			array( 'contentSync-tools', 'contentSync-Modal', 'contentSync-RestHandler', 'contentSync-SnackBar' ),
			CONTENTSYNC_VERSION,
			true
		);
		wp_enqueue_script( 'contentSync-makeRoot' );
		wp_register_script(
			'contentSync-unlinkRoot',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Views/Post_Sync/assets/js/contentSync.unlinkRoot.js',
			array( 'contentSync-blockEditorTools', 'contentSync-Modal', 'contentSync-RestHandler', 'contentSync-SnackBar' ),
			CONTENTSYNC_VERSION,
			true
		);
		wp_enqueue_script( 'contentSync-unlinkRoot' );
		wp_register_script(
			'contentSync-overwrite',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Views/Post_Sync/assets/js/contentSync.overwrite.js',
			array( 'contentSync-blockEditorTools', 'contentSync-Modal', 'contentSync-RestHandler', 'contentSync-SnackBar' ),
			CONTENTSYNC_VERSION,
			true
		);
		wp_enqueue_script( 'contentSync-overwrite' );
	}
}
