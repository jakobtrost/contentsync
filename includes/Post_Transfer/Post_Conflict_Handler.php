<?php

namespace Contentsync\Post_Transfer;

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
	 * @return array                            The original posts, keyed by ID, with some properties added:
	 *   @property WP_Post $existing_post       The conflicting post, with some additional properties:
	 *      @property int $original_post_id     The original post ID
	 *      @property string $post_link         The link to the conflicting post
	 *      @property string $conflict_action   Optional: Predefined conflict action (skip|replace|keep)
	 *                                          If set, the conflict action will be used and the user will not be able to change it.
	 *      @property string $conflict_message  Optional: Predefined conflict message
	 *                                          If set, this message will be shown to the user instead of the default message ("No conflict").
	 */
	public static function get_import_posts_with_conflicts( $posts ) {

		if ( ! $posts || ! is_array( $posts ) ) {
			return array();
		}

		foreach ( $posts as $post_id => $importing_post ) {
			if ( $existing_post = Post_Transfer_Service::get_post_by_name_and_type( $importing_post ) ) {

				$existing_post->original_post_id = $post_id;
				$existing_post->post_link        = self::get_post_link_html( $importing_post );

				/**
				 * Filter to modify the existing post object.
				 *
				 * @param WP_Post $existing_post         The existing post object.
				 * @param Prepared_Post $importing_post  The prepared post object.
				 *
				 * @return WP_Post|null The existing post object or null if the conflict should be removed.
				 */
				$existing_post = apply_filters( 'contentsync_import_post_with_conflict', $existing_post, $importing_post );

				if ( ! empty( $existing_post ) ) {
					$posts[ $post_id ]->existing_post = $existing_post;
				}
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
}
