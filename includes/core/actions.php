<?php
/**
 * Content Syncs Actions
 *
 * Handles all post actions, like import, export, update, delete, etc.
 *
 * The `Actions` class centralises the implementation of import, export
 * and update operations for global posts. It hooks into Greyd and
 * WordPress filters to manage conflict resolution, update post metadata,
 * and queue exports via the distribution system. Each method in this
 * class typically corresponds to a custom action or filter; for example,
 * `make_post_global` prepares a post for export and assigns a global ID.
 * Use this class as the primary entry point for programmatically
 * exporting or importing posts or for adding new behaviours around
 * global post operations.
 */

namespace Contentsync\Contents;

use \Contentsync\Main_Helper;
use \Contentsync\Cluster\Mail;
use \Contentsync\Distribution\Distributor;
use \Contentsync\Connections\Remote_Operations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

new Actions();
class Actions {

	/**
	 * Whether logs are echoed.
	 * Usually set via function @see enable_logs();
	 * Logs are especiallly usefull when debugging ajax actions.
	 *
	 * @var bool
	 */
	public static $logs = false;

	/**
	 * Class constructor
	 */
	public function __construct() {

		// import
		add_filter( 'contentsync_import_post_conflicts', array( $this, 'remove_conflict_when_same_gid' ), 10, 2 );
		add_action( 'contentsync_after_import_post', array( $this, 'update_contentsync_meta_after_insert_post' ), 10, 2 );
		add_filter( 'contentsync_import_conflict_actions', array( $this, 'match_global_posts_before_import' ), 10, 2 );
	}


	/**
	 * =================================================================
	 *                          UPDATE
	 * =================================================================
	 */

	/**
	 * Make a post global
	 * @formerly contentsync_export_post
	 *
	 * This sets the default post meta for global posts
	 * 
	 *
	 * @param int   $post_id    Post ID.
	 * @param array $args       Export arguments.
	 *
	 * @return string   $gid
	 */
	public static function make_post_global( $post_id, $args ) {

		$first_post = null;
		$post       = Main_Helper::call_post_export_func( 'export_post', $post_id, $args );
		$posts      = Main_Helper::call_post_export_func( 'get_all_posts' );
		$gid        = get_current_blog_id() . '-' . strval( $post_id );

		// loop through all the nested posts and make them global.
		foreach ( $posts as $_post_id => $_post ) {

			if ( $_post_id == $post_id ) {
				continue;
			}

			// don't make global if it is somehow already global
			if (
				! empty( get_post_meta( $_post_id, 'synced_post_id', true ) )
				|| ! empty( get_post_meta( $_post_id, 'synced_post_status', true ) )
			) {
				continue;
			}

			$_gid = get_current_blog_id() . '-' . strval( $_post_id ); // The global ID. Format {{blog_id}}-{{post_id}}

			update_post_meta( $_post_id, 'synced_post_id', $_gid );
			update_post_meta( $_post_id, 'synced_post_status', 'root' );
			update_post_meta( $_post_id, 'contentsync_options', (array) $args );

			// if there already were connections, we don't want to loose these
			$connection_map = Main_Helper::get_post_connection_map( $_post_id ) ?? array();
			update_post_meta( $_post_id, 'contentsync_connection_map', $connection_map );

			/**
			 * @since 2.3.0 set 'contentsync_canonical_url' to permalink
			 */
			update_post_meta( $_post_id, 'contentsync_canonical_url', get_permalink( $_post_id ) );
		}

		update_post_meta( $post_id, 'synced_post_id', $gid );
		update_post_meta( $post_id, 'synced_post_status', 'root' );
		update_post_meta( $post_id, 'contentsync_options', (array) $args );

		// if there already were connections, we don't want to loose these
		$connection_map = Main_Helper::get_post_connection_map( $post_id ) ?? array();
		update_post_meta( $post_id, 'contentsync_connection_map', $connection_map );

		/**
		 * @since 2.3.0 set 'contentsync_canonical_url' to permalink
		 */
		update_post_meta( $post_id, 'contentsync_canonical_url', get_permalink( $post_id ) );

		return $gid;
	}

	/**
	 * Update global content meta options.
	 * 
	 * @since 1.7.6
	 * 
	 * @param int     $post_id            Post ID.
	 * @param array   $request_post_data  $_POST data.
	 */
	public static function update_contentsync_options( $post_id, $request_post_data ) {

		/**
		 * Now we update the root post itself.
		 *
		 * @since 1.7 'contentsync_options' can now be updated.
		 * @since 1.8 'contentsync_canonical_url' can now be defined.
		 */
		if ( isset( $request_post_data['editable_contentsync_options'] ) && is_array( $request_post_data['editable_contentsync_options'] ) ) {

			if ( self::$logs ) {
				echo "\r\n\r\n" . "Update 'contentsync_options'.";
			}

			$meta_updated   = false;
			$contentsync_options     = $old_contentsync_options = Main_Helper::get_contentsync_meta( $post_id, 'contentsync_options' );
			foreach ( $request_post_data['editable_contentsync_options'] as $option_name => $raw_value ) {
				$contentsync_options[ $option_name ] = $raw_value === 'on';
				if ( self::$logs ) {
					echo "\r\n  - $option_name = $raw_value";
				}
			}
			if ( $old_contentsync_options !== $contentsync_options ) {
				$meta_updated = update_post_meta( $post_id, 'contentsync_options', $contentsync_options );
			}
			if ( self::$logs ) {
				echo "\r\n" . '→ ' . ( $meta_updated ? 'options have been updated.' : 'options are unchanged' );
			}
		}
		if ( isset( $request_post_data['contentsync_canonical_url'] ) ) {

			if ( self::$logs ) {
				echo "\r\n\r\n" . "Update 'contentsync_canonical_url'.";
			}

			$contentsync_canonical_url = esc_url( trim( strval( $request_post_data['contentsync_canonical_url'] ) ) );
			$meta_updated     = update_post_meta( $post_id, 'contentsync_canonical_url', $contentsync_canonical_url );
			if ( self::$logs ) {
				echo "\r\n" . '→ ' . ( $meta_updated ? 'contentsync_canonical_url has been updated.' : 'contentsync_canonical_url could not be updated' );
			}

			// error_log( 'contentsync_canonical_url: ' . $contentsync_canonical_url );
		}
	}

	/**
	 * =================================================================
	 *                          POST REVIEW
	 * =================================================================
	 */

	/**
	 * Create a post review or update an existing one.
	 * 
	 * @param int     $post_id      Post ID.
	 * @param WP_Post $post_before  Post object before the update.
	 * 
	 * @return void
	 */
	public static function create_post_review( $post_id, $post_before=null ) {

		$send_mail = true;

		// check if the post has been distributed yet
		$post_connection_map = Main_Helper::get_post_connection_map( $post_id );
		$state = empty( $post_connection_map ) ? 'new' : 'in_review';

		// is there an active review?
		$synced_post_review = get_synced_post_review_by_post( $post_id, get_current_blog_id() );

		if ( $synced_post_review ) {

			// keep the status 'new', otherwise set it to 'in_review'
			$state = $synced_post_review->state === 'new' ? 'new' : 'in_review';

			// only send mail if the state has changed
			if ( $state == $synced_post_review->state ) {
				$send_mail = false;
			}

			$update = array(
				'editor'  => get_current_user_id(),
				'date'    => date( 'Y-m-d H:i:s', time() ),
				'state'   => $state
			);

			$review_id = update_synced_post_review( $synced_post_review->ID, $update );

		} else {

			$post_before_id = $post_before ? $post_before->ID : $post_id;
			$post_before_export = Main_Helper::export_post( $post_before_id, array() );
			if ( $post_before ) {
				// loop through all keys of the $post_before object and compare them with the export
				foreach ( $post_before as $key => $value ) {
					if ( isset($post_before_export->$key) && $value !== $post_before_export->$key ) {
						$post_before_export->$key = $value;
					}
				}
				// var_error_log( $post_before_export );
			}
			if ( !empty( $post_connection_map ) ) {
				// add contentsync_connection_map meta to handle deleted post
				$post_before_export->meta["contentsync_connection_map"] = $post_connection_map;
			}

			$insert = array(
				'blog_id'       => get_current_blog_id(),
				'post_id'       => $post_id,
				'editor'        => get_current_user_id(),
				'date'          => date( 'Y-m-d H:i:s', time() ),
				'state'         => $state,
				'previous_post' => $post_before_export
			);

			$review_id = insert_synced_post_review( $insert );
		}

		if ( $send_mail ) {
			$mail_result = Mail::send_review_mail( $review_id, $state, 'reviewers' );
		}
	}

	/**
	 * Approve a post review.
	 * 
	 * @param int $review_id  Review ID.
	 * @param int $post_id    Post ID.
	 * 
	 * @return bool
	 */
	public static function approve_post_review( $review_id, $post_id=null ) {

		if ( ! $review_id ) {
			return false;
		}

		$post_review = get_synced_post_review_by_id( $review_id );
		if ( in_array( $post_review->state, array( 'denied', 'approved', 'reverted' ) ) ) {
			// review already finished
			return false;
		}

		if ( ! $post_id ) {
			$post_id = $post_review->post_id;
		}

		$post = get_post( $post_id );
		// debug($post);

		$previous_state = $post_review->state;

		// set the review state to 'approved' before the post is distributed
		// otherwise the distributor will use the previous post as post is
		// still technicaly in review
		set_synced_post_review_state( $review_id, 'approved' );

		if ( !$post ) {
			// debug("on_delete_global_post");
			$result = Trigger::on_delete_global_post( $post_review->previous_post );
		}
		else if ( $post->post_status == 'trash' ) {
			// debug("on_trash_global_post");
			$result = Trigger::on_trash_global_post( $post_review->previous_post );
		}
		else if ( $post_review->previous_post->post_status == 'trash' ) {
			// debug("on_untrash_global_post");
			$result = Trigger::on_untrash_global_post( $post_id );
		}
		else {
			// debug("distribute_post");
			$destination_ids   = array();
			foreach ( get_clusters_including_post( $post ) as $cluster ) {
				$destination_ids = array_merge( $destination_ids, $cluster->destination_ids );
			}

			// distribute the post
			$result = Distributor::distribute_single_post( $post_id, $destination_ids );
		}

		if ( ! $result ) {
			// revert the review state to the previous state
			set_synced_post_review_state( $review_id, $previous_state );
			return false;
		}

		$new_message = new \Synced_Post_Review_Message( $review_id, array(
			'content' => '',
			'timestamp' => time(),
			'action' => 'approved',
			'reviewer' => get_current_user_id() // $post_review->editor
		) );
		$new_message->save();

		Mail::send_review_mail( $review_id, 'approved', 'editor' );

		return true;
	}

	/**
	 * Deny a post review.
	 * 
	 * @param int $review_id  Review ID.
	 * @param int $post_id    Post ID.
	 * 
	 * @return bool
	 */
	public static function deny_post_review( $review_id, $post_id=null, $message_content='' ) {

		if ( ! $review_id ) {
			return false;
		}

		$post_review = get_synced_post_review_by_id( $review_id );
		if ( in_array( $post_review->state, array( 'denied', 'approved', 'reverted' ) ) ) {
			// review already finished
			return false;
		}
		
		if ( ! $post_id ) {
			$post_id = $post_review->post_id;
		}

		$new_message_args = array(
			'content' => $message_content,
			'timestamp' => time(),
			'action' => 'denied',
			'reviewer' => get_current_user_id() // $post_review->editor
		);

		$new_message = new \Synced_Post_Review_Message( $review_id, $new_message_args );
		$new_message->save();

		$result = set_synced_post_review_state( $review_id, 'denied' );

		Mail::send_review_mail( $review_id, 'denied', 'editor' );

		return $result;
	}

	/**
	 * Revert a post review.
	 * 
	 * @param int $review_id  Review ID.
	 * @param int $post_id    Post ID.
	 * 
	 * @return bool
	 */
	public static function revert_post_review( $review_id, $post_id=null, $message_content='' ) {

		if ( ! $review_id ) {
			return false;
		}

		$post_review = get_synced_post_review_by_id( $review_id );
		
		if ( ! $post_id ) {
			$post_id = $post_review->post_id;
		}

		$new_message_args = array(
			'content' => $message_content,
			'timestamp' => time(),
			'action' => 'reverted',
			'reviewer' => get_current_user_id() // $post_review->editor
		);

		$new_message = new \Synced_Post_Review_Message( $review_id, $new_message_args );
		$new_message->save();

		$previous_post = $post_review->previous_post;

		// get the destination ids
		$destination_ids   = array();
		foreach ( get_clusters_including_post( $post_id ) as $cluster ) {
			$destination_ids = array_merge( $destination_ids, $cluster->destination_ids );
		}

		// if post_status is 'auto-draft', the post did not exist before
		if ( $previous_post && $previous_post->post_status === 'auto-draft' ) {
			wp_trash_post( $post_id );
		}
		else {
			// revert the post to the previous state
			$result = Main_Helper::import_posts(
				// posts
				array( $post_id => $previous_post ),
				// conflict actions
				array(
					$post_id => array(
						'post_id' => $post_id,
						'action'  => 'replace'
					)
				)
			);

			// distribute the post
			$result = Distributor::distribute_single_post( $post_id, $destination_ids );
		}

		Mail::send_review_mail( $review_id, 'reverted', 'editor' );

		$result = set_synced_post_review_state( $review_id, 'reverted' );

		return $result;
	}

	/**
	 * =================================================================
	 *                          CHECK IMPORT
	 * =================================================================
	 */

	/**
	 * Check global post for conflicts on this page
	 *
	 * @param string $gid   Global ID.
	 *
	 * @return string   Encoded array of conflicts or post_title.
	 */
	public static function contentsync_check_import( $gid ) {

		$posts = Main_Helper::prepare_global_post_for_import( $gid );
		if ( ! $posts ) {
			return false;
		}

		// get conflicting posts
		$conflicts = Main_Helper::call_post_export_func( 'import_get_conflict_posts_for_backend_form', $posts );

		if ( $conflicts ) {
			return $conflicts;
		} else {
			// return post title when no conflicts found
			$root_post = array_shift( $posts );
			return $root_post->post_title;
		}
	}

	/**
	 * Remove conflicts when same global ID is set. This means the posts
	 * both depend on the same global post.
	 *
	 * @filter 'contentsync_import_conflicts'
	 *
	 * @param array[WP_Post] $conflicts     WP_Post objects in conflict with importing posts.
	 * @param array[WP_Post] $all_posts     All preparred WP_Post objects.
	 */
	public function remove_conflict_when_same_gid( $conflicts, $all_posts ) {

		foreach ( $conflicts as $post_id => $post ) {

			$current_gid = Main_Helper::get_gid( $post->ID );
			$import_gid  = Main_Helper::get_gid( $all_posts[ $post_id ] );

			// remove the conflict if it is the same global post
			if ( Main_Helper::get_global_post( $current_gid ) && $current_gid === $import_gid ) {
				unset( $conflicts[ $post_id ] );
			}
		}
		return $conflicts;
	}

	/**
	 * =================================================================
	 *                          IMPORT
	 * =================================================================
	 */

	/**
	 * Import a global post to the current blog
	 *
	 * @param string $gid               The global ID. Format {{blog_id}}-{{post_id}}
	 * @param array  $conflict_actions   Array of posts that already exist on the current blog.
	 *                                   Keyed by the same ID as in the @param $posts.
	 *                                  @property post_id: ID of the current post.
	 *                                  @property action: Action to be done (skip|replace|keep)
	 *
	 * @return bool|string  True on success. False or error message on failure.
	 */
	public static function contentsync_import_post( $gid, $conflict_actions = array() ) {

		$posts = Main_Helper::prepare_global_post_for_import( $gid );
		if ( ! $posts ) {
			return false;
		}

		if ( self::$logs ) {
			Main_Helper::call_post_export_func( 'enable_logs' );
		}

		do_action( 'synced_post_export_log', "\r\n------\r\n\r\n" . 'All posts prepared. Now we insert them.' );

		$result = Main_Helper::import_posts( $posts, $conflict_actions );

		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}
		return true;
	}

	/**
	 * Import only the global posttype to the current blog
	 *
	 * @param string $gid               The global ID. Format {{blog_id}}-{{post_id}}
	 * @param array  $conflict_actions   Array of posts that already exist on the current blog.
	 *                                   Keyed by the same ID as in the @param $posts.
	 *                                  @property post_id: ID of the current post.
	 *                                  @property action: Action to be done (skip|replace|keep)
	 *
	 * @return bool|string  True on success. False or error message on failure.
	 */
	public static function contentsync_import_posttype( $gid, $conflict_actions = array() ) {

		$posts = Main_Helper::prepare_global_post_for_import( $gid, array(
			'whole_posttype' => false,
		) );
		if ( ! $posts ) {
			return false;
		}

		if ( self::$logs ) {
			Main_Helper::call_post_export_func( 'enable_logs' );
		}

		do_action( 'synced_post_export_log', "\r\n------\r\n\r\n" . 'All posts prepared. Now we insert only the posttype itself.' );

		$posts  = array_slice( $posts, 0, 1, true );
		$result = Main_Helper::import_posts( $posts, $conflict_actions );

		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}
		return true;
	}

	/**
	 * Filter the conflict actions before every import, so all global
	 * posts are matched with existing ones.
	 *
	 * @filter 'contentsync_import_conflict_actions'
	 *
	 * @param array $conflict_actions   Keyed by orig. ID, values contain 'post_id' & 'action'
	 * @param array $all_posts          All posts to be imported.
	 */
	public function match_global_posts_before_import( $conflict_actions, $all_posts ) {

		if ( ! is_array( $all_posts ) ) {
			$all_posts = (array) $all_posts;
		}

		/**
		 * Look for existing local posts with the same gid and skip them.
		 */
		foreach ( $all_posts as $post_id => $post ) {

			if ( ! is_object( $post ) ) {
				$post = (object) $post;
			}

			// skip if already set
			if ( isset( $conflict_actions[ $post->ID ] ) ) {
				continue;
			}

			/**
			 * Filter to modify the GID (Global ID) used for conflict resolution during import.
			 * 
			 * This filter allows developers to customize how the GID is generated or retrieved
			 * when checking for conflicts between global posts during import operations.
			 * 
			 * @filter filter_gid_for_conflict_action
			 * 
			 * @param string $gid    The GID (Global ID) for the post.
			 * @param int    $post_id The post ID.
			 * @param object $post   The post object.
			 * 
			 * @return string $gid   The modified GID for conflict resolution.
			 */
			$gid = apply_filters( 'filter_gid_for_conflict_action', Main_Helper::get_gid( $post ), $post->ID, (object) $post );

			$existing_post = Main_Helper::get_local_post_by_gid( $gid, $post->post_type );
			if ( $existing_post ) {

				$action = isset( $post->is_contentsync_root_post ) && $post->is_contentsync_root_post ? 'replace' : 'skip';
				// if ( isset( $post->is_contentsync_root_post ) && $post->is_contentsync_root_post ) {
				// 	\Contentsync\Distribution\Logger::log( 'Post is contentsync_root_post: ' . $post->is_contentsync_root_post );
				// }

				$conflict_actions[ $post->ID ] = array(
					'post_id' => $existing_post->ID,
					'action'  => $action,
				);
				if ( self::$logs ) {
					echo "\r\n" . sprintf( "Matching local post found with GID '%s' and post-type '%s'.", $gid, $post->post_type );
				}
			}
		}

		return $conflict_actions;
	}

	/**
	 * Update contentsync meta after post was imported.
	 *
	 * @param int    $post_id  The new post ID.
	 * @param object $post  The preparred WP_Post object.
	 */
	public function update_contentsync_meta_after_insert_post( $post_id, $post ) {

		$gid = Main_Helper::get_gid( $post_id );
		if ( empty( $gid ) ) {
			return;
		}

		list( $root_blog_id, $root_post_id, $root_net_url ) = Main_Helper::explode_gid( $gid );
		if ( $root_post_id === null ) {
			return false;
		}

		$current_status = Main_Helper::get_contentsync_meta( $post, 'synced_post_status' );
		$new_status     = null;

		// if gid values match, this is the root post
		if ( $root_blog_id == get_current_blog_id() && $post_id == $root_post_id && empty( $root_net_url ) ) {
			if ( self::$logs ) {
				echo "\r\n" . sprintf( "New contentsync post status is 'root' because gid values match: %s", $gid );
			}
			$new_status = 'root';
		}
		// blog doesn't exist in this multisite network
		elseif ( empty( $root_net_url ) && function_exists( 'get_blog_details' ) && get_blog_details( $root_blog_id, false ) === false ) {
			if ( self::$logs ) {
				echo "\r\n" . sprintf( "Blog doesn't exist in the current network: %s", $gid );
			}
			Main_Helper::delete_contentsync_meta( $post_id );
		}
		// this is a linked post
		elseif ( $root_blog_id != get_current_blog_id() || ! empty( $root_net_url ) ) {
			if ( self::$logs ) {
				echo "\r\n" . sprintf( 'The gid values do not match (%s) - this is a linked post!', $gid );
			}
			$new_status = 'linked';
			Main_Helper::add_post_connection_to_connection_map( $gid, get_current_blog_id(), $post_id );
		}

		// update the status if changed
		if ( $new_status && $new_status !== $current_status ) {
			update_post_meta( $post_id, 'synced_post_status', $new_status );
		}
	}

	/**
	 * =================================================================
	 *                          UN- EX-/IMPORT
	 * =================================================================
	 */

	/**
	 * Convert global post to static one
	 *
	 * @param int $gid
	 *
	 * @return bool
	 */
	public static function contentsync_unexport_post( $gid ) {

		list( $blog_id, $post_id, $net_url ) = Main_Helper::explode_gid( $gid );

		// only local network posts
		if ( ! empty( $net_url ) ) {
			return false;
		}

		Main_Helper::switch_to_blog( $blog_id );

		$connection_map = Main_Helper::get_post_connection_map( $post_id );

		// delete meta of imported posts
		if ( is_array( $connection_map ) && count( $connection_map ) > 0 ) {
			foreach ( $connection_map as $_blog_id => $post_connection ) {
				if ( is_numeric( $_blog_id ) ) {
					Main_Helper::switch_to_blog( $_blog_id );
					Main_Helper::delete_contentsync_meta( $post_connection['post_id'] );
					Main_Helper::restore_blog();
				} else {
					/**
					 * @todo handle remote connections
					 *
					 * ...for now, we do not delete remote post meta values.
					 */
				}
			}
		}

		// delete meta of exported post
		Main_Helper::delete_contentsync_meta( $post_id );

		Main_Helper::restore_blog();

		return true;
	}

	/**
	 * Unlink imported post from the global post
	 *
	 * @param int $post_id  The ID of the imported post.
	 *
	 * @return bool
	 */
	public static function contentsync_unimport_post( $post_id ) {

		$gid    = get_post_meta( $post_id, 'synced_post_id', true );
		$result = Main_Helper::remove_post_connection_from_connection_map( $gid, get_current_blog_id(), $post_id );

		Main_Helper::delete_contentsync_meta( $post_id );

		return true;
	}

	/**
	 * =================================================================
	 *                          TRASH & DELETE
	 * =================================================================
	 */

	/**
	 * @todo REWORK with new distributor (test)
	 */
	public static function trash_connected_posts( $post_id, $connection_map = null ) {

		$result = true;

		if ( !$connection_map ) {
			$connection_map = Main_Helper::get_post_connection_map( $post_id );
		}
		\Contentsync\Distribution\Logger::add( 'trash_connected_posts', $post_id );
		\Contentsync\Distribution\Logger::add( 'connection_map', $connection_map );

		$destination_ids = Main_Helper::convert_connection_map_to_destination_ids( $connection_map );
		\Contentsync\Distribution\Logger::add( 'destination_ids', $destination_ids );

		$destination_arrays = array();
		foreach ( $destination_ids as $destination_id ) {
			$destination_arrays[ $destination_id ] = array(
				'import_action' => 'trash'
			);
		}
		\Contentsync\Distribution\Logger::add( 'destination_arrays', $destination_arrays );

		if ( is_object( $post_id ) && is_a( $post_id, 'Contentsync\Prepared_Post' ) ) {
			$post_id->import_action = 'trash';
		}

		$result = Distributor::distribute_single_post( $post_id, $destination_arrays );

		// if ( $connection_map && !empty($connection_map) ) {
		// 	foreach ( $connection_map as $blog_id => $post_connection ) {
		// 		if ( is_numeric( $blog_id ) ) {
		// 			Main_Helper::switch_to_blog( $blog_id );
		// 			$result = wp_trash_post( $post_connection['post_id'], true );
		// 			if ( self::$logs ) {
		// 				if ( $result ) {
		// 					echo "\r\n" . "post {$post_connection['post_id']} on blog {$blog_id} trashed.";
		// 				} else {
		// 					echo "\r\n" . "post {$post_connection['post_id']} on blog {$blog_id} could NOT be trashed.";
		// 				}
		// 			}
		// 			Main_Helper::restore_blog();
		// 		}
		// 		// else {
		// 		// 	$remote_network_url = $blog_id;
		// 		// 	$remote_gid         = $root_blog_id . '-' . $root_post_id . '-' . Main_Helper::get_network_url();
		// 		// 	$response           = Remote_Operations::delete_all_remote_connected_posts( $remote_network_url, $remote_gid, $post_connection );
		// 		// }
		// 	}
		// }

		return $result;

	}

	/**
	 * @todo potentially rework with new distributor
	 */
	public static function untrash_connected_posts( $post_id, $delete = false ) {

		$result = true;

		$root_gid    = Main_Helper::get_contentsync_meta( $post_id, 'synced_post_id', true );
		// debug($root_gid);

		// debug("untrash linked posts");
		$destination_ids = array();
		foreach ( get_clusters_including_post( $post_id ) as $cluster ) {
			$destination_ids = array_merge( $destination_ids, $cluster->destination_ids );
		}
		$destination_ids = array_unique( $destination_ids );
		foreach ( $destination_ids as $destination_id ) {
			if ( empty( $destination_id ) ) {
				continue;
			}
			Main_Helper::switch_to_blog($destination_id);
			$trashed = Main_Helper::get_posts(
				array(
					'numberposts' => -1,
					'post_status' => 'trash'
				)
			);
			// debug($trashed);
			if ( $trashed ) foreach ( $trashed as $trash ) {
				$status = Main_Helper::get_contentsync_meta( $trash->ID, 'synced_post_status', true );
				$gid    = Main_Helper::get_contentsync_meta( $trash->ID, 'synced_post_id', true );
				if ( $status == 'linked' && $gid == $root_gid ) {
					// debug($trash);
					if ( !$delete ) {
						$result = wp_untrash_post( $trash->ID, true );
						if ( self::$logs ) {
							if ( $result ) {
								echo "\r\n" . "post {$trash->ID} on blog {$destination_id} untrashed.";
							} else {
								echo "\r\n" . "post {$trash->ID} on blog {$destination_id} could NOT be untrashed.";
							}
						}
					}
					else {
						$result = wp_delete_post( $trash->ID, true );
						if ( self::$logs ) {
							if ( $result ) {
								echo "\r\n" . "post {$trash->ID} on blog {$destination_id} deleted.";
							} else {
								echo "\r\n" . "post {$trash->ID} on blog {$destination_id} could NOT be deleted.";
							}
						}
					}
				}
			}
			Main_Helper::restore_blog();
		}

		return $result;

	}

	/**
	 * @todo REWORK with new distributor (test)
	 */
	public static function delete_connected_posts( $post_id, $connection_map = null ) {

		if ( !$connection_map ) {
			$connection_map = Main_Helper::get_post_connection_map( $post_id );
		}
		\Contentsync\Distribution\Logger::log( 'delete_connected_posts', $post_id );
		\Contentsync\Distribution\Logger::log( 'connection_map', $connection_map );

		$destination_ids = Main_Helper::convert_connection_map_to_destination_ids( $connection_map );
		\Contentsync\Distribution\Logger::log( 'destination_ids', $destination_ids );

		$destination_arrays = array();
		foreach ( $destination_ids as $destination_id ) {
			$destination_arrays[ $destination_id ] = array(
				'import_action' => 'delete'
			);
		}
		\Contentsync\Distribution\Logger::log( 'destination_arrays', $destination_arrays );

		$result = Distributor::distribute_single_post( $post_id, $destination_arrays );

		// if ( $connection_map && !empty($connection_map) ) {
		// 	foreach ( $connection_map as $blog_id => $post_connection ) {
		// 		if ( is_numeric( $blog_id ) ) {
		// 			Main_Helper::switch_to_blog( $blog_id );
		// 			$result = wp_delete_post( $post_connection['post_id'], true );
		// 			Main_Helper::restore_blog();
		// 		}
		// 		else {
		// 			if ( strpos($blog_id, '|') !== false) {
		// 				list ($blog_id, $remote_network_url) = explode('|', $blog_id);
		// 				$root_gid = Main_Helper::get_contentsync_meta( $post_id, 'synced_post_id' );
		// 				$result = Remote_Operations::delete_all_remote_connected_posts( $remote_network_url, $root_gid, $post_connection );
		// 			} 
		// 		}

		// 		if ( self::$logs ) {
		// 			if ( $result ) {
		// 				echo "\r\n" . "post {$post_connection['post_id']} on blog {$blog_id} deleted.";
		// 			} else {
		// 				echo "\r\n" . "post {$post_connection['post_id']} on blog {$blog_id} could NOT be deleted.";
		// 			}
		// 		}
		// 	}
		// }

		return true;
	}

	public static function unlink_connected_posts( $post_id ) {

		$result = true;

		$root_gid = Main_Helper::get_contentsync_meta( $post_id, 'synced_post_id', true );

		$destination_ids = array();
		foreach ( get_clusters_including_post( $post_id ) as $cluster ) {
			$destination_ids = array_merge( $destination_ids, $cluster->destination_ids );
		}
		$destination_ids = array_unique( $destination_ids );
		// debug($destination_ids);
		foreach ( $destination_ids as $destination_id ) {
			if ( empty( $destination_id ) ) {
				continue;
			}
			$result = Main_Helper::remove_post_connection_from_connection_map( $root_gid, $destination_id, $post_id );
		}

		return $result;

	}


	/**
	 * @todo REWORK with new distributor (test)
	 */
	public static function delete_unlinked_posts( $post ) {

		if ( !$connection_map ) {
			$connection_map = Main_Helper::get_post_connection_map( $post );
		}
		\Contentsync\Distribution\Logger::log( 'delete_unlinked_posts', $post );
		\Contentsync\Distribution\Logger::log( 'connection_map', $connection_map );

		$destination_ids = Main_Helper::convert_connection_map_to_destination_ids( $connection_map );
		\Contentsync\Distribution\Logger::log( 'destination_ids', $destination_ids );

		$destination_arrays = array();
		foreach ( $destination_ids as $destination_id ) {
			$destination_arrays[ $destination_id ] = array(
				'import_action' => 'delete'
			);
		}
		\Contentsync\Distribution\Logger::log( 'destination_arrays', $destination_arrays );

		$result = Distributor::distribute_single_post( $post, $destination_arrays );

		return $result;

	}
	
	/**
	 * Permanently delete a global post and all it's linked posts.
	 * The root post needs to be on the current network. This can not be undone.
	 * 
	 * @todo REWORK with new distributor (test)
	 *
	 * @param string $gid
	 *
	 * @return WP_Post|false|null Post data on success, false or null on failure.
	 */
	public static function delete_global_post( $gid, $keep_root_post=false ) {

		if ( self::$logs ) {
			echo "\r\n" . sprintf( "DELETE global post with gid '%s'", $gid ) . "\r\n";
			Main_Helper::call_connections_func( 'enable_logs' );
		}

		// needs to be from this site
		list( $root_blog_id, $root_post_id, $root_net_url ) = Main_Helper::explode_gid( $gid );
		if ( $root_post_id === null || ! empty( $root_net_url ) ) {
			return false;
		}

		// needs to be the root post
		$global_post = Main_Helper::get_global_post( $gid );
		if ( ! $global_post || $global_post->meta['synced_post_status'] !== 'root' ) {
			return false;
		}

		$result = true;
		Main_Helper::switch_to_blog( $root_blog_id );

		// delete imported posts
		$connection_map = Main_Helper::get_post_connection_map( $global_post->ID );
		\Contentsync\Distribution\Logger::log( 'delete_global_post', $global_post->ID );
		\Contentsync\Distribution\Logger::log( 'connection_map', $connection_map );

		$destination_ids = Main_Helper::convert_connection_map_to_destination_ids( $connection_map );
		\Contentsync\Distribution\Logger::log( 'destination_ids', $destination_ids );

		$destination_arrays = array();
		foreach ( $destination_ids as $destination_id ) {
			$destination_arrays[ $destination_id ] = array(
				'import_action' => 'delete'
			);
		}
		\Contentsync\Distribution\Logger::log( 'destination_arrays', $destination_arrays );

		$result = Distributor::distribute_single_post( $post, $destination_arrays );

		// delete the root post
		if ( ! $keep_root_post ) {
			$result = wp_delete_post( $root_post_id, true );
		}


		// restore blog
		Main_Helper::restore_blog();

		return $result;
	}

	/**
	 * @deprecated 2.8.0 Use make_post_global() instead.
	 */
	public static function contentsync_export_post($post_id, $args) {
		_deprecated_function(__FUNCTION__, '2.8.0', 'make_post_global');
		return self::make_post_global($post_id, $args);
	}
}
