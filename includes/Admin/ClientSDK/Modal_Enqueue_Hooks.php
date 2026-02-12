<?php

/**
 * Content Sync Modal Enqueue Hooks
 *
 * This class handles enqueuing scripts and styles for the Modal/Modal components.
 */

namespace Contentsync\Admin\ClientSDK;

use Contentsync\Utils\Hooks_Base;
use Contentsync\Admin\Utils\Enqueue_Service;

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
		Enqueue_Service::enqueue_admin_style( 'modal', 'ClientSDK/assets/css/contentsync-modal.css' );
		Enqueue_Service::enqueue_admin_style( 'info_box', 'Utils/assets/css/contentsync-info-box.css' );
		Enqueue_Service::enqueue_admin_style( 'status', 'Utils/assets/css/contentsync-status.css' );
		Enqueue_Service::enqueue_admin_script( 'Modal', 'ClientSDK/assets/js/contentSync.Modal.js' );
	}
}
