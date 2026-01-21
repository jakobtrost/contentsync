<?php
/**
 * Distribution item service class.
 *
 * This class provides static helper methods for interacting with the distribution
 * queue database table and persisting distribution items.
 */
namespace Contentsync\Distribution;

use Contentsync\Utils\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Distribution Item Service.
 *
 * Provides static methods to save, retrieve, and delete distribution items
 * from the database.
 */
class Distribution_Item_Service {

	/**
	 * Save a distribution item to the database.
	 *
	 * @param Distribution_Item $distribution_item
	 *
	 * @return int|WP_Error  ID of the inserted item on success, WP_Error on failure.
	 */
	public static function save( $distribution_item ) {

		if ( ! is_a( $distribution_item, 'Contentsync\Distribution\Distributor_Item' ) ) {
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
						'origin'      => $distribution_item->origin ?? null,
						'origin_id'   => $distribution_item->origin_id ? intval( $distribution_item->origin_id ) : null,
						'error'       => $distribution_item->error ? serialize( $distribution_item->error ) : null,
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
				'origin'      => $distribution_item->origin ?? null,
				'origin_id'   => $distribution_item->origin_id ? intval( $distribution_item->origin_id ) : null,
				'error'       => $distribution_item->error ? serialize( $distribution_item->error ) : null,
			),
		);

		// return the ID of the inserted item
		return $wpdb->insert_id;
	}

	/**
	 * Get a distribution item from the database.
	 *
	 * @param int $ID The ID of the distribution item.
	 *
	 * @return Distribution_Item|false
	 */
	public static function get( $ID ) {
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
	 * @param array  $args    Array of arguments.
	 * @param string $select The columns to select.
	 *
	 * @return array
	 */
	public static function get_items( $args = array(), $select = '*' ) {
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
				$query .= $wpdb->prepare( ' WHERE status IN (' . implode( ',', array_fill( 0, count( $args['status'] ), '%s' ) ) . ')', $args['status'] );
			} else {
				$query .= $wpdb->prepare( ' WHERE status = %s', $args['status'] );
			}
		}

		if ( ! empty( $args['number'] ) ) {
			$query .= $wpdb->prepare( ' LIMIT %d, %d', $args['offset'], $args['number'] );
		}

		if ( ! empty( $args['time'] ) ) {
			// before
			if ( isset( $args['time']['before'] ) ) {
				if ( is_numeric( $args['time']['before'] ) ) {
					$query .= $wpdb->prepare( ' AND time < %s', date( 'Y-m-d H:i:s', $args['time']['before'] ) );
				} else {
					$query .= $wpdb->prepare( ' AND time < %s', date( 'Y-m-d H:i:s', strtotime( $args['time']['before'] ) ) );
				}
			}
			// after
			if ( isset( $args['time']['after'] ) ) {
				if ( is_numeric( $args['time']['after'] ) ) {
					$query .= $wpdb->prepare( ' AND time > %s', date( 'Y-m-d H:i:s', $args['time']['after'] ) );
				} else {
					$query .= $wpdb->prepare( ' AND time > %s', date( 'Y-m-d H:i:s', strtotime( $args['time']['after'] ) ) );
				}
			}
		}

		$query .= " ORDER BY {$args['orderby']} {$args['order']}";

		$items = $wpdb->get_results( $query );

		if ( ! $items ) {
			return array();
		}

		$items = array_map(
			function ( $item ) {
				return new Distribution_Item( $item );
			},
			$items
		);

		return $items;
	}

	/**
	 * Delete a distribution item from the database.
	 *
	 * @param int $ID The ID of the distribution item to delete.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function delete( $ID ) {
		global $wpdb;
		$table_name = $wpdb->base_prefix . 'contentsync_queue_distribution_items';

		$result = $wpdb->delete(
			$table_name,
			array( 'ID' => $ID ),
			array( '%d' )
		);

		return $result !== false;
	}
}
