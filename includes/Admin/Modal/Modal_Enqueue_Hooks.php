<?php

/**
 * Content Sync Modal Enqueue Hooks
 *
 * This class handles enqueuing scripts and styles for the Modal/Modal components.
 */

namespace Contentsync\Admin\Modal;

use Contentsync\Utils\Hooks_Base;
use Contentsync\Admin\Utils\Admin_Render;

defined( 'ABSPATH' ) || exit;

class Modal_Enqueue_Hooks extends Hooks_Base {

	/**
	 * Register admin-only hooks.
	 */
	public function register_admin() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_dialog_scripts' ), 98 );
	}

	/**
	 * Enqueue Modal/Modal Scripts.
	 */
	public function enqueue_dialog_scripts() {

		// Enqueue components-modal.css (modal styles)
		wp_register_style(
			'contentsync-components-modal',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Modal/assets/components-modal.css',
			array(),
			CONTENTSYNC_VERSION,
		);
		wp_enqueue_style( 'contentsync-components-modal' );

		// Enqueue admin-info-box.css (admin info box styles)
		Admin_Render::maybe_enqueue_stylesheet(
			'contentsync-admin-info-box',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Utils/assets/admin-info-box.css'
		);

		// Enqueue components-modal.js (base Modal class)
		wp_register_script(
			'contentsync-components-modal',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Modal/assets/components-modal.js',
			array(),
			CONTENTSYNC_VERSION,
			true
		);
		wp_enqueue_script( 'contentsync-components-modal' );

		// Enqueue example-modal-instance.js (test/example implementation)
		// Depends on components-modal.js
		wp_register_script(
			'contentsync-example-modal-instance',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Modal/assets/material/example-modal-instance.js',
			array( 'contentsync-components-modal' ),
			CONTENTSYNC_VERSION,
			true
		);
		wp_enqueue_script( 'contentsync-example-modal-instance' );
	}
}
