<?php
/**
 * Translation Manager
 *
 * A centralized, extensible translation management system that handles
 * translation tool detection, initialization, and data retrieval across
 * different translation plugins (WPML, Polylang, and potentially others).
 *
 * This class acts as a facade/wrapper that delegates all operations to
 * tool-specific implementations. It provides a unified interface for working
 * with translation plugins in WordPress multisite environments.
 *
 * Architecture:
 * - Translation_Manager: Main facade for convenience and backward compatibility
 * - Translation_Tool_Factory: Detects and creates tool-specific instances
 * - Translation_Tool_Base: Abstract base class defining the interface
 * - Translation_Tool_Polylang: Polylang-specific implementation
 * - Translation_Tool_WPML: WPML-specific implementation
 *
 * @since 2.19.0
 */

namespace Contentsync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load translation tool classes
require_once __DIR__ . '/translation-tools/class-translation-tool-base.php';
require_once __DIR__ . '/translation-tools/class-translation-tool-factory.php';
require_once __DIR__ . '/translation-tools/class-translation-tool-polylang.php';
require_once __DIR__ . '/translation-tools/class-translation-tool-wpml.php';

class Translation_Manager {

	/**
	 * Get the active translation tool name.
	 *
	 * @since 2.19.0
	 * @return string|null 'wpml', 'polylang', or null if none detected.
	 */
	public static function get_translation_tool() {
		return \Contentsync\Translation_Tools\Translation_Tool_Factory::get_tool_name();
	}

	/**
	 * Check if a translation tool is active.
	 *
	 * @since 2.19.0
	 * @return bool
	 */
	public static function is_translation_tool_active() {
		return ! empty( self::get_translation_tool() );
	}

	/**
	 * Reset the translation tool cache.
	 *
	 * This should be called when switching between blogs in a multisite environment
	 * to ensure the correct translation tool is detected for each blog.
	 *
	 * @since 2.19.0
	 * @return void
	 */
	public static function reset_translation_tool() {
		// Reload any hooks that were unloaded
		self::reload_translation_tool_hooks();

		\Contentsync\Translation_Tools\Translation_Tool_Factory::reset();
	}

	/**
	 * Unload translation tool hooks if the tool is loaded but shouldn't be active.
	 * 
	 * This is useful in multisite environments when a translation plugin is
	 * network-activated but not configured for the current blog.
	 * 
	 * @since 2.19.0
	 * @return bool True if hooks were unloaded, false otherwise.
	 */
	public static function unload_translation_tool_hooks() {
		return \Contentsync\Translation_Tools\Translation_Tool_Factory::unload_inactive_tool_hooks();
	}

	/**
	 * Reload translation tool hooks if they were previously unloaded.
	 * 
	 * This should be called after restoring to a blog where the translation
	 * tool should be active.
	 * 
	 * @since 2.19.0
	 * @return bool True if hooks were reloaded, false otherwise.
	 */
	public static function reload_translation_tool_hooks() {
		return \Contentsync\Translation_Tools\Translation_Tool_Factory::reload_active_tool_hooks();
	}

	/**
	 * Initialize translation environment.
	 *
	 * Ensures the translation environment is ready for use. This is especially
	 * important in multisite contexts after switch_to_blog() where translation
	 * plugins might not be loaded or still be loaded while not being active on
	 * the current blog.
	 *
	 * @since 2.19.0
	 * @return bool True if initialized successfully, false otherwise.
	 */
	public static function init_translation_environment() {
		// First, unload any hooks from translation plugins that shouldn't be active
		// This also adds tool-specific cleanup filters (e.g., taxonomy cleanup for Polylang)
		self::unload_translation_tool_hooks();

		$tool = self::get_tool_instance();

		if ( ! $tool ) {
			return false;
		}

		return $tool->init_environment();
	}

	/**
	 * Get language information for a post.
	 *
	 * Returns an associative array with language details that vary by translation tool:
	 * - Polylang: ['language_code' => 'en']
	 * - WPML: ['language_code' => 'en', 'element_id' => 123, 'trid' => 456, 'source_language_code' => null]
	 *
	 * @since 2.19.0
	 * @param \Contentsync\Prepared_Post|\WP_Post|int $post Prepared_Post object, WP_Post object, or post ID.
	 * @return array|null Language info array or null if not found.
	 */
	public static function get_post_language_info( $post ) {
		$tool = self::get_tool_instance();

		if ( ! $tool ) {
			return null;
		}

		return $tool->get_post_language_info( $post );
	}

	/**
	 * Get all active language codes on the current site.
	 *
	 * @since 2.19.0
	 * @return array Array of language codes (e.g., ['en', 'de', 'fr']).
	 */
	public static function get_language_codes() {
		$tool = self::get_tool_instance();

		if ( ! $tool ) {
			// Return WordPress default language as fallback
			$locale = get_locale();
			return array( substr( $locale, 0, 2 ) );
		}

		return $tool->get_language_codes();
	}

	/**
	 * Get all translations of a post.
	 *
	 * Returns an associative array mapping language codes to post IDs.
	 * Example: ['en' => 123, 'de' => 456, 'fr' => 789]
	 *
	 * @since 2.19.0
	 * @param \Contentsync\Prepared_Post|\WP_Post|int $post Prepared_Post object, WP_Post object, or post ID.
	 * @return array Associative array of translations (language_code => post_id).
	 */
	public static function get_post_translations( $post ) {
		$tool = self::get_tool_instance();

		if ( ! $tool ) {
			return array();
		}

		return $tool->get_post_translations( $post );
	}

	/**
	 * Set the language for a post.
	 *
	 * @since 2.19.0
	 * @param int $post_id Post ID.
	 * @param string $language_code Language code (e.g., 'en', 'de').
	 * @return bool True on success, false on failure.
	 */
	public static function set_post_language( $post_id, $language_code ) {
		$tool = self::get_tool_instance();

		if ( ! $tool ) {
			return false;
		}

		return $tool->set_post_language( $post_id, $language_code );
	}

	/**
	 * Prepare complete language data for a post export.
	 *
	 * This method is designed to be called from Prepared_Post::prepare_language().
	 * It gathers all language information needed for export, including the language
	 * code, tool name, and optionally translation post IDs.
	 *
	 * Returns an array with two keys:
	 * - 'language': The language data structure for export
	 * - 'log_message': Optional log message (or empty string)
	 *
	 * @since 2.19.0
	 * @param \Contentsync\Prepared_Post $post The Prepared_Post instance.
	 * @param bool $include_translations Whether to include translation post IDs.
	 * @return array Array with language data.
	 *     @property string code       The post's language code (eg. 'en')
	 *     @property string tool       The plugin used to setup the translation.
	 *     @property array  post_ids   All translated post IDs keyed by language code.
	 *     @property array  args       Additional arguments (depends on the tool)
	 */
	public static function prepare_post_language_data( $post, $include_translations = false ) {
		$tool = self::get_tool_instance();

		if ( ! $tool ) {
			return array(
				'code'     => self::get_wp_language(), // wp lang operates as fallback
				'tool'     => null,
				'post_ids' => array(),
				'args'     => array(),
			);
		}

		$language_data = $tool->prepare_post_language_data( $post, $include_translations );

		return $language_data;
	}

	/**
	 * Get language code of the current site (2 chars).
	 * 
	 * The logic in this function is a copy of parts of the wp core function
	 * get_locale() (wp-includes/locale.php). This is necessary because the
	 * core function uses the global variable $locale which leads to errors
	 * when calling it from a different blog after wp_switch_to_blog() is called.
	 * @see https://developer.wordpress.org/reference/functions/get_locale/
	 *
	 * @since 2.19.0
	 * @return string Language code (e.g., 'en', 'de').
	 */
	public static function get_wp_language() {
		$locale = null;

		// get_locale() --- begin copy ---
		if ( defined( 'WPLANG' ) ) {
			$locale = WPLANG;
		}
		if ( is_multisite() ) {
			if ( wp_installing() ) {
				$ms_locale = get_site_option( 'WPLANG' );
			} else {
				$ms_locale = get_option( 'WPLANG' );
				if ( false === $ms_locale ) {
					$ms_locale = get_site_option( 'WPLANG' );
				}
			}

			if ( false !== $ms_locale ) {
				$locale = $ms_locale;
			}
		} else {
			$db_locale = get_option( 'WPLANG' );
			if ( false !== $db_locale ) {
				$locale = $db_locale;
			}
		}
		if ( empty( $locale ) ) {
			$locale = 'en_US';
		}
		// get_locale() --- end copy ---

		return explode( '_', $locale, 2 )[0];
	}


	/**
	 * Analyze whether a post translation should be imported.
	 *
	 * This method encapsulates the complex decision logic for importing translations,
	 * determining whether a post should be imported, skipped, or replaced based on
	 * language support and existing translations.
	 *
	 * Returns an analysis array with these keys:
	 * - 'should_import': bool - Whether this post should be imported
	 * - 'language_code': string|null - The language code of this post
	 * - 'language_switched': bool - Whether we successfully switched to this language
	 * - 'supported_translations': array - Translation language codes that are supported on this site
	 * - 'unsupported_translations': array - Translation language codes that are NOT supported on this site
	 * - 'translation_ids': array - All translation post IDs from the export
	 * - 'reuse_post_id': int|null - If set, reuse this post ID instead of importing
	 * - 'reason': string - Explanation of the decision
	 *
	 * Possible reasons:
	 * - 'no_language': No language data on this post
	 * - 'language_switched': Language is supported and switched successfully
	 * - 'skip_better_translation': Another translation in a supported language exists
	 * - 'reuse_imported': Another translation was already imported, reuse it
	 * - 'import_fallback': No better option exists, import as fallback
	 *
	 * @since 2.19.0
	 * @param \Contentsync\Prepared_Post|object $post Prepared_Post object with language property.
	 * @param array $already_imported_posts Map of original post IDs to new post IDs.
	 * @return array Analysis result array.
	 */
	public static function analyze_translation_import( $post, $already_imported_posts = array() ) {
		$tool = self::get_tool_instance();

		// If no translation tool is active, use default analysis logic
		if ( ! $tool ) {
			return self::analyze_translation_import_without_tool( $post, $already_imported_posts );
		}

		return $tool->analyze_translation_import( $post, $already_imported_posts );
	}

	/**
	 * Analyze translation import when no translation tool is active.
	 *
	 * This method still analyzes the language data from the export to determine
	 * the best version to import, even though no translation plugin is active on
	 * the target site.
	 *
	 * @since 2.19.0
	 * @param \Contentsync\Prepared_Post|object $post Prepared_Post object with language property.
	 * @param array $already_imported_posts Map of original post IDs to new post IDs.
	 * @return array Analysis result.
	 */
	private static function analyze_translation_import_without_tool( $post, $already_imported_posts = array() ) {
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

		// Get WordPress default language (2-char code from locale)
		$wp_language = self::get_wp_language();

		// Populate result with language info
		$result['language_code']    = $post_language_code;
		$result['translation_ids']  = $translation_post_ids;

		// Since no translation tool is active, we can't "switch" languages
		// But we can prefer posts that match the WordPress default language
		$result['language_switched'] = false;

		// Decision 1: This post matches WordPress default language - import it
		if ( $post_language_code === $wp_language ) {
			$result['should_import'] = true;
			$result['reason']        = 'matches_wp_language';
			return $result;
		}

		// Decision 2: Check if another translation in the default language exists in the export
		if ( ! empty( $translation_post_ids ) && isset( $translation_post_ids[ $wp_language ] ) ) {
			// There's a translation in the default language - skip this one, use that instead
			$result['should_import']            = false;
			$result['supported_translations']   = array( $wp_language );
			$result['unsupported_translations'] = array_diff( array_keys( $translation_post_ids ), array( $wp_language ) );
			$result['reason']                   = 'skip_better_translation';
			return $result;
		}

		// Decision 3: Check if ANY translation was already imported (regardless of language)
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

		// Decision 4: No better option exists - import this one as fallback
		// (It's in a different language than the site default, but it's better than nothing)
		$result['should_import']            = true;
		$result['unsupported_translations'] = array_keys( $translation_post_ids );
		$result['reason']                   = 'import_fallback';
		return $result;
	}

	/**
	 * Set translation relationships for a post during import.
	 *
	 * This method handles the complete process of setting translations during import,
	 * including validation, tool detection, and delegation to the appropriate tool handler.
	 *
	 * @since 2.19.0
	 * @param int $post_id The newly imported post ID on this site.
	 * @param array|object $language_data Language data from the export.
	 * @param array $imported_post_map Map of original post IDs to new post IDs.
	 * @return bool True on success, false on failure.
	 */
	public static function set_translations_from_import( $post_id, $language_data, $imported_post_map ) {
		$language = (array) $language_data;
		$post_ids = isset( $language['post_ids'] ) ? (array) $language['post_ids'] : array();

		// Validate required data
		if (
			! $language ||
			! $post_ids ||
			! isset( $language['args'] ) ||
			! isset( $language['code'] ) ||
			! isset( $language['tool'] )
		) {
			return false;
		}

		$args          = (array) $language['args'];
		$code          = strval( $language['code'] );
		$export_tool   = strval( $language['tool'] );
		$current_tool  = self::get_translation_tool();

		do_action( 'synced_post_export_log', "\r\n" . 'Set translations for the post:', $language );

		$tool = self::get_tool_instance();

		if ( ! $tool ) {
			return false;
		}

		return $tool->set_translations_from_import( $post_id, $code, $post_ids, $args, $imported_post_map );
	}

	/**
	 * Switch to a specific language context.
	 *
	 * Attempts to switch the translation plugin's current language context to
	 * match the post's language. This is useful during import operations.
	 *
	 * @since 2.19.0
	 * @param \Contentsync\Prepared_Post|object $post Prepared_Post object with language property.
	 * @return mixed null if no language info, false if language not supported, true if switched.
	 */
	public static function switch_to_language_context( $post ) {
		$tool = self::get_tool_instance();

		if ( ! $tool ) {
			return null;
		}

		return $tool->switch_to_language_context( $post );
	}

	// =====================================================================
	// Private Helper Methods
	// =====================================================================

	/**
	 * Get the active translation tool instance.
	 *
	 * @since 2.19.0
	 * @return \Contentsync\Translation_Tools\Translation_Tool_Base|null
	 */
	private static function get_tool_instance() {
		return \Contentsync\Translation_Tools\Translation_Tool_Factory::get_instance();
	}
}
