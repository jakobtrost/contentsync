<?php

/**
 * Content Sync AJAX Handler Enqueue Hooks
 *
 * This class handles enqueuing scripts and styles for the AJAX handler.
 */

namespace Contentsync\Admin\Ajax;

use Contentsync\Utils\Hooks_Base;

defined( 'ABSPATH' ) || exit;

class Ajax_Handler_Enqueue_Hooks extends Hooks_Base {

	/**
	 * Register admin-only hooks.
	 */
	public function register_admin() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_ajax_handler_script' ), 98 );
	}

	/**
	 * Enqueue AJAX Handler Script.
	 */
	public function enqueue_ajax_handler_script() {

		// Enqueue the AJAX handler script.
		wp_register_script(
			'contentsync-ajax-handler',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Ajax/assets/ajax-handler.js',
			array(),
			CONTENTSYNC_VERSION,
			true
		);
		wp_enqueue_script( 'contentsync-ajax-handler' );

		// Localize the script with ajax url and nonce.
		wp_localize_script(
			'contentsync-ajax-handler',
			'contentSyncAjaxData',
			array(
				'url'   => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'contentsync_ajax' ),
			)
		);
	}
}
