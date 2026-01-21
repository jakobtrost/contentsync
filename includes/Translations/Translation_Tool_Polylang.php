<?php
/**
 * Polylang Translation Tool
 *
 * Handles all Polylang-specific translation operations.
 * Uses database-based access via taxonomies to avoid loading the plugin in multisite contexts.
 */

namespace Contentsync\Translations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Translation_Tool_Polylang extends Translation_Tool_Base {

	/**
	 * Store removed Polylang hooks to restore them later.
	 *
	 * @var array
	 */
	private static $removed_hooks = array();

	/**
	 * Get the tool name identifier.
	 *
	 * @return string
	 */
	public function get_tool_name() {
		return 'polylang';
	}

	/**
	 * Initialize Polylang environment without loading the plugin.
	 *
	 * Registers the language taxonomy and standalone dummy functions that
	 * provide database-based access to translation data.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function init_environment() {

		// If Polylang is already loaded, no initialization needed
		if ( function_exists( 'PLL' ) || function_exists( 'pll_get_post_language' ) ) {
			Logger::add( '  - Polylang is already loaded.' );
			return true;
		}

		Logger::add( '  - Polylang is not loaded, initializing environment.' );

		// Register language taxonomies for database queries
		$this->register_taxonomies();

		// Register standalone functions for data access
		$this->register_standalone_functions();

		return true;
	}

	/**
	 * Get language information for a post.
	 *
	 * @param Prepared_Post|WP_Post|int $post Prepared_Post object, WP_Post object, or post ID.
	 * @return array|null Language info array or null.
	 */
	public function get_post_language_info( $post ) {
		$post = $this->validate_post( $post );
		if ( ! $post ) {
			return null;
		}

		// Initialize environment to ensure functions are available
		$this->init_environment();

		$language_code = pll_get_post_language( $post->ID );

		if ( ! $language_code ) {
			return null;
		}

		return array(
			'language_code' => $language_code,
		);
	}

	/**
	 * Get all active language codes.
	 *
	 * @return array Language codes.
	 */
	public function get_language_codes() {
		// Initialize environment to ensure functions are available
		$this->init_environment();

		$codes = pll_languages_list();

		if ( empty( $codes ) ) {
			return array( $this->get_wp_default_language() );
		}

		return $codes;
	}

	/**
	 * Get post translations.
	 *
	 * @param Prepared_Post|WP_Post|int $post Prepared_Post object, WP_Post object, or post ID.
	 * @return array Translation array.
	 */
	public function get_post_translations( $post ) {
		$post = $this->validate_post( $post );
		if ( ! $post ) {
			return array();
		}

		// Initialize environment to ensure functions are available
		$this->init_environment();

		return pll_get_post_translations( $post->ID );
	}

	/**
	 * Get Polylang default language for this site.
	 *
	 * Retrieves the default language from Polylang settings. This is used when
	 * a post's language is not supported on the target site - we assign the
	 * default language to ensure the post is visible in the admin area.
	 *
	 * @return string Default language code.
	 */
	public function get_polylang_default_language() {
		// Try native Polylang function first (if Polylang is loaded)
		if ( function_exists( 'pll_default_language' ) ) {
			$default = pll_default_language();
			if ( $default ) {
				return $default;
			}
		}

		// Fall back to reading from Polylang options
		$polylang_options = get_option( 'polylang', array() );
		if ( isset( $polylang_options['default_lang'] ) && ! empty( $polylang_options['default_lang'] ) ) {
			return $polylang_options['default_lang'];
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
	 * Set the language for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $language_code Language code.
	 * @return bool True on success, false on failure.
	 */
	public function set_post_language( $post_id, $language_code ) {
		// Ensure environment is initialized
		$this->init_environment();

		// If language code is not supported on this site, use the default language instead
		// This ensures the post is visible in the admin area
		if ( ! $this->is_language_supported( $language_code ) ) {
			$default_language = $this->get_polylang_default_language();
			Logger::add( sprintf( "  - the language '%s' is not supported, using default language '%s' instead.", $language_code, $default_language ) );
			$language_code = $default_language;
		}

		if ( function_exists( 'pll_set_post_language' ) ) {
			$result = pll_set_post_language( $post_id, $language_code );

			if ( $result ) {
				Logger::add( sprintf( "  - set language of post '%s' to '%s'.", $post_id, $language_code ) );
			} else {
				Logger::add( sprintf( "  - failed to set language of post '%s' to '%s'.", $post_id, $language_code ) );
			}

			return $result;
		}

		return false;
	}

	/**
	 * Set translation relationships for a post during import.
	 *
	 * @param int    $post_id Current post ID.
	 * @param string $language_code Language code.
	 * @param array  $original_post_ids Original post IDs from export (lang => id).
	 * @param array  $language_args Polylang-specific arguments.
	 * @param array  $imported_post_map Map of original IDs to new IDs.
	 * @return bool
	 */
	public function set_translations_from_import( $post_id, $language_code, $original_post_ids, $language_args, $imported_post_map ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}

		// Ensure translation environment is initialized
		$this->init_environment();

		// Check if all translations are imported
		// Map original post IDs to current site IDs
		$translation_map = array();
		$check           = 0;

		foreach ( $original_post_ids as $lang => $original_id ) {
			// Get the new ID on this site
			$current_id = isset( $imported_post_map[ $original_id ] ) ? $imported_post_map[ $original_id ] : null;

			if ( ! empty( $current_id ) ) {
				$translation_map[ $lang ] = $current_id;
				++$check;
			}
		}

		// Make sure this post is in the post_ids array
		// If source is WPML, only other languages are set
		$translation_map[ $language_code ] = $post->ID;

		// // Only proceed if all translations have been imported
		// if ( count( $original_post_ids ) != $check ) {
		// Logger::add( sprintf( "  - Not all translations imported yet (%d of %d). Skipping translation setup.", $check, count( $original_post_ids ) ) );
		// return false;
		// }

		// Make sure the language term is set for this post
		$term = term_exists( $language_code, 'language' );
		if ( $term ) {
			wp_set_post_terms( $post_id, array( (int) $term['term_id'] ), 'language', false );
			Logger::add( sprintf( "  - Set language term '%s' for post %d", $language_code, $post_id ) );
		} else {
			Logger::add( sprintf( "  - Language term '%s' does not exist", $language_code ) );
		}

		// Set the translations using Polylang
		if ( function_exists( 'pll_save_post_translations' ) ) {
			$result = pll_save_post_translations( $translation_map );

			if ( ! empty( $result ) ) {
				Logger::add( "\r\n" . 'Set translations:', $translation_map );
				return true;
			} else {
				Logger::add( "\r\n" . 'Failed to save translations' );
				return false;
			}
		}

		Logger::add( "\r\n" . 'pll_save_post_translations function not available' );
		return false;
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
	 * @param Prepared_Post|object $post Prepared_Post object with language property.
	 * @return mixed null if no language info, false if language not supported, true if switched.
	 */
	public function switch_to_language_context( $post ) {

		// we actually do not need to switch to the language context, because we are using the standalone functions.
		return true;
	}

	// =====================================================================
	// Polylang-Specific Private Methods
	// =====================================================================

	/**
	 * Register Polylang language taxonomies.
	 */
	private function register_taxonomies() {
		// Get Polylang options from database
		$polylang_options     = get_option( 'polylang', array() );
		$translated_posttypes = array_values(
			wp_parse_args(
				isset( $polylang_options['post_types'] ) ? $polylang_options['post_types'] : array(),
				array( 'post', 'page', 'wp_block' ) // Default Polylang post types
			)
		);

		// Register 'language' taxonomy for each translated post type
		foreach ( $translated_posttypes as $posttype ) {
			register_taxonomy( 'language', $posttype );
		}

		// Register 'post_translations' taxonomy (used for translation groups)
		if ( ! taxonomy_exists( 'post_translations' ) ) {
			register_taxonomy( 'post_translations', $translated_posttypes );
		}
	}

	/**
	 * Register standalone Polylang functions.
	 */
	private function register_standalone_functions() {
		/**
		 * Load the functions from separate file to load them without Namespacing.
		 */
		require_once __DIR__ . '/functions/polyfill-functions-polylang.php';
	}

	// =====================================================================
	// Hook Management Methods for Multisite Compatibility
	// =====================================================================

	/**
	 * Check if Polylang plugin is loaded in memory.
	 *
	 * This checks if the Polylang plugin code is loaded, which can be different
	 * from whether it's active/configured for the current blog in multisite.
	 *
	 * @return bool True if Polylang is loaded, false otherwise.
	 */
	public function is_plugin_loaded() {
		return function_exists( 'pll_current_language' ) || isset( $GLOBALS['polylang'] );
	}

	/**
	 * Temporarily disable Polylang sync hooks if it's loaded but not configured.
	 *
	 * This prevents fatal errors when Polylang is loaded globally (in a multisite)
	 * but not configured for the current blog we switched to.
	 *
	 * Also adds a filter to clean up Polylang taxonomies that might still be
	 * registered after a blog switch.
	 *
	 * @return bool True if hooks were removed, false if nothing was done.
	 */
	public function unload_hooks() {
		global $polylang;

		if ( ! isset( $polylang ) || ! $polylang ) {
			return false;
		}

		// Store the hooks we're removing so we can restore them later
		self::$removed_hooks = array();

		// Logger::add( 'Unloading Polylang hooks for unconfigured blog' );

		// Add taxonomy cleanup filter to remove Polylang taxonomies from exports
		add_filter( 'synced_post_export_taxonomies_before_prepare', array( $this, 'cleanup_polylang_taxonomies' ) );

		// Get the CRUD objects
		$crud_posts = isset( $polylang->posts ) ? $polylang->posts : null;
		$crud_terms = isset( $polylang->terms ) ? $polylang->terms : null;

		// Get the sync object if it exists
		$sync = isset( $polylang->sync ) ? $polylang->sync : null;

		// Also get the sub-sync objects
		$sync_tax        = isset( $sync->taxonomies ) ? $sync->taxonomies : null;
		$sync_post_metas = isset( $sync->post_metas ) ? $sync->post_metas : null;
		$sync_term_metas = isset( $sync->term_metas ) ? $sync->term_metas : null;

		// Remove CRUD hooks that trigger term translation and sync
		if ( $crud_posts ) {
			$this->remove_hook_if_exists( 'save_post', array( $crud_posts, 'save_post' ), 2 );
			$this->remove_hook_if_exists( 'set_object_terms', array( $crud_posts, 'set_object_terms' ), 4 );
		}

		if ( $crud_terms ) {
			$this->remove_hook_if_exists( 'created_term', array( $crud_terms, 'save_term' ), 3 );
			$this->remove_hook_if_exists( 'edited_term', array( $crud_terms, 'save_term' ), 3 );
		}

		if ( ! $sync ) {
			return ! empty( self::$removed_hooks );
		}

		// Remove sync hooks
		$this->remove_hook_if_exists( 'pll_save_post', array( $sync, 'pll_save_post' ), 3 );
		$this->remove_hook_if_exists( 'pre_update_option_sticky_posts', array( $sync, 'sync_sticky_posts' ), 2 );
		$this->remove_hook_if_exists( 'created_term', array( $sync, 'sync_term_parent' ), 3 );
		$this->remove_hook_if_exists( 'edited_term', array( $sync, 'sync_term_parent' ), 3 );
		$this->remove_hook_if_exists( 'wp_insert_post_parent', array( $sync, 'can_sync_post_parent' ), 3 );
		$this->remove_hook_if_exists( 'wp_insert_post_data', array( $sync, 'can_sync_post_data' ), 2 );
		$this->remove_hook_if_exists( 'wp_insert_post_parent', array( $sync, 'wp_insert_post_parent' ), 3 );
		$this->remove_hook_if_exists( 'wp_insert_post_data', array( $sync, 'wp_insert_post_data' ), 1 );

		// Remove taxonomy sync hooks
		if ( $sync_tax ) {
			$this->remove_hook_if_exists( 'set_object_terms', array( $sync_tax, 'set_object_terms' ), 5 );
			$this->remove_hook_if_exists( 'pll_save_term', array( $sync_tax, 'create_term' ), 3 );
			$this->remove_hook_if_exists( 'pre_delete_term', array( $sync_tax, 'pre_delete_term' ), 1 );
			$this->remove_hook_if_exists( 'delete_term', array( $sync_tax, 'delete_term' ), 1 );
		}

		// Remove post meta sync hooks
		if ( $sync_post_metas ) {
			$this->remove_hook_if_exists( 'add_post_metadata', array( $sync_post_metas, 'can_synchronize_metadata' ), 3 );
			$this->remove_hook_if_exists( 'update_post_metadata', array( $sync_post_metas, 'can_synchronize_metadata' ), 3 );
			$this->remove_hook_if_exists( 'delete_post_metadata', array( $sync_post_metas, 'can_synchronize_metadata' ), 3 );
			$this->remove_hook_if_exists( 'pll_save_post', array( $sync_post_metas, 'save_object' ), 3 );
		}

		// Remove term meta sync hooks
		if ( $sync_term_metas ) {
			$this->remove_hook_if_exists( 'add_term_metadata', array( $sync_term_metas, 'can_synchronize_metadata' ), 3 );
			$this->remove_hook_if_exists( 'update_term_metadata', array( $sync_term_metas, 'can_synchronize_metadata' ), 3 );
			$this->remove_hook_if_exists( 'delete_term_metadata', array( $sync_term_metas, 'can_synchronize_metadata' ), 3 );
			$this->remove_hook_if_exists( 'pll_save_term', array( $sync_term_metas, 'save_object' ), 3 );
		}

		$removed_count = count( self::$removed_hooks );
		// if ( $removed_count > 0 ) {
		// Logger::add( sprintf( 'Removed %d Polylang hooks', $removed_count ) );
		// }

		return $removed_count > 0;
	}

	/**
	 * Restore Polylang sync hooks that were temporarily removed.
	 *
	 * Also removes the taxonomy cleanup filter.
	 *
	 * @return bool True if hooks were restored, false if nothing was done.
	 */
	public function reload_hooks() {
		// Logger::add( 'Reloading Polylang hooks' );

		if ( ! empty( self::$removed_hooks ) ) {
			foreach ( self::$removed_hooks as $hook_data ) {
				$hook     = $hook_data['hook'];
				$callback = $hook_data['callback'];
				$priority = $hook_data['priority'];
				$args     = $hook_data['args'];

				// Restore the hook (actions and filters use add_filter internally)
				add_filter( $hook, $callback, $priority, $args );
			}

			$restored_count = count( self::$removed_hooks );
			// Logger::add( sprintf( 'Restored %d Polylang hooks', $restored_count ) );
		}

		// Remove the taxonomy cleanup filter
		remove_filter( 'synced_post_export_taxonomies_before_prepare', array( $this, 'cleanup_polylang_taxonomies' ) );

		// Clear the stored hooks
		self::$removed_hooks = array();

		return true;
	}

	/**
	 * Helper method to remove a hook if it exists and store it for later restoration.
	 *
	 * @param string   $hook Hook name.
	 * @param callable $callback Callback function.
	 * @param int      $args Number of arguments.
	 * @return bool True if hook was removed, false otherwise.
	 */
	private function remove_hook_if_exists( $hook, $callback, $args = 1 ) {
		$priority = has_filter( $hook, $callback );

		if ( $priority === false ) {
			return false;
		}

		remove_filter( $hook, $callback, $priority );

		self::$removed_hooks[] = array(
			'hook'     => $hook,
			'callback' => $callback,
			'priority' => $priority,
			'args'     => $args,
		);

		return true;
	}

	/**
	 * Cleanup Polylang taxonomies before they are prepared for export.
	 *
	 * We need to remove the 'language' & 'post_translations' taxonomies, because in
	 * multisite environments, they might still be registered after a blog switch
	 * even when Polylang is not configured for the current blog.
	 *
	 * This method is used as a filter callback when Polylang is loaded but not active.
	 *
	 * @param array $taxonomies The taxonomies to be prepared.
	 * @return array The filtered taxonomies.
	 */
	public function cleanup_polylang_taxonomies( $taxonomies ) {
		Logger::add( 'Cleaning up Polylang taxonomies from export' );

		// Remove 'language' & 'post_translations' taxonomies
		$taxonomies = array_diff( $taxonomies, array( 'language', 'post_translations' ) );

		return $taxonomies;
	}
}

/**
 * Skip Polylang taxonomies during import.
 *
 * @filter contentsync_import_skip_taxonomy
 *
 * @param bool          $skip            Whether to skip the taxonomy.
 * @param string        $taxonomy      The taxonomy to skip.
 * @param array         $terms          The terms to skip.
 * @param Prepared_Post $post  The Prepared_Post object.
 *
 * @return bool Whether to skip the taxonomy.
 */
function skip_post_translations_taxonomies_during_post_import( $skip, $taxonomy, $terms, $post ) {

	// always skip 'post_translations' taxonomy
	if ( $taxonomy === 'post_translations' ) {
		Logger::add( "  - taxonomy '{$taxonomy}' is skipped." );
		return true;
	}

	return $skip;
}

// add filter to skip polylang taxonomies during import
add_filter( 'contentsync_import_skip_taxonomy', __NAMESPACE__ . '\skip_post_translations_taxonomies_during_post_import', 10, 4 );

/**
 * Skip language terms before inserting them during import.
 *
 * @filter contentsync_import_terms_before_insert
 *
 * @param array         $terms          The terms to be inserted.
 * @param string        $taxonomy      The taxonomy.
 * @param Prepared_Post $post  The Prepared_Post object.
 *
 * @return array The filtered terms.
 */
function skip_unsupported_language_terms_during_post_import( $terms, $taxonomy, $post ) {

	if ( $taxonomy !== 'language' ) {
		return $terms;
	}

	if ( ! function_exists( 'pll_languages_list' ) ) {
		return $terms;
	}

	// get the list of language slugs
	$language_slugs = pll_languages_list();

	// filter the terms to only include the language slugs
	$terms = array_filter(
		$terms,
		function ( $term ) use ( $language_slugs, $taxonomy ) {
			if ( ! is_array( $term ) ) {
				$term = (array) $term;
			}
			if ( ! isset( $term['slug'] ) ) {
				return false;
			}
			$skip = in_array( $term['slug'], $language_slugs );
			Logger::add( "  - term '{$term['name']}' of taxonomy '{$taxonomy}' is skipped: " . ( $skip ? 'true' : 'false' ) );
			return $skip;
		}
	);

	return $terms;
}

add_filter( 'contentsync_import_terms_before_insert', __NAMESPACE__ . '\skip_unsupported_language_terms_during_post_import', 10, 3 );
