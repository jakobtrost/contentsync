<?php

namespace Contentsync\Api;

use Contentsync\Main_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize endpoints by loading endpoint files.
 */
function init_endpoints() {
	global $contentsync_endpoints;
	$endpoints = array();
	foreach ( $contentsync_endpoints as $endpoint ) {
		array_push( $endpoints, __DIR__ . "/endpoints/{$endpoint}.php" );
	}
	// filter to add endpoints from e.g. global content plugin
	$endpoints = apply_filters( 'contentsync_endpoints', $endpoints );
	foreach ( $endpoints as $endpoint ) {
		if ( file_exists( $endpoint ) ) {
			require_once $endpoint;
		}
	}
}

// Register the init action
add_action( 'init', __NAMESPACE__ . '\\init_endpoints' );
