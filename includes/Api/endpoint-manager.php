<?php

namespace Contentsync\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Initialize endpoints by loading endpoint files.
 */
function init_endpoints() {

	$contentsync_endpoints = array(
		'add_site_connection',
		'check_auth',
		'connected_posts',
		'distribution_endpoint',
		'posts_connections',
		'posts_meta',
		'posts',
		'site_name',
	);

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
