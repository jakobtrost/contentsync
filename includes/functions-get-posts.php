<?php

namespace Contentsync;

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
 * @since 1.3.0 (contentsync_suite)
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
 * Retrieves the edit post link for post.
 *
 * @see wp-includes/link-template.php
 *
 * @param int|WP_Post $post Post ID or post object.
 *
 * @return string The edit post link URL for the given post.
 */
function get_edit_post_link( $post ) {

	if ( ! is_object( $post ) ) {
		$post = get_post( $post );
	}

	switch ( $post->post_type ) {
		case 'wp_global_styles':
			// do not allow editing of global styles and font families from other themes
			if ( get_wp_template_theme( $post ) != get_option( 'stylesheet' ) ) {
				return null;
			}

			// wp-admin/site-editor.php?path=/wp_global_styles&canvas=edit
			return add_query_arg(
				array(
					// 'path'   => '/wp_global_styles',
					// 'canvas' => 'edit',
					'p' => '/styles',
				),
				admin_url( 'site-editor.php' )
			);
			break;
		case 'wp_template':
			// wp-admin/site-editor.php?postType=wp_template&postId=greyd-theme//404&canvas=edit
			return add_query_arg(
				array(
					'postType' => $post->post_type,
					'postId'   => get_wp_template_theme( $post ) . '//' . $post->post_name,
					'canvas'   => 'edit',
				),
				admin_url( 'site-editor.php' )
			);
			break;
		case 'wp_template_part':
			// wp-admin/site-editor.php?postType=wp_template_part&postId=greyd-theme//footer&categoryId=footer&categoryType=wp_template_part&canvas=edit
			return add_query_arg(
				array(
					'postType'     => $post->post_type,
					'postId'       => get_wp_template_theme( $post ) . '//' . $post->post_name,
					'categoryId'   => $post->ID,
					'categoryType' => $post->post_type,
					'canvas'       => 'edit',
				),
				admin_url( 'site-editor.php' )
			);
			break;
		case 'wp_navigation':
			// wp-admin/site-editor.php?postId=169&postType=wp_navigation&canvas=edit
			return add_query_arg(
				array(
					'postId'   => $post->ID,
					'postType' => $post->post_type,
					'canvas'   => 'edit',
				),
				admin_url( 'site-editor.php' )
			);
			break;
		case 'wp_block':
			// wp-admin/edit.php?post_type=wp_block
			return add_query_arg(
				array(
					'post'   => $post->ID,
					'action' => 'edit',
				),
				admin_url( 'post.php' )
			);
			break;
		case 'wp_font_family':
			// wp-admin/site-editor.php?path=/wp_global_styles&canvas=edit
			return add_query_arg(
				array(
					// 'path'   => '/wp_global_styles',
					// 'canvas' => 'edit',
					'p' => '/styles',
				),
				admin_url( 'site-editor.php' )
			);
			break;
		default:
			return html_entity_decode( \get_edit_post_link( $post ) );
			// return add_query_arg(
			// array(
			// 'post'      => $post->ID,
			// 'action'    => 'edit',
			// ),
			// admin_url( 'post.php' )
			// );
			break;
	}
	return '';
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
