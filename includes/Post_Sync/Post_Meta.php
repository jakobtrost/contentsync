<?php

namespace Contentsync\Post_Sync;

defined( 'ABSPATH' ) || exit;

class Post_Meta {

	/**
	 * Returns list of contentsync meta keys
	 *
	 * @return array $meta_keys
	 */
	public static function get_keys() {
		return array(
			'synced_post_status',
			'synced_post_id',
			'contentsync_connection_map',
			'contentsync_export_options',
			'contentsync_canonical_url',
		);
	}

	/**
	 * @return array $default_values
	 */
	public static function get_default_values() {
		return array(
			'synced_post_status'         => null,
			'synced_post_id'             => null,
			'contentsync_connection_map' => array(),
			'contentsync_export_options' => array(
				'append_nested' => true,
				'resolve_menus' => true,
				'translations'  => true,
			),
		);
	}

	/**
	 * Return the default export options
	 */
	public static function get_default_export_options() {
		return self::get_default_values()['contentsync_export_options'];
	}

	/**
	 * get_post_meta() but with default values.
	 *
	 * @param int|WP_Post $post_id  Post ID or Preparred post object.
	 * @param string      $meta_key Key of the meta option.
	 *
	 * @return mixed
	 */
	public static function get_values( $post_id, $meta_key ) {
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
			$value = Post_Connection_Map::get( $post_id );
		} else {
			$value = get_post_meta( $post_id, $meta_key, true );
		}

		$default = self::get_default_values()[ $meta_key ];

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
	public static function get_blacklisted_keys() {
		return array(
			'contentsync_connection_map',
			'contentsync_export_options',
		);
	}

	/**
	 * Delete all global meta infos.
	 * Used to unimport & unlink posts.
	 */
	public static function delete_values( $post_id ) {
		$return = true;
		foreach ( self::get_keys() as $meta_key ) {
			$result = delete_post_meta( $post_id, $meta_key );
			if ( ! $result ) {
				$return = false;
			}
		}
		return $return;
	}

	/**
	 * Update contentsync post export options.
	 *
	 * @param int   $post_id      Post ID.
	 * @param array $new_values   New values to update.
	 *
	 * @return bool
	 */
	public static function update_export_options( $post_id, $new_values ) {

		if ( empty( $new_values ) || ! is_array( $new_values ) ) {
			return false;
		}

		Logger::add( 'Update "contentsync_export_options".' );

		$old_values = self::get_values( $post_id, 'contentsync_export_options' );
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
	public static function update_canonical_url( $post_id, $new_value ) {
		Logger::add( "Update 'contentsync_canonical_url'." );

		$sanitized_value = esc_url( trim( strval( $new_value ) ) );

		$meta_updated = update_post_meta( $post_id, 'contentsync_canonical_url', $sanitized_value );
		Logger::add( '→ ' . ( $meta_updated ? 'contentsync_canonical_url has been updated.' : 'contentsync_canonical_url could not be updated' ) );
		return $meta_updated;
	}
}
