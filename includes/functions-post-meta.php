<?php

namespace Contentsync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * =================================================================
 *                          GET
 * =================================================================
 */

/**
 * Returns list of contentsync meta keys
 *
 * @return array $meta_keys
 */
function get_contentsync_meta_keys() {
	return array(
		'synced_post_status',
		'synced_post_id',
		'contentsync_connection_map',
		'contentsync_export_options',
		'contentsync_canonical_url',
		// 'contentsync_nested',
	);
}

/**
 * @return array $default_values
 */
function get_contentsync_meta_default_values() {
	return array(
		'synced_post_status'         => null,
		'synced_post_id'             => null,
		'contentsync_connection_map' => array(),
		'contentsync_export_options' => array(
			'append_nested'  => true,
			'whole_posttype' => false,
			'all_terms'      => false,
			'resolve_menus'  => true,
			'translations'   => true,
		),
	);
}

/**
 * Return the default export options
 */
function get_contentsync_default_export_options() {
	return get_contentsync_meta_default_values()['contentsync_export_options'];
}

/**
 * get_post_meta() but with default values.
 *
 * @param int|WP_Post $post_id  Post ID or Preparred post object.
 * @param string      $meta_key Key of the meta option.
 *
 * @return mixed
 */
function get_contentsync_meta_values( $post_id, $meta_key ) {
	$value = null;

	if ( is_object( $post_id ) || is_array( $post_id ) ) {
		$post = (object) $post_id;
		if ( isset( $post->meta ) ) {
			$post->meta = (array) $post->meta;
			$value      = isset( $post->meta[ $meta_key ] ) ? $post->meta[ $meta_key ] : null;
			if ( is_array( $value ) && isset( $value[0] ) ) {
				$value = $value[0];
			}
		}
	} elseif ( $meta_key == 'contentsync_connection_map' ) {
		$value = Main_Helper::get_post_connection_map( $post_id );
	} else {
		$value = get_post_meta( $post_id, $meta_key, true );
	}

	$default = get_contentsync_meta_default_values()[ $meta_key ];

	if ( ! $value ) {
		$value = $default;
	} elseif ( is_array( $default ) ) {
		$value = (array) $value;
	}
	return $value;
}

/**
 * Returns list of blacklisted contentsync meta keys
 *
 * @return array $meta_keys
 */
function get_contentsync_blacklisted_meta_keys() {
	return array(
		'contentsync_connection_map',
		'contentsync_export_options',
		// 'contentsync_nested',
	);
}

/**
 * =================================================================
 *                          UPDATE
 * =================================================================
 */

/**
 * Update contentsync post export options.
 *
 * @param int   $post_id      Post ID.
 * @param array $new_values   New values to update.
 *
 * @return bool
 */
function update_contentsync_post_export_options( $post_id, $new_values ) {

	if ( empty( $new_values ) || ! is_array( $new_values ) ) {
		return false;
	}

	Logger::add( 'Update "contentsync_export_options".' );

	$old_values = get_contentsync_meta_values( $post_id, 'contentsync_export_options' );
	if ( $old_values == $new_values ) {
		return false;
	}

	foreach ( $new_values as $option_name => $raw_value ) {
		$old_values[ $option_name ] = $raw_value === 'on';
		Logger::add( "  - $option_name = $raw_value" );
	}
	if ( $old_values !== $new_values ) {
		$meta_updated = update_post_meta( $post_id, 'contentsync_export_options', $new_values );
		Logger::add( '→ ' . ( $meta_updated ? 'options have been updated.' : 'options are unchanged' ) );
		return $meta_updated;
	}
	return false;
}

/**
 * Update contentsync post canonical url.
 *
 * @param int    $post_id      Post ID.
 * @param string $new_value   New value to update.
 *
 * @return bool
 */
function update_contentsync_post_canonical_url( $post_id, $new_value ) {
	Logger::add( "Update 'contentsync_canonical_url'." );

	$sanitized_value = esc_url( trim( strval( $new_value ) ) );

	$meta_updated = update_post_meta( $post_id, 'contentsync_canonical_url', $sanitized_value );
	Logger::add( '→ ' . ( $meta_updated ? 'contentsync_canonical_url has been updated.' : 'contentsync_canonical_url could not be updated' ) );
	return $meta_updated;
}

/**
 * Delete all global meta infos.
 * Used to unimport & unexport posts.
 */
function delete_contentsync_meta_values( $post_id ) {
	$return = true;
	foreach ( get_contentsync_meta_keys() as $meta_key ) {
		$result = delete_post_meta( $post_id, $meta_key );
		if ( ! $result ) {
			$return = false;
		}
	}
	return $return;
}

/**
 * =================================================================
 *                          FILTERS
 * =================================================================
 */

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
function adjust_synced_post_status_after_import( $post_id, $post ) {

	$gid = Main_Helper::get_gid( $post_id );
	if ( empty( $gid ) ) {
		return;
	}

	list( $root_blog_id, $root_post_id, $root_net_url ) = Main_Helper::explode_gid( $gid );
	if ( $root_post_id === null ) {
		return false;
	}

	$current_status = get_contentsync_meta_values( $post, 'synced_post_status' );
	$new_status     = null;

	// if gid values match, this is the root post
	if ( $root_blog_id == get_current_blog_id() && $post_id == $root_post_id && empty( $root_net_url ) ) {
		Logger::add( sprintf( "New contentsync post status is 'root' because gid values match: %s", $gid ) );
		$new_status = 'root';
	}
	// blog doesn't exist in this multisite network
	elseif ( empty( $root_net_url ) && function_exists( 'get_blog_details' ) && get_blog_details( $root_blog_id, false ) === false ) {
		Logger::add( sprintf( "Blog doesn't exist in the current network: %s", $gid ) );
		delete_contentsync_meta_values( $post_id );
	}
	// this is a linked post
	elseif ( $root_blog_id != get_current_blog_id() || ! empty( $root_net_url ) ) {
		Logger::add( sprintf( 'The gid values do not match (%s) - this is a linked post!', $gid ) );
		$new_status = 'linked';
		Main_Helper::add_post_connection_to_connection_map( $gid, get_current_blog_id(), $post_id );
	}

	// update the status if changed
	if ( $new_status && $new_status !== $current_status ) {
		update_post_meta( $post_id, 'synced_post_status', $new_status );
	}
}

add_action( 'contentsync_after_import_post', __NAMESPACE__ . '\\adjust_synced_post_status_after_import', 10, 2 );

/**
 * Add blacklisted meta for Post_Export class.
 *
 * @filter 'contentsync_export_blacklisted_meta'.
 *
 * @return array $meta_keys
 */
function add_blacklisted_contentsync_meta( $meta_values ) {
	return array_merge( $meta_values, get_contentsync_blacklisted_meta_keys() );
}

add_filter( 'contentsync_export_blacklisted_meta', __NAMESPACE__ . '\\add_blacklisted_contentsync_meta' );

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
function protect_contentsync_meta_keys( $protected, $meta_key ) {
	if ( in_array( $meta_key, get_contentsync_meta_keys(), true ) ) {
		return true;
	}
	return $protected;
}

// Mark Contentsync meta keys as protected to prevent syncing by translation plugins.
// This is the most reliable method as it hooks into WordPress core.
add_filter( 'is_protected_meta', __NAMESPACE__ . '\\protect_contentsync_meta_keys', 99, 2 );

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
function exclude_contentsync_metas_from_polylang_sync( $metas ) {
	return array_diff( $metas, get_contentsync_meta_keys() );
}

// Polylang: Exclude Contentsync meta keys from being synced between translations.
// This is a fallback in case is_protected_meta doesn't catch everything.
add_filter( 'pll_copy_post_metas', __NAMESPACE__ . '\\exclude_contentsync_metas_from_polylang_sync', 99, 1 );
