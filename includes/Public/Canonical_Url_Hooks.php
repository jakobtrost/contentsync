<?php

/**
 * Content Sync hooks for canonical URL handling on the frontend
 *
 * This class hooks into front-end rendering to adjust permalinks and canonical URLs
 * for synced posts. It integrates with core WordPress functions and third-party
 * plugins such as Yoast SEO to ensure that the correct canonical URL is output
 * for a synced post and optionally uses that canonical URL as the actual permalink.
 * It also marks posts as duplicates when appropriate.
 */

namespace Contentsync\Public;

use Contentsync\Utils\Hooks_Base;

defined( 'ABSPATH' ) || exit;

class Canonical_Url_Hooks extends Hooks_Base {

	/**
	 * Register frontend-only hooks.
	 */
	public function register_frontend() {
		// Filter the canonical url
		add_filter( 'get_canonical_url', array( $this, 'filter_canonical_url' ), 10, 2 );
		add_filter( 'wpseo_canonical', array( $this, 'filter_yoast_seo_canonical_url' ), 10, 1 );

		// Output the canonical url in the <head>
		add_action( 'wp_head', array( $this, 'maybe_mark_as_duplicate' ), 1 );

		/**
		 * If the constant USE_CANONICAL_URL_AS_PERMALINK is defined and true,
		 * we filter the post permalink and the canonical url to use the
		 * canonical url as permalink.
		 */
		if ( defined( 'USE_CANONICAL_URL_AS_PERMALINK' ) && constant( 'USE_CANONICAL_URL_AS_PERMALINK' ) ) {
			// Filter post permalink
			add_filter( 'post_link', array( $this, 'filter_permalink' ), 100, 2 );
			add_filter( 'post_type_link', array( $this, 'filter_permalink' ), 100, 2 );
		}
	}

	/**
	 * Filter the permalink of a post considering its canonical urls.
	 *
	 * @param string  $permalink The post's permalink.
	 * @param WP_Post $post      The post in question.
	 * @return string The filtered permalink.
	 */
	public function filter_permalink( $permalink, $post ) {
		$global_permalink = $this->get_global_permalink( $permalink, $post );
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
	public function filter_canonical_url( $permalink, $post ) {
		$global_permalink = $this->get_global_permalink( $permalink, $post );
		if ( $global_permalink && $global_permalink !== $permalink ) {
			return $global_permalink;
		}

		return $permalink;
	}

	/**
	 * Filter the canonical URL for Yoast SEO.
	 *
	 * @param string $permalink The canonical URL from Yoast SEO.
	 * @return string The filtered canonical URL.
	 */
	public function filter_yoast_seo_canonical_url( $permalink ) {
		$post             = get_post();
		$global_permalink = $this->get_global_permalink( null, $post );

		if ( $global_permalink && $global_permalink !== $permalink ) {
			return $global_permalink;
		}

		return $permalink;
	}

	/**
	 * Mark single post as duplicate content.
	 * Outputs canonical link in the <head> if appropriate.
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
		 * So we use the filter 'rank_math/frontend/canonical' to filter the canonical
		 */
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			$rankmath_canonical_url = apply_filters( 'rank_math/frontend/canonical', esc_attr( get_post_meta( $post->ID, 'rank_math_canonical_url', true ) ) );
			if ( ! empty( $rankmath_canonical_url ) ) {
				return;
			}
		}

		$global_permalink = $this->get_global_permalink( null, $post );

		if ( $global_permalink ) {
			echo '<link rel="canonical" href="' . $global_permalink . '" />';
		}
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
	private function get_global_permalink( $permalink, $post, $leavename = false, $sample = false ) {

		if ( ! is_object( $post ) || ! isset( $post->ID ) || empty( $post->ID ) ) {
			return $permalink;
		}

		$synced_post_status = esc_attr( get_post_meta( $post->ID, 'synced_post_status', true ) );
		if ( $synced_post_status !== 'linked' ) {

			/**
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
}
