<?php

/**
 * Content Sync Admin Utils Enqueue Hooks
 *
 * This class handles enqueuing scripts and styles for the Admin Utils.
 */

namespace Contentsync\Admin\Utils;

use Contentsync\Utils\Hooks_Base;

defined( 'ABSPATH' ) || exit;

class Admin_Utils_Enqueue_Hooks extends Hooks_Base {

	/**
	 * Register admin-only hooks.
	 */
	public function register_admin() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_utils' ), 98 );
	}

	/**
	 * Enqueue Admin Utils Script.
	 */
	public function enqueue_admin_utils() {

		wp_register_script(
			'contentsync-admin-utils',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Utils/assets/admin-utils.js',
			array(),
			CONTENTSYNC_VERSION,
			true
		);
		wp_enqueue_script( 'contentsync-admin-utils' );
	}
}
