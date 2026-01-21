<?php

/**
 * Displays all connections as WP-Admin list table
 *
 * @see wp-admin/includes/class-wp-list-table.php
 */
namespace Contentsync\Admin\Pages\List_Tables;

use Contentsync\Utils\Urls;

defined( 'ABSPATH' ) || exit;

// include the parent class
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Connections_List_Table extends \WP_List_Table {

	/**
	 * Class constructor
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'gc_connection_table', // table class
				'plural'   => 'gc_connection_table', // table class
				'ajax'     => false,
			)
		);
	}


	/**
	 * =================================================================
	 *                          GENERAL
	 * =================================================================
	 */

	/**
	 * Handles data query and filter, sorting, and pagination.
	 */
	public function prepare_items() {

		// process bulk action
		$this->process_bulk_action();

		// general
		$this->_column_headers = array( $this->get_columns() );
		$this->set_pagination_args(
			array(
				'total_items' => 100,
				'per_page'    => 100,
			)
		);

		// set items
		$this->items = \Contentsync\Posts\Sync\get_site_connections();
		// debug($this->items);
	}

	/**
	 * Render the table
	 */
	public function render_table() {

		$this->prepare_items();

		echo '<form id="posts-filter" method="post" style="margin-top: 1em;">';

		$this->display();

		echo '</form>';
	}

	/**
	 * Display text when no items found
	 */
	public function no_items() {
		echo "<div style='margin: 4px 0;''><strong>" . __( 'No connections found.', 'contentsync_hub' ) . '</strong> ' . __( 'Create your first shortcuts now to manage content across pages.', 'contentsync_hub' ) . '</div>';
	}

	/**
	 * Generates the table navigation above or below the table
	 *
	 * @param string $which
	 */
	protected function display_tablenav( $which ) {
		return;
	}


	/**
	 * =================================================================
	 *                          COLUMNS
	 * =================================================================
	 */

	/**
	 * Associative array of columns
	 *
	 * @return array
	 */
	function get_columns() {
		$columns = array(
			'cb'         => '<input type="checkbox" />',
			'site_name'  => __( 'Title', 'contentsync_hub' ),
			'site_url'   => __( 'URL', 'contentsync_hub' ),
			'user_login' => __( 'User', 'contentsync_hub' ),
			'active'     => __( 'State', 'contentsync_hub' ),
			// 'password'       => __( "Password", 'contentsync_hub' ),
			// 'options'    => __( 'Settings', 'contentsync_hub' ),
			// 'debug'          => 'Debug',
		);

		return $columns;
	}


	/**
	 * Render a column when no column specific method exist.
	 *
	 * @param array  $item
	 * @param string $column_name
	 *
	 * @return mixed
	 */
	public function column_default( $item, $column_name ) {

		switch ( $column_name ) {

			case 'site_name':
				$site_url  = isset( $item['site_url'] ) ? esc_attr( $item['site_url'] ) : null;
				$site_name = isset( $item['site_name'] ) ? esc_attr( $item['site_name'] ) : null;
				if ( ! $site_name ) {
					$site_name = \Contentsync\Api\get_site_name( $site_url ) ?? $site_url;
				}
				$network_url = Urls::get_nice_url( $site_url );
				$delete_url  = remove_query_arg( array( 'user_login', 'password', 'site_url', 'success' ), add_query_arg( 'delete', $network_url ) );
				$actions     = array(
					'view'   => "<a href='$site_url' target='_blank'>" . __( 'View website', 'contentsync_hub' ) . '</a>',
					'delete' => "<a href='$delete_url'>" . __( 'Delete connection', 'contentsync_hub' ) . '</a>',
				);
				return $site_name . $this->row_actions( $actions );
				break;

			case 'site_url':
				$site_url = isset( $item['site_url'] ) ? esc_attr( $item['site_url'] ) : null;
				return "<a href='" . $site_url . "' target='_blank'>" . $site_url . '</a>';
				break;

			case 'active':
				$unused = isset( $item['contents'] ) && $item['contents'] === false && isset( $item['search'] ) && $item['search'] === false;
				if ( $unused ) {
					return \Contentsync\Admin\Utils\make_admin_info_box(
						array(
							'style' => '',
							'text'  => __( 'Connection not used', 'contentsync_hub' ),
						)
					);
				} else {
					$active = isset( $item['active'] ) ? (bool) $item['active'] : false;
					return \Contentsync\Admin\Utils\make_admin_info_box(
						array(
							'style' => $active ? 'green' : 'red',
							'text'  => $active ? __( 'Connection active', 'contentsync_hub' ) : __( 'Connection inactive', 'contentsync_hub' ),
						)
					);
				}
				break;

			case 'debug':
				return debug( $item );
				break;

			default:
				return esc_attr( $item[ $column_name ] );
		}
	}

	/**
	 * Render the bulk edit checkbox
	 */
	function column_cb( $item ) {

		if ( ! isset( $item['site_url'] ) ) {
			return;
		}

		return sprintf(
			'<input type="checkbox" name="connections[]" value="%s" />',
			$item['site_url']
		);
	}


	/**
	 * =================================================================
	 *                          BULK ACTIONS
	 * =================================================================
	 */

	/**
	 * Returns an associative array containing the bulk action
	 *
	 * @return array
	 */
	public function get_bulk_actions() {

		$actions = array(
			'delete' => __( 'Delete connection', 'contentsync_hub' ),
		);

		return $actions;
	}

	/**
	 * Process the bulk actions
	 * Called via prepare_items()
	 */
	public function process_bulk_action() {

		// delete GET request
		if ( isset( $_GET['delete'] ) ) {
			$delete_site_url = urldecode( $_GET['delete'] );
			$deleted         = \Contentsync\Posts\Sync\delete_site_connection( $delete_site_url );
			// successfull
			if ( $deleted ) {
				\Contentsync\Admin\Utils\render_admin_notice(
					sprintf(
						__( 'The connection to the %s page was successfully deleted.', 'contentsync_hub' ),
						'<strong>' . $delete_site_url . '</strong>'
					),
					'success'
				);
			}
			// failed
			elseif ( $deleted === false ) {
				\Contentsync\Admin\Utils\render_admin_notice(
					sprintf(
						__( "Errors occurred while deleting the connection to the '%s' page.", 'contentsync_hub' ),
						'<strong>' . $delete_site_url . '</strong>'
					),
					'error'
				);
			}
		}
	}
}
