<?php

namespace Contentsync\Admin\Views\Post_Sync;

use Contentsync\Utils\Hooks_Base;
use Contentsync\Admin\Views\Post_Sync\Global_List_Table;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks for the Sync overview (Content Sync list) screen options.
 *
 * Registers per-page screen option and save filter for the main Content Sync
 * admin page.
 */
class Synced_Posts_Page_Hooks extends Hooks_Base {

	const SYNCED_POSTS_PAGE_POSITION = 4;

	/**
	 * Holds instance of Global_List_Table when screen options are loaded.
	 *
	 * @var Global_List_Table|null
	 */
	public $Global_List_Table = null;

	/**
	 * Register admin-only hooks.
	 */
	public function register_admin() {

		add_action( 'admin_menu', array( $this, 'add_submenu_items' ), self::SYNCED_POSTS_PAGE_POSITION );
		add_action( 'network_admin_menu', array( $this, 'add_submenu_items' ), self::SYNCED_POSTS_PAGE_POSITION );

		add_action( 'load-toplevel_page_contentsync', array( $this, 'add_screen_options' ) );
		add_action( 'load-toplevel_page_contentsync-network', array( $this, 'add_screen_options' ) );
		add_filter( 'set-screen-option', array( $this, 'save_screen_options' ), 10, 3 );
	}

	/**
	 * Add the first submenu item
	 */
	public function add_submenu_items() {

		add_submenu_page(
			'contentsync',
			__( 'Synced Posts', 'contentsync' ),
			__( 'Synced Posts', 'contentsync' ),
			'manage_options',
			'contentsync', // by making the page slug the same as parent, this becomes the first submenu item
			array( $this, 'render_sync_overview_page' )
		);
	}

	/**
	 * Render the sync overview page
	 */
	public function render_sync_overview_page() {
		$this->Global_List_Table->render_page(
			__( 'Synced Posts', 'contentsync' )
		);
	}

	/**
	 * Screen options for the overview (Content Sync list) admin page.
	 */
	public function add_screen_options() {

		$default_posts_per_page = 20;

		$args = array(
			'label'   => __( 'Entries per page:', 'contentsync' ),
			'default' => $default_posts_per_page,
			'option'  => 'globals_per_page',
		);

		add_screen_option( 'per_page', $args );

		$this->Global_List_Table = new Global_List_Table( $default_posts_per_page );
	}

	/**
	 * Save the overview screen option.
	 *
	 * @param mixed  $status Unused. Pass-through.
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 * @return mixed
	 */
	public function save_screen_options( $status, $option, $value ) {
		if ( 'globals_per_page' === $option ) {
			return $value;
		}

		return $status;
	}
}
