<?php

namespace Contentsync\Post_Sync;

use Contentsync\Utils\Hooks_Base;
use Contentsync\Utils\Logger;

defined( 'ABSPATH' ) || exit;

class Synced_Post_Hooks extends Hooks_Base {

	/**
	 * Register hooks that run everywhere.
	 */
	public function register() {
		add_filter( 'contentsync_import_post_with_conflict', array( $this, 'adjust_conflict_action_on_import_check' ), 10, 2 );
		add_filter( 'contentsync_import_conflict_actions', array( $this, 'match_synced_posts_before_import' ), 10, 2 );
	}

	/**
	 * Adjust conflict action when same global ID is set. This usually means the posts
	 * is already synced to this site - therefore this is not a conflict and we can
	 * skip the import of this post.
	 *
	 * @see \Contentsync\Post_Transfer\Post_Import::import_posts()
	 *
	 * @filter 'contentsync_import_post_with_conflict'
	 *
	 * @param WP_Post       $existing_post  The existing post object.
	 * @param Prepared_Post $importing_post     The prepared post object.
	 *
	 * @return WP_Post|null The existing post object or null if the conflict should be removed.
	 */
	public function adjust_conflict_action_on_import_check( $existing_post, $importing_post ) {

		// check if the existing post is a synced post
		$current_gid = Synced_Post_Utils::get_gid( $existing_post->ID );
		if ( empty( $current_gid ) ) {
			return $existing_post;
		}

		// check if the global post can be found
		$global_post = Synced_Post_Query::get_synced_post( $current_gid );
		if ( empty( $global_post ) ) {
			return $existing_post;
		}

		$import_gid = Synced_Post_Utils::get_gid( $global_post );

		// check if the global post is the same as the imported post
		if ( $current_gid === $import_gid ) {

			// set the conflict action to 'skip' and the conflict message to 'Already synced.'
			$existing_post->conflict_action  = 'skip';
			$existing_post->conflict_message = __( 'Already synced.', 'contentsync' );
		}

		return $existing_post;
	}

	/**
	 * Filter the conflict actions before every import, so all global
	 * posts are matched with existing ones.
	 *
	 * @filter 'contentsync_import_conflict_actions'
	 *
	 * @param array $conflict_actions   Keyed by orig. ID, values contain 'existing_post_id' & 'conflict_action'
	 * @param array $all_posts          All posts to be imported.
	 */
	public function match_synced_posts_before_import( $conflict_actions, $all_posts ) {

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

			/**
			 * Skip if already set. In general, this is only the case when the import is
			 * started from the modal in the admin area. In these cases we do not need
			 * additional conflict resolution.
			 *
			 * But whenever the import is started from the API, already imported synced
			 * posts have not been checked for conflicts yet.
			 */
			if ( isset( $conflict_actions[ $post->ID ] ) ) {
				continue;
			}

			/**
			 * Filter to modify the GID (Global ID) used for conflict resolution during import.
			 *
			 * This filter allows developers to customize how the GID is generated or retrieved
			 * when checking for conflicts between synced posts during import operations.
			 *
			 * @filter filter_gid_for_conflict_action
			 *
			 * @param string $gid    The GID (Global ID) for the post.
			 * @param int    $post_id The post ID.
			 * @param object $post   The post object.
			 *
			 * @return string $gid   The modified GID for conflict resolution.
			 */
			$gid = apply_filters( 'filter_gid_for_conflict_action', Synced_Post_Utils::get_gid( $post ), $post->ID, (object) $post );

			$existing_post = Synced_Post_Query::get_local_post_by_gid( $gid, $post->post_type );
			if ( $existing_post ) {

				$action = isset( $post->is_root_post ) && $post->is_root_post ? 'replace' : 'skip';

				$conflict_actions[ $post->ID ] = array(
					'existing_post_id' => $existing_post->ID,
					'conflict_action'  => $action,
					'original_post_id' => $post->ID,
				);
				Logger::add( sprintf( "Matching local post found with GID '%s' and post-type '%s'.", $gid, $post->post_type ) );
			}
		}

		return $conflict_actions;
	}
}
