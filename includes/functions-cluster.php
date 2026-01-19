<?php
/**
 * Cluster helper functions.
 *
 * This file defines a collection of procedural functions that operate
 * on clusters within the Content Sync plugin. They provide simple
 * wrappers for retrieving clusters by ID, fetching all clusters or
 * clusters by destination ID, and performing operations on cluster
 * objects. These functions complement the `Cluster` class and are
 * intended to be used from templates or other procedural code without
 * needing to instantiate classes directly.
 *
 * @since 2.17.0
 */
namespace Contentsync;

/**
 * Global Cluster Functions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get a cluster by ID.
 *
 * @param int $cluster_id
 *
 * @return Cluster|false
 */
function get_cluster_by_id( $cluster_id ) {
	return Cluster::get_instance( $cluster_id );
}

/**
 * Get all clusters.
 *
 * @return array
 */
function get_clusters() {

	global $wpdb;

	$table_name = $wpdb->base_prefix . 'contentsync_clusters';
	$clusters   = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY ID DESC" );

	if ( ! $clusters ) {
		// return empty array to render empty table
		return array();
	}

	$cluster_objects = array();
	foreach ( $clusters as $cluster ) {
		$cluster_objects[] = new Cluster( $cluster );
	}

	return $cluster_objects;
}

/**
 * Get all clusters that contain a given destination.
 *
 * @param int $blog_id
 *
 * @return array
 */
function get_clusters_by_destination_id( $blog_id ) {

	global $wpdb;

	$table_name = $wpdb->base_prefix . 'contentsync_clusters';
	$clusters   = $wpdb->get_results( "SELECT * FROM $table_name WHERE FIND_IN_SET('$blog_id', destination_ids)" );

	if ( ! $clusters ) {
		return array();
	}

	$cluster_objects = array();
	foreach ( $clusters as $cluster ) {
		$cluster_objects[] = new Cluster( $cluster );
	}

	return $cluster_objects;
}

/**
 * Insert a new cluster.
 *
 * @param array $cluster
 *
 * @return bool|int
 */
function insert_cluster( $cluster ) {
	global $wpdb;

	if ( ! isset( $cluster['title'] ) ) {
		return false;
	}

	$wpdb->insert(
		$wpdb->base_prefix . 'contentsync_clusters',
		array(
			'title'              => sanitize_text_field( $cluster['title'] ),
			'destination_ids'    => isset( $cluster['destination_ids'] ) ? ( is_array( $cluster['destination_ids'] ) ? implode( ',', $cluster['destination_ids'] ) : strval( $cluster['destination_ids'] ) ) : '',
			'enable_reviews'     => isset( $cluster['enable_reviews'] ) ? $cluster['enable_reviews'] : false,
			'reviewer_ids'       => isset( $cluster['reviewer_ids'] ) ? $cluster['reviewer_ids'] : '',
			'content_conditions' => isset( $cluster['content_conditions'] ) ? $cluster['content_conditions'] : serialize( array() ),
		)
	);
	return $wpdb->insert_id;
}

/**
 * Update a cluster.
 *
 * @param array $cluster
 *
 * @return bool|int
 */
function update_cluster( $cluster ) {
	global $wpdb;

	if ( ! isset( $cluster['ID'] ) ) {
		return false;
	}

	// update only the fields that are set

	$update_data = array();

	if ( isset( $cluster['title'] ) ) {
		$update_data['title'] = sanitize_text_field( $cluster['title'] );
	}

	if ( isset( $cluster['destination_ids'] ) ) {
		$update_data['destination_ids'] = $cluster['destination_ids'];
	}

	if ( isset( $cluster['enable_reviews'] ) ) {
		$update_data['enable_reviews'] = $cluster['enable_reviews'];
	}

	if ( isset( $cluster['reviewer_ids'] ) ) {
		$update_data['reviewer_ids'] = $cluster['reviewer_ids'];
	}

	if ( isset( $cluster['content_conditions'] ) ) {
		$update_data['content_conditions'] = $cluster['content_conditions'];
	}

	$result = $wpdb->update(
		$wpdb->base_prefix . 'contentsync_clusters',
		$update_data,
		array( 'ID' => $cluster['ID'] )
	);

	if ( $result === false ) {
		return false;
	}

	return $cluster['ID'];
}

/**
 * Delete a cluster.
 *
 * @param int $cluster_id
 *
 * @return bool
 */
function delete_cluster( $cluster_id ) {
	global $wpdb;
	return $wpdb->delete( $wpdb->base_prefix . 'contentsync_clusters', array( 'ID' => $cluster_id ) );
}

/**
 * Check if a post is in a cluster.
 *
 * @param int|WP_Post $post_or_post_id
 * @param int|Cluster $cluster_or_cluster_id
 *
 * @return bool
 */
function is_post_in_cluster( $post_or_post_id, $cluster_or_cluster_id ) {

	if ( ! $cluster_or_cluster_id instanceof Cluster ) {
		$cluster = get_cluster_by_id( $cluster_or_cluster_id );
		if ( ! $cluster ) {
			return false;
		}
	} else {
		$cluster = $cluster_or_cluster_id;
	}

	if ( ! $post_or_post_id instanceof WP_Post ) {
		$post = get_post( $post_or_post_id );
		if ( ! $post ) {
			return false;
		}
	} else {
		$post = $post_or_post_id;

		// if blog_id isset, switch to that blog
		if ( isset( $post->blog_id ) ) {
			switch_blog( $post->blog_id );
		}
	}

	$conditions = $cluster->content_conditions; // get_cluster_content_conditions_by_cluster_id( $cluster->ID );

	if ( is_array( $conditions ) && ! empty( $conditions ) ) {
		foreach ( $conditions as $condition ) {
			if ( post_meets_cluster_content_condition( $post, $condition ) ) {
				if ( isset( $post->blog_id ) ) {
					restore_blog();
				}
				return true;
			}
		}
	}
	if ( isset( $post->blog_id ) ) {
		restore_blog();
	}
	return false;
}

/**
 * Get all clusters that have contents with date conditions.
 *
 * @return Cluster[]  All clusters containing contents with date conditions.
 */
function get_clusters_with_date_mode_condition() {

	$clusters = array();

	foreach ( get_clusters() as $cluster ) {
		// check if cluster has time-based condition
		$has_date_condition = false;
		foreach ( $cluster->content_conditions as $condition ) {
			if ( ! empty( $condition->filter ) &&
				is_array( $condition->filter ) &&
				count( $condition->filter ) > 0
			) {
				foreach ( $condition->filter as $filter ) {
					if ( isset( $filter['date_mode'] ) && $filter['date_mode'] !== '' ) {
						$has_date_condition = true;
					}
				}
			}
		}
		if ( ! $has_date_condition ) {
			continue;
		}
		$clusters[ $cluster->ID ] = $cluster;
	}

	return $clusters;
}

/**
 * Get all clusters that include a post
 *
 * @param int|WP_Post $post         The post object or ID.
 * @param string      $with_filter  Filter the clusters by a specific filter, either 'count' or 'date_mode'
 *
 * @return Cluster[]             All clusters containing the post, keyed by cluster ID.
 */
function get_clusters_including_post( $post, $with_filter = null ) {

	// Logger::add( "get_clusters_including_post" );

	if ( ! is_object( $post ) ) {
		$post = get_post( $post );
	}

	if ( ! $post ) {
		return array();
	}

	// if blog_id isset, switch to that blog
	if ( isset( $post->blog_id ) ) {
		switch_blog( $post->blog_id );
	}

	$clusters = array();

	$conditions = get_cluster_content_conditions_including_post( $post, $with_filter );

	if ( ! $conditions ) {
		return array();
	}

	foreach ( $conditions as $condition ) {
		$cluster = get_cluster_by_id( $condition->contentsync_cluster_id );
		if ( $cluster ) {
			$clusters[ $condition->contentsync_cluster_id ] = $cluster;
		}
	}

	if ( isset( $post->blog_id ) ) {
		restore_blog();
	}

	return $clusters;
}

/**
 * Get all clusters that include rules for a post type.
 *
 * @param mixed $post_or_posttype  The post object, post ID or post type.
 *
 * @return Cluster[]             All clusters containing the post type, keyed by cluster ID.
 */
function get_clusters_including_posttype( $post_or_posttype ) {

	if ( is_object( $post_or_posttype ) ) {
		$posttype = $post_or_posttype->post_type;
	} elseif ( is_numeric( $post_or_posttype ) ) {
		$post = get_post( $post_or_posttype );
		if ( ! $post ) {
			return array();
		}
		$posttype = $post->post_type;
	} else {
		$posttype = $post_or_posttype;
	}

	if ( empty( $posttype ) ) {
		return array();
	}

	// if blog_id isset, switch to that blog
	if ( is_object( $post ) && isset( $post->blog_id ) ) {
		switch_blog( $post->blog_id );
	}

	$conditions = get_cluster_content_conditions_including_posttype( $posttype );

	if ( ! $conditions ) {
		return array();
	}

	$clusters = array();
	foreach ( $conditions as $condition ) {
		$cluster = get_cluster_by_id( $condition->contentsync_cluster_id );
		if ( $cluster ) {
			$clusters[ $condition->contentsync_cluster_id ] = $cluster;
		}
	}

	if ( is_object( $post ) && isset( $post->blog_id ) ) {
		restore_blog();
	}

	return $clusters;
}

/**
 * Get all posts inside a cluster.
 *
 * @param int|Cluster $cluster_or_cluster_id
 *
 * @return array        All WP_Post objects (+ @property int blog_id) keyed by blog_id.
 * @example
 *    array(
 *    1 => array(
 *      123 => WP_Post,
 *      456 => WP_Post,
 *    ),
 *    2 => array(
 *      789 => WP_Post,
 *    ),
 *  )
 */
function get_cluster_posts_per_blog( $cluster_or_cluster_id ) {

	Logger::add( 'get_cluster_posts_per_blog' );

	if ( ! $cluster_or_cluster_id instanceof Cluster ) {
		if ( is_array( $cluster_or_cluster_id ) ) {
			$cluster = new Cluster( (object) $cluster_or_cluster_id );
		} else {
			$cluster = get_cluster_by_id( $cluster_or_cluster_id );
		}
		if ( ! $cluster ) {
			return false;
		}
	} else {
		$cluster = $cluster_or_cluster_id;
	}

	$posts_per_blog = array();

	foreach ( $cluster->content_conditions as $condition ) {
		$condition_posts = get_posts_by_cluster_content_condition( $condition );
		if ( ! empty( $condition_posts ) ) {
			if ( ! isset( $posts_per_blog[ $condition->blog_id ] ) ) {
				$posts_per_blog[ $condition->blog_id ] = $condition_posts;
			} else {
				$posts_per_blog[ $condition->blog_id ] = array_merge( $posts_per_blog[ $condition->blog_id ], $condition_posts );
			}
		}
	}

	return $posts_per_blog;
}

/**
 * Start the scheduler for date conditions.
 * If no cluster has date conditions, the scheduler will stop.
 *
 * Called from the save function of any cluster edit screen.
 */
function schedule_cluster_date_check() {

	// save cluster posts next schedule
	$clusters_with_date_condition_before = array();
	foreach ( get_clusters_with_date_mode_condition() as $cluster_id => $cluster ) {
		$clusters_with_date_condition_before[ $cluster_id ] = array(
			'posts' => get_cluster_posts_per_blog( $cluster_id ),
		);
	}

	if ( empty( $clusters_with_date_condition_before ) ) {
		// Logger::add( "No clusters with date conditions." );
		return;
	}

	if ( wp_next_scheduled( 'cron_action_check_cluster', array( $clusters_with_date_condition_before ) ) ) {
		// Logger::add( "Check already scheduled." );
		return;
	}

	// schedule single event at 1:00 am tomorrow
	$timestamp = strtotime( 'tomorrow 1:00' );
	$result    = wp_schedule_single_event(
		$timestamp,
		'cron_action_check_cluster',
		array( $clusters_with_date_condition_before )
	);

	if ( is_wp_error( $result ) ) {
		// Logger::add('scheduling failed.');
	} else {
		// Logger::add('check scheduled.');
	}
}

/**
 * Scheduler action - syncs cluster posts and re-schedules.
 *
 * If posts are synced based on date conditions, we see if the post ids have changed.
 * If they have, we distribute the posts again.
 *
 * @action cron_action_check_cluster
 */
function check_clusters_on_date_change( $clusters_with_date_condition_before ) {

	$clusters_with_date_condition = array();
	foreach ( get_clusters_with_date_mode_condition() as $cluster_id => $cluster ) {
		$clusters_with_date_condition[ $cluster_id ] = array(
			'posts' => get_cluster_posts_per_blog( $cluster_id ),
		);
	}

	if ( ! empty( $clusters_with_date_condition ) ||
		! empty( $clusters_with_date_condition_before )
	) {
		$cluster_ids_to_be_synced = array_merge(
			array_keys( $clusters_with_date_condition ),
			array_keys( $clusters_with_date_condition_before )
		);

		foreach ( $cluster_ids_to_be_synced as $cluster_id ) {
			if ( ! isset( $clusters_with_date_condition[ $cluster_id ] ) ) {
				continue;
			}

			$before = isset( $clusters_with_date_condition_before[ $cluster_id ] ) ? $clusters_with_date_condition_before[ $cluster_id ] : array();

			// compare post_ids
			$flattened_post_ids = array();
			foreach ( $clusters_with_date_condition[ $cluster_id ]['posts'] as $blog_id => $posts ) {
				foreach ( $posts as $post_id => $post ) {
					$flattened_post_ids[] = $blog_id . '-' . $post_id;
				}
			}
			sort( $flattened_post_ids );

			// ... with post_ids before
			$flattened_post_ids_before = array();
			foreach ( $before['posts'] as $blog_id => $posts ) {
				foreach ( $posts as $post_id => $post ) {
					$flattened_post_ids_before[] = $blog_id . '-' . $post_id;
				}
			}
			sort( $flattened_post_ids_before );

			// -> if post_ids are different, distribute posts
			if ( $flattened_post_ids !== $flattened_post_ids_before ) {
				\Contentsync\distribute_cluster_posts( $cluster_id, $before );
			}
		}
	}

	// Logger::add("re-schedule");
	schedule_cluster_date_check();
}
add_action( 'cron_action_check_cluster', 'check_clusters_on_date_change', 10, 1 );
