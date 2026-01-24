<?php

namespace Contentsync\Api;

use Contentsync\Utils\Directory_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Automatically discovers and loads all admin endpoint classes.
 *
 * This loader scans the includes/Api/Admin_Endpoints/ directory for endpoint files
 * and instantiates each class. Since endpoint classes extend Admin_Endpoint_Base
 * and register their endpoints in the constructor, this ensures all endpoints
 * are registered without manual instantiation.
 */
class Admin_Endpoint_Loader extends Directory_Loader {

	/**
	 * Constructor - sets up the admin endpoint loader.
	 */
	public function __construct() {

		$rest_url_part   = '/' . rest_get_url_prefix() . '/'; // e.g. /wp-json/
		$is_rest_request = strpos( $_SERVER['REQUEST_URI'], $rest_url_part ) !== false;
		if ( ! $is_rest_request ) {
			return;
		}

		$endpoints_dir = CONTENTSYNC_PLUGIN_PATH . '/includes/Api/Admin_Endpoints';
		$namespace     = '\Contentsync\Api\Admin_Endpoints';
		parent::__construct( $endpoints_dir, $namespace );
	}
}
