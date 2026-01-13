<?php
/**
 * Register and interact with distribution database tables.
 *
 * This file contains functions to create the custom database table used
 * for the distribution queue and to persist distribution items. The
 * `maybe_add_queue_distribution_items_table` function checks whether the
 * table exists and creates it if necessary, while `save_distribution_item`
 * inserts or updates queue entries and handles schema migrations such as
 * adding new columns. These functions are hooked into WordPress at
 * plugin init, so the table is ready before any queue operations run.
 *
 * @since 2.17.0
 */
namespace Contentsync\Distribution;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add the table to the database.
 */
function maybe_add_queue_distribution_items_table() {

	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	$table_name      = $wpdb->base_prefix . 'contentsync_queue_distribution_items';

	// return if table exists
	$query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) );

	if ( $wpdb->get_var( $query ) == $table_name ) {
		return false;
	}

	$sql = "CREATE TABLE $table_name (
		ID int(11) NOT NULL AUTO_INCREMENT,
		status text NOT NULL,
		posts longtext NULL,
		destination longtext NULL,
		time timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
		origin text NULL,
		origin_id int(11) NULL,
		error longtext NULL,
		PRIMARY KEY  (ID)
	) $charset_collate;";


	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	return true;
}

add_action( 'init', __NAMESPACE__.'\maybe_add_queue_distribution_items_table' );

/**
 * Save a distribution item to the database.
 * 
 * @param Distribution_Item $distribution_item
 * 
 * @return int|WP_Error  ID of the inserted item on success, WP_Error on failure.
 */
function save_distribution_item( $distribution_item ) {

	if ( ! is_a( $distribution_item, 'Contentsync\Distribution\Distribution_Item' ) ) {
		return new \WP_Error( 'invalid_data', __( 'Invalid data.', 'global-contents' ) );
	}

	if ( empty( $distribution_item->posts ) || empty( $distribution_item->destination ) ) {
		return new \WP_Error( 'empty_data', __( 'Posts or destination is empty.', 'global-contents' ) );
	}

	global $wpdb;
	$table_name = $wpdb->base_prefix . 'contentsync_queue_distribution_items';

	// check if the table has the column 'error', has it has been added only recently
	$has_error_column = $wpdb->get_var( "SHOW COLUMNS FROM $table_name LIKE 'error'" );
	if ( ! $has_error_column ) {
		$wpdb->query( "ALTER TABLE $table_name ADD COLUMN error longtext NULL" );
	}

	// if it already exists, update it
	if ( $distribution_item->ID ) {
		$existing_item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE ID = %d", $distribution_item->ID ) );

		if ( $existing_item ) {
			$result = $wpdb->update(
				$table_name,
				array(
					'status'      => $distribution_item->status,
					'posts'       => serialize( $distribution_item->posts ),
					'destination' => serialize( $distribution_item->destination ),
					'origin'      => $distribution_item->origin ?? NULL,
					'origin_id'   => $distribution_item->origin_id ? intval($distribution_item->origin_id) : NULL,
					'error'       => $distribution_item->error ? serialize( $distribution_item->error ) : NULL
				),
				array( 'ID' => $distribution_item->ID )
			);

			// return the ID of the updated item
			return $distribution_item->ID;
		}
	}

	$result = $wpdb->insert(
		$table_name,
		array(
			'status'      => $distribution_item->status,
			'posts'       => serialize( $distribution_item->posts ),
			'destination' => serialize( $distribution_item->destination ),
			'origin'      => $distribution_item->origin ?? NULL,
			'origin_id'   => $distribution_item->origin_id ? intval($distribution_item->origin_id) : NULL,
			'error'       => $distribution_item->error ? serialize( $distribution_item->error ) : NULL
		),
	);

	// return the ID of the inserted item
	return $wpdb->insert_id;
}

/**
 * Get a distribution item from the database.
 */
function get_distribution_item( $ID ) {
	global $wpdb;
	$table_name = $wpdb->base_prefix . 'contentsync_queue_distribution_items';

	$existing_item = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE ID = %d", $ID ) );

	if ( ! $existing_item ) {
		return false;
	}

	return new Distribution_Item( $existing_item );
}

/**
 * Get distribution items from the database.
 * 
 * @param array $args    Array of arguments.
 * @param string $select The columns to select.
 * 
 * @return array
 */
function get_distribution_items( $args = array(), $select = '*' ) {
	global $wpdb;
	$table_name = $wpdb->base_prefix . 'contentsync_queue_distribution_items';

	$defaults = array(
		'number'  => 0,
		'offset'  => 0,
		'status'  => '',
		'orderby' => 'ID',
		'order'   => 'DESC',
	);

	$args = wp_parse_args( $args, $defaults );

	$query = "SELECT $select FROM $table_name";

	if ( ! empty( $args['status'] ) ) {
		if ( is_array( $args['status'] ) ) {
			$query .= $wpdb->prepare( " WHERE status IN (".implode( ',', array_fill( 0, count( $args['status'] ), '%s' ) ).")", $args['status'] );
		} else {
			$query .= $wpdb->prepare( " WHERE status = %s", $args['status'] );
		}
	}

	if ( ! empty( $args['number'] ) ) {
		$query .= $wpdb->prepare( " LIMIT %d, %d", $args['offset'], $args['number'] );
	}

	if ( ! empty( $args['time'] ) ) {
		// before
		if ( isset( $args['time']['before'] ) ) {
			if ( is_numeric( $args['time']['before'] ) ) {
				$query .= $wpdb->prepare( " AND time < %s", date( 'Y-m-d H:i:s', $args['time']['before'] ) );
			} else {
				$query .= $wpdb->prepare( " AND time < %s", date( 'Y-m-d H:i:s', strtotime( $args['time']['before'] ) ) );
			}
		}
		// after
		if ( isset( $args['time']['after'] ) ) {
			if ( is_numeric( $args['time']['after'] ) ) {
				$query .= $wpdb->prepare( " AND time > %s", date( 'Y-m-d H:i:s', $args['time']['after'] ) );
			} else {
				$query .= $wpdb->prepare( " AND time > %s", date( 'Y-m-d H:i:s', strtotime( $args['time']['after'] ) ) );
			}
		}
	}

	$query .= " ORDER BY {$args['orderby']} {$args['order']}";

	$items = $wpdb->get_results( $query );

	if ( ! $items ) {
		return array();
	}

	$items = array_map( function( $item ) {
		return new Distribution_Item( $item );
	}, $items );

	return $items;
}

/**
 * Delete a distribution item from the database.
 * 
 * @param int $ID The ID of the distribution item to delete.
 * 
 * @return bool True on success, false on failure.
 */
function delete_distribution_item( $ID ) {
	global $wpdb;
	$table_name = $wpdb->base_prefix . 'contentsync_queue_distribution_items';

	$result = $wpdb->delete(
		$table_name,
		array( 'ID' => $ID ),
		array( '%d' )
	);

	return $result !== false;
}

/**
 * Schedule a daily check to delete all items older than 7 days.
 */
function schedule_daily_cleanup() {
	if ( ! wp_next_scheduled( 'contentsync_daily_cleanup' ) ) {
		wp_schedule_event( time(), 'daily', 'contentsync_daily_cleanup' );
	}
}
add_action( 'init', __NAMESPACE__.'\schedule_daily_cleanup' );

/**
 * Delete all items older than 3 days.
 */
function daily_cleanup() {

	global $wpdb;
	$table_name = $wpdb->base_prefix . 'contentsync_queue_distribution_items';
	
	// delete all items older than 3 days
	$wpdb->query( "DELETE FROM $table_name WHERE time < DATE_SUB( NOW(), INTERVAL 3 DAY )" );


	// // if more than 100 items are left, clean them up
	// $count = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );

	// if ( $count > 100 ) {
	// 	$wpdb->query( "DELETE FROM $table_name ORDER BY time ASC LIMIT 100" );
	// }
}
add_action( 'contentsync_daily_cleanup', __NAMESPACE__.'\daily_cleanup' );