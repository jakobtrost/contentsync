<?php
/**
 * Content condition helper functions.
 *
 * This file contains procedural helper functions for working with
 * `Cluster_Content_Condition` objects, which represent rules for selecting
 * posts to include in clusters or to export automatically. The
 * functions allow you to retrieve conditions by ID, list all
 * conditions, fetch conditions for a given cluster or blog, insert
 * new conditions and update existing ones. They abstract away the
 * underlying database interactions so that other parts of the plugin
 * can work with content conditions more conveniently.
 *
 * @since 2.17.0
 */
namespace Contentsync;

use Contentsync\Main_Helper;
use Contentsync\Distribution\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get a content condition by its ID
 *
 * @param int $content_condition_id
 *
 * @return Cluster_Content_Condition|false
 */
function get_cluster_content_condition_by_id( $content_condition_id ) {
	return Cluster_Content_Condition::get_instance( $content_condition_id );
}

/**
 * Get all content conditions
 *
 * @return Cluster_Content_Condition[]|false
 */
function get_cluster_content_conditions() {
	global $wpdb;

	$table_name         = $wpdb->base_prefix . 'cluster_content_conditions';
	$content_conditions = $wpdb->get_results( "SELECT * FROM $table_name" );

	if ( ! $content_conditions ) {
		return false;
	}

	$content_condition_objects = array();
	foreach ( $content_conditions as $content_condition ) {
		$content_condition_objects[] = new Cluster_Content_Condition( $content_condition );
	}

	return $content_condition_objects;
}

/**
 * Get all content conditions by cluster ID
 *
 * @param int $cluster_id
 *
 * @return Cluster_Content_Condition[]|false
 */
function get_cluster_content_conditions_by_cluster_id( $cluster_id ) {
	global $wpdb;

	$table_name         = $wpdb->base_prefix . 'cluster_content_conditions';
	$content_conditions = $wpdb->get_results( "SELECT * FROM $table_name WHERE contentsync_cluster_id = $cluster_id" );

	if ( ! $content_conditions ) {
		return false;
	}

	$content_condition_objects = array();
	foreach ( $content_conditions as $content_condition ) {
		$content_condition_objects[] = new Cluster_Content_Condition( $content_condition );
	}

	return $content_condition_objects;
}

/**
 * Get all content conditions by blog ID
 *
 * @param int $blog_id
 *
 * @return Cluster_Content_Condition[]|false
 */
function insert_cluster_content_condition( $content_condition ) {
	global $wpdb;

	if ( ! is_array( $content_condition ) ) {
		return false;
	}

	$wpdb->insert(
		$wpdb->base_prefix . 'cluster_content_conditions',
		array(
			'contentsync_cluster_id'          => $content_condition['contentsync_cluster_id'],
			'blog_id'                         => $content_condition['blog_id'],
			'post_type'                       => $content_condition['post_type'],
			'filter'                          => isset( $content_condition['filter'] ) ? serialize( $content_condition['filter'] ) : '',
			// 'title' => $content_condition['title'],
			'make_posts_global_automatically' => isset( $content_condition['make_posts_global_automatically'] ) && $content_condition['make_posts_global_automatically'] == 'true' ? true : false,
			'taxonomy'                        => isset( $content_condition['taxonomy'] ) ? $content_condition['taxonomy'] : '',
			'terms'                           => isset( $content_condition['terms'] ) ? serialize( $content_condition['terms'] ) : '',
			'export_arguments'                => isset( $content_condition['export_arguments'] ) ? serialize( $content_condition['export_arguments'] ) : '',
		)
	);

	return $wpdb->insert_id;
}

/**
 * Update a content condition
 *
 * @param Cluster_Content_Condition $content_condition
 *
 * @return int|false
 */
function update_cluster_content_condition( $content_condition ) {
	global $wpdb;

	if ( ! isset( $content_condition['ID'] ) ) {
		return false;
	}

	$update_data = array();

	if ( isset( $content_condition['contentsync_cluster_id'] ) ) {
		$update_data['contentsync_cluster_id'] = $content_condition['contentsync_cluster_id'];
	}

	if ( isset( $content_condition['blog_id'] ) ) {
		$update_data['blog_id'] = $content_condition['blog_id'];
	}

	if ( isset( $content_condition['post_type'] ) ) {
		$update_data['post_type'] = $content_condition['post_type'];
	}

	if ( isset( $content_condition['filter'] ) ) {
		$update_data['filter'] = serialize( $content_condition['filter'] );
	}

	// if ( isset( $content_condition['title'] ) ) {
	// $update_data['title'] = $content_condition['title'];
	// }

	if ( isset( $content_condition['make_posts_global_automatically'] ) ) {
		if ( $content_condition['make_posts_global_automatically'] === 'true' ) {
			$content_condition['make_posts_global_automatically'] = 1;
		} elseif ( $content_condition['make_posts_global_automatically'] === 'false' ) {
			$content_condition['make_posts_global_automatically'] = 0;
		}
		$update_data['make_posts_global_automatically'] = $content_condition['make_posts_global_automatically'];
	}

	if ( isset( $content_condition['taxonomy'] ) ) {
		$update_data['taxonomy'] = $content_condition['taxonomy'];
	}

	if ( isset( $content_condition['terms'] ) ) {
		$update_data['terms'] = serialize( $content_condition['terms'] );
	}

	if ( isset( $content_condition['export_arguments'] ) ) {

		foreach ( $content_condition['export_arguments'] as $key => $value ) {
			if ( $value === 'true' || $value === 'on' ) {
				$content_condition['export_arguments'][ $key ] = true;
			} elseif ( $value === 'false' || $value === 'off' ) {
				$content_condition['export_arguments'][ $key ] = false;
			}
		}

		$update_data['export_arguments'] = serialize( $content_condition['export_arguments'] );
	}

	$result = $wpdb->update(
		$wpdb->base_prefix . 'cluster_content_conditions',
		$update_data,
		array( 'ID' => $content_condition['ID'] )
	);

	if ( $result === false ) {
		return false;
	}

	return $content_condition['ID'];
}

/**
 * Delete a content condition
 *
 * @param int $content_condition_id
 *
 * @return int|false
 */
function delete_cluster_content_condition( $content_condition_id ) {
	global $wpdb;

	if ( ! $content_condition_id ) {
		return false;
	}
	$wpdb->delete( $wpdb->base_prefix . 'cluster_content_conditions', array( 'ID' => $content_condition_id ) );

	return $content_condition_id;
}

/**
 * Get all posts inside a cluster by condition.
 *
 * @param Cluster_Content_Condition $condition  Content condition object.
 *
 * @return array        All WP_Post objects (+ @property int blog_id) keyed by post_id.
 */
function get_posts_by_cluster_content_condition( $condition ) {

	// Logger::add( "get_posts_by_cluster_content_condition" );
	// Logger::add( $condition );

	$posts = array();

	$blog_id = get_current_blog_id();
	if ( $blog_id != $condition->blog_id ) {
		Main_Helper::switch_to_blog( $condition->blog_id );
	}

	// export arguments
	$export_arguments = isset( $condition->export_arguments ) ? wp_parse_args(
		$condition->export_arguments ? (array) $condition->export_arguments : array(),
		array(
			'append_nested'  => false,
			'whole_posttype' => false,
			'all_terms'      => false,
			'resolve_menus'  => false,
			'translations'   => false,
		)
	) : array();

	$query_args = get_query_args_for_cluster_content_condition( $condition );
	// Logger::add( "Query args:", $query_args );

	$posts = Main_Helper::get_posts( $query_args );
	// Logger::add( "Posts:", $posts );

	$cluster_posts = array();

	foreach ( $posts as $post ) {
		$post->blog_id          = $condition->blog_id;
		$post->export_arguments = $export_arguments;

		$cluster_posts[ $post->ID ] = $post;
	}

	if ( $blog_id != $condition->blog_id ) {
		Main_Helper::restore_blog();
	}

	return $cluster_posts;
}

/**
 * Check if a post meets a content condition
 *
 * @param WP_Post|int                   $post_or_post_id
 * @param Cluster_Content_Condition|int $condition_or_condition_id
 *
 * @return bool
 */
function post_meets_cluster_content_condition( $post_or_post_id, $condition_or_condition_id ) {

	// error_log( "post_meets_cluster_content_condition" );

	if ( ! $condition_or_condition_id instanceof Cluster_Content_Condition ) {
		$condition = get_cluster_content_condition_by_id( $condition_or_condition_id );
		if ( ! $condition ) {
			return false;
		}
	} else {
		$condition = $condition_or_condition_id;
	}

	// check if blog_id matches
	if ( $condition->blog_id != get_current_blog_id() ) {
		return false;
	}

	// get post id
	$post_id = 0;
	if ( is_object( $post_or_post_id ) && isset( $post_or_post_id->ID ) ) {
		$post_id = $post_or_post_id->ID;
	} elseif ( is_numeric( $post_or_post_id ) ) {
		$post_id = intval( $post_or_post_id );
	} else {
		return false;
	}

	$query_args = get_query_args_for_cluster_content_condition( $condition );

	// if posts_per_page is set, we cannot add the post__in parameter
	// because it automatically include the post even if it does not
	// match the condition, eg. because it is in the first 3 posts...
	if ( ! isset( $query_args['posts_per_page'] ) ) {
		$query_args['post__in'] = array( $post_id );
	}

	$posts = \Contentsync\Main_Helper::get_posts( $query_args );

	// ... so we have to check manually
	if ( ! isset( $query_args['post__in'] ) ) {

		$post_is_in_results = false;

		// check if post is in the result set
		foreach ( $posts as $post ) {
			if ( $post->ID == $post_id ) {
				$post_is_in_results = true;
				break;
			}
		}

		return $post_is_in_results;
	}

	if ( empty( $posts ) ) {
		return false;
	}

	return true;
}

/**
 * Get query args for a content condition.
 *
 * @param Cluster_Content_Condition $condition  Content condition object.
 */
function get_query_args_for_cluster_content_condition( $condition ) {

	if ( ! is_object( $condition ) ) {
		return false;
	}

	$query_args = array(
		'post_type'        => $condition->post_type,
		'numberposts'      => -1,
		'post_status'      => 'publish',
		'suppress_filters' => true,
		'lang'             => '',
	);

	if ( $condition->post_type == 'attachment' ) {
		$query_args['post_status'] = 'inherit';
	}

	/**
	 * filter by taxonomy
	 */
	if ( ! empty( $condition->terms ) ) {
		$query_args['tax_query'] = array(
			array(
				'taxonomy' => $condition->taxonomy,
				'field'    => 'slug',
				'terms'    => explode( ',', $condition->terms ),
			),
		);
	}

	/**
	 * filter by global status
	 */
	if ( ! $condition->make_posts_global_automatically ) {
		$query_args['meta_query'] = array(
			array(
				'key'     => 'synced_post_status',
				'value'   => 'root',
				'compare' => '=',
			),
		);
	}

	/**
	 * filter by date
	 */
	if ( ! empty( $condition->filter ) ) {
		foreach ( (array) $condition->filter as $filter ) {

			// restrict number of posts
			if ( isset( $filter['count'] ) && ! empty( $filter['count'] ) ) {
				$query_args['posts_per_page'] = $filter['count'];
			}

			// restrict by date
			if ( isset( $filter['date_mode'] ) && ! empty( $filter['date_mode'] ) ) {
				if ( $filter['date_mode'] === 'static' ) {
					$date_since = strtotime( $filter['date_value'] );

					$query_args['date_query'] = array(
						array(
							'after' => array(
								'year'  => date( 'Y', $date_since ),
								'month' => date( 'm', $date_since ),
								'day'   => date( 'd', $date_since ),
							),
						),
					);
				} elseif ( $filter['date_mode'] === 'static_range' ) {
					$date_from = strtotime( $filter['date_value_from'] );
					$date_to   = strtotime( $filter['date_value_to'] );

					$query_args['date_query'] = array(
						array(
							'after'     => array(
								'year'  => date( 'Y', $date_from ),
								'month' => date( 'm', $date_from ),
								'day'   => date( 'd', $date_from ),
							),
							'before'    => array(
								'year'  => date( 'Y', $date_to ),
								'month' => date( 'm', $date_to ),
								'day'   => date( 'd', $date_to ),
							),
							'inclusive' => true,
						),
					);
				} elseif ( $filter['date_mode'] === 'dynamic' ) {

					// days|months|years
					$type = $filter['date_since'];

					// number of days|months|years to go back from today
					$count_back = $filter['date_since_value'];

					// get the date to go back to. Logic is: '- 1 days ago' etc.
					$date_phrase = $type == 'days' ? '-' . ( $count_back + 1 ) . ' ' . $type : '-' . $count_back . ' ' . $type . ' - 1 day';
					$date_since  = strtotime( $date_phrase );

					// error_log( "date_phrase: ".$date_phrase );
					// error_log( "date_since: ".date( 'Y-m-d', $date_since ) );

					$query_args['date_query'] = array(
						array(
							'after' => array(
								'year'  => date( 'Y', $date_since ),
								'month' => date( 'm', $date_since ),
								'day'   => date( 'd', $date_since ),
							),
						),
					);
				}
			}
		}
	}

	return $query_args;
}

/**
 * Get all content conditions for a post type
 *
 * @param string $post_type
 *
 * @return Cluster_Content_Condition[]|false
 */
function get_cluster_content_conditions_including_posttype( $post_type ) {
	$conditions = Cluster_Content_Condition::get_conditions_by(
		array(
			'blog_id'   => get_current_blog_id(),
			'post_type' => $post_type,
		)
	);

	return $conditions;
}

/**
 * Get all content conditions that match a post
 *
 * @param WP_Post|int $post
 * @param string      $with_filter    Either 'count' or 'date_mode'
 *
 * @return Cluster_Content_Condition[]|false
 */
function get_cluster_content_conditions_including_post( $post, $with_filter = null ) {

	// error_log( "get_cluster_content_conditions_including_post" );

	if ( ! is_object( $post ) ) {
		$post = get_post( $post );
	}

	if ( ! $post ) {
		return false;
	}

	// first, we filter by blog_id and post_type
	$conditions_that_match_blog_and_posttype = Cluster_Content_Condition::get_conditions_by(
		array(
			'blog_id'   => get_current_blog_id(),
			'post_type' => $post->post_type,
		)
	);

	if ( ! $conditions_that_match_blog_and_posttype ) {
		return false;
	}

	// so some conditions match the blog and post type, we will check if these conditions match the post
	$conditions_matching_post = array();
	foreach ( $conditions_that_match_blog_and_posttype as $condition ) {
		if ( ! empty( $with_filter ) ) {

			// check if one of the filters as specified in $with_filter matches
			$match = false;
			foreach ( (array) $condition->filter as $filter ) {
				if ( isset( $filter[ $with_filter ] ) && ! empty( $filter[ $with_filter ] ) ) {
					$match = true;
					break;
				}
			}

			// if we have no match, we skip this condition
			if ( ! $match ) {
				continue;
			}
		}

		if ( post_meets_cluster_content_condition( $post, $condition ) ) {
			$conditions_matching_post[ $condition->ID ] = $condition;
		}
	}

	return $conditions_matching_post;
}
