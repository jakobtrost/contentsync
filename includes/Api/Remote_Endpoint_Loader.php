<?php

namespace Contentsync\Api;

use Contentsync\Utils\Directory_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * Automatically discovers and loads all remote endpoint classes.
 *
 * This loader scans the includes/Api/Remote_Endpoints/ directory for endpoint files
 * and instantiates each class. Since endpoint classes extend Remote_Endpoint_Base
 * and register their endpoints in the constructor, this ensures all endpoints
 * are registered without manual instantiation.
 */
class Remote_Endpoint_Loader extends Directory_Loader {

	/**
	 * Constructor - sets up the remote endpoint loader.
	 */
	public function __construct() {

		$rest_url_part   = '/' . rest_get_url_prefix() . '/'; // e.g. /wp-json/
		$is_rest_request = strpos( $_SERVER['REQUEST_URI'], $rest_url_part ) !== false;
		if ( ! $is_rest_request ) {
			return;
		}

		$endpoints_dir = CONTENTSYNC_PLUGIN_PATH . '/includes/Api/Remote_Endpoints';
		$namespace     = '\Contentsync\Api\Remote_Endpoints';
		parent::__construct( $endpoints_dir, $namespace );
	}
}
