<?php
/**
 * Translation Tool Factory
 *
 * Responsible for detecting which translation tool is active and
 * providing the appropriate translation tool instance.
 */

namespace Contentsync\Translations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Translation_Tool_Factory {

	/**
	 * Cached translation tool instance.
	 *
	 * @var Translation_Tool_Base|null
	 */
	private static $instance = null;

	/**
	 * Cached tool name.
	 *
	 * @var string|null
	 */
	private static $tool_name = null;

	/**
	 * Get the active translation tool instance.
	 *
	 * @return Translation_Tool_Base|null Translation tool instance or null if none detected.
	 */
	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = self::create_instance();
		}

		return self::$instance;
	}

	/**
	 * Get the name of the active translation tool.
	 *
	 * @return string|null Tool name ('wpml', 'polylang') or null if none detected.
	 */
	public static function get_tool_name() {
		if ( self::$tool_name === null ) {
			self::$tool_name = self::detect_tool();
		}

		return self::$tool_name;
	}

	/**
	 * Reset the factory cache.
	 *
	 * Useful for testing or when the translation environment changes.
	 */
	public static function reset() {
		self::$instance  = null;
		self::$tool_name = null;
	}

	/**
	 * Create a translation tool instance based on the active tool.
	 *
	 * @return Translation_Tool_Base|null
	 */
	private static function create_instance() {
		$tool_name = self::get_tool_name();

		if ( ! $tool_name ) {
			return null;
		}

		switch ( $tool_name ) {
			case 'wpml':
				return new Translation_Tool_WPML();

			case 'polylang':
				return new Translation_Tool_Polylang();

			default:
				return null;
		}
	}

	/**
	 * Detect which translation tool is active.
	 *
	 * @return string|null Tool name or null if none detected.
	 */
	private static function detect_tool() {
		$tool = null;

		$plugins = self::get_active_plugins();
		if ( in_array( 'sitepress-multilingual-cms/sitepress.php', $plugins ) ) {
			$tool = 'wpml';
		} elseif ( in_array( 'polylang/polylang.php', $plugins ) ) {
			$tool = 'polylang';
		} elseif ( in_array( 'polylang-pro/polylang.php', $plugins ) ) {
			$tool = 'polylang';
		}

		return $tool;
	}

	/**
	 * Get all active Plugins from Option.
	 * Including all active sitewide Plugins.
	 *
	 * @param string $mode  all|site|global (default: all)
	 */
	public static function get_active_plugins( $mode = 'all' ) {

		$plugins = array();

		// get all active plugins
		if ( $mode == 'all' || $mode == 'site' ) {
			$plugins = get_option( 'active_plugins' );
			if ( ! is_array( $plugins ) ) {
				$plugins = array();
			}
		}

		// on multisite, get all active sitewide plugins as well
		if (
			is_multisite()
			&& ( $mode == 'all' || $mode == 'global' )
		) {
			$plugins_multi = get_site_option( 'active_sitewide_plugins' );
			if ( is_array( $plugins_multi ) && ! empty( $plugins_multi ) ) {
				foreach ( $plugins_multi as $key => $value ) {
					$plugins[] = $key;
				}
				$plugins = array_unique( $plugins );
				sort( $plugins );
			}
		}

		return $plugins;
	}

	/**
	 * Check if a specific translation tool plugin is loaded in memory.
	 *
	 * This checks if the plugin code is loaded, which can be different from
	 * whether it's active/configured for the current blog in multisite.
	 *
	 * @param string|null $tool_name Tool name ('wpml', 'polylang') or null to detect automatically.
	 * @return bool True if the plugin is loaded, false otherwise.
	 */
	public static function is_plugin_loaded( $tool_name = null ) {
		if ( $tool_name === null ) {
			$tool_name = self::get_tool_name();
		}

		if ( ! $tool_name ) {
			return false;
		}

		$instance = self::create_instance_by_name( $tool_name );

		if ( ! $instance ) {
			return false;
		}

		return $instance->is_plugin_loaded();
	}

	/**
	 * Detect which translation tool is loaded in memory (if any).
	 *
	 * This is different from detect_tool() which checks if a tool is active
	 * for the current blog. This method checks all known tools to see if
	 * any of them are loaded in memory.
	 *
	 * @return string|null Tool name if loaded, null if none found.
	 */
	public static function get_loaded_tool_name() {
		$known_tools = array( 'polylang', 'wpml' );

		foreach ( $known_tools as $tool_name ) {
			if ( self::is_plugin_loaded( $tool_name ) ) {
				return $tool_name;
			}
		}

		return null;
	}

	/**
	 * Unload hooks from a translation tool that's loaded but not active.
	 *
	 * This is a generic method that detects if any translation plugin is loaded
	 * but not active for the current blog, then unloads its hooks.
	 *
	 * @return bool True if hooks were unloaded, false otherwise.
	 */
	public static function unload_inactive_tool_hooks() {
		// Check which tool is active for this blog
		$active_tool = self::get_tool_name();

		// Check which tool is loaded in memory
		$loaded_tool = self::get_loaded_tool_name();

		// If no tool is loaded or the loaded tool matches the active tool, nothing to do
		if ( ! $loaded_tool || $loaded_tool === $active_tool ) {
			return false;
		}

		// Tool is loaded but shouldn't be active - create instance and unload its hooks
		$instance = self::create_instance_by_name( $loaded_tool );

		if ( ! $instance ) {
			return false;
		}

		return $instance->unload_hooks();
	}

	/**
	 * Reload hooks for the active translation tool.
	 *
	 * This should be called after switching back to a blog where the translation
	 * tool should be active.
	 *
	 * @return bool True if hooks were reloaded, false otherwise.
	 */
	public static function reload_active_tool_hooks() {
		$instance = self::get_instance();

		if ( ! $instance ) {
			return false;
		}

		return $instance->reload_hooks();
	}

	/**
	 * Create a translation tool instance by name.
	 *
	 * @param string $tool_name Tool name ('wpml', 'polylang').
	 * @return Translation_Tool_Base|null
	 */
	private static function create_instance_by_name( $tool_name ) {
		switch ( $tool_name ) {
			case 'wpml':
				return new Translation_Tool_WPML();

			case 'polylang':
				return new Translation_Tool_Polylang();

			default:
				return null;
		}
	}
}
