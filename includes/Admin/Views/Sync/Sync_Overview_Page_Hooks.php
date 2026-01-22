<?php

namespace Contentsync\Admin\Views\Sync;

use Contentsync\Utils\Hooks_Base;
use Contentsync\Admin\Views\Sync\Global_List_Table;

defined( 'ABSPATH' ) || exit;

/**
 * Hooks for the Sync overview (Content Sync list) screen options.
 *
 * Registers per-page screen option and save filter for the main Content Sync
 * admin page.
 */
class Sync_Overview_Page_Hooks extends Hooks_Base {

	/**
	 * Default posts per page for the overview.
	 */
	const DEFAULT_POSTS_PER_PAGE = 20;

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
		add_action( 'load-toplevel_page_contentsync', array( $this, 'add_screen_options' ) );
		add_action( 'load-toplevel_page_contentsync-network', array( $this, 'add_screen_options' ) );
		add_filter( 'set-screen-option', array( $this, 'save_screen_options' ), 10, 3 );
	}

	/**
	 * Screen options for the overview (Content Sync list) admin page.
	 */
	public function add_screen_options() {
		$args = array(
			'label'   => __( 'Entries per page:', 'contentsync' ),
			'default' => self::DEFAULT_POSTS_PER_PAGE,
			'option'  => 'globals_per_page',
		);

		add_screen_option( 'per_page', $args );

		$this->Global_List_Table = new Global_List_Table( self::DEFAULT_POSTS_PER_PAGE );
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
