<?php

namespace Contentsync\Posts\Transfer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Post_Import extends Post_Transfer {

	/**
	 * Collect strings during import to replace them later.
	 *
	 * @var array Key => Value pairs of strings to be replaced.
	 */
	private $collected_replace_strings = array();


	/**
	 * Import posts with all its meta, taxonomies, media etc.
	 *
	 * @since 2.18.0
	 *
	 * @param int[]|object[] $post_ids_or_objects  Array of post IDs or post objects.
	 * @param array          $arguments            Import arguments.
	 *
	 * @return Prepared_Post[]
	 */
	public function __construct( $post_ids_or_objects, $arguments = array() ) {
		parent::__construct( $post_ids_or_objects, $arguments );

		$this->collected_replace_strings = array();
	}

	/**
	 * ================================================
	 * PRIVATE METHODS
	 * ================================================
	 */

	/**
	 * Get the default arguments.
	 *
	 * @return array The default arguments.
	 */
	private function get_default_arguments() {

		return apply_filters(
			'contentsync_import_default_arguments',
			array(
				/**
				 * @property array $conflict_actions Array of posts that already exist on the current blog.
				 *                                   Keyed by the same ID as in the @param $posts.
				 *                                   @property post_id: ID of the current post.
				 *                                   @property action: Action to be done (skip|replace|keep)
				 */
				'conflict_actions' => array(),
				/**
				 * @property string $zip_file        Path to imported ZIP archive.
				 */
				'zip_file'         => null,
			)
		);
	}

	/**
	 * Parse export arguments.
	 *
	 * @since 2.18.0
	 *
	 * @param array $arguments The arguments to parse.
	 *
	 * @return array The parsed arguments.
	 */
	private function parse_arguments( $arguments ) {

		$default_arguments = $this->get_default_arguments();

		if ( ! is_array( $arguments ) ) {
			return $default_arguments;
		}

		$parsed_arguments = wp_parse_args( $arguments, $default_arguments );

		/**
		 * Filter the parsed export arguments.
		 *
		 * @filter contentsync_import_arguments
		 *
		 * @param array $parsed_arguments The parsed arguments.
		 * @param array $arguments        The original arguments.
		 *
		 * @return array The filtered parsed arguments.
		 */
		return apply_filters( 'contentsync_import_arguments', $parsed_arguments, $arguments );
	}




	/**
	 * @todo REWORK
	 */

	/**
	 * Import posts with backward compatiblity and additional actions.
	 *
	 * @see \Contentsync\Post_Import::import_posts()
	 *
	 * @param array $posts             Array of posts to import.
	 * @param array $conflict_actions  Array of conflict actions.
	 *
	 * @return mixed                  True on success. WP_Error on failure.
	 */
	public static function import_posts( $posts, $conflict_actions = array() ) {

		/**
		 * @action contentsync_before_import_global_posts
		 * @since 2.18.0
		 */
		do_action( 'contentsync_before_import_global_posts', $posts, $conflict_actions );

		$result = self::call_post_export_func( 'import_posts', $posts, $conflict_actions );

		/**
		 * @action contentsync_after_import_global_posts
		 * @since 2.18.0
		 */
		do_action( 'contentsync_after_import_global_posts', $posts, $conflict_actions, $result );

		return $result;
	}
}




/**
 * @todo REWORK
 */

/**
 * =================================================================
 *                          SANITIZATION
 * =================================================================
 */
add_action( 'contentsync_before_import_synced_posts', __NAMESPACE__ . '\\before_import_synced_posts', 10, 2 );
add_action( 'contentsync_after_import_synced_posts', __NAMESPACE__ . '\\after_import_synced_posts', 10, 2 );

/**
 * Before import synced posts: Filter the HTML tags that are allowed for a given context.
 *
 * @param array $posts  The posts to import.
 * @param array $conflict_actions  The conflict actions.
 *
 * @return void
 */
function before_import_synced_posts( $posts, $conflict_actions ) {
	add_filter( 'wp_kses_allowed_html', __NAMESPACE__ . '\\filter_allowed_html_tags_during_distribution', 98, 2 );
}

/**
 * After import synced posts: Remove the filter for the HTML tags that are allowed for a given context.
 *
 * @param array $posts  The posts to import.
 * @param array $conflict_actions  The conflict actions.
 *
 * @return void
 */
function after_import_synced_posts( $posts, $conflict_actions ) {
	remove_filter( 'wp_kses_allowed_html', __NAMESPACE__ . '\\filter_allowed_html_tags_during_distribution', 98 );
}

/**
 * Filters the HTML tags that are allowed for a given context.
 *
 * HTML tags and attribute names are case-insensitive in HTML but must be
 * added to the KSES allow list in lowercase. An item added to the allow list
 * in upper or mixed case will not recognized as permitted by KSES.
 *
 * @param array[] $html    Allowed HTML tags.
 * @param string  $context Context name.
 */
function filter_allowed_html_tags_during_distribution( $html, $context ) {

	if ( $context !== 'post' ) {
		return $html;
	}

	$default_attributes = array(
		'id'            => true,
		'class'         => true,
		'href'          => true,
		'name'          => true,
		'target'        => true,
		'download'      => true,
		'data-*'        => true,
		'style'         => true,
		'title'         => true,
		'role'          => true,
		'onclick'       => true,
		'aria-*'        => true,
		'aria-expanded' => true,
		'aria-controls' => true,
		'aria-label'    => true,
		'tabindex'      => true,
	);

	// iframe
	$html['iframe'] = array_merge(
		isset( $html['iframe'] ) ? $html['iframe'] : array(),
		$default_attributes,
		array(
			'src'             => true,
			'width'           => true,
			'height'          => true,
			'frameborder'     => true,
			'allowfullscreen' => true,
		)
	);

	// script
	$html['script'] = array_merge(
		isset( $html['script'] ) ? $html['script'] : array(),
		$default_attributes,
		array(
			'src'   => true,
			'type'  => true,
			'async' => true,
			'defer' => true,
		)
	);

	return $html;
}
