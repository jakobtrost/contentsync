<?php

namespace Contentsync\Admin\Utils\Notice;

defined( 'ABSPATH' ) || exit;

class Admin_Notice_Service {

	/**
	 * Add an admin notice, which will be displayed in the next admin request via
	 * a transient.
	 *
	 * @param string $msg The message to display.
	 * @param string $mode The mode of the notice (info, warning, error, success).
	 */
	public static function add( $msg, $mode = 'info' ) {
		set_transient( 'contentsync_transient_notice', $mode . '::' . $msg );
	}
}
