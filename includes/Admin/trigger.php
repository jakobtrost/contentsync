<?php
/**
 * Content Syncs Trigger.
 *
 * Handles all the triggers on update, import, export, delete and trash posts.
 *
 * This file defines the `Trigger` class, which hooks into a variety of
 * WordPress actions and filters to respond when posts are inserted,
 * updated, trashed or untrashed, attachments are replaced, and other
 * events occur. It performs pre‑ and post‑update tasks such as saving
 * cluster conditions, updating global metadata and dispatching
 * distribution or review workflows.
 *
 * @since 2.17.0
 */

namespace Contentsync\Admin;

use Contentsync\Posts\Transfer\Post_Export;
use Contentsync\Utils\Logger;
use Contentsync\Utils\Multisite_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

new Trigger();
class Trigger {

	/**
	 * Class constructor
	 */
	public function __construct() {

		// update post
		// before
		add_filter( 'wp_insert_post_parent', array( $this, 'before_update_post' ), 0, 4 );
		// after
		add_action( 'wp_after_insert_post', array( $this, 'after_insert_post' ), 10, 4 );
		add_action( 'attachment_updated', array( $this, 'after_attachment_updated' ), 10, 3 );
		add_action( 'enable-media-replace-upload-done', array( $this, 'after_media_replaced' ), 10, 3 );

		// trash
		add_filter( 'pre_trash_post', array( $this, 'pre_trash_post' ), 10, 3 );
		add_action( 'untrash_post', array( $this, 'on_untrash_post' ) );

		// delete
		// add_action( 'before_delete_post', array( $this, 'on_delete_post' ), 10, 2 );
		// add_action( 'delete_attachment', array( $this, 'on_delete_post' ), 10, 2 );
	}


	/**
	 * =================================================================
	 *                          UPDATE (before)
	 * =================================================================
	 */

	/**
	 * Called before any post is inserted or updated or trashed, to check if the post update should be processed.
	 *
	 * @filter wp_insert_post_parent
	 *
	 * @param int   $post_parent Post parent ID.
	 * @param int   $post_id     Post ID.
	 * @param array $new_postarr Array of parsed post data.
	 * @param array $postarr     Array of sanitized, but otherwise unmodified post data.
	 */
	public function before_update_post( $post_parent, $post_id, $new_postarr, $postarr ) {

		if (
			$new_postarr['post_status'] === 'auto-draft'
			|| wp_is_post_revision( $post_id )
			|| $postarr['post_type'] === 'revision'
			|| wp_is_post_autosave( $post_id )
		) {
			return $post_parent;
		}

		$contentsync_status = get_post_meta( $post_id, 'synced_post_status', true );
		if ( $contentsync_status === 'linked' ) {
			return $post_parent;
		}

		// Never process this call twice after the post update for the same post
		if ( $this->already_processed( 'before_update_post', $post_id ) ) {
			return $post_parent;
		}

		// save all condition ids that include this post before the post update
		$this->get_condition_ids_including_this_post_before( $post_id );

		// save all conditions that could affect this post before the post update
		$this->get_all_conditions_that_can_be_affected_by_post_update( $postarr );

		return $post_parent;
	}


	/**
	 * =================================================================
	 *                          UPDATE (after)
	 * =================================================================
	 */

	/**
	 * This function is called whenever any post is inserted or updated.
	 *
	 * @action wp_after_insert_post
	 *
	 * @param int     $post_id    Post ID.
	 * @param WP_Post $post       The post object that has been saved.
	 * @param bool    $update     Whether this is an existing post being updated.
	 * @param WP_Post $post_before Post object before the update.
	 */
	public function after_insert_post( $post_id, $post, $update, $post_before ) {

		if (
			! $update
			|| ! is_object( $post )
			|| $post->post_status === 'auto-draft'
			|| wp_is_post_revision( $post_id )
			|| $post->post_type === 'revision'
			|| wp_is_post_autosave( $post_id )
		) {
			return;
		}

		$contentsync_status = get_post_meta( $post_id, 'synced_post_status', true );

		// abort if the current user is not allowed to edit synced posts
		if ( ! empty( $contentsync_status ) ) {
			$current_user_can_edit = \Contentsync\Admin\current_user_can_edit_synced_posts( $contentsync_status );
			if ( ! $current_user_can_edit ) {
				wp_die(
					__( 'You are not allowed to edit synced posts.', 'global-contents' ),
					405
				);
			}
		}

		// update contentsync options
		if ( ! empty( $_POST ) && is_array( $_POST ) ) {

			if ( isset( $_POST['editable_contentsync_export_options'] ) ) {
				\Contentsync\Posts\Sync\update_contentsync_post_export_options( $post_id, $_POST['editable_contentsync_export_options'] );
			}

			if ( isset( $_POST['contentsync_canonical_url'] ) ) {
				\Contentsync\Posts\Sync\update_contentsync_post_canonical_url( $post_id, $_POST['contentsync_canonical_url'] );
			}
		}

		// get & save the post before the update
		$post_before = $this->get_post_before_update( $post_id, $post_before );

		// compare the conditions before and after the post update
		if ( $post_before ) {
			$this->compare_conditions_before_and_after_post_update( $post_id, $post_before );
		}

		/**
		 * When an article is saved, the hook 'wp_after_insert_post' is often
		 * called twice. This happens in different scenarios, most often when
		 * an article is saved within the block editor. In those cases, there
		 * are 2 requests sent to the server. One request is the initial save,
		 * the second request is mostly used to save metadata.
		 *
		 * We prevent that by identifying the correct scenario and only triggering
		 * the actions when necessary.
		 *
		 * @since 2.18.0
		 */
		if ( $this->maybe_ignore_this_post_update_action( $post_id ) ) {
			return;
		}

		// abort, this will be handled by $this->pre_trash_post()
		if ( $post->post_status === 'trash' ) {
			return;
		}

		if ( empty( $contentsync_status ) ) {
			self::after_update_local_post( $post_id, $post, $post_before );
		} elseif ( $contentsync_status === 'root' ) {
			self::after_update_synced_post( $post_id, $post, $post_before );
		} elseif ( $contentsync_status === 'linked' ) {

			/**
			 * Filter to control whether linked posts can be updated locally.
			 *
			 * This filter allows developers to override the default behavior that prevents
			 * linked posts from being updated locally. By default, linked posts are not
			 * allowed to be updated to maintain content synchronization.
			 *
			 * @filter contentsync_allow_update_of_linked_post
			 *
			 * @param bool   $allow_update_of_linked_post Whether to allow updating linked posts.
			 * @param int    $post_id                     The post ID being updated.
			 * @param WP_Post $post                       The current post object.
			 * @param WP_Post $post_before                The post object before the update.
			 *
			 * @return bool Whether to allow updating linked posts.
			 */
			$allow_update_of_linked_post = apply_filters( 'contentsync_allow_update_of_linked_post', false, $post_id, $post, $post_before );

			if ( ! $allow_update_of_linked_post ) {

				// prevent updating linked posts
				wp_die(
					__( 'This post is linked to a synced post and cannot be updated locally.', 'global-contents' ),
					405
				);
			}
		}
	}

	/**
	 * Compare the conditions before and after the post update.
	 *
	 * A condition could be "all posts from category x", "the latest
	 * 3 posts", etc. Here we compare the conditions for changes:
	 *
	 * Scenario A)
	 *   The post was not part of a condition and is still not part
	 *   of a condition. We have nothing to do and can continue.
	 *
	 * Scenario B)
	 *   The post was part of at least one condition and is still
	 *   part of the same conditions. We have nothing to do and
	 *   can continue.
	 *
	 * Scenario C)
	 *   The post was part of at least one condition and is now not
	 *   part of the same conditions. Or the post was part of a condition
	 *   and is now part of a different condition. Or the post was not
	 *   part of a condition and is now part of a condition.
	 *   For each condition that has changed, we need to distribute all
	 *   posts in the condition. Example:
	 *    - The post was part of in "all posts from category 'news'"
	 *    - This category 'news' was removed from the post, which
	 *      means the post is not part of the condition anymore.
	 *    - But it also assigned the category 'events' to the post,
	 *      which includes the post in the condition "all posts from
	 *      category 'events'"
	 *    - as this could be the same cluster, we really need to check
	 *      every condition that includes the post.
	 *
	 * @param int     $post_id The ID of the post being updated.
	 * @param WP_Post $post_before The post object before the update.
	 */
	public function compare_conditions_before_and_after_post_update( $post_id, $post_before ) {

		// Never process this call twice after the post update for the same post
		if ( $this->already_processed( 'compare_conditions_before_and_after_post_update', $post_id ) ) {
			return;
		}

		// get the conditions before the post update
		$condition_ids_including_this_post_before = $this->get_condition_ids_including_this_post_before( $post_id );

		// get the conditions right now (after the post update)
		$content_conditions_including_post = get_cluster_content_conditions_including_post( $post_id );
		if ( ! empty( $content_conditions_including_post ) ) {
			$condition_ids_including_this_post = array_keys( $content_conditions_including_post );
		} else {
			$condition_ids_including_this_post = array();
		}

		// return if the post is not part of any condition
		if ( empty( $condition_ids_including_this_post_before ) && empty( $condition_ids_including_this_post ) ) {
			return;
		}

		// Logger::add( 'condition_ids_including_this_post - before: ', $condition_ids_including_this_post_before );
		// Logger::add( 'condition_ids_including_this_post - after: ', $condition_ids_including_this_post );

		$removed_conditions = array_diff( $condition_ids_including_this_post_before, $condition_ids_including_this_post );
		$added_conditions   = array_diff( $condition_ids_including_this_post, $condition_ids_including_this_post_before );
		$changed_conditions = array_unique( array_merge( $removed_conditions, $added_conditions ) );

		// Logger::add( 'removed_conditions: ', $removed_conditions );
		// Logger::add( 'added_conditions: ', $added_conditions );
		// Logger::add( 'changed_conditions: ', $changed_conditions );

		// return if no conditions have changed
		if ( empty( $changed_conditions ) ) {
			return;
		}

		// get all conditions that could be affected by the post update before the post update
		$all_conditions = $this->get_all_conditions_that_can_be_affected_by_post_update( $post_before );

		// process the removed conditions
		foreach ( $removed_conditions as $condition_id ) {

			// get the condition and the posts before the post update
			$condition    = $all_conditions[ $condition_id ]['condition'];
			$posts_before = $all_conditions[ $condition_id ]['posts'];

			// check if cluster has reviews enabled
			$cluster = get_cluster_by_id( $condition->contentsync_cluster_id );
			if ( $cluster->enable_reviews ) {
				\Contentsync\Reviews\create_post_review( $post_id, $post_before );
			}

			// check if the post is still in this cluster.
			if ( ! is_post_in_cluster( $post_id, $condition->contentsync_cluster_id ) ) {
				Logger::add( 'post is removed from cluster: ', $condition->contentsync_cluster_id );
				$this->add_cluster_id_the_post_is_removed_from( $post_id, $condition->contentsync_cluster_id );
			}

			// check if the condition has a count filter enabled
			$condition_has_count_filter = false;
			if ( isset( $condition->filter ) ) {
				foreach ( $condition->filter as $filter ) {
					if ( isset( $filter['count'] ) && ! empty( $filter['count'] ) ) {
						$condition_has_count_filter = true;
						break;
					}
				}
			}

			// We only distribute the entire condition if it has a count filter
			// enabled. In all other cases the other posts are not affected by
			// this post being removed from the condition.
			// Example:
			// 1.) Condition: "all posts from category 'news'"
			// The other posts in the condition are not affected by this post
			// being removed from the condition. They remain in 'news'.
			// 2.) Condition: "the latest 3 posts"
			// The other posts in the condition could be affected because the
			// '3' posts likely now includes 1 other post that has not been
			// included yet.
			if ( $condition_has_count_filter ) {
				// Logger::add( 'distributing condition (removed post id: '.$post_id.'): ', $condition );
				\Contentsync\distribute_cluster_content_condition_posts( $condition, $posts_before );
			}
		}

		// process the added conditions
		foreach ( $added_conditions as $condition_id ) {
			$condition = $content_conditions_including_post[ $condition_id ];
			if ( isset( $all_conditions[ $condition_id ] ) ) {
				$posts_before = $all_conditions[ $condition_id ]['posts'];
			} else {
				$posts_before = array();
			}

			// check if cluster has reviews enabled
			$cluster = get_cluster_by_id( $condition->contentsync_cluster_id );
			if ( $cluster->enable_reviews ) {
				\Contentsync\Reviews\create_post_review( $post_id, $post_before );
			}

			// check if the condition has a count filter enabled
			$condition_has_count_filter = false;
			if ( isset( $condition->filter ) ) {
				foreach ( $condition->filter as $filter ) {
					if ( isset( $filter['count'] ) && ! empty( $filter['count'] ) ) {
						$condition_has_count_filter = true;
						break;
					}
				}
			}

			// We only distribute the entire condition if it has a count filter
			// enabled. In all other cases the other posts are not affected by
			// this post being added to the condition.
			// Example:
			// 1.) Condition: "all posts from category 'news'"
			// The other posts in the condition are not affected by this post
			// being added to the condition. They remain in 'news'.
			// 2.) Condition: "the latest 3 posts"
			// The other posts in the condition could be affected because the
			// '3' posts likely now includes 1 less other post that has been
			// included before, but now needs to be removed from the condition.
			if ( $condition_has_count_filter ) {
				// Logger::add( 'distributing condition (added post id: '.$post_id.'): ', $condition );
				\Contentsync\distribute_cluster_content_condition_posts( $condition, $posts_before );
			}
		}
	}

	/**
	 * A local post has been updated.
	 *
	 * Decide whether to make that post global.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 * @param WP_Post $post_before
	 */
	public function after_update_local_post( $post_id, $post, $post_before = null ) {

		// we only distribute posts once they are published
		if ( $post->post_status !== 'publish' && $post->post_status !== 'inherit' ) {
			return;
		}

		$make_post_synced  = false;
		$post_needs_review = false;
		$export_args       = \Contentsync\Posts\Sync\get_contentsync_default_export_options();
		$destination_ids   = array();

		/**
		 * Check if the post is inside a cluster with reviews enabled
		 */
		foreach ( get_clusters_including_post( $post ) as $cluster ) {
			if ( $cluster->enable_reviews ) {
				$post_needs_review = true;
				break;
			}
			// save the destination ids, where the post is added to
			$destination_ids  = array_merge( $destination_ids, $cluster->destination_ids );
			$make_post_synced = true;
		}

		$destination_ids = array_unique( array_map( 'strval', $destination_ids ) );

		// return if no need to make post global
		if ( ! $make_post_synced ) {
			return;
		}

		/**
		 * Make the post global, but not distribute it yet.
		 */
		$gid = \Contentsync\Posts\Sync\make_post_synced( $post_id, $export_args );

		/**
		 * Create a review if the post needs review.
		 */
		if ( $post_needs_review ) {
			\Contentsync\Reviews\create_post_review( $post_id, $post_before );
		}
		/**
		 * Distribute the post to all connections
		 */
		else {
			\Contentsync\distribute_single_post( $post_id, $destination_ids );
		}
	}

	/**
	 * A global root post has been updated.
	 *
	 * Decide whether to distribute the changes to all connected posts or
	 * to if a review should be created.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 * @param WP_Post $post_before
	 */
	public function after_update_synced_post( $post_id, $post, $post_before = null ) {

		$post_needs_review = false;
		$destination_ids   = array();

		/**
		 * Check if the post is inside a cluster with reviews enabled
		 */
		foreach ( get_clusters_including_post( $post ) as $cluster ) {
			if ( $cluster->enable_reviews ) {
				$post_needs_review = true;
				break;
			}
			// save the destination ids, where the post is distributed to
			$destination_ids = array_merge( $destination_ids, $cluster->destination_ids );
		}

		$destination_ids = array_unique( array_map( 'strval', $destination_ids ) );

		// Create a review if the post needs review
		if ( $post_needs_review ) {
			\Contentsync\Reviews\create_post_review( $post_id, $post_before );
		}
		// Distribute the post to all connections & destinations
		else {

			// get the destinations the post is removed from
			$destinations_to_be_removed_from      = array();
			$cluster_ids_the_post_is_removed_from = $this->get_cluster_ids_the_post_is_removed_from( $post_id );
			if ( ! empty( $cluster_ids_the_post_is_removed_from ) ) {
				foreach ( $cluster_ids_the_post_is_removed_from as $cluster_id ) {
					$cluster = get_cluster_by_id( $cluster_id );
					if ( $cluster ) {
						if ( $cluster->destination_ids && ! empty( $cluster->destination_ids ) ) {
							foreach ( $cluster->destination_ids as $destination_id ) {

								if ( empty( $destination_id ) ) {
									continue;
								}
								if ( ! in_array( $destination_id, $destination_ids ) ) {
									$destinations_to_be_removed_from[ $destination_id ] = array(
										'import_action' => 'delete',
									);
								}
							}
						}
					}
				}
			}

			// if we have destinations to be removed from, we modify the destination_ids
			if ( ! empty( $destinations_to_be_removed_from ) ) {
				/**
				 * $destination_ids -> simple array of strings.
				 * array(
				 *    '2',
				 *    '3|https://remote.site.com'
				 * )
				 *
				 * $destinations_to_be_removed_from array of arrays.
				 * array(
				 *   '2' => array(
				 *     'import_action'    => 'insert|draft|trash|delete',
				 *     'conflict_action'  => 'keep|replace|skip',
				 *     'export_arguments' => array( 'translations' => true )
				 *   ),
				 *   '3|https://remote.site.com' => array(
				 *     'import_action'    => 'insert|draft|trash|delete',
				 *     'conflict_action'  => 'keep|replace|skip',
				 *     'export_arguments' => array( 'translations' => true )
				 *   ),
				 *   ...
				 * )
				 */
				$simple_destination_ids = $destination_ids;
				$destination_ids        = $destinations_to_be_removed_from;
				foreach ( $simple_destination_ids as $destination_id ) {
					if ( empty( $destination_id ) ) {
						continue;
					}
					$destination_ids[ $destination_id ] = array(
						'import_action' => 'insert',
					);
				}
			}

			$result = \Contentsync\distribute_single_post( $post_id, $destination_ids );
		}
	}

	/**
	 * This function is called whenever an attachment is updated (not created).
	 *
	 * The default action 'wp_after_insert_post' is not called only for attachments.
	 * Also, attachments are not made global on initial upload, only when they are
	 * included in a synced post as nested content.
	 *
	 * @param int     $post_id      Post ID.
	 * @param WP_Post $post_after   Post object following the update.
	 * @param WP_Post $post_before  Post object before the update.
	 */
	public function after_attachment_updated( $post_id, $post_after, $post_before ) {
		if ( 'root' === get_post_meta( $post_id, 'synced_post_status', true ) ) {
			self::after_update_synced_post( $post_id, $post_after );
		}
	}

	/**
	 * Support for Enable Media Replace
	 *
	 * @action 'enable-media-replace-upload-done'
	 * @see EnableMediaReplace\Replacer->removeCurrent()
	 * @link https://github.com/short-pixel-optimizer/enable-media-replace/blob/master/classes/replacer.php
	 *
	 * @since 1.0.9
	 *
	 * @param string $target_url    New file path.
	 * @param string $source_url    Old file path.
	 * @param int    $post_id       The attachment WP_Post ID.
	 */
	public function after_media_replaced( $target_url, $source_url, $post_id ) {
		if ( 'root' === get_post_meta( $post_id, 'synced_post_status', true ) ) {
			self::after_update_synced_post( $post_id, get_post( $post_id ) );
		}
	}

	/**
	 * Whether a post needs review.
	 *
	 * @param int $post_id
	 *
	 * @return bool
	 */
	public static function post_needs_review( $post_id ) {
		$post_needs_review = false;
		foreach ( get_clusters_including_post( $post_id ) as $cluster ) {
			if ( $cluster->enable_reviews ) {
				$post_needs_review = true;
			}
		}
		return $post_needs_review;
	}

	/**
	 * =================================================================
	 *                          TRASH & DELETE
	 * =================================================================
	 */

	/**
	 * Called whenever a post is trashed via action hook 'trash_post'
	 *
	 * @param bool    $proceed
	 * @param WP_Post $post
	 * @param string  $previous_status
	 */
	public function pre_trash_post( $proceed, $post, $previous_status ) {

		$post_id = $post->ID;

		$status = \Contentsync\Posts\Sync\get_contentsync_meta_values( $post_id, 'synced_post_status' );
		$gid    = \Contentsync\Posts\Sync\get_contentsync_meta_values( $post_id, 'synced_post_id' );

		if ( ! empty( $status ) ) {
			/**
			 * Check if the current user can trash synced posts.
			 */
			$current_user_can_trash = \Contentsync\Admin\current_user_can_edit_synced_posts( $status );
			if ( ! $current_user_can_trash ) {
				wp_die(
					__( 'You are not allowed to trash synced posts.', 'global-contents' ),
					405
				);
			}
		}

		if ( $status === 'linked' ) {
			// remove connection from root post
			\Contentsync\Posts\Sync\remove_post_connection_from_connection_map( $gid, get_current_blog_id(), $post_id );
		} elseif ( $status === 'root' ) {
			if ( self::post_needs_review( $post_id ) ) {
				$post_before              = $post;
				$post_before->post_status = $previous_status;
				\Contentsync\Reviews\create_post_review( $post_id, $post_before );
				return;
			}
			self::on_trash_synced_post( $post_id );
		}
	}

	/**
	 * Called whenever a post is untrashed via action hook 'untrash_post'
	 *
	 * @param int $post_id
	 */
	public function on_untrash_post( $post_id ) {

		$status = get_post_meta( $post_id, 'synced_post_status', true );
		if ( $status === 'linked' ) {
			$gid = get_post_meta( $post_id, 'synced_post_id', true );
			\Contentsync\Posts\Sync\add_post_connection_to_connection_map( $gid, get_current_blog_id(), $post_id );
		} elseif ( $status === 'root' ) {
			if ( self::post_needs_review( $post_id ) ) {
				$post_before              = get_post( $post_id );
				$post_before->post_status = 'trash';
				\Contentsync\Reviews\create_post_review( $post_id, $post_before );
				return;
			}
			self::on_untrash_synced_post( $post_id );
		}
	}

	/**
	 * Called whenever a post is deleted via action hook 'before_delete_post'
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public function on_delete_post( $post_id, $post ) {

		$status = get_post_meta( $post_id, 'synced_post_status', true );
		if ( $status === 'linked' ) {
			\Contentsync\Posts\Sync\unlink_synced_post( $post_id );
			// delete also all connected cluster posts
			foreach ( get_clusters_including_post( $post ) as $cluster ) {
				foreach ( $cluster->destination_ids as $blog_id ) {
					/**
					 * @todo REWORK delete post from destinations
					 */
					Multisite_Manager::switch_blog( $blog_id );
					\Contentsync\Posts\Sync\unlink_synced_post( $post_id );
					Multisite_Manager::restore_blog();
				}
			}
		} elseif ( $status === 'root' ) {
			if ( self::post_needs_review( $post_id ) ) {
				\Contentsync\Reviews\create_post_review( $post_id, $post );
				return;
			}
			self::on_delete_synced_post( $post );
		}
	}

	public static function on_trash_synced_post( $post_id ) {

		// get setting
		$setting = 'trash'; // TODO: get setting from database

		$result = true;

		/**
		 * trash everywhere
		 */
		if ( $setting == 'trash' ) {
			$result = \Contentsync\Posts\Sync\trash_connected_posts( $post_id );
		}

		/**
		 * delete everywhere
		 */
		if ( $setting == 'delete' ) {
			$result = \Contentsync\Posts\Sync\delete_connected_posts( $post_id );
		}

		/**
		 * make local everywhere (default)
		 */
		if ( $setting == 'localize' ) {
			$result = \Contentsync\Posts\Sync\unlink_connected_posts( $post_id );
		}

		// display admin notice when root post is trashed and there is still a connection
		$connection_map = \Contentsync\Posts\Sync\get_post_connection_map( $post_id );
		if ( $connection_map && is_array( $connection_map ) && count( $connection_map ) ) {
			set_transient(
				'contentsync_transient_notice',
				'info::' .
				__( '<strong>You have trashed a global source post, which is used on other pages.</strong> This does not delete the linked posts.', 'contentsync' ) .
				'</p><p>' .
				__( 'If you want to delete this post everywhere, restore the post and use the "Delete everywhere" feature via "Content Sync > Network Overview".', 'contentsync' )
			);
		}

		return $result;
	}

	public static function on_untrash_synced_post( $post_id ) {

		// get setting
		$setting = 'trash'; // TODO: get setting from database

		$result = true;

		/**
		 * trash everywhere
		 */
		if ( $setting == 'trash' ) {
			$result = \Contentsync\Posts\Sync\untrash_connected_posts( $post_id );
		}

		/**
		 * delete everywhere
		 */
		if ( $setting == 'delete' ) {
			// do nothing - untrashed root post is in Draft, will be distributed and re-created once published
		}

		/**
		 * make local everywhere (default)
		 */
		if ( $setting == 'localize' ) {
			// do nothing - untrashed root post is in Draft, will be distributed and re-linked once published
		}

		return $result;
	}

	/**
	 *
	 * @todo REWORK with new distributor
	 */
	public static function on_delete_synced_post( $post ) {

		// get settings
		$delete_synced_post_setting = 'delete'; // TODO: get setting from database
		$trash_synced_post_setting  = 'trash'; // TODO: get setting from database

		$result = true;

		/**
		 * delete everywhere
		 */
		if ( $delete_synced_post_setting == 'delete' ) {

			// get connection_map from review
			$connection_map = isset( $post->meta['contentsync_connection_map'] ) ? $post->meta['contentsync_connection_map'] : \Contentsync\Posts\Sync\get_post_connection_map( $post->ID );
			$result         = \Contentsync\Posts\Sync\delete_connected_posts( $post->ID, $connection_map );

			if ( $trash_synced_post_setting == 'trash' ) {
				// search for trashed posts and delete them
				\Contentsync\Posts\Sync\untrash_connected_posts( $post->ID, true );
			} elseif ( $trash_synced_post_setting == 'delete' ) {
				// do nothing, linked posts are already deleted
			} elseif ( $trash_synced_post_setting == 'localize' ) {
				// search for localized (unlinked) posts and delete them
				\Contentsync\Posts\Sync\delete_unlinked_posts( $post );
			}
		}

		/**
		 * make local everywhere
		 */
		if ( $delete_synced_post_setting == 'localize' ) {
			$result = \Contentsync\Posts\Sync\unlink_connected_posts( $post->ID );

			if ( $trash_synced_post_setting == 'trash' ) {
				// search for trashed posts and untrash them
				\Contentsync\Posts\Sync\untrash_connected_posts( $post->ID );
			} elseif ( $trash_synced_post_setting == 'delete' ) {
				// do nothing, linked posts are gone
			} elseif ( $trash_synced_post_setting == 'localize' ) {
				// do nothing, posts are already unlinked
			}
		}

		/**
		 * do nothing (default)
		 */
		if ( $delete_synced_post_setting == 'nothing' ) {
			// do nothing
		}

		return $result;
	}

	/**
	 * =================================================================
	 *                          RETRIEVEVAL & CACHING
	 * =================================================================
	 *
	 * This section contains functions that retrieve and cache data.
	 * This is necessary to prevent duplicate processing. WordPress often
	 * fires the 'insert post actions' twice, which would trigger the same post
	 * update multiple times (eg. 2x 'wp_after_insert_post')
	 *
	 * This can lead to a lot of unexpected behavior and issues. It makes the
	 * evaluation very trickyof what has actually changed since before the post
	 * was updated. This is why we cache the data in transients. Doing that we
	 * generally follow the rule:
	 *      "If it's in the transient, it's already been processed".
	 * Therefore we make sure that a lot of the functions used to retrieve data
	 * that is used to evaluate the state before and after are only fired once
	 * during the entire update post flow. Those include:
	 *  - the post object before it was changed
	 *  - the cluster conditions the post has been a part of before
	 *
	 * Additionally, we want the actual distribution to only take place once the
	 * post-object, meta, terms etc. are fully up-to-date. In order ro achieve this,
	 * we might ignore the first 'insert post action' firing and wait for the second.
	 * But in order to do so, we need to be sure, that a second action is about to
	 * fire. Take a look at the comment @see maybe_ignore_this_post_update_action()
	 * to understand how we achieve and evaluate that.
	 *
	 * @since 2.18.0
	 */

	/**
	 * How many seconds to keep the transients for the post update.
	 *
	 * This is intentionally short to allow legitimate rapid updates
	 * while preventing duplicate processing.
	 *
	 * @since 2.18.0
	 */
	const TRANSIENT_LIFETIME = 2;

	/**
	 * Check if an action has already been processed in the last 2 seconds for the same post.
	 *
	 * @param string $action The action that has been processed.
	 * @param int    $post_id The ID of the post being updated.
	 *
	 * @return bool True if the action has already been processed, false otherwise.
	 */
	public function already_processed( $action, $post_id ) {
		if ( get_transient( 'synced_post_update_already_processed_' . $action . '_' . $post_id ) ) {
			// Logger::add( 'ignoring duplicate action: "' . $action . '" for post id: '. $post_id );
			return true;
		}
		set_transient( 'synced_post_update_already_processed_' . $action . '_' . $post_id, true, self::TRANSIENT_LIFETIME );
		return false;
	}

	/**
	 * Get cluster condition ids before post update. This is used to compare the specific
	 * conditions the post has been added to or removed from after the update.
	 *
	 * The result is saved in a transient. This is necessary to prevent
	 * duplicate processing. WordPress often fires the hook 'wp_insert_post_parent' multiple times,
	 * which would trigger the same post update multiple times.
	 *
	 * @param int  $post_id The ID of the post being updated.
	 * @param bool $use_cache Whether to use the cache.
	 *      If true, the function will check the transient.
	 *      If false, the function will return the condition IDs including this post from the database.
	 * @return array The condition IDs including this post.
	 */
	public function get_condition_ids_including_this_post_before( $post_id, $use_cache = true ) {

		// check transient
		if ( $use_cache ) {
			$transient = get_transient( 'synced_post_update_condition_ids_including_this_post_before_' . $post_id );
			if ( $transient !== false ) {
				return $transient;
			}
		}

		// get content conditions including this post
		$content_conditions_including_post = get_cluster_content_conditions_including_post( $post_id );

		if ( ! empty( $content_conditions_including_post ) ) {
			$content_conditions_including_post = array_keys( $content_conditions_including_post );
		} else {
			// we set an empty array to make the transient valid and returnable.
			$content_conditions_including_post = array();
		}

		// save transient
		set_transient( 'synced_post_update_condition_ids_including_this_post_before_' . $post_id, $content_conditions_including_post, self::TRANSIENT_LIFETIME );

		return $content_conditions_including_post;
	}

	/**
	 * Get all cluster conditions that could affect this post - before post update.
	 * This is used to preserve the exact state and all posts of each condition before the
	 * update has taken place. As the post could have been added to a condition that it is
	 * not already part of, it would not be enough to only get the conditions that include
	 * the post right now. But the post can only ever be part of a condition that affects
	 * the same posttype - therefore we retrieve all conditions based on the post type.
	 *
	 * The result is saved in the transient. This is necessary to prevent
	 * duplicate processing. WordPress often fires the hook 'wp_insert_post_parent' multiple times,
	 * which would trigger the same post update multiple times.
	 *
	 * @param string|array|object         $postarr_object_or_type The post array, object or type to get the conditions for.
	 * @param bool                        $use_cache Whether to use the cache.
	 *                             If true, the function will check the transient.
	 *                             If false, the function will return the all conditions that could be affected by the post update from the database.
	 *
	 * @return array Keyed by condition_id, value is an array with the condition and the posts.
	 *      @param Content_Condition condition
	 *      @param WP_Post[] posts
	 */
	public function get_all_conditions_that_can_be_affected_by_post_update( $postarr_object_or_type, $use_cache = true ) {

		// get the post type from the post array, object or type
		if ( is_object( $postarr_object_or_type ) ) {
			$post_type = $postarr_object_or_type->post_type;
		} elseif ( is_array( $postarr_object_or_type ) ) {
			$post_type = $postarr_object_or_type['post_type'];
		} else {
			$post_type = $postarr_object_or_type;
		}

		// check transient
		if ( $use_cache ) {
			$transient = get_transient( 'synced_post_update_conditions_with_this_posttype_before_' . $post_type );
			if ( $transient ) {
				return $transient;
			}
		}

		$conditions_with_this_posttype_before = array();
		$content_conditions_with_posttype     = get_cluster_content_conditions_including_posttype( $post_type );

		if ( ! empty( $content_conditions_with_posttype ) ) {

			foreach ( $content_conditions_with_posttype as $condition_id => $condition ) {

				$conditions_with_this_posttype_before[ $condition_id ] = array(
					'condition' => $condition,
					'posts'     => get_posts_by_cluster_content_condition( $condition ),
				);
			}
		}

		// save transient
		set_transient( 'synced_post_update_conditions_with_this_posttype_before_' . $post_type, $conditions_with_this_posttype_before, self::TRANSIENT_LIFETIME );

		// Logger::add( 'conditions_with_this_posttype_before: ', $conditions_with_this_posttype_before );

		return $conditions_with_this_posttype_before;
	}

	/**
	 * Get the post before update.
	 *
	 * This function uses a transient to store the post before update. This is necessary to prevent
	 * duplicate processing. WordPress often fires the hook 'wp_insert_post_parent' multiple times,
	 * which would trigger the same post update multiple times.
	 *
	 * @param int     $post_id The ID of the post being updated.
	 * @param WP_Post $post_before The post object to use if no post before update is found.
	 * @param bool    $use_cache Whether to use the cache.
	 *         If true, the function will check the transient.
	 *         If false, the function will return the post before update from the database.
	 *
	 * @return WP_Post The post before update.
	 */
	public function get_post_before_update( $post_id, $post_before = null, $use_cache = true ) {

		// check transient
		if ( $use_cache ) {
			$transient = get_transient( 'synced_post_update_post_before_' . $post_id );
			if ( $transient ) {
				return $transient;
			}
		}

		// save transient
		set_transient( 'synced_post_update_post_before_' . $post_id, $post_before, self::TRANSIENT_LIFETIME );

		return $post_before;
	}

	/**
	 * Get the cluster ids the post is removed from.
	 *
	 * The updated post can have destinations, outside of a cluster. After
	 * distributing the cluster, the root post itself is distributed. This
	 * means, the post is distributed to all destinations, saved in the
	 * connection map (post meta).
	 * At the time the post is scheduled for distribution, the post meta
	 * is not updated yet. This means, the post would be distributed to
	 * already removed destinations.
	 *
	 * We need to make sure, that the post is NOT distributed
	 * to the destinations that have been removed from the
	 * cluster condition.
	 *
	 * @param int $post_id The ID of the post being updated.
	 * @return array The cluster ids the post is removed from.
	 */
	public function get_cluster_ids_the_post_is_removed_from( $post_id, $use_cache = true ) {
		if ( $use_cache ) {
			$transient = get_transient( 'synced_post_update_cluster_ids_the_post_is_removed_from_' . $post_id );
			if ( $transient ) {
				return $transient;
			}
		}
		return array();
	}

	/**
	 * Add a cluster id to the list of cluster ids the post is removed from.
	 *
	 * @param int $post_id The ID of the post being updated.
	 * @param int $cluster_id The ID of the cluster the post is removed from.
	 */
	public function add_cluster_id_the_post_is_removed_from( $post_id, $cluster_id ) {
		$cluster_ids   = $this->get_cluster_ids_the_post_is_removed_from( $post_id );
		$cluster_ids[] = $cluster_id;
		$cluster_ids   = array_unique( $cluster_ids );
		set_transient( 'synced_post_update_cluster_ids_the_post_is_removed_from_' . $post_id, $cluster_ids, self::TRANSIENT_LIFETIME );
	}

	/**
	 * Determines whether a post update should be ignored to prevent duplicate processing.
	 *
	 * PROBLEM CONTEXT:
	 * ================
	 * WordPress's 'wp_after_insert_post' hook is triggered inconsistently depending on the editor
	 * and context being used. This creates a complex scenario where the same post save action can
	 * trigger our hook 1x or 2x, and the metadata state differs between calls.
	 *
	 * IMPORTANT NOTES FOR FUTURE MAINTENANCE:
	 * ========================================
	 * 1. This solution is dependent on WordPress core behavior that may change in future versions.
	 * 2. The double-call behavior in the Block Editor is related to how meta boxes are loaded.
	 * 3. If WordPress changes how the Block Editor handles meta box saves, this logic may need updating.
	 * 4. The 2-second transient timeout is intentionally short to prevent race conditions while
	 *    allowing legitimate rapid updates.
	 * 5. Plugin conflicts (especially with meta box plugins like Yoast SEO, ACF, etc.) may affect
	 *    when metadata is available in each call.
	 *
	 * DETAILED BEHAVIOR ANALYSIS:
	 * ===========================
	 *
	 * 1. CLASSIC POST EDITOR (without block editor):
	 *    - Hook calls: 1x
	 *    - REQUEST_URI: '/wp-admin/post.php'
	 *    - Metadata state: Correct ✓
	 *    - Action: Process normally
	 *
	 * 2. BLOCK EDITOR WITH META BOXES (e.g., Yoast SEO):
	 *    - Hook calls: 2x
	 *    - Call 1:
	 *      * REQUEST_URI: '/wp-json/wp/v2/{post_type}/{id}?_locale=user'
	 *      * HTTP_REFERER: '.../wp-admin/post.php?post={id}&action=edit'
	 *      * Metadata state: INCORRECT ✗ (metadata not yet saved)
	 *      * Reason: Initial REST API save without metadata
	 *    - Call 2:
	 *      * REQUEST_URI: '/wp-admin/post.php?post={id}&action=edit&meta-box-loader=1&meta-box-loader-nonce=...'
	 *      * HTTP_REFERER: '.../wp-admin/post.php?post={id}&action=edit'
	 *      * Metadata state: Correct ✓ (metadata now saved)
	 *      * Reason: Meta box loader saves additional metadata
	 *    - Action: IGNORE call 1, PROCESS call 2
	 *
	 * 3. BLOCK EDITOR WITHOUT META BOXES:
	 *    - Hook calls: 2x
	 *    - Call 1:
	 *      * REQUEST_URI: '/wp-json/wp/v2/{post_type}/{id}?_locale=user'
	 *      * HTTP_REFERER: '.../wp-admin/post.php?post={id}&action=edit'
	 *      * Metadata state: Correct ✓ (no additional metadata to save)
	 *    - Call 2:
	 *      * REQUEST_URI: '/wp-admin/post.php?post={id}&action=edit&meta-box-loader=1&meta-box-loader-nonce=...'
	 *      * HTTP_REFERER: '.../wp-admin/post.php?post={id}&action=edit'
	 *      * Metadata state: Correct ✓ (unchanged)
	 *    - Action: IGNORE call 1, PROCESS call 2 (for consistency)
	 *
	 * 4. SITE EDITOR (Full Site Editing):
	 *    - Hook calls: 1x
	 *    - REQUEST_URI: '/wp-json/wp/v2/{post_type}/{id}?_locale=user'
	 *    - HTTP_REFERER: '.../wp-admin/site-editor.php?p=%2Fpage%2F{id}&canvas=edit'
	 *    - Metadata state: Correct ✓
	 *    - Action: Process normally
	 *
	 * 5. QUICK EDIT:
	 *    - Hook calls: 1x
	 *    - REQUEST_URI: '/wp-admin/admin-ajax.php'
	 *    - Metadata state: Correct ✓
	 *    - Action: Process normally
	 *
	 * THE CHALLENGE:
	 * ==============
	 * The REQUEST_URI for both "Block Editor Call 1" and "Site Editor" are IDENTICAL:
	 * '/wp-json/wp/v2/{post_type}/{id}?_locale=user'
	 *
	 * However, their behavior differs:
	 * - Block Editor: First call of two (should be ignored)
	 * - Site Editor: Only call (should be processed)
	 *
	 * THE SOLUTION:
	 * =============
	 * We differentiate these scenarios using HTTP_REFERER:
	 * - Block Editor referer: '.../wp-admin/post.php?post={id}&action=edit'
	 * - Site Editor referer: '.../wp-admin/site-editor.php?p=%2Fpage%2F{id}&canvas=edit'
	 *
	 * Logic:
	 * 1. If REQUEST_URI is a REST API call (/wp-json/wp/v2/...)
	 *    AND HTTP_REFERER contains '/wp-admin/post.php'
	 *    → IGNORE (this is Block Editor call 1, call 2 will follow)
	 *
	 * 2. Otherwise, use transient-based deduplication:
	 *    - Check if transient 'synced_post_update_{post_id}' exists
	 *    - If exists: IGNORE (already processed within last 2 seconds)
	 *    - If not: Set transient and PROCESS
	 *
	 * DEBUGGING:
	 * ==========
	 * Uncomment the error_log statements below to debug request patterns:
	 * - error_log( 'request_uri: ' . $request_uri );
	 * - error_log( 'http_referer: ' . $http_referer );
	 * - error_log( ' --- IGNORED - because it comes from the post editor ---' );
	 * - error_log( ' --- IGNORED - because it has already been processed ---' );
	 * - error_log( ' --- VALID ---' );
	 *
	 * @since 2.18.0
	 * @param int $post_id The ID of the post being updated.
	 * @return bool True if the update should be ignored, false if it should be processed.
	 */
	public function maybe_ignore_this_post_update_action( $post_id ) {

		// Get the current request URI
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';

		// Get the URI where the request came from
		$http_referer = isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '';

		// Get the REST API prefix (typically '/wp-json/wp/')
		$wp_rest_prefix = '/' . trailingslashit( rest_get_url_prefix() ) . 'wp/';

		// Debugging (uncomment to troubleshoot):
		// error_log( 'request_uri: ' . $request_uri );
		// error_log( 'http_referer: ' . $http_referer );
		// error_log( 'wp_rest_prefix: ' . $wp_rest_prefix );

		// Check if this is a REST API request
		$is_rest_api_request = strpos( $request_uri, $wp_rest_prefix ) !== false;

		if ( $is_rest_api_request ) {
			// Check if the request originated from the Block Editor (post.php)
			// This indicates it's the first call of a two-call sequence
			$is_post_editor_request = strpos( $http_referer, '/wp-admin/post.php' ) !== false || strpos( $http_referer, '/wp-admin/post-new.php' ) !== false;

			if ( $is_post_editor_request ) {
				// Ignore: This is the Block Editor's first call (without complete metadata)
				// The second call with meta-box-loader will follow shortly
				// error_log( 'action ignored because it comes from the post editor ---> will not be processed' );
				return true;
			}
		}

		// Never process this call twice after the post update for the same post
		if ( $this->already_processed( 'this_post_update_action', $post_id ) ) {
			// error_log( 'action ignored because it has already been processed ---> will not be processed' );
			return true;
		}

		// Allow processing: This is a valid update that should be handled
		// error_log( 'action valid ---> will be processed' );
		return false;
	}
}
