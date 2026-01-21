<?php
/**
 * WPML Translation Tool
 *
 * Handles all WPML-specific translation operations.
 * Uses database queries to access WPML's custom tables.
 */

namespace Contentsync\Translations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Translation_Tool_WPML extends Translation_Tool_Base {

	/**
	 * Get the tool name identifier.
	 *
	 * @return string
	 */
	public function get_tool_name() {
		return 'wpml';
	}

	/**
	 * Check if WPML plugin is loaded in memory.
	 *
	 * @return bool True if WPML is loaded, false otherwise.
	 */
	public function is_plugin_loaded() {
		return defined( 'ICL_LANGUAGE_CODE' ) && isset( $GLOBALS['sitepress'] );
	}

	/**
	 * Initialize WPML environment.
	 *
	 * Registers standalone functions that provide database-based access
	 * to WPML translation data when the plugin is not loaded.
	 *
	 * @return bool True on success.
	 */
	public function init_environment() {
		// If WPML is already loaded with sitepress, no initialization needed
		if ( $this->is_plugin_loaded() ) {
			return true;
		}

		// Check if standalone functions are already loaded
		if ( function_exists( 'contentsync_wpml_set_element_language_details' ) ) {
			return true;
		}

		// Register standalone functions for data access
		$this->register_standalone_functions();

		return true;
	}

	/**
	 * Register standalone WPML functions.
	 */
	private function register_standalone_functions() {
		/**
		 * Load the functions from separate file to load them without Namespacing.
		 */
		require_once __DIR__ . '/functions/polyfill-functions-wpml.php';
	}

	/**
	 * Get language information for a post.
	 *
	 * @param Prepared_Post|WP_Post|int $post Prepared_Post object, WP_Post object, or post ID.
	 * @return array|null Language info array or null.
	 */
	public function get_post_language_info( $post ) {
		global $wpdb;

		$post = $this->validate_post( $post );
		if ( ! $post ) {
			return null;
		}

		$table_name = "{$wpdb->prefix}icl_translations";

		// Check if WPML table exists
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) != $table_name ) {
			return null;
		}

		$query = $wpdb->prepare(
			"SELECT language_code, element_id, trid, source_language_code
			FROM {$table_name}
			WHERE element_id=%d
			AND element_type=%s
			LIMIT 1",
			array( $post->ID, 'post_' . $post->post_type )
		);

		$result = $wpdb->get_results( $query );

		if ( is_array( $result ) && count( $result ) ) {
			return (array) $result[0];
		}

		return null;
	}

	/**
	 * Get all active language codes.
	 *
	 * @return array Language codes.
	 */
	public function get_language_codes() {
		global $wpdb;

		$table_name = "{$wpdb->prefix}icl_languages";

		// Check if WPML table exists
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) != $table_name ) {
			return array( $this->get_wp_default_language() );
		}

		$query = $wpdb->prepare(
			"SELECT code
			FROM {$table_name}
			WHERE active=%d",
			array( 1 )
		);

		$result = $wpdb->get_results( $query );

		if ( is_array( $result ) && count( $result ) ) {
			return array_map(
				function ( $object ) {
					return esc_attr( $object->code );
				},
				$result
			);
		}

		return array( $this->get_wp_default_language() );
	}

	/**
	 * Get WPML default language for this site.
	 *
	 * Retrieves the default language from WPML settings. This is used when
	 * a post's language is not supported on the target site - we assign the
	 * default language to ensure the post is visible in the admin area.
	 *
	 * @return string Default language code.
	 */
	public function get_wpml_default_language() {
		// Try native WPML filter first (if WPML is loaded)
		if ( $this->is_plugin_loaded() ) {
			$default = apply_filters( 'wpml_default_language', null );
			if ( $default ) {
				return $default;
			}
		}

		// Fall back to reading from WPML settings option
		$wpml_settings = get_option( 'icl_sitepress_settings', array() );
		if ( isset( $wpml_settings['default_language'] ) && ! empty( $wpml_settings['default_language'] ) ) {
			return $wpml_settings['default_language'];
		}

		// Fall back to first active language
		$languages = $this->get_language_codes();
		if ( ! empty( $languages ) ) {
			return $languages[0];
		}

		// Ultimate fallback to WordPress default
		return $this->get_wp_default_language();
	}

	/**
	 * Get post translations.
	 *
	 * @param Prepared_Post|WP_Post|int $post Prepared_Post object, WP_Post object, or post ID.
	 * @return array Translation array.
	 */
	public function get_post_translations( $post ) {
		global $wpdb;

		$post = $this->validate_post( $post );
		if ( ! $post ) {
			return array();
		}

		$table_name = "{$wpdb->prefix}icl_translations";

		// Check if WPML table exists
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) != $table_name ) {
			return array();
		}

		// First, get the translation group ID (trid) for this post
		$query = $wpdb->prepare(
			"SELECT trid
			FROM {$table_name}
			WHERE element_id=%d
			AND element_type=%s
			LIMIT 1",
			array( $post->ID, 'post_' . $post->post_type )
		);

		$trid = $wpdb->get_var( $query );

		if ( ! $trid ) {
			return array();
		}

		// Get all translations in this group
		$query = $wpdb->prepare(
			"SELECT language_code, element_id
			FROM {$table_name}
			WHERE trid=%d
			AND element_type=%s",
			array( $trid, 'post_' . $post->post_type )
		);

		$results = $wpdb->get_results( $query );

		if ( ! is_array( $results ) || empty( $results ) ) {
			return array();
		}

		$translations = array();
		foreach ( $results as $result ) {
			$translations[ $result->language_code ] = (int) $result->element_id;
		}

		return $translations;
	}

	/**
	 * Set the language for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $language_code Language code.
	 * @return bool True on success, false on failure.
	 */
	public function set_post_language( $post_id, $language_code ) {
		// Ensure environment is initialized (loads standalone functions if needed)
		$this->init_environment();

		// If language code is not supported on this site, use the default language instead
		// This ensures the post is visible in the admin area (WPML filters out posts without language)
		if ( ! $this->is_language_supported( $language_code ) ) {
			$default_language = $this->get_wpml_default_language();
			Logger::add( sprintf( "  - the language '%s' is not supported, using default language '%s' instead.", $language_code, $default_language ) );
			$language_code = $default_language;
		}

		$post_type    = get_post_type( $post_id );
		$element_type = 'post_' . $post_type;

		// If WPML is loaded, use the native action
		if ( $this->is_plugin_loaded() ) {
			do_action(
				'wpml_set_element_language_details',
				array(
					'element_id'    => $post_id,
					'element_type'  => apply_filters( 'wpml_element_type', $post_type ),
					'language_code' => $language_code,
				)
			);
			Logger::add( sprintf( "  - set language of post '%s' to '%s'.", $post_id, $language_code ) );
			return true;
		}

		// Use standalone function when WPML is not loaded
		if ( function_exists( 'contentsync_wpml_set_element_language_details' ) ) {
			$result = contentsync_wpml_set_element_language_details(
				array(
					'element_id'    => $post_id,
					'element_type'  => $element_type,
					'language_code' => $language_code,
				)
			);

			if ( $result ) {
				Logger::add( sprintf( "  - set language of post '%s' to '%s'.", $post_id, $language_code ) );
				return true;
			} else {
				Logger::add( sprintf( "  - failed to set language of post '%s' to '%s'.", $post_id, $language_code ) );
				return false;
			}
		}

		Logger::add( '  - standalone WPML functions not available.' );
		return false;
	}

	/**
	 * Set translation relationships for a post during import.
	 *
	 * @param int    $post_id Current post ID.
	 * @param string $language_code Language code.
	 * @param array  $original_post_ids Original post IDs from export (lang => id).
	 * @param array  $language_args WPML-specific arguments (trid/source_language_code are ignored - they refer to source site).
	 * @param array  $imported_post_map Map of original IDs to new IDs.
	 * @return bool
	 */
	public function set_translations_from_import( $post_id, $language_code, $original_post_ids, $language_args, $imported_post_map ) {
		// Ensure environment is initialized (loads standalone functions if needed)
		$this->init_environment();

		$post = get_post( $post_id );
		if ( ! $post ) {
			Logger::add( '  - failed to set translations: post not found.' );
			return false;
		}

		$element_type         = 'post_' . $post->post_type;
		$trid                 = null;
		$source_language_code = null; // Always derive from current site's trid, never from language_args
		$current_post_trid    = null; // Store the current post's trid for later comparison

		// First, get the current post's trid (if it has one from set_post_language())
		$current_post_trid = $this->get_element_trid( $post_id, $element_type );

		// Strategy 1: FIRST check if any OTHER translated posts have already been imported with a trid
		// This is important because we want to JOIN an existing translation group, not create separate ones
		if ( ! empty( $original_post_ids ) && is_array( $original_post_ids ) ) {
			foreach ( $original_post_ids as $lang_code => $original_id ) {
				// Skip the current post's language
				if ( $lang_code === $language_code ) {
					continue;
				}

				// Check if this original post was already imported
				$imported_id = isset( $imported_post_map[ $original_id ] ) ? $imported_post_map[ $original_id ] : null;

				if ( ! empty( $imported_id ) && $imported_id !== $post_id ) {
					// Try to get the trid from this imported post
					$found_trid = $this->get_element_trid( $imported_id, $element_type );

					if ( ! empty( $found_trid ) ) {
						$trid = $found_trid;
						// Get the source language for this trid from the current site's database
						$trid_source_lang = $this->get_source_language_by_trid( $trid );
						// Only set source_language_code if the current post is NOT the source
						if ( ! empty( $trid_source_lang ) && $trid_source_lang !== $language_code ) {
							$source_language_code = $trid_source_lang;
						}
						break;
					}
				}
			}
		}

		// Strategy 2: If no trid found from other translations, use the current post's trid
		if ( empty( $trid ) && ! empty( $current_post_trid ) ) {
			$trid = $current_post_trid;
			// Get the source language for this trid
			$trid_source_lang = $this->get_source_language_by_trid( $trid );
			if ( ! empty( $trid_source_lang ) && $trid_source_lang !== $language_code ) {
				$source_language_code = $trid_source_lang;
			}
		}

		// Strategy 3: If still no trid, create a new translation group with this post as the source
		if ( empty( $trid ) ) {
			// Get next available trid
			$trid = $this->get_next_trid();
			// This post becomes the source (source_language_code = null for source posts)
			$source_language_code = null;
		}

		// If WPML is loaded, use the native action
		if ( $this->is_plugin_loaded() ) {
			$wpml_element_type = apply_filters( 'wpml_element_type', $post->post_type );

			do_action(
				'wpml_set_element_language_details',
				array(
					'element_id'           => $post_id,
					'element_type'         => $wpml_element_type,
					'trid'                 => $trid,
					'language_code'        => $language_code,
					'source_language_code' => $source_language_code,
					'check_duplicates'     => false,
				)
			);

			Logger::add( sprintf( "  - set translation for post '%s' with trid '%s'.", $post_id, $trid ) );
			return true;
		}

		// Use standalone function when WPML is not loaded
		if ( function_exists( 'contentsync_wpml_set_element_language_details' ) ) {
			$result = contentsync_wpml_set_element_language_details(
				array(
					'element_id'           => $post_id,
					'element_type'         => $element_type,
					'trid'                 => $trid,
					'language_code'        => $language_code,
					'source_language_code' => $source_language_code,
					'check_duplicates'     => false,
				)
			);

			if ( $result ) {
				Logger::add( sprintf( "  - set translation for post '%s' with trid '%s'.", $post_id, $trid ) );
				return true;
			} else {
				Logger::add( sprintf( "  - failed to set translation for post '%s'.", $post_id ) );
				return false;
			}
		}

		Logger::add( '  - standalone WPML functions not available.' );
		return false;
	}

	/**
	 * Get the translation group ID (trid) for an element.
	 *
	 * Helper method that works with both native WPML and standalone functions.
	 *
	 * @param int    $element_id   The element ID (post ID for posts).
	 * @param string $element_type The element type (e.g., 'post_post', 'post_page').
	 * @return int|null The trid or null if not found.
	 */
	private function get_element_trid( $element_id, $element_type ) {
		// If WPML is loaded, use the native filter
		if ( $this->is_plugin_loaded() ) {
			return apply_filters( 'wpml_element_trid', null, $element_id, $element_type );
		}

		// Use standalone function
		if ( function_exists( 'contentsync_wpml_get_element_trid' ) ) {
			return contentsync_wpml_get_element_trid( $element_id, $element_type );
		}

		return null;
	}

	/**
	 * Get the source language code for a translation group.
	 *
	 * Helper method that works with both native WPML and standalone functions.
	 *
	 * @param int $trid The translation group ID.
	 * @return string|null The source language code or null if not found.
	 */
	private function get_source_language_by_trid( $trid ) {
		if ( empty( $trid ) ) {
			return null;
		}

		// If WPML is loaded, use the native approach
		if ( $this->is_plugin_loaded() && isset( $GLOBALS['sitepress'] ) ) {
			$translations = $GLOBALS['sitepress']->get_element_translations( $trid );
			if ( ! empty( $translations ) ) {
				foreach ( $translations as $translation ) {
					if ( empty( $translation->source_language_code ) ) {
						return $translation->language_code;
					}
				}
			}
			return null;
		}

		// Use standalone function
		if ( function_exists( 'contentsync_wpml_get_source_language_by_trid' ) ) {
			return contentsync_wpml_get_source_language_by_trid( $trid );
		}

		return null;
	}

	/**
	 * Get the next available translation group ID (trid).
	 *
	 * Helper method that works with both native WPML and standalone functions.
	 *
	 * @return int The next available trid.
	 */
	private function get_next_trid() {
		// Query directly - WPML doesn't have a direct API for this
		global $wpdb;
		$table_name = "{$wpdb->prefix}icl_translations";

		// Check if table exists
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) === $table_name ) {
			$max_trid = $wpdb->get_var( "SELECT MAX(trid) FROM {$table_name}" );
			return (int) $max_trid + 1;
		}

		// Use standalone function as fallback
		if ( function_exists( 'contentsync_wpml_get_next_trid' ) ) {
			return contentsync_wpml_get_next_trid();
		}

		return 1;
	}

	/**
	 * Prepare complete language data for a post.
	 *
	 * @param Prepared_Post|WP_Post|int $post Prepared_Post object, WP_Post object, or post ID.
	 * @param bool                      $include_translations Whether to include translation post IDs.
	 * @return array Language data structure.
	 */
	public function prepare_post_language_data( $post, $include_translations = false ) {
		$post = $this->validate_post( $post );

		$language_data = array(
			'code'     => null,
			'tool'     => $this->get_tool_name(),
			'post_ids' => array(),
			'args'     => array(),
		);

		if ( ! $post ) {
			return $language_data;
		}

		// Get language information
		$language_details = $this->get_post_language_info( $post );

		if ( $language_details && isset( $language_details['language_code'] ) ) {
			$language_data['code'] = $language_details['language_code'];
			$language_data['args'] = $language_details;
			Logger::add( "  - post has language '{$language_data['code']}'" );
		}

		// Get translations if requested
		if ( $include_translations ) {
			$translations = $this->get_post_translations( $post );

			// Remove current post from translations list
			if ( ! empty( $translations ) ) {
				foreach ( $translations as $lang => $post_id ) {
					if ( $post_id == $post->ID ) {
						unset( $translations[ $lang ] );
						break;
					}
				}
			}

			$language_data['post_ids'] = $translations;

			if ( ! empty( $language_data['post_ids'] ) ) {
				Logger::add( '  - translations of this post prepared: ' . implode( ', ', $language_data['post_ids'] ) );
			}
		}

		return $language_data;
	}

	/**
	 * Analyze whether a post translation should be imported.
	 *
	 * @param Prepared_Post|object $post Prepared_Post object with language property.
	 * @param array                $already_imported_posts Map of original post IDs to new post IDs.
	 * @return array Analysis result.
	 */
	public function analyze_translation_import( $post, $already_imported_posts = array() ) {
		// Default result structure
		$result = array(
			'should_import'            => true,
			'language_code'            => null,
			'language_switched'        => false,
			'supported_translations'   => array(),
			'unsupported_translations' => array(),
			'translation_ids'          => array(),
			'reuse_post_id'            => null,
			'reason'                   => 'no_language',
		);

		// Check if post has language data
		if (
			! isset( $post->language )
			|| (
				( is_array( $post->language ) && ( ! isset( $post->language['code'] ) || empty( $post->language['code'] ) ) )
				&& ( is_object( $post->language ) && ( ! isset( $post->language->code ) || empty( $post->language->code ) ) )
			)
		) {
			// No language data - import normally
			$result['should_import'] = true;
			$result['reason']        = 'no_language';
			return $result;
		}

		// Extract language data
		$language_data        = (array) $post->language;
		$post_language_code   = isset( $language_data['code'] ) ? $language_data['code'] : '';
		$translation_post_ids = isset( $language_data['post_ids'] ) ? (array) $language_data['post_ids'] : array();

		// Get language compatibility info
		$languages_on_this_site   = $this->get_language_codes();
		$languages_of_this_post   = ! empty( $translation_post_ids ) ? array_keys( $translation_post_ids ) : array();
		$supported_translations   = array_intersect( $languages_on_this_site, $languages_of_this_post );
		$unsupported_translations = array_diff( $languages_of_this_post, $languages_on_this_site );

		// Populate result with language info
		$result['language_code']            = $post_language_code;
		$result['translation_ids']          = $translation_post_ids;
		$result['supported_translations']   = $supported_translations;
		$result['unsupported_translations'] = $unsupported_translations;

		// Decision 1: Language supported - import this post
		if ( $this->is_language_supported( $post_language_code ) ) {
			$result['should_import'] = true;
			$result['reason']        = 'supported_language';
			return $result;
		}

		// Decision 2: Language not supported, but other supported translations exist - skip this one
		if ( count( $supported_translations ) > 0 ) {
			Logger::add( '  - There is at least 1 supported language for this post: ' . implode( ', ', $supported_translations ) );
			$result['should_import'] = false;
			$result['reason']        = 'skip_better_translation';
			return $result;
		}

		// Decision 3: No supported translations exist, check if another translation was already imported
		if ( ! empty( $translation_post_ids ) ) {
			foreach ( (array) $translation_post_ids as $lang => $translated_post_id ) {
				if ( isset( $already_imported_posts[ $translated_post_id ] ) ) {
					// Found an already-imported translation - reuse it instead of importing this one
					$result['should_import'] = false;
					$result['reuse_post_id'] = $already_imported_posts[ $translated_post_id ];
					$result['reason']        = 'reuse_imported';
					return $result;
				}
			}
		}

		// Decision 4: No supported translations and none imported yet - import this one as fallback
		$result['should_import'] = true;
		$result['reason']        = 'import_fallback';
		return $result;
	}

	/**
	 * Switch to a specific language context.
	 *
	 * With standalone functions, we no longer need to switch language context
	 * as we can directly set the language in the database.
	 *
	 * @param Prepared_Post|object $post Prepared_Post object with language property.
	 * @return mixed null if no language info, false if language not supported, true if switched.
	 */
	public function switch_to_language_context( $post ) {

		global $sitepress;

		$language  = isset( $post->language ) && ! empty( $post->language ) ? (array) $post->language : array();
		$post_lang = isset( $language['code'] ) ? $language['code'] : null;

		if ( empty( $post_lang ) ) {
			return null;
		}

		/**
		 * The $sitepress object is not initialized when we're trying to call
		 * this function from a different blog without WPML, e.g. when the root post
		 * is updated on a single-language site.
		 */
		if ( ! $sitepress || ! is_object( $sitepress ) || ! method_exists( $sitepress, 'switch_lang' ) ) {
			Logger::add( sprintf( "  - the global \$sitepress object is not initialized, so we could not switch to the language '%s'.", $post_lang ) );
			return false;
		}

		// Check whether the language is supported on the current site
		if ( ! $this->is_language_supported( $post_lang ) ) {
			Logger::add( "  - the language '$post_lang' is not part of the supported languages (" . implode( ', ', $this->get_language_codes() ) . ')' );
			return false;
		}

		// Check if we've already switched to this language
		static $current_language_code = null;
		if ( $current_language_code && $current_language_code === $post_lang ) {
			return true;
		}

		$sitepress->switch_lang( $post_lang );
		$current_language_code = $post_lang;
		Logger::add( sprintf( "  - switched the language to '%s'.", $post_lang ) );
		return true;
	}
}
