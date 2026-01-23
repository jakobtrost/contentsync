<?php

namespace Contentsync\Admin\Ajax;

use Contentsync\Utils\Directory_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Automatically discovers and loads all AJAX handler classes.
 *
 * This loader scans the includes/Admin/Ajax/Handler/ directory for handler files
 * and instantiates each class. Since handler classes extend Ajax_Base
 * and register their AJAX actions in the constructor, this ensures all handlers
 * are registered without manual instantiation.
 *
 * Only loads handlers when in the admin context.
 */
class Ajax_Handler_Loader extends Directory_Loader {

	/**
	 * Constructor - sets up the AJAX handler loader.
	 */
	public function __construct() {

		// Only load handlers in admin context.
		if ( ! is_admin() ) {
			return;
		}

		// load all handler classes in the Handler directory.
		$handler_dir = CONTENTSYNC_PLUGIN_PATH . '/includes/Admin/Ajax/Handler';
		$namespace   = '\Contentsync\Admin\Ajax\Handler';
		parent::__construct( $handler_dir, $namespace );
	}
}
