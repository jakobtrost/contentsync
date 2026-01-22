<?php

namespace Contentsync\Admin\Menu;

use Contentsync\Utils\Hooks_Base;

class Menu_Hooks extends Hooks_Base {

	public function register() {

		add_action( 'admin_menu', array( $this, 'add_menu_pages' ), 3 );
		add_action( 'network_admin_menu', array( $this, 'add_menu_pages' ), 3 );
		add_action( 'admin_bar_menu', array( $this, 'add_network_adminbar_items' ), 11 );
	}

	/**
	 * Add the menu pages
	 */
	public function add_menu_pages() {

		$args = array(
			'slug'           => 'contentsync',
			'title'          => __( 'Content Sync', 'contentsync' ),
			'singular'       => __( 'Content Sync', 'contentsync' ),
			'plural'         => __( 'Content Sync', 'contentsync' ),
			'admin_url'      => admin_url( 'admin.php?page=contentsync' ),
			'network_url'    => network_admin_url( 'admin.php?page=contentsync' ),
			'posts_per_page' => 20,
		);

		/**
		 * add the main page
		 */
		$hook = add_menu_page(
			$args['title'], // page title
			$args['title'], // menu title
			'manage_options', // capability
			$args['slug'], // slug
			array( $this, 'render_main_plugin_page' ), // function
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Menu/assets/contentsync-menu-icon.svg', // icon url
			72 // position
		);
		// add_action( 'load-' . $hook, array( $this, 'add_overview_screen_options' ) );

		/**
		 * add the ghost submenu item
		 */
		add_submenu_page(
			$args['slug'], // parent slug
			$args['title'],  // page title
			__( 'Dashboard', 'contentsync' ),
			'manage_options', // capability
			$args['slug'], // slug
			'', // function
			1 // position
		);

		if ( is_multisite() && is_super_admin() ) {

			/**
			 * add the network link
			 */
			if ( ! is_network_admin() ) {
				add_submenu_page(
					$args['slug'], // parent slug
					__( 'Network Content', 'contentsync' ),  // page title
					__( 'Network Content', 'contentsync' ), // menu title
					'manage_options', // capability
					$args['network_url'], // slug
					'', // function
					50 // position
				);
			}
		}
	}

	public function render_main_plugin_page() {
		do_action( 'contentsync_render_main_plugin_page' );
	}

	/**
	 * Network adminbar
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
}
