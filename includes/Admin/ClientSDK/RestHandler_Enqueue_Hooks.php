<?php

/**
 * Content Sync REST Handler Enqueue Hooks
 *
 * Enqueues the RestHandler script for the admin REST API (contentsync/v1/admin/*).
 * Localizes contentSyncRestData (basePath, restRoot, nonce) for use in JS.
 */

namespace Contentsync\Admin\ClientSDK;

use Contentsync\Utils\Hooks_Base;

defined( 'ABSPATH' ) || exit;

class RestHandler_Enqueue_Hooks extends Hooks_Base {

	/**
	 * Register admin-only hooks.
	 */
	public function register_admin() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_rest_handler_script' ), 98 );
	}

	/**
	 * Enqueue REST Handler Script.
	 */
	public function enqueue_rest_handler_script() {

		// wp-api-fetch is optional; RestHandler falls back to fetch when wp.apiFetch is missing.
		$deps = array( 'jquery' );
		if ( wp_script_is( 'wp-api-fetch', 'registered' ) ) {
			$deps[] = 'wp-api-fetch';
		}

		wp_register_script(
			'contentSync-RestHandler',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/ClientSDK/assets/js/contentSync.RestHandler.js',
			$deps,
			CONTENTSYNC_VERSION,
			true
		);
		wp_enqueue_script( 'contentSync-RestHandler' );

		wp_localize_script(
			'contentSync-RestHandler',
			'contentSyncRestData',
			array(
				'basePath' => CONTENTSYNC_REST_NAMESPACE . '/admin',
				'restRoot' => esc_url( rest_url() ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
}
