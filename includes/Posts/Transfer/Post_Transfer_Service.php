<?php

namespace Contentsync\Posts\Transfer;

use Contentsync\Translations\Translation_Manager;
use Contentsync\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Helper class for post transfer operations.
 *
 * Provides static methods for retrieving supported post types and
 * finding existing posts by name and type.
 */
class Post_Transfer_Service {

	/**
	 * Get all supported posttypes for transfer operations
	 *
	 * @return string[] Array of post type slugs.
	 */
	public static function get_supported_post_types() {

		// check cache
		if ( $cache = wp_cache_get( 'get_supported_post_types', 'synced_post_export' ) ) {
			return $cache;
		}

		$include = array( 'page', 'post', 'attachment', 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation' );
		$exclude = array();

		$posttypes = array_keys( get_post_types( array( '_builtin' => false ) ) );

		$supported = array_diff( array_merge( $include, $posttypes ), $exclude );

		// Set cache
		wp_cache_set( 'get_supported_post_types', $supported, 'synced_post_export' );

		return $supported;
	}

	/**
	 * Get post by name and post_type
	 *
	 * eg. checks if post already exists.
	 *
	 * @param object|string $post   WP_Post object or post_name
	 *
	 * @return bool|object False on failure, WP_Post on success.
	 */
	public static function get_post_by_name_and_type( $post ) {

		$post_name = is_object( $post ) ? (string) $post->post_name : (string) $post;
		$post_type = is_object( $post ) ? (string) $post->post_type : self::get_supported_post_types();
		$args      = array(
			'name'        => $post_name,
			'post_type'   => $post_type,
			'numberposts' => 1,
			'post_status' => array( 'publish', 'pending', 'draft', 'future', 'private', 'inherit' ),
		);

		// only get post of same language
		if ( Translation_Manager::switch_to_language_context( $post ) ) {
			$args['suppress_filters'] = false;
		} else {
			$args['suppress_filters'] = true;
			$args['lang']             = '';
		}

		// query
		$result = get_posts( $args );

		if ( is_array( $result ) && isset( $result[0] ) ) {
			Logger::add( sprintf( "  - %s found with ID '%s'.", $post->post_type, $result[0]->ID ) );
			return $result[0];
		} else {
			Logger::add( sprintf( "  - Post '%s' not found by name and post type.", $post_name ) );
			return false;
		}
	}

	/**
	 * Get existing post ID by name and post_type
	 *
	 * @param object|string $post   WP_Post object or post_name
	 *
	 * @return int 0 on failure, post ID on success.
	 */
	public static function get_existing_post_id( $post ) {
		$existing_post = self::get_post_by_name_and_type( $post );
		if ( $existing_post && isset( $existing_post->ID ) ) {
			Logger::add( sprintf( '  - existing post with ID: %s.', $existing_post->ID ) );
			return $existing_post->ID;
		}
		return 0;
	}
}
