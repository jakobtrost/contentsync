<?php

namespace Contentsync\Posts\Transfer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Post_Transfer {

	/**
	 * Holds all Prepared_Post objects for a post transfer, keyed by post ID.
	 *
	 * @var Prepared_Post[]
	 */
	protected $posts = array();

	/**
	 * Holds different arguments based on the transfer type (import, export).
	 *
	 * @var array
	 */
	protected $arguments = array();

	/**
	 * Constructor.
	 *
	 * @param int[]|object[] $post_ids_or_objects   Array of post IDs or post objects.
	 * @param array          $arguments             Arguments.
	 */
	public function __construct( $post_ids_or_objects, $arguments = array() ) {
		$this->arguments = $this->parse_arguments( $arguments );
	}

	/**
	 * Parse the arguments.
	 *
	 * @param array $arguments The arguments to parse.
	 *
	 * @return array The parsed arguments.
	 */
	protected function parse_arguments( $arguments ) {
		return $arguments;
	}

	/**
	 * ==================================================
	 * Getters
	 * ==================================================
	 */
	public function get_posts() {
		return $this->posts;
	}
}
