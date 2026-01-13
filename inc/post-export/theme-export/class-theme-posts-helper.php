<?php
/**
 * Export & Import Post Helper
 */
namespace Contentsync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

new Theme_Posts_Helper();

class Theme_Posts_Helper {

	/**
	 * Get supported post types.
	 * 
	 * @return array Array of supported post type slugs.
	 */
	public static function get_supported_post_types() {
		return array(
			'wp_template',
			'wp_template_part',
			'wp_block',
			'wp_navigation',
			'wp_global_styles',
			// 'wp_font_family',
		);
	}

	/**
	 * Retrieves the posts for the given post types.
	 * 
	 * @param array $args Array of arguments.
	 * 
	 * @return array Array of posts.
	 */
	public static function get_posts( $args ) {

		$args = wp_parse_args( $args, array(
			'post_type'      => self::get_supported_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
		) );

		$posts = get_posts( $args );

		return $posts;
	}

	/**
	 * Assign a template to the current theme.
	 * 
	 * @param WP_Post $post Post object.
	 * @param bool    $switch_references_in_content Optional. Whether to switch references in the content. Default false.
	 * 
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function set_wp_template_theme( $post, $switch_references_in_content = false) {
		
		$current_theme = get_option( 'stylesheet' );

		$old_theme = null;
		if ( $switch_references_in_content ) {
			$old_theme = Main_Helper::get_wp_template_theme( $post );
		}


		// set the terms
		$result = wp_set_post_terms( $post->ID, $current_theme, 'wp_theme' );

		if ( is_wp_error( $result ) ) {
			echo $result->get_error_message();
			return $result;
		}

		// change references in content
		if ( $switch_references_in_content && !empty( $old_theme ) ) {

			$content = str_replace( '"theme":"' . $old_theme . '"', '"theme":"' . $current_theme . '"', $post->post_content );
			$result  = wp_update_post( array(
				'ID'           => $post->ID,
				'post_content' => wp_slash($content),
			) );
		}
		
		return (bool) $result;
	}

	/**
	 * Assign global styles to the current theme.
	 * 
	 * @param WP_Post $post Post object.
	 * 
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function set_wp_global_styles_theme( $post ) {
		
		$current_theme = get_option( 'stylesheet' );

		$current_global_styles_id = 0;
		if ( class_exists( 'WP_Theme_JSON_Resolver_Gutenberg' ) ) {
			$current_global_styles_id = \WP_Theme_JSON_Resolver_Gutenberg::get_user_global_styles_post_id();
		} elseif ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			$current_global_styles_id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		}

		if ( ! $current_global_styles_id ) {
			echo __( 'No global_styles_id created yet.', 'contentsync_hub' );
			return new \WP_Error( 'no_global_styles_id', __( 'No global_styles_id created yet.', 'contentsync_hub' ) );
		}

		$result = wp_update_post( array(
			'ID'           => $current_global_styles_id,
			'post_content' => wp_slash($post->post_content),
		) );

		if ( is_wp_error( $result ) ) {
			echo $result->get_error_message();
			return $result;
		}
		
		return (bool) $result;
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
			$wpnonce = '';
			$url_parts = parse_url( $delete_link );
			if ( isset( $url_parts['query'] ) ) {
				parse_str( $url_parts['query'], $query );
				if ( isset( $query['_wpnonce'] ) ) {
					$wpnonce = $query['_wpnonce'];
				}
				else if ( isset( $query['amp;_wpnonce'] ) ) {
					$wpnonce = $query['amp;_wpnonce'];
				}
			}

			$delete_link = add_query_arg(
				array(
					'post'    => $post->ID,
					'action'  => 'trash',
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
				'action' => 'delete',
				'post'   => $post->ID,
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
}