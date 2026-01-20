<?php

namespace Contentsync\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the nice url.
 *
 * @param string $url
 *
 * @return string
 */
function get_nice_url( $url ) {
	return untrailingslashit( preg_replace( '/^(http|https):\/\/(www.)?/', '', strval( $url ) ) );
}

/**
 * Get network url without protocol and trailing slash.
 *
 * @return string
 */
function get_network_url() {
	return get_nice_url( \network_site_url() );
}

/**
 * Filter the permalink of a post considering its canonical urls.
 *
 * @param string  $post_link The post's permalink.
 * @param WP_Post $post      The post in question.
 * @param bool    $leavename Whether to keep the post name.
 * @param bool    $sample    Is it a sample permalink.
 *
 * @return string First non-empty string:
 *      (1) Yoast canonical url.
 *      (2) Global canonical url.
 *      (3) The default permalink.
 */
function get_global_permalink( $permalink, $post, $leavename = false, $sample = false ) {

	if ( ! is_object( $post ) || ! isset( $post->ID ) || empty( $post->ID ) ) {
		return $permalink;
	}

	$synced_post_status = esc_attr( get_post_meta( $post->ID, 'synced_post_status', true ) );
	if ( $synced_post_status !== 'linked' ) {

		/**
		 * @since 1.7.0 If the post is not linked, we don't need to do anything.
		 *
		 * @filter contentsync_get_global_permalink
		 *
		 * @param string  $permalink The new permalink.
		 * @param WP_Post $post      Post object.
		 * @param string  $permalink The original permalink (if any)
		 */
		return apply_filters( 'contentsync_get_global_permalink', $permalink, $post, $permalink );
	}

	$yoast_canonical_url = esc_attr( get_post_meta( $post->ID, '_yoast_wpseo_canonical', true ) );
	if ( ! empty( $yoast_canonical_url ) ) {

		/**
		 * (1) Yoast canonical url
		 *
		 * @filter contentsync_get_global_permalink
		 *
		 * @param string  $permalink The new permalink.
		 * @param WP_Post $post      Post object.
		 * @param string  $original  The original permalink (if any)
		 */
		return apply_filters( 'contentsync_get_global_permalink', $yoast_canonical_url, $post, $permalink );
	}

	$rankmath_canonical_url = esc_attr( get_post_meta( $post->ID, 'rank_math_canonical_url', true ) );
	if ( ! empty( $rankmath_canonical_url ) ) {

		/**
		 * (1) Rankmath canonical url
		 *
		 * @filter contentsync_get_global_permalink
		 *
		 * @param string  $permalink The new permalink.
		 * @param WP_Post $post      Post object.
		 * @param string  $original  The original permalink (if any)
		 */
		return apply_filters( 'contentsync_get_global_permalink', $rankmath_canonical_url, $post, $permalink );
	}

	$contentsync_canonical_url = esc_attr( get_post_meta( $post->ID, 'contentsync_canonical_url', true ) );
	if ( ! empty( $contentsync_canonical_url ) ) {

		/**
		 * (2) Global canonical URL
		 *
		 * @filter contentsync_get_global_permalink
		 *
		 * @param string  $permalink The new permalink.
		 * @param WP_Post $post      Post object.
		 * @param string  $original The original permalink (if any)
		 */
		return apply_filters( 'contentsync_get_global_permalink', $contentsync_canonical_url, $post, $permalink );
	}

	/**
	 * (3) The default permalink.
	 *
	 * @filter contentsync_get_global_permalink
	 *
	 * @param string  $permalink The new permalink.
	 * @param WP_Post $post      Post object.
	 * @param string  $permalink The original permalink (if any)
	 */
	return apply_filters( 'contentsync_get_global_permalink', $permalink, $post, $permalink );
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
function get_edit_post_link( $post ) {

	if ( ! is_object( $post ) ) {
		$post = get_post( $post );
	}

	switch ( $post->post_type ) {
		case 'wp_global_styles':
			// do not allow editing of global styles and font families from other themes
			if ( get_wp_template_theme( $post ) != get_option( 'stylesheet' ) ) {
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
					'postId'   => get_wp_template_theme( $post ) . '//' . $post->post_name,
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
					'postId'       => get_wp_template_theme( $post ) . '//' . $post->post_name,
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

/**
 * Whether we are in a REST REQUEST. Similar to is_admin().
 */
function is_rest_request() {
	return defined( 'REST_REQUEST' ) && REST_REQUEST;
}
