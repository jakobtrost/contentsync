<?php

namespace Contentsync\Connections;

use Contentsync\Utils\Hooks_Base;

defined( 'ABSPATH' ) || exit;

class Site_Connection_Hooks extends Hooks_Base {

	/**
	 * Register hooks that run everywhere.
	 */
	public function register() {
		// Activate the application password extension
		add_filter( 'wp_is_application_passwords_available', '__return_true', 99 );
	}
}
