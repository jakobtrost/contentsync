<?php

/**
 * Content Sync SnackBar Enqueue Hooks
 *
 * This class handles enqueuing scripts and styles for the SnackBar.
 */

namespace Contentsync\Admin\ClientSDK;

use Contentsync\Utils\Hooks_Base;
use Contentsync\Admin\Utils\Admin_Render;

defined( 'ABSPATH' ) || exit;

class SnackBar_Enqueue_Hooks extends Hooks_Base {

	/**
	 * Register admin-only hooks.
	 */
	public function register_admin() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_snackbar_assets' ), 98 );
	}

	/**
	 * Enqueue SnackBar Scripts.
	 */
	public function enqueue_snackbar_assets() {

		// Enqueue SnackBar (WordPress-style snackbars, vanilla JS)
		wp_register_style(
			'contentsync-snackbar',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/ClientSDK/assets/contentsync-snackbar.css',
			array(),
			CONTENTSYNC_VERSION
		);
		// wp_enqueue_style( 'contentsync-snackbar' );

		wp_register_script(
			'contentSync-SnackBar',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/ClientSDK/assets/contentSync.SnackBar.js',
			array(),
			CONTENTSYNC_VERSION,
			true
		);
		wp_enqueue_script( 'contentSync-SnackBar' );
	}
}
