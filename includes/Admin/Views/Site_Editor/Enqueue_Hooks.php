<?php

/**
 * Content Sync Site Editor Enqueue Hooks
 *
 * This class handles enqueuing scripts and styles for the Site Editor integration.
 */

namespace Contentsync\Admin\Sync\Site_Editor;

use Contentsync\Utils\Hooks_Base;

defined( 'ABSPATH' ) || exit;

class Enqueue_Hooks extends Hooks_Base {

	/**
	 * Register admin-only hooks.
	 */
	public function register_admin() {
		add_action( 'enqueue_block_editor_assets', array( $this, 'block_editor_scripts' ), 13 );
	}

	/**
	 * Enqueue Block Editor Styles & Scripts.
	 */
	public function block_editor_scripts() {

		// editor styles
		wp_register_style(
			'contentsync-site-editor-admin-styles',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Sync/Site_Editor/assets/site-editor-admin-styles.css',
			array(),
			CONTENTSYNC_VERSION,
		);
		wp_enqueue_style( 'contentsync-site-editor-admin-styles' );

		// enqueue script
		wp_register_script(
			'contentsync-site-editor-plugin',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Sync/Site_Editor/assets/site-editor-plugin.js',
			array( 'wp-data', 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'lodash' ),
			CONTENTSYNC_VERSION,
			true
		);
		wp_enqueue_script( 'contentsync-site-editor-plugin' );

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
