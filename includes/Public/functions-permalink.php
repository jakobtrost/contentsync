<?php

namespace Contentsync\Public;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
