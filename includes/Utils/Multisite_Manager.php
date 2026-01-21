<?php

namespace Contentsync\Utils;

use Contentsync\Posts\Post_Query;
use Contentsync\Translations\Translation_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Multisite Manager
 *
 * Manages multisite operations including blog switching, origin site URL tracking,
 * and upload directory filtering.
 */
class Multisite_Manager {

	/**
	 * Origin site url.
	 *
	 * This is necessary to make sure the upload url is returned correctly when
	 * switching to another blog.
	 *
	 * This is related to a core issue open since 2013:
	 *
	 * @see https://core.trac.wordpress.org/ticket/25650
	 *
	 * @var string|null
	 */
	private static $origin_site_url = null;

	/**
	 * Set the origin site URL.
	 *
	 * @param string|null $site_url The origin site URL.
	 *
	 * @return void
	 */
	public static function set_origin_site_url( $site_url ) {
		self::$origin_site_url = $site_url;
	}

	/**
	 * Get the origin site URL.
	 *
	 * @return string|null
	 */
	public static function get_origin_site_url() {
		return self::$origin_site_url;
	}

	/**
	 * Switch to another blog.
	 *
	 * This function unifies the switch_to_blog() function by also
	 * registering all dynamic post types & taxonomies after the switch.
	 * Otherwise, dynamic post types & taxonomies are not available, which
	 * leads to various errors retrieving post, terms and more.
	 *
	 * @param int $blog_id
	 *
	 * @return bool
	 */
	public static function switch_blog( $blog_id ) {
		if ( empty( $blog_id ) ) {
			return false;
		}
		if ( ! is_multisite() ) {
			return true;
		}

		if ( empty( self::get_origin_site_url() ) ) {
			self::set_origin_site_url( get_site_url() );
		}

		\switch_to_blog( $blog_id );

		// Reset translation tool cache to detect the correct tool for this blog
		Translation_Manager::reset_translation_tool();

		/**
		 * Ensures the translation environment is ready for use.
		 * This is important because translation plugins might not be loaded
		 * or still be loaded while not being active on the current blog.
		 *
		 * @see Translation_Manager::init_translation_environment()
		 */
		Translation_Manager::init_translation_environment();

		// remove filters from the query args within the export process
		if ( ! has_filter( 'contentsync_export_post_query_args', [ Post_Query::class, 'remove_filters_from_query_args' ] ) ) {
			add_filter( 'contentsync_export_post_query_args', [ Post_Query::class, 'remove_filters_from_query_args' ] );
		}

		// Register the filter hook if not already registered
		if ( ! has_filter( 'upload_dir', array( __CLASS__, 'filter_wp_upload_dir' ) ) ) {
			add_filter( 'upload_dir', array( __CLASS__, 'filter_wp_upload_dir' ), 98, 1 );
		}

		return true;
	}

	/**
	 * Restore the current blog.
	 *
	 * This function unifies the restore_current_blog() function by also
	 * making sure the origin site url is set again.
	 *
	 * @return bool
	 */
	public static function restore_blog() {
		\restore_current_blog();
		self::set_origin_site_url( null );

		/**
		 * Reset translation tool cache to detect the correct tool for this blog.
		 * This also handles reloading translation tool hooks if they were unloaded.
		 *
		 * @see Translation_Manager::reset_translation_tool()
		 */
		Translation_Manager::reset_translation_tool();

		return true;
	}

	/**
	 * Filter the wp the upload url.
	 *
	 * @see https://developer.wordpress.org/reference/hooks/upload_dir/
	 *
	 * This function unifies the return of the wp_upload_dir() function by
	 * making sure the origin site url is replaced with the current site url.
	 *
	 * This is related to a core issue open since 2013 (!).
	 * Yes. That's right. Two thousand f***ing thirteen.
	 * @see https://core.trac.wordpress.org/ticket/25650
	 *
	 * @param array $upload_dir
	 *
	 * @return array $upload_dir
	 */
	public static function filter_wp_upload_dir( $upload_dir ) {

		if ( ! empty( self::get_origin_site_url() ) ) {

			$current_site_url = get_site_url();

			// if the current site url is different from the origin site url, we need to replace the url and baseurl
			if ( $current_site_url !== self::get_origin_site_url() ) {
				$upload_dir['url']     = str_replace( self::get_origin_site_url(), $current_site_url, $upload_dir['url'] );
				$upload_dir['baseurl'] = str_replace( self::get_origin_site_url(), $current_site_url, $upload_dir['baseurl'] );
			}
		}

		return $upload_dir;
	}

	/**
	 * Get all blogs of a multisite
	 *
	 * @return array ID => [site_url, prefix]
	 */
	public static function get_all_blogs() {
		global $wpdb;

		if ( ! is_multisite() ) {
			$all_blogs = array(
				get_current_blog_id() => array(
					'site_url' => get_site_url(),
					'prefix'   => $wpdb->get_blog_prefix(),
				),
			);
		} else {
			$all_blogs = array();
			$sites     = get_sites( array( 'number' => 999 ) );
			if ( $sites ) {
				foreach ( $sites as $blog ) {
					$all_blogs[ $blog->blog_id ] = array(
						'site_url' => $blog->domain . $blog->path,
						'prefix'   => $wpdb->get_blog_prefix( $blog->blog_id ),
					);
				}
			}
		}

		return $all_blogs;
	}
}
