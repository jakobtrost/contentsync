<?php
/**
 * WPML Functions.
 *
 * Standalone functions for WPML that are not namespaced.
 * This file only gets loaded if WPML is not loaded in memory but is active on
 * the current site. This happens in multisite contexts after a blog switch.
 *
 * These functions provide direct database access to WPML's icl_translations table
 * to set post languages and translation relationships without requiring the
 * global $sitepress object.
 *
 * @since 2.19.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'contentsync_wpml_get_element_language_details' ) ) {
	/**
	 * Get language details for an element from the icl_translations table.
	 *
	 * @since 2.19.0
	 *
	 * @param int    $element_id   The element ID (post ID for posts).
	 * @param string $element_type The element type (e.g., 'post_post', 'post_page').
	 *
	 * @return object|null Object with language_code, trid, source_language_code, translation_id or null.
	 */
	function contentsync_wpml_get_element_language_details( $element_id, $element_type ) {
		global $wpdb;

		if ( empty( $element_id ) || empty( $element_type ) ) {
			return null;
		}

		$table_name = "{$wpdb->prefix}icl_translations";

		// Check if WPML table exists
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) != $table_name ) {
			return null;
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT translation_id, language_code, trid, source_language_code
				FROM {$table_name}
				WHERE element_id = %d
				AND element_type = %s
				LIMIT 1",
				$element_id,
				$element_type
			)
		);
	}
}

if ( ! function_exists( 'contentsync_wpml_get_element_trid' ) ) {
	/**
	 * Get the translation group ID (trid) for an element.
	 *
	 * @since 2.19.0
	 *
	 * @param int    $element_id   The element ID (post ID for posts).
	 * @param string $element_type The element type (e.g., 'post_post', 'post_page').
	 *
	 * @return int|null The trid or null if not found.
	 */
	function contentsync_wpml_get_element_trid( $element_id, $element_type ) {
		$details = contentsync_wpml_get_element_language_details( $element_id, $element_type );
		return $details ? (int) $details->trid : null;
	}
}

if ( ! function_exists( 'contentsync_wpml_get_source_language_by_trid' ) ) {
	/**
	 * Get the source (original) language code for a translation group.
	 *
	 * The source language is the one where source_language_code is NULL.
	 *
	 * @since 2.19.0
	 *
	 * @param int $trid The translation group ID.
	 *
	 * @return string|null The source language code or null.
	 */
	function contentsync_wpml_get_source_language_by_trid( $trid ) {
		global $wpdb;

		if ( empty( $trid ) ) {
			return null;
		}

		$table_name = "{$wpdb->prefix}icl_translations";

		// Check if WPML table exists
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) != $table_name ) {
			return null;
		}

		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT language_code
				FROM {$table_name}
				WHERE trid = %d
				AND source_language_code IS NULL
				LIMIT 1",
				$trid
			)
		);
	}
}

if ( ! function_exists( 'contentsync_wpml_get_next_trid' ) ) {
	/**
	 * Get the next available trid value.
	 *
	 * @since 2.19.0
	 *
	 * @return int The next available trid.
	 */
	function contentsync_wpml_get_next_trid() {
		global $wpdb;

		$table_name = "{$wpdb->prefix}icl_translations";

		// Check if WPML table exists
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) != $table_name ) {
			return 1;
		}

		$max_trid = $wpdb->get_var( "SELECT MAX(trid) FROM {$table_name}" );
		return (int) $max_trid + 1;
	}
}

if ( ! function_exists( 'contentsync_wpml_set_element_language_details' ) ) {
	/**
	 * Set language details for an element in the icl_translations table.
	 *
	 * This is a standalone implementation of WPML's wpml_set_element_language_details action
	 * that works without the global $sitepress object being loaded.
	 *
	 * @since 2.19.0
	 *
	 * @param array $args {
	 *     Arguments for setting element language details.
	 *
	 *     @type int         $element_id           The element ID (post ID for posts).
	 *     @type string      $element_type         The element type (e.g., 'post_post', 'post_page').
	 *     @type int|null    $trid                 Translation group ID. If null/empty, a new one is created.
	 *     @type string      $language_code        The language code to set.
	 *     @type string|null $source_language_code The source language code (null for originals).
	 *     @type bool        $check_duplicates     Whether to check for duplicates (default true).
	 * }
	 *
	 * @return int|false The translation_id on success, false on failure.
	 */
	function contentsync_wpml_set_element_language_details( $args ) {
		global $wpdb;

		// Parse arguments
		$defaults = array(
			'element_id'           => 0,
			'element_type'         => 'post_post',
			'trid'                 => null,
			'language_code'        => '',
			'source_language_code' => null,
			'check_duplicates'     => true,
		);

		$args = wp_parse_args( $args, $defaults );

		$element_id           = (int) $args['element_id'];
		$element_type         = sanitize_text_field( $args['element_type'] );
		$trid                 = $args['trid'] ? (int) $args['trid'] : null;
		$language_code        = sanitize_text_field( $args['language_code'] );
		$source_language_code = $args['source_language_code'] ? sanitize_text_field( $args['source_language_code'] ) : null;

		// Validate required fields
		if ( empty( $element_id ) || empty( $language_code ) ) {
			Logger::add( '  - WPML standalone: missing element_id or language_code.' );
			return false;
		}

		// Element type cannot be longer than 60 chars
		if ( strlen( $element_type ) > 60 ) {
			Logger::add( '  - WPML standalone: element_type too long (max 60 chars).' );
			return false;
		}

		$table_name = "{$wpdb->prefix}icl_translations";

		// Check if WPML table exists
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) != $table_name ) {
			Logger::add( "  - WPML standalone: table {$table_name} does not exist." );
			return false;
		}

		// Source language cannot be the same as target language
		if ( $source_language_code === $language_code ) {
			$source_language_code = null;
		}

		// Check for existing entry for this element
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT translation_id, trid, language_code
				FROM {$table_name}
				WHERE element_type = %s
				AND element_id = %d
				LIMIT 1",
				$element_type,
				$element_id
			)
		);

		if ( $trid ) {
			// We have a trid - this is a translation of an existing element

			// Check if there's already an entry for this trid + language combination
			$existing_in_trid = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT translation_id, element_id
					FROM {$table_name}
					WHERE trid = %d
					AND language_code = %s
					LIMIT 1",
					$trid,
					$language_code
				)
			);

			if ( $existing ) {
				// Element already has a translation entry

				if ( (int) $existing->trid === $trid && $existing->language_code === $language_code ) {
					// Same trid and language - nothing to update
					return (int) $existing->translation_id;
				}

				// Update the existing entry
				$wpdb->update(
					$table_name,
					array(
						'trid'                 => $trid,
						'language_code'        => $language_code,
						'source_language_code' => $source_language_code,
					),
					array(
						'translation_id' => $existing->translation_id,
					),
					array( '%d', '%s', '%s' ),
					array( '%d' )
				);

				return (int) $existing->translation_id;

			} elseif ( $existing_in_trid && empty( $existing_in_trid->element_id ) ) {
				// There's a placeholder entry (element_id is NULL) - update it
				$wpdb->update(
					$table_name,
					array( 'element_id' => $element_id ),
					array( 'translation_id' => $existing_in_trid->translation_id ),
					array( '%d' ),
					array( '%d' )
				);

				return (int) $existing_in_trid->translation_id;

			} elseif ( ! $existing_in_trid ) {
				// No entry exists for this trid + language - insert new row
				// Get source language if not provided
				if ( empty( $source_language_code ) ) {
					$source_language_code = contentsync_wpml_get_source_language_by_trid( $trid );
				}

				$wpdb->insert(
					$table_name,
					array(
						'element_type'         => $element_type,
						'element_id'           => $element_id,
						'trid'                 => $trid,
						'language_code'        => $language_code,
						'source_language_code' => $source_language_code,
					),
					array( '%s', '%d', '%d', '%s', '%s' )
				);

				if ( ! $wpdb->insert_id ) {
					Logger::add( "  - WPML standalone: failed to insert translation entry. Error: {$wpdb->last_error}" );
				}

				return $wpdb->insert_id;
			}

			// Entry already exists for this trid + language with different element_id - conflict
			Logger::add( "  - WPML standalone: conflict - entry exists for trid={$trid} + language={$language_code} with different element_id." );
			return false;

		} else {
			// No trid provided - this is a new element or we're removing it from a trid

			// Delete any existing entry for this element
			if ( $existing ) {
				$wpdb->delete(
					$table_name,
					array(
						'element_type' => $element_type,
						'element_id'   => $element_id,
					),
					array( '%s', '%d' )
				);
			}

			// Create a new trid
			$new_trid = contentsync_wpml_get_next_trid();

			// Insert new row
			$wpdb->insert(
				$table_name,
				array(
					'element_type'         => $element_type,
					'element_id'           => $element_id,
					'trid'                 => $new_trid,
					'language_code'        => $language_code,
					'source_language_code' => null, // New elements are originals
				),
				array( '%s', '%d', '%d', '%s', '%s' )
			);

			if ( ! $wpdb->insert_id ) {
				Logger::add( "  - WPML standalone: failed to insert new translation group. Error: {$wpdb->last_error}" );
			}

			return $wpdb->insert_id;
		}
	}
}

if ( ! function_exists( 'contentsync_wpml_delete_element_translation' ) ) {
	/**
	 * Delete translation entry for an element.
	 *
	 * @since 2.19.0
	 *
	 * @param int    $element_id   The element ID.
	 * @param string $element_type The element type.
	 *
	 * @return bool True on success, false on failure.
	 */
	function contentsync_wpml_delete_element_translation( $element_id, $element_type ) {
		global $wpdb;

		if ( empty( $element_id ) || empty( $element_type ) ) {
			return false;
		}

		$table_name = "{$wpdb->prefix}icl_translations";

		// Check if WPML table exists
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) != $table_name ) {
			return false;
		}

		$result = $wpdb->delete(
			$table_name,
			array(
				'element_type' => $element_type,
				'element_id'   => $element_id,
			),
			array( '%s', '%d' )
		);

		return $result !== false;
	}
}

if ( ! function_exists( 'contentsync_wpml_get_translations_by_trid' ) ) {
	/**
	 * Get all translations in a translation group.
	 *
	 * @since 2.19.0
	 *
	 * @param int    $trid         The translation group ID.
	 * @param string $element_type Optional. Filter by element type.
	 *
	 * @return array Associative array of language_code => element_id.
	 */
	function contentsync_wpml_get_translations_by_trid( $trid, $element_type = '' ) {
		global $wpdb;

		if ( empty( $trid ) ) {
			return array();
		}

		$table_name = "{$wpdb->prefix}icl_translations";

		// Check if WPML table exists
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) != $table_name ) {
			return array();
		}

		$query  = "SELECT language_code, element_id FROM {$table_name} WHERE trid = %d";
		$params = array( $trid );

		if ( ! empty( $element_type ) ) {
			$query   .= ' AND element_type = %s';
			$params[] = $element_type;
		}

		$results = $wpdb->get_results( $wpdb->prepare( $query, $params ) );

		if ( ! is_array( $results ) || empty( $results ) ) {
			return array();
		}

		$translations = array();
		foreach ( $results as $result ) {
			if ( ! empty( $result->element_id ) ) {
				$translations[ $result->language_code ] = (int) $result->element_id;
			}
		}

		return $translations;
	}
}

if ( ! function_exists( 'contentsync_wpml_get_active_languages' ) ) {
	/**
	 * Get active language codes from WPML.
	 *
	 * @since 2.19.0
	 *
	 * @return array Array of active language codes.
	 */
	function contentsync_wpml_get_active_languages() {
		global $wpdb;

		$table_name = "{$wpdb->prefix}icl_languages";

		// Check if WPML table exists
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) != $table_name ) {
			return array();
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT code FROM {$table_name} WHERE active = %d",
				1
			)
		);

		if ( ! is_array( $results ) || empty( $results ) ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				return $row->code;
			},
			$results
		);
	}
}
