<?php

/**
 * Content Sync Modal Enqueue Hooks
 *
 * This class handles enqueuing scripts and styles for the Modal/Modal components.
 */

namespace Contentsync\Admin\ClientSDK;

use Contentsync\Utils\Hooks_Base;
use Contentsync\Admin\Utils\Admin_Render;

defined( 'ABSPATH' ) || exit;

class Modal_Enqueue_Hooks extends Hooks_Base {

	/**
	 * Register admin-only hooks.
	 */
	public function register_admin() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_modal_assets' ), 98 );
	}

	/**
	 * Enqueue Modal/Modal Scripts.
	 */
	public function enqueue_modal_assets() {

		// Enqueue components-modal.css (modal styles)
		wp_register_style(
			'contentsync-modal',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/ClientSDK/assets/css/contentsync-modal.css',
			array(),
			CONTENTSYNC_VERSION,
		);
		wp_enqueue_style( 'contentsync-modal' );

		// Enqueue admin-info-box.css (admin info box styles)
		Admin_Render::maybe_enqueue_stylesheet(
			'contentsync-info-box',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Utils/assets/css/contentsync-info-box.css'
		);
		Admin_Render::maybe_enqueue_stylesheet(
			'contentsync-status',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Utils/assets/css/contentsync-status.css'
		);

		// Enqueue components-modal.js (base Modal class)
		wp_register_script(
			'contentSync-Modal',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/ClientSDK/assets/js/contentSync.Modal.js',
			array(),
			CONTENTSYNC_VERSION,
			true
		);
		wp_enqueue_script( 'contentSync-Modal' );

		// // Enqueue example-modal-instance.js (test/example implementation)
		// wp_register_script(
		// 'contentsync-example-modal-instance',
		// CONTENTSYNC_PLUGIN_URL . '/includes/Admin/ClientSDK/assets/_examples/example-modal-instance.js',
		// array( 'contentSync-Modal' ),
		// CONTENTSYNC_VERSION,
		// true
		// );
		// wp_enqueue_script( 'contentsync-example-modal-instance' );
	}
}
