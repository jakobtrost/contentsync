<?php
/**
 * Post review helper functions.
 *
 * This file defines a set of helper functions used to retrieve
 * `\Contentsync\Reviews\Post_Review` objects by ID, by post and blog combination, or to
 * retrieve all reviews for a specific post. These functions provide
 * convenient accessors around the underlying database queries and
 * object construction performed by the `\Contentsync\Reviews\Post_Review` class. Use
 * them from your templates or controllers to interact with post review
 * data without directly writing SQL.
 *
 * @since 2.17.0
 */
namespace Contentsync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/reviews/class-post-review.php';
require_once __DIR__ . '/reviews/class-post-review-message.php';

/**
 * Get a \Contentsync\Reviews\Post_Review object by ID
 *
 * @param int $post_review_id
 *
 * @return \Contentsync\Reviews\Post_Review|false
 */
function get_synced_post_review_by_id( $post_review_id ) {
	return \Contentsync\Reviews\Post_Review::get_instance( $post_review_id );
}

/**
 * Get a \Contentsync\Reviews\Post_Review object by post ID and blog ID
 *
 * @param int $post_id
 *
 * @return \Contentsync\Reviews\Post_Review|false
 */
function get_synced_post_review_by_post( $post_id, $blog_id, $state = null ) {
	global $wpdb;

	$post_id = (int) $post_id;
	$blog_id = (int) $blog_id;
	if ( ! $post_id || ! $blog_id ) {
		return false;
	}

	if ( empty( $state ) ) {
		$state = array( 'new', 'in_review', 'denied' );
	} elseif ( ! is_array( $state ) ) {
		$state = array( $state );
	}

	$table_name   = $wpdb->base_prefix . 'synced_post_reviews';
	$_post_review = $wpdb->get_row( "SELECT * FROM $table_name WHERE post_id = $post_id AND blog_id = $blog_id AND state IN ('" . implode( "','", $state ) . "') ORDER BY date DESC" );

	if ( ! $_post_review ) {
		return false;
	}

	return new \Contentsync\Reviews\Post_Review( $_post_review );
}

/**
 * Get all \Contentsync\Reviews\Post_Review objects by post ID and blog ID
 *
 * @param int $post_id
 *
 * @return \Contentsync\Reviews\Post_Review[]
 */
function get_contentsync_all_post_reviews_by_post( $post_id, $blog_id, $state = null ) {
	global $wpdb;

	$post_id = (int) $post_id;
	$blog_id = (int) $blog_id;
	if ( ! $post_id || ! $blog_id ) {
		return false;
	}

	if ( empty( $state ) ) {
		$state = array( 'new', 'in_review', 'denied', 'approved', 'reverted' );
	} elseif ( ! is_array( $state ) ) {
		$state = array( $state );
	}

	$table_name   = $wpdb->base_prefix . 'synced_post_reviews';
	$post_reviews = $wpdb->get_results( "SELECT * FROM $table_name WHERE post_id = $post_id AND blog_id = $blog_id AND state IN ('" . implode( "','", $state ) . "') ORDER BY date DESC" );

	if ( ! $post_reviews ) {
		// return empty array to render empty table
		return array();
	}

	$post_review_objects = array();
	foreach ( $post_reviews as $post_review ) {
		$post_review_objects[] = new \Contentsync\Reviews\Post_Review( $post_review );
	}

	return $post_review_objects;
}

/**
 * Get all \Contentsync\Reviews\Post_Reviews depending on the state
 *
 * @param string|array $state
 *
 * @return \Contentsync\Reviews\Post_Review[]
 */
function get_synced_post_reviews( $state = null ) {
	global $wpdb;

	if ( empty( $state ) ) {
		$state = array( 'new', 'in_review', 'denied' );
	} elseif ( ! is_array( $state ) ) {
		$state = array( $state );
	}

	$table_name   = $wpdb->base_prefix . 'synced_post_reviews';
	$post_reviews = $wpdb->get_results( "SELECT * FROM $table_name WHERE state IN ('" . implode( "','", $state ) . "') ORDER BY date DESC" );

	if ( ! $post_reviews ) {
		// return empty array to render empty table
		return array();
	}

	$post_review_objects = array();
	foreach ( $post_reviews as $post_review ) {
		$post_review_objects[] = new \Contentsync\Reviews\Post_Review( $post_review );
	}

	return $post_review_objects;
}

/**
 * Insert a new \Contentsync\Reviews\Post_Review
 *
 * @param array $post_review
 *
 * @return int|false
 */
function insert_synced_post_review( $post_review ) {
	global $wpdb;

	if ( ! is_array( $post_review ) ) {
		return false;
	}

	$wpdb->insert(
		$wpdb->base_prefix . 'synced_post_reviews',
		array(
			'blog_id'       => $post_review['blog_id'],
			'post_id'       => $post_review['post_id'],
			'editor'        => $post_review['editor'],
			'date'          => $post_review['date'],
			'state'         => $post_review['state'],
			'previous_post' => serialize( $post_review['previous_post'] ),
		)
	);

	return $wpdb->insert_id;
}

/**
 * Update a \Contentsync\Reviews\Post_Review
 *
 * @param int   $post_review_id
 * @param array $post_review
 *
 * @return int|false
 */
function update_synced_post_review( $post_review_id, $post_review ) {
	global $wpdb;

	$post_review_id = (int) $post_review_id;
	if ( ! $post_review_id ) {
		return false;
	}

	if ( ! is_array( $post_review ) ) {
		return false;
	}

	$wpdb->update(
		$wpdb->base_prefix . 'synced_post_reviews',
		array(
			'editor' => $post_review['editor'],
			'date'   => $post_review['date'],
			'state'  => $post_review['state'],
		),
		array( 'ID' => $post_review_id )
	);

	return $post_review_id;
}

/**
 * Delete a \Contentsync\Reviews\Post_Review
 *
 * @param int $post_review_id
 *
 * @return int|false
 */
function delete_synced_post_review( $post_review_id ) {
	global $wpdb;

	if ( ! $post_review_id ) {
		return false;
	}
	$wpdb->delete( $wpdb->base_prefix . 'synced_post_reviews', array( 'ID' => $post_review_id ) );

	return $post_review_id;
}

/**
 * Set the state of a \Contentsync\Reviews\Post_Review
 *
 * @param int    $post_review_id
 * @param string $state
 *
 * @return int|false
 */
function set_synced_post_review_state( $post_review_id, $state ) {

	// TODO: escape variables properly?
	$post_review = get_synced_post_review_by_id( $post_review_id );

	$update = array(
		'editor' => $post_review->editor,
		'date'   => date( 'Y-m-d H:i:s', time() ),
		'state'  => $state,
	);

	update_synced_post_review( $post_review_id, $update );

	return $post_review_id;
}

/**
 * Add a message to a \Contentsync\Reviews\Post_Review
 *
 * @param int    $post_review_id
 * @param string $message
 *
 * @return int|false
 */
function get_messages_by_synced_post_review_id( $post_review_id ) {
	global $wpdb;

	$post_review_id = (int) $post_review_id;
	if ( ! $post_review_id ) {
		return false;
	}

	$table_name = $wpdb->base_prefix . 'synced_post_reviews';
	$messages   = $wpdb->get_var( "SELECT messages FROM $table_name WHERE ID = $post_review_id" );

	if ( ! $messages ) {
		return false;
	}

	// unserialize
	$messages = unserialize( $messages );

	// loop through the messages and create \Contentsync\Reviews\Post_Review_Message objects
	$messages = array_map(
		function ( $message ) use ( $post_review_id ) {
			return new \Contentsync\Reviews\Post_Review_Message( $post_review_id, $message );
		},
		$messages
	);

	return $messages;
}

/**
 * Get the latest message of a \Contentsync\Reviews\Post_Review
 *
 * @param int $post_review_id
 *
 * @return \Contentsync\Reviews\Post_Review_Message|false
 */
function get_latest_message_by_synced_post_review_id( $post_review_id ) {
	$messages = get_messages_by_synced_post_review_id( $post_review_id );

	if ( ! $messages && ! is_array( $messages ) ) {
		return false;
	}

	$latest_message = end( $messages );

	// if no object of synced_post_review_message, create one
	if ( ! $latest_message instanceof \Contentsync\Reviews\Post_Review_Message ) {
		$latest_message = new \Contentsync\Reviews\Post_Review_Message( $post_review_id, $latest_message );
	}

	return $latest_message;
}

/**
 * Before we distribute posts, we need to replace all posts that have not
 * been reviewed yet with the previous version.
 *
 * @see Distributor::prepare_posts_for_distribution()
 * @filter contentsync_prepared_posts_for_distribution
 *
 * @param Prepared_Post[] $prepared_posts  Array of prepared posts.
 * @param int[]           $post_ids         Array of post IDs.
 * @param array           $export_args      Export arguments.
 *
 * @return Prepared_Post[]
 */
function replace_posts_with_previous_version_before_distribution( $prepared_posts, $post_ids, $export_args ) {

	Logger::add( 'replace_posts_with_previous_version_before_distribution' );
	// Logger::add( 'prepared_posts', $prepared_posts );
	// Logger::add( 'post_ids', $post_ids );
	// Logger::add( 'export_args', $export_args );

	foreach ( $prepared_posts as $key => $post ) {

		$post_review = get_synced_post_review_by_post( $post->ID, get_current_blog_id(), array( 'new', 'in_review' ) );
		// Logger::add( 'post_review', $post_review );

		if ( $post_review && $post_review->previous_post ) {

			// if post_status is 'auto-draft', the post has not existed yet, therefore it needs to be removed from the array
			if ( $post_review->previous_post->post_status === 'auto-draft' ) {
				unset( $prepared_posts[ $key ] );
				continue;
			}

			$prepared_posts[ $key ] = $post_review->previous_post;
		}
	}

	return $prepared_posts;
}

add_filter( 'contentsync_prepared_posts_for_distribution', 'replace_posts_with_previous_version_before_distribution', 10, 3 );
