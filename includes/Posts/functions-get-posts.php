<?php

namespace Contentsync\Posts;

use Contentsync\Translations\Translation_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get posts without filters.
 *
 * This function unifies the get_posts() function in order to prevent
 * errors with dynamic post types or filtered queries through plugins
 * like WPML or Polylang, whose filters are falsely applied on other blogs.
 *
 * @param array $args
 *
 * @return WP_Post[]
 */
function get_unfiltered_posts( $args ) {

	$args = remove_filters_from_query_args( $args );

	/**
	 * Filter arguments for the get_post() function.
	 *
	 * @param array $args
	 *
	 * @return array $args
	 */
	$parsed_args = apply_filters( 'contentsync_get_posts_args', $args );

	$posts = \get_posts( $parsed_args );

	/**
	 * Filter the posts after the get_posts() function.
	 *
	 * @param WP_Post[] $posts
	 * @param array $parsed_args
	 *
	 * @return WP_Post[] $posts
	 */
	$posts = apply_filters( 'contentsync_get_posts', $posts, $parsed_args );

	return $posts;
}


/**
 * Retrieves the terms of the taxonomy that are attached to the post.
 *
 * Usually we would use the core function get_the_terms(). However it sometimes returns
 * terms of completely different taxonomies - without returning an error. To retrieve the
 * terms directly from the database seems to work more consistent in those cases.
 *
 * @see get_the_terms()
 * @see https://developer.wordpress.org/reference/functions/get_the_terms/
 * @see this function was copied from contentsync_tp_management/inc/post_export.php
 *
 * @param int    $post_id    Post ID.
 * @param string $taxonomy   Taxonomy name.
 * @return WP_Term[]|null       Array of WP_Term objects on success, null if there are no terms
 *                              or the post does not exist.
 */
function get_post_taxonomy_terms( $post_id, string $taxonomy ) {

	if ( ! is_numeric( $post_id ) || ! is_string( $taxonomy ) ) {
		return null;
	}

	global $wpdb;
	$results = $wpdb->get_results(
		"
		SELECT {$wpdb->terms}.term_id, name, slug, term_group, {$wpdb->term_relationships}.term_taxonomy_id, taxonomy, description, parent, count FROM {$wpdb->terms}
			LEFT JOIN {$wpdb->term_relationships} ON
				({$wpdb->terms}.term_id = {$wpdb->term_relationships}.term_taxonomy_id)
			LEFT JOIN {$wpdb->term_taxonomy} ON
				({$wpdb->term_relationships}.term_taxonomy_id = {$wpdb->term_taxonomy}.term_taxonomy_id)
		WHERE {$wpdb->term_relationships}.object_id = {$post_id}
			AND {$wpdb->term_taxonomy}.taxonomy = '{$taxonomy}'
	"
	);
	if ( $results && is_array( $results ) && count( $results ) ) {
		return array_map(
			function ( $term ) {
				return new \WP_Term( $term );
			},
			$results
		);
	}
	return null;
}

/**
 * Get language code of a post.
 *
 * @see Translation_Manager::get_post_language_info( $post )
 *
 * @param WP_Post $post
 * @return string Empty string if no language was found.
 */
function get_post_language_code( $post ) {
	$language_details = Translation_Manager::get_post_language_info( $post );
	if ( is_array( $language_details ) && isset( $language_details['language_code'] ) ) {
		return $language_details['language_code'];
	}
	return '';
}

/**
 * Remove filters from query args.
 *
 * @param array $args
 *
 * @return array $args
 */
function remove_filters_from_query_args( $args ) {
	$parsed_args = \wp_parse_args(
		$args,
		array(
			'suppress_filters' => true,
			'lang'             => '',
		)
	);

	if (
		isset( $parsed_args['post_type'] )
		&& $parsed_args['post_type'] === 'attachment'
		&& isset( $parsed_args['post_status'] )
	) {
		if ( is_array( $parsed_args['post_status'] ) ) {
			$parsed_args['post_status'][] = 'inherit';
		} else {
			$parsed_args['post_status'] = array_merge( array( 'inherit' ), explode( ',', $parsed_args['post_status'] ) );
		}
	}

	return $parsed_args;
}
