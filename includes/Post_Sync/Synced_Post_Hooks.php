<?php

namespace Contentsync\Posts\Sync;

use Contentsync\Utils\Hooks_Base;
use Contentsync\Utils\Logger;

defined( 'ABSPATH' ) || exit;

class Synced_Post_Hooks extends Hooks_Base {

	/**
	 * Register hooks that run everywhere.
	 */
	public function register() {
		add_filter( 'contentsync_import_conflict_actions', array( $this, 'match_synced_posts_before_import' ), 10, 2 );
		add_filter( 'import_synced_post_conflicts', array( $this, 'remove_conflict_when_same_gid' ), 10, 2 );
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

			// skip if already set
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
					'post_id' => $existing_post->ID,
					'action'  => $action,
				);
				Logger::add( sprintf( "Matching local post found with GID '%s' and post-type '%s'.", $gid, $post->post_type ) );
			}
		}

		return $conflict_actions;
	}

	/**
	 * Remove conflicts when same global ID is set. This means the posts
	 * both depend on the same synced post.
	 *
	 * @filter 'contentsync_import_conflicts'
	 *
	 * @param array[WP_Post] $conflicts     WP_Post objects in conflict with importing posts.
	 * @param array[WP_Post] $all_posts     All prepared WP_Post objects.
	 */
	public function remove_conflict_when_same_gid( $conflicts, $all_posts ) {

		foreach ( $conflicts as $post_id => $post ) {

			$current_gid = Synced_Post_Utils::get_gid( $post->ID );
			$import_gid  = Synced_Post_Utils::get_gid( $all_posts[ $post_id ] );

			// remove the conflict if it is the same synced post
			if ( Synced_Post_Query::get_synced_post( $current_gid ) && $current_gid === $import_gid ) {
				unset( $conflicts[ $post_id ] );
			}
		}
		return $conflicts;
	}
}
