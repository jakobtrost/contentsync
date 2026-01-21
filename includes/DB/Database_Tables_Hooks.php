<?php
/**
 * Register database tables for the cluster feature.
 *
 * This class defines methods that create the custom database tables
 * required for the cluster, post review and content condition
 * subsystems. Each `maybe_add_*` method checks whether the table
 * already exists and creates it if necessary, ensuring that the
 * plugin's schema is up to date when activated or updated. Call these
 * methods during plugin initialisation to provision the cluster
 * database tables before any data is written.
 */

namespace Contentsync\DB;

use Contentsync\Utils\Hooks_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Database_Tables_Hooks extends Hooks_Base {

	/**
	 * Hook the table-creation helpers into `init` as a safety net.
	 *
	 * The plugin's activation routine eagerly calls the `maybe_add_*`
	 * functions so that tables are provisioned when the plugin is
	 * activated or updated. These `init` hooks remain to recover from
	 * edge cases where activation did not run (for example on some
	 * multisite scenarios) or tables were manually dropped.
	 */
	public function register_admin() {
		add_action( 'init', array( $this, 'maybe_add_tables' ) );
	}

	/**
	 * Create all required tables if they don't exist.
	 */
	public function maybe_add_tables() {

		$tables = array(
			'contentsync_clusters',
			'synced_post_reviews',
			'cluster_content_conditions',
			'contentsync_queue_distribution_items',
		);

		foreach ( $tables as $table ) {
			if ( ! $this->table_exists( $table ) ) {
				switch ( $table ) {
					case 'contentsync_clusters':
						$this->add_cluster_table();
						break;
					case 'synced_post_reviews':
						$this->add_post_reviews_table();
						break;
					case 'cluster_content_conditions':
						$this->add_cluster_content_conditions_table();
						break;
					case 'contentsync_queue_distribution_items':
						$this->add_queue_distribution_items_table();
						break;
					default:
						break;
				}
			}
		}
	}

	/**
	 * Check if the table exists.
	 *
	 * @param string $table_name The name of the table to check.
	 * @return bool True if the table exists, false otherwise.
	 */
	public function table_exists( $table_name ) {
		global $wpdb;
		$table_name = $wpdb->base_prefix . $table_name;

		$query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) );
		return $wpdb->get_var( $query ) == $table_name;
	}

	/**
	 * Add the cluster table to the database.
	 */
	public function add_cluster_table() {

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->base_prefix . 'contentsync_clusters';

		$sql = "CREATE TABLE $table_name (
			ID int(11) NOT NULL AUTO_INCREMENT,
			title varchar(255) NOT NULL,
			destination_ids text NOT NULL,
			enable_reviews tinyint(1) NOT NULL,
			reviewer_ids text NOT NULL,
			content_conditions text NOT NULL,
			PRIMARY KEY  (ID)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		return true;
	}

	/**
	 * Add the post reviews table to the database.
	 */
	public function add_post_reviews_table() {

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->base_prefix . 'synced_post_reviews';

		$sql = "CREATE TABLE $table_name (
			ID int(11) NOT NULL AUTO_INCREMENT,
			blog_id int(11) NOT NULL,
			post_id int(11) NOT NULL,
			editor int(11) NOT NULL,
			date datetime NOT NULL,
			state varchar(255) NOT NULL,
			previous_post text NOT NULL,
			messages longtext NULL,
			PRIMARY KEY  (ID)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		return true;
	}

	/**
	 * Add the cluster content conditions table to the database.
	 */
	public function add_cluster_content_conditions_table() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->base_prefix . 'cluster_content_conditions';

		$sql = "CREATE TABLE $table_name (
			ID int(11) NOT NULL AUTO_INCREMENT,
			contentsync_cluster_id int(11) NOT NULL,
			blog_id int(11) NOT NULL,
			post_type varchar(255) NOT NULL,
			filter text NOT NULL,
			title varchar(255) NOT NULL,
			export_arguments text NOT NULL,
			make_posts_global_automatically tinyint(1) NOT NULL,
			taxonomy varchar(255) NOT NULL,
			terms text NOT NULL,
			PRIMARY KEY  (ID)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		return true;
	}

	/**
	 * Add the queue distribution items table to the database.
	 */
	public function add_queue_distribution_items_table() {

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = $wpdb->base_prefix . 'contentsync_queue_distribution_items';

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
}
