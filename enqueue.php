<?php
/**
 * Content Syncs Enqueue
 *
 * enqueues scripts & styles
 *
 * This file defines the `Enqueue` class, which is responsible for
 * registering and enqueueing the CSS and JavaScript assets used by the
 * Content Sync admin interface. It hooks into `admin_enqueue_scripts` to
 * load the plugin’s styles and tools at the right time and adds a
 * Polylang‑related filter to copy meta values when duplicating content.
 * Extend or modify this class if you introduce new admin assets or need to
 * adjust the order in which assets are loaded.
 */

namespace Contentsync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

new Enqueue();
class Enqueue {

	public function __construct() {

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ), 98 );

	}

	public function admin_enqueue_scripts() {

		// Styles
		wp_register_style(
			'contentsync_admin',
			CONTENTSYNC_PLUGIN_URL . '/assets/css/admin.css',
			null,
			CONTENTSYNC_VERSION,
			'all'
		);
		wp_enqueue_style( 'contentsync_admin' );

		// Scripts
		wp_register_script(
			'contentsync_admin_tools',
			CONTENTSYNC_PLUGIN_URL . '/assets/js/admin-tools.js',
			array( 'jquery' ),
			CONTENTSYNC_VERSION,
			true
		);
		wp_enqueue_script( 'contentsync_admin_tools' );
	}
}
