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
}
