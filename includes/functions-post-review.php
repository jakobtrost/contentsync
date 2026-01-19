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
 * =================================================================
 *                          USER ACTIONS
 * =================================================================
 */

/**
 * Create a post review or update an existing one.
 *
 * @param int     $post_id     Post ID.
 * @param WP_Post $post_before Post object before the update.
 *
 * @return void
 */
function create_post_review( $post_id, $post_before = null ) {

	$send_mail = true;

	// check if the post has been distributed yet
	$post_connection_map = Main_Helper::get_post_connection_map( $post_id );
	$state               = empty( $post_connection_map ) ? 'new' : 'in_review';

	// is there an active review?
	$post_review = get_post_review_by_post( $post_id, get_current_blog_id() );

	if ( $post_review ) {

		// keep the status 'new', otherwise set it to 'in_review'
		$state = $post_review->state === 'new' ? 'new' : 'in_review';

		// only send mail if the state has changed
		if ( $state == $post_review->state ) {
			$send_mail = false;
		}

		$update = array(
			'editor' => get_current_user_id(),
			'date'   => date( 'Y-m-d H:i:s', time() ),
			'state'  => $state,
		);

		$review_id = update_post_review( $post_review->ID, $update );

	} else {

		$post_before_id     = $post_before ? $post_before->ID : $post_id;
		$post_before_export = Main_Helper::export_post( $post_before_id, array() );
		if ( $post_before ) {
			// loop through all keys of the $post_before object and compare them with the export
			foreach ( $post_before as $key => $value ) {
				if ( isset( $post_before_export->$key ) && $value !== $post_before_export->$key ) {
					$post_before_export->$key = $value;
				}
			}
		}
		if ( ! empty( $post_connection_map ) ) {
			// add contentsync_connection_map meta to handle deleted post
			$post_before_export->meta['contentsync_connection_map'] = $post_connection_map;
		}

		$insert = array(
			'blog_id'       => get_current_blog_id(),
			'post_id'       => $post_id,
			'editor'        => get_current_user_id(),
			'date'          => date( 'Y-m-d H:i:s', time() ),
			'state'         => $state,
			'previous_post' => $post_before_export,
		);

		$review_id = insert_post_review( $insert );
	}

	if ( $send_mail ) {
		\contentsync\mails\send_review_mail( $review_id, $state, 'reviewers' );
	}
}

/**
 * Approve a post review.
 *
 * @param int $review_id Review ID.
 * @param int $post_id   Post ID.
 *
 * @return bool
 */
function approve_post_review( $review_id, $post_id = null ) {

	if ( ! $review_id ) {
		return false;
	}

	$post_review = get_post_review_by_id( $review_id );
	if ( in_array( $post_review->state, array( 'denied', 'approved', 'reverted' ) ) ) {
		// review already finished
		return false;
	}

	if ( ! $post_id ) {
		$post_id = $post_review->post_id;
	}

	$post = get_post( $post_id );

	$previous_state = $post_review->state;

	// set the review state to 'approved' before the post is distributed
	// otherwise the distributor will use the previous post as post is
	// still technicaly in review
	set_post_review_state( $review_id, 'approved' );

	if ( ! $post ) {
		$result = Trigger::on_delete_global_post( $post_review->previous_post );
	} elseif ( $post->post_status == 'trash' ) {
		$result = Trigger::on_trash_global_post( $post_review->previous_post );
	} elseif ( $post_review->previous_post->post_status == 'trash' ) {
		$result = Trigger::on_untrash_global_post( $post_id );
	} else {
		$destination_ids = array();
		foreach ( get_clusters_including_post( $post ) as $cluster ) {
			$destination_ids = array_merge( $destination_ids, $cluster->destination_ids );
		}

		// distribute the post
		$result = \Contentsync\distribute_single_post( $post_id, $destination_ids );
	}

	if ( ! $result ) {
		// revert the review state to the previous state
		set_post_review_state( $review_id, $previous_state );
		return false;
	}

	$new_message = new \Contentsync\Reviews\Post_Review_Message(
		$review_id,
		array(
			'content'   => '',
			'timestamp' => time(),
			'action'    => 'approved',
			'reviewer'  => get_current_user_id(),
		)
	);
	$new_message->save();

	\contentsync\mails\send_review_mail( $review_id, 'approved', 'editor' );

	return true;
}

/**
 * Deny a post review.
 *
 * @param int    $review_id       Review ID.
 * @param int    $post_id         Post ID.
 * @param string $message_content Optional message content.
 *
 * @return bool
 */
function deny_post_review( $review_id, $post_id = null, $message_content = '' ) {

	if ( ! $review_id ) {
		return false;
	}

	$post_review = get_post_review_by_id( $review_id );
	if ( in_array( $post_review->state, array( 'denied', 'approved', 'reverted' ) ) ) {
		// review already finished
		return false;
	}

	if ( ! $post_id ) {
		$post_id = $post_review->post_id;
	}

	$new_message_args = array(
		'content'   => $message_content,
		'timestamp' => time(),
		'action'    => 'denied',
		'reviewer'  => get_current_user_id(),
	);

	$new_message = new \Contentsync\Reviews\Post_Review_Message( $review_id, $new_message_args );
	$new_message->save();

	$result = set_post_review_state( $review_id, 'denied' );

	\contentsync\mails\send_review_mail( $review_id, 'denied', 'editor' );

	return $result;
}

/**
 * Revert a post review.
 *
 * @param int    $review_id       Review ID.
 * @param int    $post_id         Post ID.
 * @param string $message_content Optional message content.
 *
 * @return bool
 */
function revert_post_review( $review_id, $post_id = null, $message_content = '' ) {

	if ( ! $review_id ) {
		return false;
	}

	$post_review = get_post_review_by_id( $review_id );

	if ( ! $post_id ) {
		$post_id = $post_review->post_id;
	}

	$new_message_args = array(
		'content'   => $message_content,
		'timestamp' => time(),
		'action'    => 'reverted',
		'reviewer'  => get_current_user_id(),
	);

	$new_message = new \Contentsync\Reviews\Post_Review_Message( $review_id, $new_message_args );
	$new_message->save();

	$previous_post = $post_review->previous_post;

	// get the destination ids
	$destination_ids = array();
	foreach ( get_clusters_including_post( $post_id ) as $cluster ) {
		$destination_ids = array_merge( $destination_ids, $cluster->destination_ids );
	}

	// if post_status is 'auto-draft', the post did not exist before
	if ( $previous_post && $previous_post->post_status === 'auto-draft' ) {
		wp_trash_post( $post_id );
	} else {
		// revert the post to the previous state
		$result = Main_Helper::import_posts(
			// posts
			array( $post_id => $previous_post ),
			// conflict actions
			array(
				$post_id => array(
					'post_id' => $post_id,
					'action'  => 'replace',
				),
			)
		);

		// distribute the post
		$result = \Contentsync\distribute_single_post( $post_id, $destination_ids );
	}

	\contentsync\mails\send_review_mail( $review_id, 'reverted', 'editor' );

	$result = set_post_review_state( $review_id, 'reverted' );

	return $result;
}


/**
 * =================================================================
 *                          GET FUNCTIONS
 * =================================================================
 */


/**
 * Get a post review by ID.
 *
 * @param int $post_review_id
 *
 * @return \Contentsync\Reviews\Post_Review|false
 */
function get_post_review_by_id( $post_review_id ) {
	return \Contentsync\Reviews\Post_Review::get_instance( $post_review_id );
}

/**
 * Get a post review by post ID and blog ID.
 *
 * @param int $post_id
 *
 * @return \Contentsync\Reviews\Post_Review|false
 */
function get_post_review_by_post( $post_id, $blog_id, $state = null ) {
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
 * Get all post reviews by post ID and blog ID.
 *
 * @param int $post_id
 *
 * @return \Contentsync\Reviews\Post_Review[]
 */
function get_all_post_reviews_by_post( $post_id, $blog_id, $state = null ) {
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
 * Get all post reviews depending on the state.
 *
 * @param string|array $state
 *
 * @return \Contentsync\Reviews\Post_Review[]
 */
function get_post_reviews( $state = null ) {
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
 * Add a message to a post review.
 *
 * @param int    $post_review_id
 * @param string $message
 *
 * @return int|false
 */
function get_messages_by_post_review_id( $post_review_id ) {
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
 * Get the latest message of a post review.
 *
 * @param int $post_review_id
 *
 * @return \Contentsync\Reviews\Post_Review_Message|false
 */
function get_latest_message_by_post_review_id( $post_review_id ) {
	$messages = get_messages_by_post_review_id( $post_review_id );

	if ( ! $messages && ! is_array( $messages ) ) {
		return false;
	}

	$latest_message = end( $messages );

	// if no object of post_review_message, create one
	if ( ! $latest_message instanceof \Contentsync\Reviews\Post_Review_Message ) {
		$latest_message = new \Contentsync\Reviews\Post_Review_Message( $post_review_id, $latest_message );
	}

	return $latest_message;
}


/**
 * =================================================================
 *                          UPDATE FUNCTIONS
 * =================================================================
 */

/**
 * Insert a new post review.
 *
 * @param array $post_review
 *
 * @return int|false
 */
function insert_post_review( $post_review ) {
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
 * Update a post review.
 *
 * @param int   $post_review_id
 * @param array $post_review
 *
 * @return int|false
 */
function update_post_review( $post_review_id, $post_review ) {
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
 * Delete a post review.
 *
 * @param int $post_review_id
 *
 * @return int|false
 */
function delete_post_review( $post_review_id ) {
	global $wpdb;

	if ( ! $post_review_id ) {
		return false;
	}
	$wpdb->delete( $wpdb->base_prefix . 'synced_post_reviews', array( 'ID' => $post_review_id ) );

	return $post_review_id;
}

/**
 * Set the state of a post review.
 *
 * @param int    $post_review_id
 * @param string $state
 *
 * @return int|false
 */
function set_post_review_state( $post_review_id, $state ) {

	// TODO: escape variables properly?
	$post_review = get_post_review_by_id( $post_review_id );

	$update = array(
		'editor' => $post_review->editor,
		'date'   => date( 'Y-m-d H:i:s', time() ),
		'state'  => $state,
	);

	update_post_review( $post_review_id, $update );

	return $post_review_id;
}

/**
 * =================================================================
 *                          PREPARE FUNCTIONS
 * =================================================================
 */

/**
 * Before we distribute posts, we need to replace all posts that have not
 * been reviewed yet with the previous version.
 *
 * @see prepare_posts_for_distribution()
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

		$post_review = get_post_review_by_post( $post->ID, get_current_blog_id(), array( 'new', 'in_review' ) );
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
