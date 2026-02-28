<?php

/**
 * Enqueue Service
 *
 * Centralizes admin asset enqueuing for scripts (plain and build) and styles.
 * Paths are relative to CONTENTSYNC_PLUGIN_URL . '/includes/Admin/'. Handles
 * are auto-prefixed (contentSync- for scripts, contentsync- for styles).
 */

namespace Contentsync\Admin\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Class Enqueue_Service
 */
class Enqueue_Service {

	/**
	 * Enqueue a plain admin script.
	 *
	 * @param string $name                 Short name (e.g. makeRoot).
	 * @param string $admin_relative_path  Path under includes/Admin/.
	 * @param array  $args                 Optional. Keys: external, internal, in_footer, version, localization, inline.
	 *     @param array  $args['external']: array of external script handles (e.g. jquery, wp-api-fetch).
	 *     @param array  $args['internal']: array of internal script short names (e.g. tools, Modal).
	 *     @param array  $args['localization']: array with 'var' and 'values' keys for wp_localize_script.
	 *     @param array  $args['inline']: array with 'content' and 'position' for wp_add_inline_script.
	 *     @param bool   $args['in_footer']: whether to enqueue the script in the footer (default true).
	 *     @param string $args['version']: version of the script (default CONTENTSYNC_VERSION).
	 * @return bool True if registered and enqueued.
	 */
	public static function enqueue_admin_script( $name, $admin_relative_path, $args = array() ) {
		$handle = self::script_handle( $name );
		$base   = self::get_admin_base_url();
		$url    = $base . ltrim( str_replace( '\\', '/', $admin_relative_path ), '/' );

		$deps      = self::resolve_script_dependencies( $args );
		$version   = isset( $args['version'] ) ? $args['version'] : ( defined( 'CONTENTSYNC_VERSION' ) ? CONTENTSYNC_VERSION : '' );
		$in_footer = isset( $args['in_footer'] ) ? $args['in_footer'] : true;

		wp_register_script( $handle, $url, $deps, $version, $in_footer );
		wp_enqueue_script( $handle );

		self::maybe_localize_script( $handle, $args );
		self::maybe_add_inline_script( $handle, $args );

		return true;
	}

	/**
	 * Enqueue a build script (source path resolved to build path).
	 *
	 * @param string $name                 Short name (e.g. block_editor_tools).
	 * @param string $admin_relative_path  Path under includes/Admin/ (e.g. Views/Post_Sync/assets/js/src/file.jsx).
	 * @param array  $args                 Optional. Keys: external, internal, in_footer, version, localization, inline.
	 *     @param array  $args['external']: array of external script handles (e.g. jquery, wp-api-fetch).
	 *     @param array  $args['internal']: array of internal script short names (e.g. tools, Modal).
	 *     @param array  $args['localization']: array with 'var' and 'values' keys for wp_localize_script.
	 *     @param array  $args['inline']: array with 'content' and 'position' for wp_add_inline_script.
	 *     @param bool   $args['in_footer']: whether to enqueue the script in the footer (default true).
	 *     @param string $args['version']: version of the script (default CONTENTSYNC_VERSION).
	 * @return bool True if registered and enqueued.
	 */
	public static function enqueue_build_script( $name, $admin_relative_path, $args = array() ) {
		$handle   = self::script_handle( $name );
		$base     = self::get_admin_base_url();
		$full_url = $base . ltrim( str_replace( '\\', '/', $admin_relative_path ), '/' );
		$url      = self::resolve_build_url( $full_url );

		$deps      = self::resolve_script_dependencies( $args );
		$version   = isset( $args['version'] ) ? $args['version'] : ( defined( 'CONTENTSYNC_VERSION' ) ? CONTENTSYNC_VERSION : '' );
		$in_footer = isset( $args['in_footer'] ) ? $args['in_footer'] : true;

		wp_register_script( $handle, $url, $deps, $version, $in_footer );
		wp_enqueue_script( $handle );

		self::maybe_localize_script( $handle, $args );
		self::maybe_add_inline_script( $handle, $args );

		return true;
	}

	/**
	 * Enqueue an admin style.
	 *
	 * @param string $name                 Short name (e.g. post_list_table).
	 * @param string $admin_relative_path  Path under includes/Admin/.
	 * @param array  $args                 Optional. Keys: external, version, media.
	 * @return bool True if registered and enqueued.
	 */
	public static function enqueue_admin_style( $name, $admin_relative_path, $args = array() ) {
		$handle = self::style_handle( $name );

		if ( wp_style_is( $handle, 'enqueued' ) ) {
			return true;
		}

		$base    = self::get_admin_base_url();
		$url     = $base . ltrim( str_replace( '\\', '/', $admin_relative_path ), '/' );
		$deps    = isset( $args['external'] ) ? $args['external'] : array();
		$version = isset( $args['version'] ) ? $args['version'] : ( defined( 'CONTENTSYNC_VERSION' ) ? CONTENTSYNC_VERSION : '' );
		$media   = isset( $args['media'] ) ? $args['media'] : 'all';

		wp_register_style( $handle, $url, $deps, $version, $media );
		wp_enqueue_style( $handle );

		return true;
	}

	/**
	 * =================================================================
	 *                          PRIVATE METHODS
	 * =================================================================
	 */

	/**
	 * Get the admin base URL for asset paths.
	 *
	 * @return string
	 */
	private static function get_admin_base_url() {
		return CONTENTSYNC_PLUGIN_URL . '/includes/Admin/';
	}

	/**
	 * Convert snake_case to camelCase.
	 *
	 * @param string $name Name in snake_case.
	 * @return string camelCase.
	 */
	private static function to_camel_case( $name ) {
		return lcfirst( str_replace( ' ', '', ucwords( str_replace( '_', ' ', $name ) ) ) );
	}

	/**
	 * Convert snake_case to kebab-case.
	 *
	 * @param string $name Name in snake_case.
	 * @return string kebab-case.
	 */
	private static function to_kebab_case( $name ) {
		return str_replace( '_', '-', strtolower( $name ) );
	}

	/**
	 * Get the script handle for a given name.
	 *
	 * @param string $name Short name (e.g. block_editor_tools or makeRoot).
	 * @return string Full handle (e.g. contentSync-blockEditorTools).
	 */
	public static function script_handle( $name ) {
		$suffix = strpos( $name, '_' ) !== false ? self::to_camel_case( $name ) : $name;
		return 'contentSync-' . $suffix;
	}

	/**
	 * Get the style handle for a given name.
	 *
	 * @param string $name Short name (e.g. post_list_table).
	 * @return string Full handle (e.g. contentsync-post-list-table).
	 */
	public static function style_handle( $name ) {
		return 'contentsync-' . self::to_kebab_case( $name );
	}

	/**
	 * Resolve a source script URL to the build script URL.
	 *
	 * If the URL contains /js/src/ or /js/source/, replaces that segment with /js/build/
	 * and changes extension .jsx to .js. Otherwise returns the URL as-is.
	 *
	 * @param string $script_url Full URL to the script.
	 * @return string Full URL to the built script.
	 */
	private static function resolve_build_url( $script_url ) {
		$url = str_replace( '\\', '/', $script_url );

		if ( strpos( $url, '/js/src/' ) !== false ) {
			$url = str_replace( '/js/src/', '/js/build/', $url );
		} elseif ( strpos( $url, '/js/source/' ) !== false ) {
			$url = str_replace( '/js/source/', '/js/build/', $url );
		}

		if ( substr( $url, -4 ) === '.jsx' ) {
			$url = substr( $url, 0, -4 ) . '.js';
		}

		return $url;
	}

	/**
	 * Resolve script dependencies from external and internal dependency lists.
	 *
	 * @param array $args Args with external dependencies and internal dependencies (plugin scripts by short name).
	 * @return array Merged array of script handles.
	 */
	private static function resolve_script_dependencies( $args ) {
		$external         = isset( $args['external'] ) ? $args['external'] : array();
		$internal         = isset( $args['internal'] ) ? $args['internal'] : array();
		$internal_handles = array_map( array( __CLASS__, 'script_handle' ), $internal );
		return array_merge( $external, $internal_handles );
	}

	/**
	 * Apply localization to a script if configured.
	 *
	 * @param string $handle Script handle.
	 * @param array  $args   Args with optional 'localization' key containing 'var' and 'values'.
	 *     @param string $args['localization']['var']: variable name.
	 *     @param array  $args['localization']['values']: values to localize.
	 */
	private static function maybe_localize_script( $handle, $args ) {
		if (
			! isset( $args['localization'] )
			|| ! isset( $args['localization']['var'] )
			|| empty( $args['localization']['var'] )
			|| ! isset( $args['localization']['values'] )
			|| empty( $args['localization']['values'] )
		) {
			return;
		}
		wp_localize_script( $handle, $args['localization']['var'], $args['localization']['values'] );
	}

	/**
	 * Add inline script if configured.
	 *
	 * @param string $handle Script handle.
	 * @param array  $args   Args with optional 'inline' key containing 'content' and 'position' (optional, 'after'|'before', default 'after').
	 *     @param string $args['inline']['content']: JS content to add inline.
	 *     @param string $args['inline']['position']: position to add inline (optional, 'after'|'before', default 'after').
	 */
	private static function maybe_add_inline_script( $handle, $args ) {
		if (
			! isset( $args['inline'] )
			|| ! isset( $args['inline']['content'] )
			|| empty( $args['inline']['content'] )
		) {
			return;
		}
		$position = isset( $args['inline']['position'] ) ? $args['inline']['position'] : 'after';
		wp_add_inline_script( $handle, $args['inline']['content'], $position );
	}
}
