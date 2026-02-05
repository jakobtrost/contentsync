<?php

namespace Contentsync\Admin\Views\Post_Transfer;

use Contentsync\Post_Transfer\Post_Transfer_Service;
use Contentsync\Utils\Urls;

defined( 'ABSPATH' ) || exit;

/**
 * Static helper class for handling conflicting posts.
 */
class Post_Conflict_Handler {

	/**
	 * Get posts with conflicts.
	 *
	 * @param Prepared_Post[]|WP_Post[] $posts  Prepared posts keyed by ID
	 *
	 * @return array   The original posts, keyed by ID, with some properties added:
	 *   @property object $existing_post        The conflicting post
	 *      @property string $post_link         The link to the conflicting post
	 *      @property string $original_post_id  The original post ID
	 */
	public static function get_import_posts_with_conflicts( $posts ) {

		if ( ! $posts || ! is_array( $posts ) ) {
			return array();
		}

		foreach ( $posts as $post_id => $post ) {
			if ( $existing_post = Post_Transfer_Service::get_post_by_name_and_type( $post ) ) {

				$existing_post->original_post_id = $post_id;
				$existing_post->post_link        = self::get_post_link_html( $post );

				$posts[ $post_id ]->existing_post = $existing_post;
			}
		}

		/**
		 * Filter to modify the list of posts with conflicts.
		 *
		 * @param array   $posts  Array of posts with conflicts.
		 *
		 * @return array
		 */
		$posts = apply_filters( 'contentsync_import_posts_with_conflicts', $posts );
		return $posts;
	}
	/**
	 * Get link to post as html object.
	 * Example: <a>Example page (Page)</a>
	 *
	 * @param WP_Post $post  The post object.
	 *
	 * @return string       The html link to the post.
	 */
	public static function get_post_link_html( $post ) {

		if ( ! is_object( $post ) ) {
			return '';
		}

		if ( $post->post_type === 'attachment' ) {
			$post_title = basename( get_attached_file( $post->ID ) );
			$post_type  = __( 'Image/file', 'contentsync' );
			$post_url   = wp_get_attachment_url( $post->ID );
		} else {
			$post_title = $post->post_title;
			$post_type  = get_post_type_object( $post->post_type )->labels->singular_name;
			$post_url   = Urls::get_edit_post_link( $post );
		}
		$post_title = empty( $post_title ) ? '<i>' . __( 'Unknown post', 'contentsync' ) . '</i>' : $post_title;
		$post_type  = empty( $post_type ) ? '<i>' . __( 'Unknown post type', 'contentsync' ) . '</i>' : $post_type;

		return "<a href='$post_url' target='_blank' title='" . __( 'Open in new tab', 'contentsync' ) . "'>$post_title ($post_type)</a>";
	}

	/**
	 * Get all conflicting post actions from the modal-form data.
	 *
	 * We have form data from the backend 'check-import' modal-form (see example below).
	 * We convert this to a proper array that can be used for the function
	 * Synced_Post_Service::import_synced_post() (See function doc for details)
	 *
	 * @param array $conflicts
	 * conflicts: [
	 *   0 => array(
	 *     'existing_post_id' => 123, // ID of the existing post on the current blog
	 *     'original_post_id' => 456, // ID of the original post
	 *     'conflict_action' => 'keep', // action to be done (skip|replace|keep)
	 *   ),
	 *   1 => array(
	 *     'existing_post_id' => 101,
	 *     'original_post_id' => 789,
	 *     'conflict_action' => 'replace',
	 *   )
	 * ]
	 *
	 * @return array The conflicting posts keyed by original post ID. Example:
	 * [
	 *   456 => array(
	 *     'existing_post_id' => 123, // ID of the existing post on the current blog
	 *     'conflict_action'  => 'keep', // action to be done (skip|replace|keep)
	 *     'original_post_id' => 456, // ID of the original post
	 *   ),
	 *   789 => array(
	 *     'existing_post_id' => 101,
	 *     'conflict_action'  => 'replace',
	 *     'original_post_id' => 789,
	 *   ),
	 * ]
	 */
	public static function get_conflicting_post_selections( $conflicts ) {
		$conflict_actions = array();
		foreach ( (array) $conflicts as $conflict ) {
			$conflict_actions[ $conflict['original_post_id'] ] = $conflict;
		}
		return $conflict_actions;
	}
}
