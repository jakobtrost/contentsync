<?php

/**
 * Content Sync Block Editor Hooks
 *
 * This class handles hooks for the Block Editor integration.
 */

namespace Contentsync\Admin\Views\Site_Editor;

use Contentsync\Utils\Hooks_Base;

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

		// editor styles
		wp_register_style(
			'contentsync-block-editor-controls',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Views/Site_Editor/assets/css/block-editor-controls.css',
			array(),
			CONTENTSYNC_VERSION,
		);
		wp_enqueue_style( 'contentsync-block-editor-controls' );

		// enqueue scripts

		wp_register_script(
			'contentsync-block-editor-tools',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Views/Site_Editor/assets/js/contentSync.blockEditorTools.js',
			array( 'wp-data', 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'lodash' ),
			CONTENTSYNC_VERSION,
			true
		);
		wp_enqueue_script( 'contentsync-block-editor-tools' );

		wp_localize_script(
			'contentsync-block-editor-tools',
			'contentSyncEditorData',
			array(
				'restBasePath' => CONTENTSYNC_REST_NAMESPACE . '/admin',
			)
		);

		wp_register_script(
			'contentsync-block-editor-plugin',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Views/Site_Editor/assets/js/blockEditorPlugin.js',
			array( 'wp-data', 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'lodash' ),
			CONTENTSYNC_VERSION,
			true
		);
		wp_enqueue_script( 'contentsync-block-editor-plugin' );

		// // script translations
		// if ( function_exists( 'wp_set_script_translations' ) ) {
		// wp_set_script_translations(
		// 'contentsync-site-editor-plugin',
		// 'contentsync',
		// CONTENTSYNC_PLUGIN_PATH . '/languages'
		// );
		// }
	}
}
