<?php

/**
 * Content Sync REST Handler Enqueue Hooks
 *
 * Enqueues the RestHandler script for the admin REST API (contentsync/v1/admin/*).
 * Localizes contentSyncRestData (basePath, restRoot, nonce) for use in JS.
 */

namespace Contentsync\Admin\ClientSDK;

use Contentsync\Utils\Hooks_Base;
use Contentsync\Admin\Utils\Enqueue_Service;

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

		Enqueue_Service::enqueue_admin_script(
			'RestHandler',
			'ClientSDK/assets/js/contentSync.RestHandler.js',
			array(
				'external'     => $deps,
				'localization' => array(
					'var'    => 'contentSyncRestData',
					'values' => array(
						'basePath' => CONTENTSYNC_REST_NAMESPACE . '/admin',
						'restRoot' => esc_url( rest_url() ),
						'nonce'    => wp_create_nonce( 'wp_rest' ),
					),
				),
			)
		);
	}
}
