<?php

namespace Contentsync\Admin\Utils\Notice;

use Contentsync\Admin\Utils\Admin_Render;
use Contentsync\Utils\Hooks_Base;

defined( 'ABSPATH' ) || exit;

class Admin_Notices_Hooks extends Hooks_Base {

	public function register_admin() {
		add_action( 'admin_notices', array( $this, 'display_admin_notice' ) );
	}

	/**
	 * Display an admin notice if the transient 'contentsync_transient_notice' is set
	 */
	public function display_admin_notice() {

		// get transient
		$transient = get_transient( 'contentsync_transient_notice' );

		if ( $transient ) {
			// cut transient into pieces
			$transient = explode( '::', $transient );
			$mode      = $transient[0];
			$msg       = $transient[1];
			// this is my last resort
			Admin_Render::render_admin_notice( $msg, $mode );

			// delete transient
			delete_transient( 'contentsync_transient_notice' );
		}
	}
}
