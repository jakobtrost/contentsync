<?php

namespace Contentsync\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Function to check if current user is allowed to edit global contents.
 * Permission is based on 'edit_posts' capability and can be overridden
 * with the filter 'contentsync_user_can_edit'.
 *
 * @param string $status 'root' or 'linked'
 *
 * @return bool
 */
function current_user_can_edit_synced_posts( $status = '' ) {

	$can_edit = function_exists( 'current_user_can' ) ? current_user_can( 'edit_posts' ) : true;

	if ( $status === 'root' ) {

		/**
		 * Filter to allow editing of root posts.
		 *
		 * @param bool $can_edit
		 *
		 * @return bool
		 */
		$can_edit = apply_filters( 'contentsync_user_can_edit_root_posts', $can_edit );
	} elseif ( $status === 'linked' ) {

		/**
		 * Filter to allow editing of linked posts.
		 *
		 * @param bool $can_edit
		 *
		 * @return bool
		 */
		$can_edit = apply_filters( 'contentsync_user_can_edit_linked_posts', $can_edit );
	}

	/**
	 * Filter to allow editing of all synced posts, no matter the status.
	 *
	 * @param bool $can_edit
	 *
	 * @return bool
	 */
	return apply_filters( 'contentsync_user_can_edit_synced_posts', $can_edit, $status );
}

/**
 * Find similar synced posts.
 *
 * Criteria:
 * * not from current blog
 * * same posttype
 * * post_name has at least 90% similarity
 *
 * @param WP_Post $post
 *
 * @return array of similar posts
 */
function get_similar_synced_posts( $post ) {

	$found   = array();
	$blog_id = get_current_blog_id();
	$net_url = \Contentsync\Utils\get_network_url();

	if ( ! isset( $post->post_name ) ) {
		return $found;
	}

	// we're replacing ending numbers after a dash (footer-2 becomes footer)
	$regex     = '/\-[0-9]{0,2}$/';
	$post_name = preg_replace( $regex, '', $post->post_name );

	// find and list all similar posts
	$all_posts = \Contentsync\Posts\Sync\get_all_synced_posts();

	foreach ( $all_posts as $synced_post ) {

		$synced_post = \Contentsync\Posts\Sync\new_synced_post( $synced_post );
		$gid         = \Contentsync\Posts\Sync\get_contentsync_meta_values( $synced_post, 'synced_post_id' );

		list( $_blog_id, $_post_id, $_net_url ) = \Contentsync\Posts\Sync\explode_gid( $gid );

		// exclude posts from other posttypes
		if ( $post->post_type !== $synced_post->post_type ) {
			continue;
		}
		// exclude posts from current blog
		elseif ( empty( $_net_url ) && $blog_id == $_blog_id ) {
			continue;
		}
		// exclude if a connection to this site is already established
		elseif (
			( empty( $_net_url ) && isset( $synced_post->meta['contentsync_connection_map'][ $blog_id ] ) ) ||
			( ! empty( $_net_url ) && isset( $synced_post->meta['contentsync_connection_map'][ $net_url ][ $blog_id ] ) )
		) {
			continue;
		}

		// check the post_name for similarity
		$name = preg_replace( $regex, '', $synced_post->post_name );
		similar_text( $post_name, $name, $percent ); // store percentage in variable $percent

		// list, if similarity is at least 90%
		if ( intval( $percent ) >= 90 ) {

			// make sure to get the post links
			if ( empty( $synced_post->post_links ) ) {

				// retrieve the post including all post_links from url
				if ( ! empty( $_net_url ) ) {
					$synced_post = \Contentsync\Posts\Sync\new_synced_post( \Contentsync\Posts\Sync\get_synced_post( $gid ) );
				} else {
					$synced_post->post_links = \Contentsync\Posts\Sync\get_local_post_links( $_blog_id, $_post_id );
				}
			}

			// add the post to the response
			$found[ $gid ] = $synced_post;
		}
	}

	return apply_filters( 'contentsync_get_similar_synced_posts', $found, $post );
}

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
function get_delete_post_link( $post = 0, $deprecated = '', $force_delete = false ) {
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
	 * @since 2.9.0
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
function get_untrash_post_link( $post = 0 ) {
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
function get_permanent_delete_post_link( $post = 0 ) {
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
	 * @since 3.0.0
	 *
	 * @param string $link    The delete link.
	 * @param int    $post_id Post ID.
	 */
	return apply_filters( 'get_permanent_delete_post_link', $delete_link, $post->ID );
}
