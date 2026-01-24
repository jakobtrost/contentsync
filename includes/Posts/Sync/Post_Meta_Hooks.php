<?php

namespace Contentsync\Posts\Sync;

use Contentsync\Utils\Hooks_Base;
use Contentsync\Utils\Logger;

defined( 'ABSPATH' ) || exit;

class Post_Meta_Hooks extends Hooks_Base {

	/**
	 * Register hooks that run everywhere.
	 */
	public function register() {
		add_action( 'contentsync_after_import_post', array( $this, 'adjust_synced_post_status_after_import' ), 10, 2 );
		add_filter( 'contentsync_export_blacklisted_meta', array( $this, 'add_blacklisted_contentsync_meta' ) );
		add_filter( 'is_protected_meta', array( $this, 'protect_contentsync_meta_keys' ), 99, 2 );
		add_filter( 'pll_copy_post_metas', array( $this, 'exclude_contentsync_metas_from_polylang_sync' ), 99, 1 );
	}

	/**
	 * Adjust synced post status after post was imported.
	 *
	 * If the post is the root post, the status is set to 'root'.
	 * If the post is a linked post, the status is set to 'linked'.
	 * If the post is not a linked post, the status is removed.
	 *
	 * @param int    $post_id  The new post ID.
	 * @param object $post  The prepared WP_Post object.
	 */
	public function adjust_synced_post_status_after_import( $post_id, $post ) {

		$gid = Synced_Post_Utils::get_gid( $post_id );
		if ( empty( $gid ) ) {
			return;
		}

		list( $root_blog_id, $root_post_id, $root_net_url ) = explode_gid( $gid );
		if ( $root_post_id === null ) {
			return false;
		}

		$current_status = Post_Meta::get_values( $post, 'synced_post_status' );
		$new_status     = null;

		// if gid values match, this is the root post
		if ( $root_blog_id == get_current_blog_id() && $post_id == $root_post_id && empty( $root_net_url ) ) {
			Logger::add( sprintf( "New contentsync post status is 'root' because gid values match: %s", $gid ) );
			$new_status = 'root';
		}
		// blog doesn't exist in this multisite network
		elseif ( empty( $root_net_url ) && function_exists( 'get_blog_details' ) && get_blog_details( $root_blog_id, false ) === false ) {
			Logger::add( sprintf( "Blog doesn't exist in the current network: %s", $gid ) );
			Post_Meta::delete_values( $post_id );
		}
		// this is a linked post
		elseif ( $root_blog_id != get_current_blog_id() || ! empty( $root_net_url ) ) {
			Logger::add( sprintf( 'The gid values do not match (%s) - this is a linked post!', $gid ) );
			$new_status = 'linked';
			Post_Connection_Map::add( $gid, get_current_blog_id(), $post_id );
		}

		// update the status if changed
		if ( $new_status && $new_status !== $current_status ) {
			update_post_meta( $post_id, 'synced_post_status', $new_status );
		}
	}

	/**
	 * Add blacklisted meta for Post_Export class.
	 *
	 * @filter 'contentsync_export_blacklisted_meta'.
	 *
	 * @return array $meta_keys
	 */
	public function add_blacklisted_contentsync_meta( $meta_values ) {
		return array_merge( $meta_values, Post_Meta::get_blacklisted_keys() );
	}

	/**
	 * Mark Contentsync meta keys as protected to prevent syncing by translation plugins.
	 *
	 * WordPress considers meta keys starting with '_' as protected by default.
	 * This filter allows us to mark our contentsync_ prefixed meta keys as protected too,
	 * which prevents Polylang (and other plugins) from syncing them.
	 *
	 * @filter is_protected_meta
	 *
	 * @param bool   $protected Whether the meta key is protected.
	 * @param string $meta_key  The meta key being checked.
	 * @return bool Whether the meta key should be protected.
	 */
	public function protect_contentsync_meta_keys( $protected, $meta_key ) {
		if ( in_array( $meta_key, Post_Meta::get_keys(), true ) ) {
			return true;
		}
		return $protected;
	}

	/**
	 * Exclude Content Syncs meta keys from Polylang synchronization.
	 *
	 * Polylang syncs custom fields between translations, but Contentsync meta values
	 * are unique per post and should never be copied or synced.
	 *
	 * @filter pll_copy_post_metas
	 *
	 * @param string[] $metas List of meta keys to be synced.
	 * @return string[] Filtered list of meta keys.
	 */
	public function exclude_contentsync_metas_from_polylang_sync( $metas ) {
		return array_diff( $metas, Post_Meta::get_keys() );
	}

	/**
	 * Returns list of blacklisted meta keys that should not be exported.
	 *
	 * This function provides a list of meta keys that are automatically
	 * excluded from post exports. The list can be customized using the
	 * 'contentsync_export_blacklisted_meta' filter.
	 *
	 * @param string $context      The context of the operation (export or import).
	 * @param int    $post_id      The ID of the post being exported or imported.
	 *
	 * @return array Array of meta keys to exclude from export or import.
	 */
	public static function get_blacklisted_meta_for_export( $context = 'export', $post_id = 0 ) {
		/**
		 * Filter to customize the list of blacklisted meta keys for export or import.
		 *
		 * This filter allows developers to add or remove meta keys that should
		 * be excluded from post exports or imports. It's useful for preventing sensitive
		 * or site-specific meta data from being exported or imported.
		 *
		 * @filter contentsync_export_blacklisted_meta
		 * @filter contentsync_import_blacklisted_meta
		 *
		 * @param array $blacklisted_meta Array of meta keys to exclude from export or import.
		 * @param int   $post_id        The ID of the post being exported or imported.
		 *
		 * @return array                   Modified array of blacklisted meta keys.
		 */
		return apply_filters(
			'contentsync_' . $context . '_blacklisted_meta',
			array(
				'_wp_attached_file',
				'_wp_attachment_metadata',
				'_edit_lock',
				'_edit_last',
				'_wp_old_slug',
				'_wp_old_date',
				'_wpb_vc_js_status',
			),
			$post_id
		);
	}

	/**
	 * Check whether to skip a certain meta key and not im- or export it
	 *
	 * @param string $meta_key     The meta key being evaluated.
	 * @param mixed  $meta_value   The meta value being evaluated.
	 * @param string $context      The context of the operation (export or import).
	 * @param int    $post_id      The ID of the post being exported or imported.
	 *
	 * @return bool                Whether to skip the meta option.
	 */
	public static function maybe_skip_meta_option( $meta_key, $meta_value, $context = 'export', $post_id = 0 ) {

		$skip = false;

		// skip empty options
		if ( $meta_value === '' ) {
			Logger::add( "  - skipped empty meta option for '$meta_key'" );
			$skip = true;
		}
		// skip oembed meta options
		elseif ( strpos( $meta_key, '_oembed_' ) === 0 ) {
			Logger::add( "  - skipped oembed option '$meta_key'" );
			$skip = true;
		}

		/**
		 * Filter to determine whether a specific meta option should be skipped during export or import.
		 *
		 * This filter allows developers to implement custom logic for determining
		 * whether specific meta keys or values should be excluded from export or import.
		 * It's useful for implementing site-specific export/import rules or business logic.
		 *
		 * @filter contentsync_export_maybe_skip_meta_option
		 * @filter contentsync_import_maybe_skip_meta_option
		 *
		 * @param bool   $skip_meta    Whether to skip the meta option (default: false).
		 * @param string $meta_key     The meta key being evaluated.
		 * @param mixed  $meta_value   The meta value being evaluated.
		 * @param int    $post_id      The ID of the post being exported or imported.
		 *
		 * @return bool                Whether to skip the meta option.
		 */
		return apply_filters( 'contentsync_' . $context . '_maybe_skip_meta_option', $skip, $meta_key, $meta_value, $post_id );
	}
}
