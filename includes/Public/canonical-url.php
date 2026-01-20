<?php

/**
 * Content Sync functions for the frontend
 *
 * This file hooks into front‑end rendering to adjust permalinks and canonical URLs
 * for synced posts. It integrates with core WordPress functions and third‑party
 * plugins such as Yoast SEO to ensure that the correct canonical URL is output
 * for a synced post and optionally uses that canonical URL as the actual permalink.
 * It also marks posts as duplicates when appropriate.
 */

namespace Contentsync\Public;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( is_admin() ) {
	return;
}

// filter the canonical url
add_filter( 'get_canonical_url', __NAMESPACE__ . '\\filter_canonical_url', 10, 2 );
add_filter( 'wpseo_canonical', __NAMESPACE__ . '\\filter_yoast_seo_canonical_url', 10, 1 );

// output the canonical url in the <head>
add_action( 'wp_head', __NAMESPACE__ . '\maybe_mark_as_duplicate', 1 );

/**
 * If the constant USE_CANONICAL_URL_AS_PERMALINK is defined and true,
 * we filter the post permalink and the canonical url to use the
 * canonical url as permalink.
 *
 * @since 1.7.6
 */
if ( defined( 'USE_CANONICAL_URL_AS_PERMALINK' ) && constant( 'USE_CANONICAL_URL_AS_PERMALINK' ) ) {
	// filter post permalink
	add_filter( 'post_link', __NAMESPACE__ . '\\filter_permalink', 100, 2 );
	add_filter( 'post_type_link', __NAMESPACE__ . '\\filter_permalink', 100, 2 );
}

/**
 * Filter the permalink of a post considering its canonical urls.
 *
 * @param string  $permalink The post's permalink.
 * @param WP_Post $post      The post in question.
 * @return string The filtered permalink.
 */
function filter_permalink( $permalink, $post ) {
	$global_permalink = get_global_permalink( $permalink, $post );
	if ( $global_permalink && $global_permalink !== $permalink ) {
		return $global_permalink;
	}

	return $permalink;
}

/**
 * Filter the WordPress canonical URL.
 *
 * @see https://developer.wordpress.org/reference/hooks/get_canonical_url/
 *
 * @param string  $permalink The post's canonical URL.
 * @param WP_Post $post      Post object.
 * @return string The filtered canonical URL.
 */
function filter_canonical_url( $permalink, $post ) {
	$global_permalink = get_global_permalink( $permalink, $post );
	if ( $global_permalink && $global_permalink !== $permalink ) {
		return $global_permalink;
	}

	return $permalink;
}

/**
 * Filter the canonical URL for Yoast SEO.
 *
 * @since 1.7.0
 *
 * @param string $permalink The canonical URL from Yoast SEO.
 * @return string The filtered canonical URL.
 */
function filter_yoast_seo_canonical_url( $permalink ) {
	$post             = get_post();
	$global_permalink = get_global_permalink( null, $post );

	if ( $global_permalink && $global_permalink !== $permalink ) {
		return $global_permalink;
	}

	return $permalink;
}

/**
 * Mark single post as duplicate content.
 * Outputs canonical link in the <head> if appropriate.
 */
function maybe_mark_as_duplicate() {
	// Only on single posts
	if ( ! is_single() ) {
		return;
	}

	$post = get_post();

	/**
	 * If yoast is active, the canonical link is set in the <head> by yoast,
	 * we instead use the filter 'wpseo_canonical' to filter the canonical
	 * URL.
	 */
	if ( defined( 'WPSEO_VERSION' ) ) {
		$yoast_canonical_url = apply_filters( 'wpseo_canonical', esc_attr( get_post_meta( $post->ID, '_yoast_wpseo_canonical', true ) ) );
		if ( ! empty( $yoast_canonical_url ) ) {
			return;
		}
	}

	/**
	 * It is the same for rankmath. If it is active, the canonical link is set in the <head>
	 * So we use the filter 'rank_math/frontend/canonical' to filter the canonical
	 */
	if ( defined( 'RANK_MATH_VERSION' ) ) {
		$rankmath_canonical_url = apply_filters( 'rank_math/frontend/canonical', esc_attr( get_post_meta( $post->ID, 'rank_math_canonical_url', true ) ) );
		if ( ! empty( $rankmath_canonical_url ) ) {
			return;
		}
	}

	$global_permalink = get_global_permalink( null, $post );

	if ( $global_permalink ) {
		echo '<link rel="canonical" href="' . $global_permalink . '" />';
	}
}
