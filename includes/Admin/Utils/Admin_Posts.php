<?php

namespace Contentsync\Admin\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Static helper class for admin post operations.
 */
class Admin_Posts {

	/**
	 * Retrieves the delete posts link for post.
	 * This function is a copy of the original function, but with support for wp_template
	 * post type. The core function throws a fatal error when trying to retrieve the
	 * delete link for a wp_template post type, because the post type edit link has two
	 * %s placeholders, and the core function only supports one.
	 *
	 * @see wp-includes/link-template.php
	 *
	 * @param int|WP_Post $post         Optional. Post ID or post object. Default is the global `$post`.
	 * @param string      $deprecated   Not used.
	 * @param bool        $force_delete Optional. Whether to bypass Trash and force deletion. Default false.
	 * @return string|void The delete post link URL for the given post.
	 */
	public static function get_delete_post_link( $post = 0, $deprecated = '', $force_delete = false ) {
		if ( ! empty( $deprecated ) ) {
			_deprecated_argument( __FUNCTION__, '3.0.0' );
		}

		$post = get_post( $post );

		if ( ! $post ) {
			return;
		}

		$post_type_object = get_post_type_object( $post->post_type );

		if ( ! $post_type_object ) {
			return;
		}

		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return;
		}

		$action = ( $force_delete || ! EMPTY_TRASH_DAYS ) ? 'delete' : 'trash';

		$edit_link = $post_type_object->_edit_link;

		// if '%s' occurs once, we replace it with the post ID
		if ( substr_count( $edit_link, '%s' ) === 1 ) {
			$edit_link = admin_url( sprintf( $edit_link, $post->ID ) );
		} else {
			$edit_link = admin_url( sprintf( $edit_link, $post->post_type, $post->ID ) );
		}

		// fix patterns edit link 'post.php?post=0'
		if ( $post->post_type == 'wp_block' ) {
			$edit_link = add_query_arg( 'post', $post->ID, $edit_link );
		}

		$delete_link = add_query_arg( 'action', $action, $edit_link );

		// site editor trash links don't work with wp_delete_post()
		if ( strpos( $delete_link, 'site-editor.php' ) !== false ) {

			// get wpnonce from url
			$wpnonce   = '';
			$url_parts = parse_url( $delete_link );
			if ( isset( $url_parts['query'] ) ) {
				parse_str( $url_parts['query'], $query );
				if ( isset( $query['_wpnonce'] ) ) {
					$wpnonce = $query['_wpnonce'];
				} elseif ( isset( $query['amp;_wpnonce'] ) ) {
					$wpnonce = $query['amp;_wpnonce'];
				}
			}

			$delete_link = add_query_arg(
				array(
					'post'     => $post->ID,
					'action'   => 'trash',
					'_wpnonce' => $wpnonce,
				),
				admin_url( 'post.php' )
			);
		}

		/**
		 * Filters the post delete link.
		 *
		 * @param string $link         The delete link.
		 * @param int    $post_id      Post ID.
		 * @param bool   $force_delete Whether to bypass the Trash and force deletion. Default false.
		 */
		return apply_filters( 'get_delete_post_link', wp_nonce_url( $delete_link, "$action-post_{$post->ID}" ), $post->ID, $force_delete );
	}

	/**
	 * Retrieves the restore (untrash) post link for a post.
	 * Mirrors core behavior while supporting FSE post types (e.g., wp_template) and Site Editor URLs.
	 *
	 * @param int|\WP_Post $post Optional. Post ID or post object. Default is the global `$post`.
	 * @return string|void The restore post link URL for the given post.
	 */
	public static function get_untrash_post_link( $post = 0 ) {
		$post = get_post( $post );

		if ( ! $post ) {
			return;
		}

		$post_type_object = get_post_type_object( $post->post_type );

		if ( ! $post_type_object ) {
			return;
		}

		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return;
		}

		$edit_link = $post_type_object->_edit_link;

		// If '%s' occurs once, replace with the post ID. Otherwise, it's the FSE pattern with two placeholders.
		if ( substr_count( $edit_link, '%s' ) === 1 ) {
			$edit_link = admin_url( sprintf( $edit_link, $post->ID ) );
		} else {
			$edit_link = admin_url( sprintf( $edit_link, $post->post_type, $post->ID ) );
		}

		// Fix patterns edit link 'post.php?post=0'.
		if ( $post->post_type == 'wp_block' ) {
			$edit_link = add_query_arg( 'post', $post->ID, $edit_link );
		}

		$untrash_link = add_query_arg( 'action', 'untrash', $edit_link );

		// Site Editor links don't work directly with post actions; map to post.php.
		if ( strpos( $untrash_link, 'site-editor.php' ) !== false ) {
			$untrash_link = add_query_arg(
				array(
					'post'   => $post->ID,
					'action' => 'untrash',
				),
				admin_url( 'post.php' )
			);
		}

		// Ensure redirect returns to current screen
		$referer = wp_get_referer();
		if ( $referer ) {
			$untrash_link = add_query_arg( array( '_wp_http_referer' => $referer ), $untrash_link );
		}

		return wp_nonce_url( $untrash_link, "untrash-post_{$post->ID}" );
	}

	/**
	 * Retrieves the permanent delete post link for a post.
	 * This function creates a link that works with the bulk action nonce system.
	 *
	 * @param int|\WP_Post $post Optional. Post ID or post object. Default is the global `$post`.
	 * @return string|void The permanent delete post link URL for the given post.
	 */
	public static function get_permanent_delete_post_link( $post = 0 ) {
		$post = get_post( $post );

		if ( ! $post ) {
			return;
		}

		$post_type_object = get_post_type_object( $post->post_type );

		if ( ! $post_type_object ) {
			return;
		}

		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return;
		}

		// Create a link that will work with the bulk action system
		$delete_link = add_query_arg(
			array(
				'action'      => 'delete',
				'post'        => $post->ID,
				'post_status' => 'trash',
			),
			admin_url( 'admin.php' )
		);

		// Add the page parameter to identify this as a theme posts page
		$delete_link = add_query_arg( 'page', 'theme-posts', $delete_link );

		/**
		 * Filters the post permanent delete link.
		 *
		 * @param string $link    The delete link.
		 * @param int    $post_id Post ID.
		 */
		return apply_filters( 'get_permanent_delete_post_link', $delete_link, $post->ID );
	}
}
