<?php

/**
 * Build Scripts utility
 *
 * Resolves source script URLs to build URLs for compiled JS and provides
 * helpers for enqueuing build scripts. Accepts full script URLs (same as
 * wp_register_script), e.g. CONTENTSYNC_PLUGIN_URL . '/path/to/js/src/file.jsx'.
 */

namespace Contentsync\Admin\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Class Build_Scripts
 */
class Build_Scripts {

	/**
	 * Resolve a source script URL to the build script URL.
	 *
	 * If the URL contains /js/src/ or /js/source/, replaces that segment with /js/build/
	 * and changes extension .jsx to .js. Otherwise returns the URL as-is.
	 *
	 * @param string $script_url Full URL to the script (e.g. CONTENTSYNC_PLUGIN_URL . '/includes/.../js/src/file.jsx').
	 * @return string Full URL to the built script.
	 */
	public static function resolve_build_url( $script_url ) {
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
	 * Get the URL for a build script.
	 *
	 * @param string $script_url Full URL to the script (source or build).
	 * @return string Full URL for the built script.
	 */
	public static function get_build_script_url( $script_url ) {
		return self::resolve_build_url( $script_url );
	}

	/**
	 * Register and enqueue a build script.
	 *
	 * Behaves like wp_register_script + wp_enqueue_script: pass the full URL to the source file
	 * (e.g. CONTENTSYNC_PLUGIN_URL . '/includes/.../js/src/file.jsx'). The helper resolves it to
	 * the build URL and registers/enqueues with CONTENTSYNC_VERSION as the version.
	 *
	 * @param string $handle     Script handle.
	 * @param string $script_url Full URL to the script (e.g. CONTENTSYNC_PLUGIN_URL . '/path/to/js/src/file.jsx').
	 * @param array  $deps       Optional. Dependencies. Default empty.
	 * @param bool   $in_footer  Optional. In footer. Default true.
	 * @return bool True if registered and enqueued.
	 */
	public static function enqueue_build_script( $handle, $script_url, $deps = array(), $in_footer = true ) {
		$url = self::get_build_script_url( $script_url );

		wp_register_script( $handle, $url, $deps, CONTENTSYNC_VERSION, $in_footer );
		wp_enqueue_script( $handle );
		return true;
	}
}
