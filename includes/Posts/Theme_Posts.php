<?php
/**
 * Theme post helper class.
 *
 * This class contains helper functions for working with theme posts such as templates, template parts, global styles, etc.
 */
namespace Contentsync\Posts;

defined( 'ABSPATH' ) || exit;

/**
 * Theme_Posts utility helpers.
 */
class Theme_Posts {

	/**
	 * Get theme post types.
	 *
	 * @return array Array of supported post type slugs.
	 */
	public static function get_theme_post_types() {
		return array(
			'wp_template',
			'wp_template_part',
			'wp_block',
			'wp_navigation',
			'wp_global_styles',
			// 'wp_font_family',
		);
	}

	/**
	 * Retrieves the theme posts for the given post types.
	 *
	 * @param array $args Array of arguments.
	 *
	 * @return array Array of posts.
	 */
	public static function get_theme_posts( $args ) {

		$args = wp_parse_args(
			$args,
			array(
				'post_type'      => self::get_theme_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$posts = get_posts( $args );

		return $posts;
	}

	/**
	 * Retrieves the theme slug for a wp_template post.
	 *
	 * @param WP_Post $post Post object.
	 *
	 * @return string The theme slug for the given template.
	 */
	public static function get_wp_template_theme( $post ) {
		$theme = wp_get_post_terms( $post->ID, 'wp_theme' );
		if ( $theme && is_array( $theme ) && isset( $theme[0] ) ) {
			return $theme[0]->name;
		}
		return '';
	}

	/**
	 * Assign a template to the current theme.
	 *
	 * @param WP_Post $post Post object.
	 * @param bool    $switch_references_in_content Optional. Whether to switch references in the content. Default false.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function set_wp_template_theme( $post, $switch_references_in_content = false ) {

		$current_theme = get_option( 'stylesheet' );

		$old_theme = null;
		if ( $switch_references_in_content ) {
			$old_theme = self::get_wp_template_theme( $post );
		}

		// set the terms
		$result = wp_set_post_terms( $post->ID, $current_theme, 'wp_theme' );

		if ( is_wp_error( $result ) ) {
			echo $result->get_error_message();
			return $result;
		}

		// change references in content
		if ( $switch_references_in_content && ! empty( $old_theme ) ) {

			$content = str_replace( '"theme":"' . $old_theme . '"', '"theme":"' . $current_theme . '"', $post->post_content );
			$result  = wp_update_post(
				array(
					'ID'           => $post->ID,
					'post_content' => wp_slash( $content ),
				)
			);
		}

		return (bool) $result;
	}

	/**
	 * Assign global styles to the current theme.
	 *
	 * @param WP_Post $post Post object.
	 *
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function set_wp_global_styles_theme( $post ) {

		$current_theme = get_option( 'stylesheet' );

		$current_global_styles_id = 0;
		if ( class_exists( 'WP_Theme_JSON_Resolver_Gutenberg' ) ) {
			$current_global_styles_id = \WP_Theme_JSON_Resolver_Gutenberg::get_user_global_styles_post_id();
		} elseif ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			$current_global_styles_id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		}

		if ( ! $current_global_styles_id ) {
			echo __( 'No global_styles_id created yet.', 'contentsync_hub' );
			return new \WP_Error( 'no_global_styles_id', __( 'No global_styles_id created yet.', 'contentsync_hub' ) );
		}

		$result = wp_update_post(
			array(
				'ID'           => $current_global_styles_id,
				'post_content' => wp_slash( $post->post_content ),
			)
		);

		if ( is_wp_error( $result ) ) {
			echo $result->get_error_message();
			return $result;
		}

		return (bool) $result;
	}
}
