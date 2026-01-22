<?php
/**
 * Cluster service helper class.
 *
 * This class provides static helper methods that operate on clusters
 * within the Content Sync plugin. They provide simple wrappers for
 * retrieving clusters by ID, fetching all clusters or clusters by
 * destination ID, and performing operations on cluster objects. These
 * methods complement the `Cluster` class and are intended to be used
 * from templates or other procedural code without needing to
 * instantiate classes directly.
 */
namespace Contentsync\Cluster;

use Contentsync\Distribution\Distributor;
use Contentsync\Utils\Multisite_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Cluster Service
 */
final class Cluster_Service {

	/**
	 * Get a cluster by ID.
	 *
	 * @param int $cluster_id
	 *
	 * @return Cluster|false
	 */
	public static function get_cluster_by_id( $cluster_id ) {
		return Cluster::get_instance( $cluster_id );
	}

	/**
	 * Get all clusters.
	 *
	 * @return array
	 */
	public static function get_clusters() {

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
	 * Insert a new cluster.
	 *
	 * @param array $cluster
	 *
	 * @return bool|int
	 */
	public static function insert_cluster( $cluster ) {
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
	public static function update_cluster( $cluster ) {
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
	public static function delete_cluster( $cluster_id ) {
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
	public static function is_post_in_cluster( $post_or_post_id, $cluster_or_cluster_id ) {

		if ( ! $cluster_or_cluster_id instanceof Cluster ) {
			$cluster = self::get_cluster_by_id( $cluster_or_cluster_id );
			if ( ! $cluster ) {
				return false;
			}
		} else {
			$cluster = $cluster_or_cluster_id;
		}

		if ( ! $post_or_post_id instanceof \WP_Post ) {
			$post = get_post( $post_or_post_id );
			if ( ! $post ) {
				return false;
			}
		} else {
			$post = $post_or_post_id;

			// if blog_id isset, switch to that blog
			if ( isset( $post->blog_id ) ) {
				Multisite_Manager::switch_blog( $post->blog_id );
			}
		}

		$conditions = $cluster->content_conditions; // get_cluster_content_conditions_by_cluster_id( $cluster->ID );

		if ( is_array( $conditions ) && ! empty( $conditions ) ) {
			foreach ( $conditions as $condition ) {
				if ( Content_Condition_Service::post_meets_cluster_content_condition( $post, $condition ) ) {
					if ( isset( $post->blog_id ) ) {
						Multisite_Manager::restore_blog();
					}
					return true;
				}
			}
		}
		if ( isset( $post->blog_id ) ) {
			Multisite_Manager::restore_blog();
		}
		return false;
	}

	/**
	 * Get all clusters that have contents with date conditions.
	 *
	 * @return Cluster[]  All clusters containing contents with date conditions.
	 */
	public static function get_clusters_with_date_mode_condition() {

		$clusters = array();

		foreach ( self::get_clusters() as $cluster ) {
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
	public static function get_clusters_including_post( $post, $with_filter = null ) {

		// Logger::add( "get_clusters_including_post" );

		if ( ! is_object( $post ) ) {
			$post = get_post( $post );
		}

		if ( ! $post ) {
			return array();
		}

		// if blog_id isset, switch to that blog
		if ( isset( $post->blog_id ) ) {
			Multisite_Manager::switch_blog( $post->blog_id );
		}

		$clusters = array();

		$conditions = Content_Condition_Service::get_cluster_content_conditions_including_post( $post, $with_filter );

		if ( ! $conditions ) {
			return array();
		}

		foreach ( $conditions as $condition ) {
			$cluster = self::get_cluster_by_id( $condition->contentsync_cluster_id );
			if ( $cluster ) {
				$clusters[ $condition->contentsync_cluster_id ] = $cluster;
			}
		}

		if ( isset( $post->blog_id ) ) {
			Multisite_Manager::restore_blog();
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
	public static function get_cluster_posts_per_blog( $cluster_or_cluster_id ) {

		// Logger::add( 'get_cluster_posts_per_blog' );

		if ( ! $cluster_or_cluster_id instanceof Cluster ) {
			if ( is_array( $cluster_or_cluster_id ) ) {
				$cluster = new Cluster( (object) $cluster_or_cluster_id );
			} else {
				$cluster = self::get_cluster_by_id( $cluster_or_cluster_id );
			}
			if ( ! $cluster ) {
				return false;
			}
		} else {
			$cluster = $cluster_or_cluster_id;
		}

		$posts_per_blog = array();

		foreach ( $cluster->content_conditions as $condition ) {
			$condition_posts = Content_Condition_Service::get_posts_by_cluster_content_condition( $condition );
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
	public static function schedule_cluster_date_check() {

		// save cluster posts next schedule
		$clusters_with_date_condition_before = array();
		foreach ( self::get_clusters_with_date_mode_condition() as $cluster_id => $cluster ) {
			$clusters_with_date_condition_before[ $cluster_id ] = array(
				'posts' => self::get_cluster_posts_per_blog( $cluster_id ),
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
}
