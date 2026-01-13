<?php
/**
 * Register database tables for the cluster feature.
 *
 * This file defines functions that create the custom database tables
 * required for the cluster, post review and content condition
 * subsystems. Each `maybe_add_*` function checks whether the table
 * already exists and creates it if necessary, ensuring that the
 * plugin’s schema is up to date when activated or updated. Call these
 * functions during plugin initialisation to provision the cluster
 * database tables before any data is written.
 *
 * @since 2.17.0
 */

namespace Contentsync\Cluster;

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

function maybe_add_cluster_table() {

	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	$table_name      = $wpdb->base_prefix.'contentsync_clusters';

	// return if table exists
	$query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) );

	if ( $wpdb->get_var( $query ) == $table_name ) {
		return false;
	}

	$sql = "CREATE TABLE $table_name (
		ID int(11) NOT NULL AUTO_INCREMENT,
		title varchar(255) NOT NULL,
		destination_ids text NOT NULL,
		enable_reviews tinyint(1) NOT NULL,
		reviewer_ids text NOT NULL,
		content_conditions text NOT NULL,
		PRIMARY KEY  (ID)
	) $charset_collate;";

	require_once ABSPATH.'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	return true;
}

function maybe_add_post_review_table() {

	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	$table_name      = $wpdb->base_prefix.'synced_post_reviews';

	// return if table exists
	$query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) );

	if ( $wpdb->get_var( $query ) == $table_name ) {
		return false;
	}

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

	require_once ABSPATH.'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	return true;
}

function maybe_add_content_conditions() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	$table_name      = $wpdb->base_prefix.'contentsync_content_conditions';

	// return if table exists
	$query = $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table_name ) );

	if ( $wpdb->get_var( $query ) == $table_name ) {

		// if table row 'export_arguments' does not exist, add it
		$column_name = 'export_arguments';
		$column_exists = $wpdb->get_results( "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = '$table_name' AND COLUMN_NAME = '$column_name'" );

		if ( empty( $column_exists ) ) {
			$sql = "ALTER TABLE $table_name ADD COLUMN $column_name text NOT NULL";
			$wpdb->query( $sql );
		}

		return false;
	}

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

	require_once ABSPATH.'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	return true;
}

add_action( 'init', __NAMESPACE__.'\maybe_add_cluster_table' );
add_action( 'init', __NAMESPACE__.'\maybe_add_post_review_table' );
add_action( 'init', __NAMESPACE__.'\maybe_add_content_conditions' );
