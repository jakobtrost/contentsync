<?php
/**
 * Translation Tool Base Class
 *
 * Abstract base class for translation tool implementations.
 * Provides common utilities and defines the interface that all translation tools must implement.
 *
 * @since 2.19.0
 */

namespace Contentsync\Translation_Tools;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Translation_Tool_Base {

	/**
	 * Get the tool name identifier.
	 *
	 * @since 2.19.0
	 * @return string Tool name (e.g., 'wpml', 'polylang')
	 */
	abstract public function get_tool_name();

	/**
	 * Check if the plugin is loaded in memory.
	 * 
	 * This checks if the plugin code is loaded, which can be different from
	 * whether it's active/configured for the current blog in multisite.
	 * 
	 * @since 2.19.0
	 * @return bool True if the plugin is loaded, false otherwise.
	 */
	abstract public function is_plugin_loaded();

	/**
	 * Initialize the translation environment.
	 *
	 * Ensures the translation environment is ready for use. This is especially
	 * important in multisite contexts after switch_to_blog().
	 *
	 * @since 2.19.0
	 * @return bool True if initialized successfully, false otherwise.
	 */
	abstract public function init_environment();

	/**
	 * Get language information for a post.
	 *
	 * @since 2.19.0
	 * @param \Contentsync\Prepared_Post|\WP_Post|int $post Prepared_Post object, WP_Post object, or post ID.
	 * @return array|null Array with language info, or null if not found.
	 */
	abstract public function get_post_language_info( $post );

	/**
	 * Get all active language codes.
	 *
	 * @since 2.19.0
	 * @return array Array of language codes (e.g., ['en', 'de', 'fr']).
	 */
	abstract public function get_language_codes();

	/**
	 * Get post translations.
	 *
	 * @since 2.19.0
	 * @param \Contentsync\Prepared_Post|\WP_Post|int $post Prepared_Post object, WP_Post object, or post ID.
	 * @return array Associative array (e.g., ['en' => 123, 'de' => 456]).
	 */
	abstract public function get_post_translations( $post );

	/**
	 * Set the language for a post.
	 *
	 * @since 2.19.0
	 * @param int $post_id Post ID.
	 * @param string $language_code Language code.
	 * @return bool True on success, false on failure.
	 */
	abstract public function set_post_language( $post_id, $language_code );

	/**
	 * Set translation relationships for a post during import.
	 *
	 * @since 2.19.0
	 * @param int $post_id Current post ID on this site.
	 * @param string $language_code Language code for this post.
	 * @param array $original_post_ids Original post IDs from export (lang => id).
	 * @param array $language_args Tool-specific arguments.
	 * @param array $imported_post_map Map of original IDs to new IDs.
	 * @return bool True on success, false on failure.
	 */
	abstract public function set_translations_from_import( $post_id, $language_code, $original_post_ids, $language_args, $imported_post_map );

	/**
	 * Prepare complete language data for a post.
	 *
	 * @since 2.19.0
	 * @param \Contentsync\Prepared_Post|\WP_Post|int $post Prepared_Post object, WP_Post object, or post ID.
	 * @param bool $include_translations Whether to include translation post IDs.
	 * @return array Language data structure.
	 */
	abstract public function prepare_post_language_data( $post, $include_translations = false );

	/**
	 * Analyze whether a post translation should be imported.
	 *
	 * @since 2.19.0
	 * @param \Contentsync\Prepared_Post|object $post Prepared_Post object with language property.
	 * @param array $already_imported_posts Map of original post IDs to new post IDs.
	 * @return array Analysis result.
	 */
	abstract public function analyze_translation_import( $post, $already_imported_posts = array() );

	/**
	 * Switch to a specific language context.
	 *
	 * @since 2.19.0
	 * @param \Contentsync\Prepared_Post|object $post Prepared_Post object with language property.
	 * @return mixed null if no language info, false if language not supported, true if switched.
	 */
	abstract public function switch_to_language_context( $post );

	// =====================================================================
	// Shared Utility Methods
	// =====================================================================

	/**
	 * Validate and normalize post input.
	 *
	 * Accepts either a post ID or post object and ensures we have a valid WP_Post object.
	 *
	 * @since 2.19.0
	 * @param \WP_Post|int $post Post object or ID.
	 * @return \WP_Post|null Post object if valid, null otherwise.
	 */
	protected function validate_post( $post ) {
		if ( is_numeric( $post ) ) {
			$post = get_post( $post );
		}

		if ( ! $post || ! is_object( $post ) || ! isset( $post->ID ) ) {
			return null;
		}

		return $post;
	}

	/**
	 * Log a message.
	 *
	 * Convenience wrapper for the synced_post_export_log action.
	 *
	 * @since 2.19.0
	 * @param string $message Message to log.
	 * @param mixed $data Optional data to log.
	 */
	protected function log( $message, $data = 'do_not_log' ) {
		do_action( 'synced_post_export_log', $message, $data );
	}

	/**
	 * Get WordPress default language code.
	 *
	 * Extracts the 2-character language code from WordPress locale.
	 *
	 * @since 2.19.0
	 * @return string Language code (e.g., 'en', 'de').
	 */
	protected function get_wp_default_language() {
		$locale = get_locale();
		return substr( $locale, 0, 2 );
	}

	/**
	 * Check if a language is supported on this site.
	 *
	 * @since 2.19.0
	 * @param string $language_code Language code to check.
	 * @return bool True if supported, false otherwise.
	 */
	protected function is_language_supported( $language_code ) {
		$supported_languages = $this->get_language_codes();
		return in_array( $language_code, $supported_languages );
	}

	// =====================================================================
	// Optional Hook Management Methods (for Multisite Compatibility)
	// =====================================================================

	/**
	 * Unload translation tool hooks if the tool is loaded but not configured.
	 * 
	 * This is optional - tools can override this if they need to handle
	 * scenarios where the plugin is loaded but not active on the current blog.
	 * 
	 * @since 2.19.0
	 * @return bool True if hooks were unloaded, false otherwise.
	 */
	public function unload_hooks() {
		// Default: do nothing
		return false;
	}

	/**
	 * Reload translation tool hooks that were previously unloaded.
	 * 
	 * This is optional - tools can override this if they implement unload_hooks().
	 * 
	 * @since 2.19.0
	 * @return bool True if hooks were reloaded, false otherwise.
	 */
	public function reload_hooks() {
		// Default: do nothing
		return false;
	}
}

