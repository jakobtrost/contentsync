<?php

namespace Contentsync\Admin\Sync;

use Contentsync\Utils\Logger;

defined( 'ABSPATH' ) || exit;

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

	$old_values = \Contentsync\Posts\Sync\get_contentsync_meta_values( $post_id, 'contentsync_export_options' );
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
