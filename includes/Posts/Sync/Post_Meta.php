<?php

namespace Contentsync\Posts\Sync;

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
	 * Used to unimport & unexport posts.
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
}
