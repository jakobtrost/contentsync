<?php

/**
 * Content Sync SnackBar Enqueue Hooks
 *
 * This class handles enqueuing scripts and styles for the SnackBar.
 */

namespace Contentsync\Admin\ClientSDK;

use Contentsync\Utils\Hooks_Base;
use Contentsync\Admin\Utils\Enqueue_Service;

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
		Enqueue_Service::enqueue_admin_style(
			'snackbar',
			'ClientSDK/assets/css/contentsync-snackbar.css'
		);

		Enqueue_Service::enqueue_admin_script(
			'SnackBar',
			'ClientSDK/assets/js/contentSync.SnackBar.js'
		);
	}
}
