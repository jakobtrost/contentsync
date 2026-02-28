<?php

/**
 * Content Sync Tools Enqueue Hooks
 *
 * This class handles enqueuing scripts and styles for the Tools.
 */

namespace Contentsync\Admin\ClientSDK;

use Contentsync\Utils\Hooks_Base;
use Contentsync\Admin\Utils\Enqueue_Service;

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
		Enqueue_Service::enqueue_admin_script(
			'tools',
			'ClientSDK/assets/js/contentSync.tools.js',
			array(
				'localization' => array(
					'var'    => 'contentSyncToolsData',
					'values' => array(
						'iconsPath' => CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Utils/assets/icon/',
					),
				),
			)
		);
	}
}
