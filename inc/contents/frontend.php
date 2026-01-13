<?php

/**
 * Content Syncs functions for the frontend
 *
 * This file defines the `Frontend` class, which hooks into front‑end
 * rendering to adjust permalinks and canonical URLs for global posts.
 * It integrates with core WordPress functions and third‑party plugins
 * such as Yoast SEO to ensure that the correct canonical URL is output
 * for a global post and optionally uses that canonical URL as the
 * actual permalink. It also marks posts as duplicates when
 * appropriate. Extend this class if you need to add further front‑end
 * behaviours related to global posts or SEO.
 */

namespace Contentsync\Contents;

use \Contentsync\Main_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

new Frontend();
class Frontend {

	public function __construct() {

		if ( is_admin() ) {
			return;
		}

		// filter the canonical url
		add_filter( 'get_canonical_url', array( $this, 'filter_canonical_url' ), 10, 2 );
		add_filter( 'wpseo_canonical', array( $this, 'filter_yoast_seo_canonical_url' ), 10, 1 );

		// output the canonical url in the <head>
		add_action( 'wp_head', array($this, 'maybe_mark_as_duplicate'), 1 );

		/**
		 * If the constant USE_CANONICAL_URL_AS_PERMALINK is defined and true,
		 * we filter the post permalink and the canonical url to use the
		 * canonical url as permalink.
		 * 
		 * @since 1.7.6
		 */
		if ( defined( 'USE_CANONICAL_URL_AS_PERMALINK' ) && constant( 'USE_CANONICAL_URL_AS_PERMALINK' ) ) {

			// filter post permalink
			add_filter( 'post_link', array( $this, 'filter_permalink' ), 100, 2 );
			add_filter( 'post_type_link', array( $this, 'filter_permalink' ), 100, 2 );
		}
	}

	/**
	 * Filter the permalink of a post considering its canonical urls.
	 *
	 * @param string  $permalink The post's permalink.
	 * @param WP_Post $post      The post in question.
	 */
	public static function filter_permalink( $permalink, $post ) {

		$global_permalink = Main_Helper::get_global_permalink( $permalink, $post );
		if ( $global_permalink && $global_permalink !== $permalink ) {
			return $global_permalink;
		}

		return $permalink;
	}

	/**
	 * Filter the WordPress canonical URL.
	 * @see https://developer.wordpress.org/reference/hooks/get_canonical_url/
	 * 
	 * @param string  $permalink The post's canonical URL.
	 * @param WP_Post $post      Post object.
	 */
	public function filter_canonical_url( $permalink, $post ) {

		$global_permalink = Main_Helper::get_global_permalink( $permalink, $post );
		if ( $global_permalink && $global_permalink !== $permalink ) {
			return $global_permalink;
		}

		return $permalink;
	}

	/**
	 * Filter the canonical URL for Yoast SEO.
	 * @since 1.7.0
	 */
	public function filter_yoast_seo_canonical_url( $permalink ) {

		$post = get_post();
		$global_permalink = Main_Helper::get_global_permalink( null, $post );
		
		if ( $global_permalink && $global_permalink !== $permalink ) {
			return $global_permalink;
		}

		return $permalink;
	}

	/**
	 * Mark single post as duplicate content
	 */
	public function maybe_mark_as_duplicate() {

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
		 * So we  use the filter 'rank_math/frontend/canonical' to filter the canonical
		 * 
		 */
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$rankmath_canonical_url = apply_filters( 'rank_math/frontend/canonical', esc_attr( get_post_meta( $post->ID, 'rank_math_canonical_url', true ) ) );
			if ( ! empty( $rankmath_canonical_url ) ) {
				return;
			}
		}

		$global_permalink = Main_Helper::get_global_permalink( null, $post );
		
		if ( $global_permalink ) {
			echo '<link rel="canonical" href="' . $global_permalink . '" />';
		}
	}
}
