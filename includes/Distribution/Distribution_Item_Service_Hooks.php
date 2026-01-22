<?php
/**
 * Distribution item service hooks provider.
 *
 * Registers hooks for scheduling and executing the daily cleanup of old
 * distribution items.
 */
namespace Contentsync\Distribution;

use Contentsync\Utils\Hooks_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Distribution Item Service Hooks.
 *
 * Registers the cron hooks for cleaning up old distribution items.
 */
class Distribution_Item_Service_Hooks extends Hooks_Base {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'schedule_daily_cleanup' ) );
		add_action( 'contentsync_daily_distribution_items_cleanup', array( $this, 'delete_old_items' ) );
	}

	/**
	 * Schedule a daily check to delete all items older than 3 days.
	 *
	 * @return void
	 */
	public function schedule_daily_cleanup() {
		if ( ! wp_next_scheduled( 'contentsync_daily_distribution_items_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'contentsync_daily_distribution_items_cleanup' );
		}
	}

	/**
	 * Delete all items older than 3 days.
	 *
	 * @return void
	 */
	public function delete_old_items() {
		global $wpdb;
		$table_name = $wpdb->base_prefix . 'contentsync_queue_distribution_items';

		// delete all items older than 3 days
		$wpdb->query( "DELETE FROM $table_name WHERE time < DATE_SUB( NOW(), INTERVAL 3 DAY )" );
	}
}
