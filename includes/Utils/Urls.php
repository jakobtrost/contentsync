<?php

namespace Contentsync\Utils;

use Contentsync\Posts\Theme_Posts;

defined( 'ABSPATH' ) || exit;

/**
 * URL utility helpers.
 */
class Urls {

	/**
	 * Get the nice url.
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	public static function get_nice_url( $url ) {
		return untrailingslashit( preg_replace( '/^(http|https):\/\/(www.)?/', '', strval( $url ) ) );
	}

	/**
	 * Get network url without protocol and trailing slash.
	 *
	 * @return string
	 */
	public static function get_network_url() {

		if ( ! function_exists( '\network_site_url' ) ) {
			return self::get_nice_url( \site_url() );
		}

		return self::get_nice_url( \network_site_url() );
	}

	/**
	 * Retrieves the edit post link for post.
	 *
	 * @see wp-includes/link-template.php
	 *
	 * @param int|WP_Post $post Post ID or post object.
	 *
	 * @return string The edit post link URL for the given post.
	 */
	public static function get_edit_post_link( $post ) {

		if ( ! is_object( $post ) ) {
			$post = get_post( $post );
		}

		switch ( $post->post_type ) {
			case 'wp_global_styles':
				// do not allow editing of global styles and font families from other themes
				if ( Theme_Posts::get_wp_template_theme( $post ) != get_option( 'stylesheet' ) ) {
					return null;
				}

				// wp-admin/site-editor.php?path=/wp_global_styles&canvas=edit
				return add_query_arg(
					array(
						// 'path'   => '/wp_global_styles',
						// 'canvas' => 'edit',
						'p' => '/styles',
					),
					admin_url( 'site-editor.php' )
				);
				break;
			case 'wp_template':
				// wp-admin/site-editor.php?postType=wp_template&postId=greyd-theme//404&canvas=edit
				return add_query_arg(
					array(
						'postType' => $post->post_type,
						'postId'   => Theme_Posts::get_wp_template_theme( $post ) . '//' . $post->post_name,
						'canvas'   => 'edit',
					),
					admin_url( 'site-editor.php' )
				);
				break;
			case 'wp_template_part':
				// wp-admin/site-editor.php?postType=wp_template_part&postId=greyd-theme//footer&categoryId=footer&categoryType=wp_template_part&canvas=edit
				return add_query_arg(
					array(
						'postType'     => $post->post_type,
						'postId'       => Theme_Posts::get_wp_template_theme( $post ) . '//' . $post->post_name,
						'categoryId'   => $post->ID,
						'categoryType' => $post->post_type,
						'canvas'       => 'edit',
					),
					admin_url( 'site-editor.php' )
				);
				break;
			case 'wp_navigation':
				// wp-admin/site-editor.php?postId=169&postType=wp_navigation&canvas=edit
				return add_query_arg(
					array(
						'postId'   => $post->ID,
						'postType' => $post->post_type,
						'canvas'   => 'edit',
					),
					admin_url( 'site-editor.php' )
				);
				break;
			case 'wp_block':
				// wp-admin/edit.php?post_type=wp_block
				return add_query_arg(
					array(
						'post'   => $post->ID,
						'action' => 'edit',
					),
					admin_url( 'post.php' )
				);
				break;
			case 'wp_font_family':
				// wp-admin/site-editor.php?path=/wp_global_styles&canvas=edit
				return add_query_arg(
					array(
						// 'path'   => '/wp_global_styles',
						// 'canvas' => 'edit',
						'p' => '/styles',
					),
					admin_url( 'site-editor.php' )
				);
				break;
			default:
				return html_entity_decode( \get_edit_post_link( $post ) );
				// return add_query_arg(
				// array(
				// 'post'      => $post->ID,
				// 'action'    => 'edit',
				// ),
				// admin_url( 'post.php' )
				// );
				break;
		}
		return '';
	}
}
