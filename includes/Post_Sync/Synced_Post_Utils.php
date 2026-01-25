<?php
/**
 * Synced Post Utils
 *
 * This utility class provides helper methods for working with synced posts,
 * including GID manipulation and REST request detection.
 */

namespace Contentsync\Post_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Class Synced_Post_Utils
 *
 * Static helper class providing synced post utility operations.
 */
class Synced_Post_Utils {

	/**
	 * Get the global ID.
	 *
	 * @param WP_Post|string $post  Preparred WP_Post Object or post ID.
	 *
	 * @return string|bool          Global ID on success, false on failure.
	 */
	public static function get_gid( $post ) {
		/**
		 * Filter to modify the global ID before returning.
		 *
		 * This filter allows developers to customize how the global ID
		 * is retrieved or formatted, enabling modifications to the ID
		 * structure or additional processing before it's returned.
		 *
		 * @filter contentsync_get_gid
		 *
		 * @param string|bool $gid  The global ID or false if not found.
		 * @param WP_Post|string $post The post object or post ID.
		 *
		 * @return string|bool The modified global ID or false.
		 */
		return apply_filters(
			'contentsync_get_gid',
			Post_Meta::get_values( $post, 'synced_post_id' ),
			$post
		);
	}

	/**
	 * Get global ID args.
	 *
	 * @param string $gid
	 *
	 * @return array    array( 0 => {blog_id}, 1 => {post_id}, 2 => {site_url} )
	 */
	public static function explode_gid( $gid ) {

		$default = array(
			0 => null,
			1 => null,
			2 => null,
		);

		if ( is_string( $gid ) && strpos( $gid, '-' ) !== false ) {
			$exploded = array_replace( $default, explode( '-', $gid, 3 ) );
		} else {
			$exploded = $default;
		}

		/**
		 * Filter to modify the blog ID component of the exploded GID.
		 *
		 * @filter contentsync_explode_gid_blog_id
		 *
		 * @param string|null $blog_id The blog ID component of the GID.
		 *
		 * @return string|null The modified blog ID component.
		 */
		$exploded[0] = apply_filters( 'contentsync_explode_gid_blog_id', $exploded[0] );

		/**
		 * Filter to modify the post ID component of the exploded GID.
		 *
		 * @filter contentsync_explode_gid_post_id
		 *
		 * @param string|null $post_id The post ID component of the GID.
		 *
		 * @return string|null The modified post ID component.
		 */
		$exploded[1] = apply_filters( 'contentsync_explode_gid_post_id', $exploded[1] );

		/**
		 * Filter to modify the site URL component of the exploded GID.
		 *
		 * @filter contentsync_explode_gid_site_url
		 *
		 * @param string|null $site_url The site URL component of the GID.
		 *
		 * @return string|null The modified site URL component.
		 */
		$exploded[2] = apply_filters( 'contentsync_explode_gid_site_url', $exploded[2] );

		/**
		 * Filter to modify the complete exploded GID array.
		 *
		 * This filter allows developers to customize the complete array
		 * of exploded GID components after individual component filtering.
		 *
		 * @filter contentsync_explode_gid
		 *
		 * @param array $exploded Array containing [blog_id, post_id, site_url].
		 *
		 * @return array The modified exploded GID array.
		 */
		return apply_filters( 'contentsync_explode_gid', $exploded );
	}

	/**
	 * Whether we are in a REST REQUEST. Similar to is_admin().
	 */
	public static function is_rest_request() {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}
}
