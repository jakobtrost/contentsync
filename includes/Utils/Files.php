<?php

namespace Contentsync\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * File utility functions.
 */
class Files {

	/**
	 * Alternative for 'file_get_contents', but with error handling.
	 * This function handles a couple of possible mistakes accessing file contents.
	 *
	 * - curl is first and preferred file handling
	 * - if not available, allow_url_fopen is checked (msg when false)
	 * - then, file_get_contents is used
	 * - if it fails, http authentication is used
	 * - returns false if everything fails
	 *
	 * @param string $file  Full URL of file.
	 * @return string|bool  Contents of the file or false.
	 */
	public static function get_remote_file_contents( $file ) {

		// normalize path/url
		$file = wp_normalize_path( $file );

		$timeout = 3600;
		set_time_limit( $timeout );

		// try curl first;
		if ( function_exists( 'curl_init' ) && function_exists( 'curl_exec' ) ) {
			// make url (for curl)
			$file_url = self::abspath_to_url( $file );
			if ( strpos( $file_url, 'http' ) === 0 ) {
				$curl = curl_init();
				curl_setopt( $curl, CURLOPT_AUTOREFERER, true );
				curl_setopt( $curl, CURLOPT_HEADER, 0 );
				curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1 );
				curl_setopt( $curl, CURLOPT_URL, $file_url );
				curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, true );
				curl_setopt( $curl, CURLOPT_SSL_VERIFYHOST, false );
				curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, false );
				curl_setopt( $curl, CURLOPT_CONNECTTIMEOUT, 0 );
				curl_setopt( $curl, CURLOPT_TIMEOUT, $timeout ); // timeout in seconds
				curl_setopt( $curl, CURLOPT_COOKIE, 'wordpress_logged_in' );

				if ( isset( $_SERVER['PHP_AUTH_USER'] ) && isset( $_SERVER['PHP_AUTH_PW'] ) ) {
					$auth = base64_encode( $_SERVER['PHP_AUTH_USER'] . ':' . $_SERVER['PHP_AUTH_PW'] );
					curl_setopt( $curl, CURLOPT_HTTPHEADER, array( 'Authorization: Basic ' . $auth ) );
				}

				$contents = curl_exec( $curl );
				curl_close( $curl );

				if ( $contents ) {
					return $contents;
				}
			}
		}

		// convert the url to an absolute path (for file_get_contents)
		$file = self::url_to_abspath( $file );

		// with the prefix '@' the function doesn't throw a warning, as error handling is done below
		$contents = @file_get_contents( $file );
		if ( $contents ) {
			return $contents;
		}

		/**
		 *  Check if HTTP Authentication is enabled and pass it as $context
		 *  otherwise files may not be found, due to the athentication not being passed
		 */
		if ( isset( $_SERVER['PHP_AUTH_USER'] ) && isset( $_SERVER['PHP_AUTH_PW'] ) ) {
			$auth     = base64_encode( $_SERVER['PHP_AUTH_USER'] . ':' . $_SERVER['PHP_AUTH_PW'] );
			$context  = stream_context_create(
				array(
					'http' => array(
						'header' => "Authorization: Basic $auth",
					),
				)
			);
			$contents = file_get_contents( $file, false, $context );
			if ( $contents ) {
				return $contents;
			}
		}

		// request failed
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG === true ) {
			echo '<pre>HTTP request failed. Error was: ' . ( function_exists( 'error_get_last' ) ? error_get_last()['message'] : 'unidentified error' ) . '</pre>';
			// check 'allow_url_fopen' setting
			if ( ini_get( 'allow_url_fopen' ) !== true ) {
				echo "<pre>Your server's PHP settings are not compatible with the Greyd.Suite. The variable 'allow_url_fopen' is deactivated, which leads to the website being displayed incorrectly. Please contact your server administrator to resolve the problem.</pre>";
			}
		}
		return false;
	}

	/**
	 * Convert an URL into an absolute filepath
	 *
	 * @param string $path URL or path.
	 * @return string Absolute filepath.
	 */
	public static function url_to_abspath( $path ) {
		// if this is a relative path, convert it to an absolute one
		if ( strpos( $path, get_site_url() ) === 0 ) {
			$path = ABSPATH . substr( explode( get_site_url(), $path )[1], 1 );
		}
		// remove url params
		if ( strpos( $path, '?' ) !== false ) {
			$path = explode( '?', $path )[0];
		}
		return wp_normalize_path( $path );
	}

	/**
	 * Convert an absolute filepath into an URL
	 *
	 * @param string $url Absolute filepath or URL.
	 * @return string URL.
	 */
	public static function abspath_to_url( $url ) {
		// if this is an absolute path, convert it to a url
		if ( strpos( $url, wp_normalize_path( ABSPATH ) ) === 0 ) {
			$url = str_replace(
				untrailingslashit( wp_normalize_path( ABSPATH ) ),
				get_site_url(),
				$url
			);
		}
		return $url;
	}

	/**
	 * Get path to the contentsync export folder. Use this path to write files.
	 *
	 * @param string $folder Folder inside wp-content/contentsync/
	 *
	 * @return string $path
	 */
	public static function get_wp_content_folder_path( $folder = '' ) {

		// check cache
		$path = wp_cache_get( 'contentsync_posts_transfer_file_path' );

		if ( ! $path ) {

			$path = WP_CONTENT_DIR . '/contentsync';

			if ( ! file_exists( $path ) ) {
				Logger::add( sprintf( '  - create folder "%s".', $path ) );
				mkdir( $path, 0755, true );
			}
			$path .= '/';

			// save in cache
			wp_cache_set( 'contentsync_posts_transfer_file_path', $path );
		}

		// get directory
		if ( ! empty( $folder ) ) {
			$path .= $folder;
			if ( ! file_exists( $path ) ) {
				Logger::add( sprintf( '  - create folder "%s".', $path ) );
				mkdir( $path, 0755, true );
			}
		}

		$path .= '/';
		return $path;
	}
}
