<?php

/**
 * Content Sync Tools Enqueue Hooks
 *
 * This class handles enqueuing scripts and styles for the Tools.
 */

namespace Contentsync\Admin\ClientSDK;

use Contentsync\Utils\Hooks_Base;

defined( 'ABSPATH' ) || exit;

class Tools_Enqueue_Hooks extends Hooks_Base {

	/**
	 * Register admin-only hooks.
	 */
	public function register_admin() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_tools' ), 98 );
	}

	/**
	 * Enqueue Tools Script.
	 */
	public function enqueue_tools() {

		wp_register_script(
			'contentSync-tools',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/ClientSDK/assets/contentSync.tools.js',
			array(),
			CONTENTSYNC_VERSION,
			true
		);
		wp_enqueue_script( 'contentSync-tools' );
	}
}
