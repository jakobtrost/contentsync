<?php
/**
 * Polylang Functions.
 *
 * Standalone functions for Polylang that are not namespaced.
 * This file only gets loaded if Polylang is not loaded in memory but is active on
 * the current site. This happens in multisite contexts after a blog switch.
 *
 * @since 2.19.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'pll_get_post_language' ) ) {
	/**
	 * Returns the post language slug.
	 */
	function pll_get_post_language( $post_id, $field = 'slug' ) {
		if ( empty( $post_id ) ) {
			return false;
		}

		$terms = wp_get_object_terms( $post_id, 'language', array( 'fields' => 'all' ) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return false;
		}

		$lang_term = reset( $terms );
		return $lang_term->slug;
	}
}

if ( ! function_exists( 'pll_languages_list' ) ) {
	/**
	 * Returns the list of available language slugs.
	 */
	function pll_languages_list( $args = array() ) {
		$args = wp_parse_args( $args, array(
			'fields'     => 'slug',
			'hide_empty' => false,
		) );

		$language_terms = get_terms( array(
			'taxonomy'   => 'language',
			'hide_empty' => $args['hide_empty'],
			'orderby'    => 'term_group',
			'order'      => 'ASC',
		) );

		if ( is_wp_error( $language_terms ) || empty( $language_terms ) ) {
			return array();
		}

		return wp_list_pluck( $language_terms, 'slug' );
	}
}

if ( ! function_exists( 'pll_get_post_translations' ) ) {
	/**
	 * Returns an array of translations of a post.
	 */
	function pll_get_post_translations( $post_id ) {
		if ( empty( $post_id ) ) {
			return array();
		}

		$terms = wp_get_object_terms( $post_id, 'post_translations', array( 'fields' => 'all' ) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$translation_term = reset( $terms );

		if ( empty( $translation_term->description ) ) {
			return array();
		}

		$translations = maybe_unserialize( $translation_term->description );

		if ( ! is_array( $translations ) ) {
			return array();
		}

		$translations = array_filter( $translations, function( $translation_id ) {
			return is_numeric( $translation_id ) && $translation_id > 0;
		} );

		return array_map( 'intval', $translations );
	}
}

if ( ! function_exists( 'pll_set_post_language' ) ) {
	/**
	 * Sets the post language.
	 * 
	 * Standalone version that assigns a language to a post by setting the
	 * language term via the 'language' taxonomy without requiring Polylang to be loaded.
	 * 
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language slug.
	 * 
	 * @return bool True on success, false on failure.
	 */
	function pll_set_post_language( $post_id, $lang ) {
		if ( empty( $post_id ) || empty( $lang ) ) {
			return false;
		}

		// Get the language term
		$term = term_exists( $lang, 'language' );

		if ( ! $term ) {
			return false;
		}

		// Set the language term for this post
		$result = wp_set_object_terms( $post_id, (int) $term['term_id'], 'language', false );

		return ! is_wp_error( $result ) && ! empty( $result );
	}
}

if ( ! function_exists( 'pll_save_post_translations' ) ) {
	/**
	 * Saves post translations.
	 * 
	 * Standalone version that creates or updates a translation group in the 'post_translations'
	 * taxonomy without requiring Polylang to be loaded. The translations are stored as a
	 * serialized array in the term description.
	 * 
	 * @param array $translations Associative array with language code as key and post ID as value.
	 *                            Example: array( 'en' => 123, 'de' => 456, 'fr' => 789 )
	 * 
	 * @return array The translations array on success, empty array on failure.
	 */
	function pll_save_post_translations( $translations ) {
		if ( empty( $translations ) || ! is_array( $translations ) ) {
			return array();
		}

		// Get the first post ID to work with
		$post_id = reset( $translations );
		if ( empty( $post_id ) ) {
			return array();
		}

		// Validate and sanitize all post IDs
		$translations = array_filter( $translations, function( $translation_id ) {
			return is_numeric( $translation_id ) && $translation_id > 0;
		} );
		$translations = array_map( 'intval', $translations );

		if ( empty( $translations ) ) {
			return array();
		}

		// Check if any of these posts already have a translation group
		$existing_terms = wp_get_object_terms( array_values( $translations ), 'post_translations', array(
			'fields' => 'all',
		) );

		$term = false;
		if ( ! is_wp_error( $existing_terms ) && ! empty( $existing_terms ) ) {
			// Use the first existing term
			$term = reset( $existing_terms );
		}

		// Create or update the translation term
		if ( empty( $term ) ) {
			// Create a new translation group term
			$group_name = uniqid( 'pll_' );
			$term_data = wp_insert_term( $group_name, 'post_translations', array(
				'description' => maybe_serialize( $translations ),
			) );

			if ( is_wp_error( $term_data ) ) {
				return array();
			}

			$term_id = $term_data['term_id'];
		} else {
			// Update existing term description with new translations
			$term_id = $term->term_id;
			
			// Get existing translations from description
			$existing_translations = array();
			if ( ! empty( $term->description ) ) {
				$existing_translations = maybe_unserialize( $term->description );
				if ( ! is_array( $existing_translations ) ) {
					$existing_translations = array();
				}
			}

			// Merge with new translations (new ones override existing)
			$merged_translations = array_merge( $existing_translations, $translations );

			wp_update_term( $term_id, 'post_translations', array(
				'description' => maybe_serialize( $merged_translations ),
			) );
		}

		// Link all posts in the translation group to this term
		foreach ( $translations as $lang => $tr_post_id ) {
			wp_set_object_terms( $tr_post_id, (int) $term_id, 'post_translations', false );
		}

		return $translations;
	}
}