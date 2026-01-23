<?php

namespace Contentsync\Admin\Menu;

use Contentsync\Utils\Hooks_Base;

class Menu_Hooks extends Hooks_Base {

	public function register() {

		add_action( 'admin_menu', array( $this, 'add_main_plugin_menu_item' ), 3 );
		add_action( 'network_admin_menu', array( $this, 'add_main_plugin_menu_item' ), 3 );

		add_action( 'admin_bar_menu', array( $this, 'add_network_adminbar_items' ), 11 );

		// enqueue css
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_css' ) );
	}

	/**
	 * Add the main plugin menu item
	 */
	public function add_main_plugin_menu_item() {

		/**
		 * add the main page
		 */
		$hook = add_menu_page(
			__( 'Content Sync', 'contentsync' ), // page title
			__( 'Content Sync', 'contentsync' ), // menu title
			'manage_options', // capability
			'contentsync', // slug
			array( $this, 'render_main_plugin_page' ), // function
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Menu/assets/contentsync-menu-icon-alternate.svg', // icon url
			is_network_admin() ? 10 : 65 // position
		);
	}

	/**
	 * Render the main plugin page
	 */
	public function render_main_plugin_page() {
		do_action( 'contentsync_render_main_plugin_page' );
	}

	/**
	 * Screen options for the admin pages
	 */
	public function add_screen_options() {
		do_action( 'contentsync_add_main_plugin_page_screen_options' );
	}

	/**
	 * Add item to network adminbar
	 */
	public function add_network_adminbar_items( $wp_admin_bar ) {
		$wp_admin_bar->add_node(
			array(
				'id'     => 'contentsync',
				'title'  => __( 'Content Sync', 'contentsync' ),
				'parent' => 'network-admin',
				'href'   => network_admin_url( 'admin.php?page=contentsync' ),
			)
		);
	}

	/**
	 * Enqueue css
	 */
	public function enqueue_css() {
		wp_enqueue_style(
			'contentsync-menu',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Menu/assets/admin-menu.css',
			array(),
			CONTENTSYNC_VERSION
		);
	}
}
