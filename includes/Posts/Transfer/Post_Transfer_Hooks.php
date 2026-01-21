<?php

namespace Contentsync\Posts\Transfer;

use Contentsync\Utils\Hooks_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hook provider for post transfer/import operations.
 *
 * Registers filters and actions related to importing synced posts,
 * including content filtering and allowed HTML tag management.
 */
class Post_Transfer_Hooks extends Hooks_Base {

	/**
	 * Register hooks that run on both frontend and admin.
	 */
	public function register() {
		add_filter( 'contentsync_filter_post_content_before_post_import', array( $this, 'filter_block_content_on_import' ), 10, 3 );
		add_action( 'contentsync_before_import_synced_posts', array( $this, 'before_import_synced_posts' ), 10, 2 );
		add_action( 'contentsync_after_import_synced_posts', array( $this, 'after_import_synced_posts' ), 10, 2 );
	}

	/**
	 * Filter the post content before import.
	 *
	 * @param string $content     The post content.
	 * @param int    $new_post_id The new post ID.
	 * @param object $post        The post object.
	 *
	 * @return string The filtered content.
	 */
	public function filter_block_content_on_import( $content, $new_post_id, $post ) {
		$content = str_replace( '\\u002d\\u002d', '--', $content );
		$content = str_replace( '\u002d\u002d', '--', $content );
		$content = str_replace( 'u002du002d', '--', $content );

		return $content;
	}

	/**
	 * Before import synced posts: Filter the HTML tags that are allowed for a given context.
	 *
	 * @param array $posts            The posts to import.
	 * @param array $conflict_actions The conflict actions.
	 *
	 * @return void
	 */
	public function before_import_synced_posts( $posts, $conflict_actions ) {
		add_filter( 'wp_kses_allowed_html', array( $this, 'filter_allowed_html_tags_during_distribution' ), 98, 2 );
	}

	/**
	 * After import synced posts: Remove the filter for the HTML tags that are allowed for a given context.
	 *
	 * @param array $posts            The posts to import.
	 * @param array $conflict_actions The conflict actions.
	 *
	 * @return void
	 */
	public function after_import_synced_posts( $posts, $conflict_actions ) {
		remove_filter( 'wp_kses_allowed_html', array( $this, 'filter_allowed_html_tags_during_distribution' ), 98 );
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
	 *
	 * @return array[] The filtered allowed HTML tags.
	 */
	public function filter_allowed_html_tags_during_distribution( $html, $context ) {
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
}
