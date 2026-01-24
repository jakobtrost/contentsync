<?php

namespace Contentsync\Admin\Views\Transfer;

use Contentsync\Posts\Transfer\Post_Transfer_Service;
use Contentsync\Posts\Sync\Synced_Post_Query;
use Contentsync\Utils\Urls;

defined( 'ABSPATH' ) || exit;

/**
 * Static helper class for handling conflicting posts.
 */
class Post_Conflict_Handler {

	/**
	 * Check synced posts for conflicts on this page
	 *
	 * @param string $gid   Global ID.
	 *
	 * @return string   Encoded array of conflicts or post_title.
	 */
	public static function check_synced_post_import( $gid ) {

		$posts = Synced_Post_Query::prepare_synced_post_for_import( $gid );
		if ( ! $posts ) {
			return false;
		}

		// get conflicting posts
		$conflicts = self::get_conflicting_post_options( $posts );

		if ( $conflicts ) {
			return $conflicts;
		} else {
			// return post title when no conflicts found
			$root_post = array_shift( $posts );
			return $root_post->post_title;
		}
	}

	/**
	 * Get conflicting posts as decoded array to be read and displayed
	 * in the backend 'check-import' overlay-form via backend.js.
	 *
	 * @param WP_Post[] $posts  WP_Posts keyed by ID
	 *
	 * @return string|bool  Decoded string when conflicts found, false otherwise.
	 */
	public static function get_conflicting_post_options( $posts ) {

		// get conflicting posts
		$conflicts = self::get_conflicting_posts( $posts );

		if ( count( $conflicts ) > 0 ) {
			foreach ( $conflicts as $post_id => $post ) {

				// get the post link to display in the backend
				$conflicts[ $post_id ]->post_link = self::get_post_link_html( $post );
				/**
				 * We add the original ID of the import to the existing post ID.
				 *
				 * In the backend the dropdowns to decide what to do with existing
				 * posts get named by the ID. So their name will be something
				 * like '12-54'
				 *
				 * On the import we have form-data like array('12-54' => 'replace').
				 * We later convert this data via the function
				 * get_conflicting_post_selections()
				 */
				$conflicts[ $post_id ]->ID = $post_id . '-' . $conflicts[ $post_id ]->ID;
			}
			if ( count( $conflicts ) > 1 ) {
				array_unshift(
					$conflicts,
					(object) array(
						'ID'        => 'multioption',
						'post_link' => __( 'Multiselect', 'contentsync' ),
						'post_type' => '',
					)
				);
			}
			// we don't set keys to keep the order when decoding the array to JS
			return array_values( $conflicts );
		}
		return false;
	}

	/**
	 * Check if there are existing posts in conflict with posts to be imported.
	 *
	 * @param Prepared_Post[] $posts  Posts keyed by ID
	 *
	 * @return WP_Post[]        Returns WP_Posts keyed by the original IDs. Contains
	 *                          post object and full html link object.
	 */
	public static function get_conflicting_posts( $posts ) {

		$conflicts = array();
		foreach ( $posts as $post_id => $post ) {
			if ( $existing_post = Post_Transfer_Service::get_post_by_name_and_type( $post ) ) {
				$conflicts[ $post_id ] = $existing_post;
			}
		}

		/**
		 * Filter to modify the list of conflicting posts before returning them.
		 *
		 * This filter allows developers to customize the list of posts that conflict
		 * with posts being imported. It's useful for adding custom conflict detection
		 * logic or filtering out certain types of conflicts.
		 *
		 * @filter import_synced_post_conflicts
		 *
		 * @param array $conflicts  Array of conflicting posts, keyed by post ID.
		 * @param array $posts      Array of posts being imported, keyed by post ID.
		 *
		 * @return array            Modified array of conflicting posts.
		 */
		return apply_filters( 'import_synced_post_conflicts', $conflicts, $posts );
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
	 * Get all conflicting post actions from the backend 'check-import' overlay-form.
	 *
	 * We have form data from the backend like array( '43-708' => 'skip' )
	 * and we convert this to a proper array that can be used for the function
	 * Post_Import->import_posts() (See function doc for details)
	 *
	 * @param array $conflicts  The conflicts.
	 *
	 * @return array
	 */
	public static function get_conflicting_post_selections( $conflicts ) {
		$conflict_actions = array();
		foreach ( (array) $conflicts as $ids => $action ) {
			if ( strpos( $ids, '-' ) !== false ) {
				// original format: '43-708' => 'skip'
				$ids                          = explode( '-', $ids );
				$post_id                      = $ids[0];
				$conflict_actions[ $post_id ] = array(
					'post_id' => $ids[1],
					'action'  => $action,
				);
				// new format: 43 => array( 'post_id' => '708', 'action' => 'skip' )
			}
		}
		return $conflict_actions;
	}
}
