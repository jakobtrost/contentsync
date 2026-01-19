<?php
/**
 * Helper functions for the global contents class
 *
 * The `Main_Helper` class offers a suite of helper functions that underpin the global
 * contents system. It encapsulates operations for retrieving synced posts by their
 * unique global ID, preparing posts for import, splitting global IDs into their
 * component parts and aggregating posts across multisite networks or remote sites.
 * By centralising these operations into static methods, the helper promotes reuse
 * and reduces duplication throughout the distribution codebase.
 *
 * An instance of `Main_Helper` is created when the file loads. The constructor adds
 * a filter to merge additional meta keys into the export blacklist. Many of the
 * methods are static and can be called without instantiating the class again. The
 * `get_synced_post` method accepts a global ID string, caches results in object and
 * transient caches and uses `Remote_Operations` to fetch posts from remote networks
 * when necessary. It returns a `Synced_Post` object or `null` and applies a filter to
 * allow customisation of the result. The companion `prepare_synced_post_for_import`
 * method resolves all dependent posts for a given global ID and merges query
 * arguments with options stored in post meta. On remote networks it delegates to
 * `Remote_Operations` to fetch prepared data.
 *
 * The helper also provides functions to derive and interpret global IDs. The
 * `get_gid` method retrieves a global ID for a post using a filter for customisation,
 * while `get_post_id_by_gid` finds a local post ID by global ID. The `explode_gid`
 * method splits a global ID into blog ID, post ID and site URL components and exposes
 * filters to adjust each part. Aggregation functions such as `get_all_synced_posts`
 * and `get_all_synced_posts_from_current_network` return arrays of synced posts from both the local
 * multisite network and connected remote networks, using caching and remote operations
 * to improve performance. Dozens of additional methods handle tasks like switching
 * blogs, retrieving network URLs, collecting blog lists and creating connection maps.
 * Because these functions interact closely with WordPress multisite APIs and remote
 * requests, you should exercise care when using them and ensure that network
 * connections and caches are correctly configured.
 */

namespace Contentsync;

use Remote_Operations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

new Main_Helper();
class Main_Helper {

	public function __construct() {
	}

	/**
	 * =================================================================
	 *                          GET FUNCTIONS
	 * =================================================================
	 */

	/**
	 * Get the global root post by gid
	 *
	 * @param string $gid
	 *
	 * @return Synced_Post|null
	 */
	public static function get_synced_post( $gid ) {

		// check object cache (local post)
		if ( $cache = wp_cache_get( 'gid_' . $gid, 'synced_posts' ) ) {
			return $cache;
		}

		// check persistent cache (remote post)
		if ( $cache = get_transient( 'gid_' . $gid ) ) {
			return $cache;
		}

		list( $blog_id, $post_id, $site_url ) = self::explode_gid( $gid );
		if ( $post_id === null ) {
			return null;
		}

		$post = null;

		// network post
		if ( empty( $site_url ) ) {
			switch_blog( $blog_id );

			$status = get_post_meta( $post_id, 'synced_post_status', true );

			if ( $status === 'root' ) {
				$post = new_synced_post( $post_id );

				/**
				 * Set object cache for local post.
				 */
				wp_cache_set( 'gid_' . $gid, $post, 'synced_posts' );
			}

			restore_blog();
		}
		// remote post
		else {
			$response = \Contentsync\Api\get_remote_synced_post( $site_url, $gid );
			if ( $response ) {
				$post = new_synced_post( $response );

				/**
				 * Set persistent cache for remote post, with 10 minutes expiration.
				 */
				set_transient( 'gid_' . $gid, $post, 10 * MINUTE_IN_SECONDS );
			}
		}

		/**
		 * Filter to modify the synced post object before returning.
		 *
		 * This filter allows developers to customize the synced post object
		 * that is retrieved by GID, enabling modifications to post data,
		 * structure, or additional processing before the post is returned.
		 *
		 * @filter contentsync_get_synced_post
		 *
		 * @param WP_Post|null $post The synced post object or null if not found.
		 * @param string      $gid  The global ID of the post.
		 *
		 * @return WP_Post|null The modified synced post object or null.
		 */
		return apply_filters( 'contentsync_get_synced_post', $post ?? null, $gid );
	}

	/**
	 * Get a synced post with all it's dependant posts.
	 *
	 * @param string $gid
	 *
	 * @return WP_Post[]|null
	 */
	public static function prepare_synced_post_for_import( $gid, $args = array() ) {

		list( $blog_id, $post_id, $site_url ) = self::explode_gid( $gid );
		if ( $post_id === null ) {
			return null;
		}

		$posts = null;

		// network post
		if ( empty( $site_url ) ) {

			switch_blog( $blog_id );
			$status = get_post_meta( $post_id, 'synced_post_status', true );

			if ( $status === 'root' ) {
				$post = new_synced_post( $post_id );
				if ( $post ) {
					$args = array_merge( get_contentsync_meta_values( $post, 'contentsync_export_options' ), $args );

					if ( $post->post_type === 'tp_posttypes' && $args['whole_posttype'] ) {
						$args['query_args'] = array(
							'meta_query' => array(
								array(
									'key'     => 'synced_post_status',
									'value'   => 'root',
									'compare' => 'LIKE',
								),
							),
						);
					}

					$root_post = self::call_post_export_func( 'export_post', $post_id, $args );
					$posts     = self::call_post_export_func( 'get_all_posts' );
				}
			}

			restore_blog();
		}
		// remote post
		else {
			$posts = \Contentsync\Api\prepare_remote_synced_post( $site_url, $gid );
		}

		return $posts ? (array) $posts : null;
	}

	/**
	 * Get a WP_Post ID by global ID.
	 *
	 * @param string $gid     eg. '1-1234' or '1-1234-http://example.com'
	 *
	 * @return int            Post ID on success, 0 on failure.
	 */
	public static function get_post_id_by_gid( $gid ) {
		$result = get_unfiltered_posts(
			array(
				'posts_per_page' => 1,
				'post_type'      => 'any',
				'meta_key'       => 'synced_post_id',
				'meta_value'     => $gid,
				'fields'         => 'ids',
			)
		);
		return $result ? $result[0] : 0;
	}

	/**
	 * Get all synced posts for this multisite including connections
	 *
	 * @param string|array $query   Search query term or array of query args.
	 * @param string       $network_url   Filter by network url ('here' only retrieves posts from this network)
	 *
	 * @return array of all synced posts
	 */
	public static function get_all_synced_posts( $query = null, $network_url = null ) {

		$all_synced_posts = array();
		$current_network  = \Contentsync\Utils\get_network_url();

		// get network posts
		if ( empty( $network_url ) || $network_url === 'here' || $network_url === $current_network ) {
			$all_synced_posts = self::get_all_synced_posts_from_current_network( $query );
		}

		// get remote posts
		foreach ( \Contentsync\get_site_connections() as $site_url => $connection ) {

			// continue if filter is different
			if ( ! empty( $network_url ) && $network_url !== $site_url ) {
				continue;
			}

			// don't add posts from the same network
			if ( \Contentsync\Utils\get_nice_url( $site_url ) === $current_network ) {
				continue;
			}

			// load from persistant cache
			$_cache_key = "remote_posts_$site_url" . ( empty( $query ) ? '' : '_' . md5( serialize( $query ) ) );
			$_cache     = get_transient( $_cache_key );
			if ( is_array( $_cache ) && count( $_cache ) ) {
				$all_synced_posts = array_merge( $all_synced_posts, $_cache );
			}
			// load new
			else {
				$posts = \Contentsync\Api\get_remote_synced_posts( $connection, $query );
				if ( is_array( $posts ) && count( $posts ) ) {

					// set persistent cache
					set_transient( $_cache_key, $posts, HOUR_IN_SECONDS );

					$all_synced_posts = array_merge( $all_synced_posts, $posts );
				}
			}
		}

		/**
		 * Filter to modify the complete array of synced posts before returning.
		 *
		 * This filter allows developers to customize the complete array of global
		 * posts retrieved from both network and remote sources, enabling
		 * modifications to post data, filtering, or additional processing.
		 *
		 * @filter contentsync_get_all_synced_posts
		 *
		 * @param array $all_synced_posts Array of all synced posts.
		 * @param string|array $query Search query or query arguments.
		 * @param string $network_url Network URL filter.
		 *
		 * @return array Modified array of all synced posts.
		 */
		return apply_filters( 'contentsync_get_all_synced_posts', $all_synced_posts, $query, $network_url );
	}

	/**
	 * Get all synced posts of this multisite
	 *
	 * @param string|array $query   Search query term or array of query args.
	 *
	 * @return array of all network posts
	 */
	public static function get_all_synced_posts_from_current_network( $query = null ) {

		$cache_key = empty( $query ) ? 'all_network_posts' : 'all_network_posts_' . md5( serialize( $query ) );

		// check cache (not persistent, as we need to update it frequently)
		if ( $cache = wp_cache_get( $cache_key, 'synced_posts' ) ) {
			return $cache;
		}

		// loop through all blogs and get the posts
		$all_network_posts = array();
		foreach ( get_all_blogs() as $blog_id => $blog_args ) {
			$posts = self::get_synced_posts_of_blog( $blog_id, 'root', $query );

			if ( is_array( $posts ) && ! empty( $posts ) ) {
				$all_network_posts = array_merge( $all_network_posts, $posts );
			}
		}

		// set cache
		wp_cache_set( $cache_key, $all_network_posts, 'synced_posts' );

		/**
		 * Filter to modify the array of network posts before returning.
		 *
		 * This filter allows developers to customize the array of global
		 * posts retrieved from the current multisite network, enabling
		 * modifications to post data, filtering, or additional processing.
		 *
		 * @filter contentsync_get_all_synced_posts_from_current_network
		 *
		 * @param array $all_network_posts Array of all network posts.
		 * @param string|array $query Search query or query arguments.
		 *
		 * @return array Modified array of all network posts.
		 */
		return apply_filters( 'contentsync_get_all_synced_posts_from_current_network', $all_network_posts, $query );
	}

	/**
	 * Get all synced posts of a certain blog
	 *
	 * @param int          $blog_id       ID of the blog, defaults to the current blog.
	 * @param string       $filter_status Filter 'synced_post_status' meta. Leave empty for no filtering.
	 *                                    Either 'root' or 'linked'.
	 * @param string|array $query         Search query term or array of query args.
	 *
	 * @return array of posts
	 */
	public static function get_synced_posts_of_blog( $blog_id = '', $filter_status = '', $query = null ) {

		switch_blog( $blog_id );

		$post_type = 'any';
		if ( empty( $post_type ) || $post_type === 'any' ) {
			$post_type = \Contentsync\Utils\is_rest_request() ? 'any' : \Contentsync\get_export_post_types();
		}

		$args = array(
			'posts_per_page' => -1,
			'post_type'      => $post_type,
			'post_status'    => array( 'publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit' ),
		);

		if ( $query ) {
			if ( is_string( $query ) ) {
				$args['s'] = $query;
			} elseif ( is_array( $query ) ) {
				foreach ( $query as $k => $v ) {
					if ( ! empty( $v ) ) {
						$args[ $k ] = $v;
					}
				}
			}
		}
		if ( empty( $filter_status ) ) {
			$args['meta_key'] = 'synced_post_status';
		} else {
			$args['meta_query'] = array(
				array(
					'key'   => 'synced_post_status',
					'value' => $filter_status,
				),
				array(
					'key'     => 'synced_post_id',
					'value'   => get_current_blog_id() . '-',
					'compare' => $filter_status === 'root' ? 'LIKE' : 'NOT LIKE',
				),
			);
		}

		$posts = self::extend_post_object( get_unfiltered_posts( $args ) );

		restore_blog();

		/**
		 * Filter to modify the array of blog synced posts before returning.
		 *
		 * This filter allows developers to customize the array of global
		 * posts retrieved from a specific blog, enabling modifications
		 * to post data, filtering, or additional processing.
		 *
		 * @filter contentsync_get_synced_posts_of_blog
		 *
		 * @param array $posts Array of synced posts from the blog.
		 * @param int $blog_id The blog ID.
		 * @param string $filter_status The filter status used.
		 * @param string|array $query Search query or query arguments.
		 *
		 * @return array Modified array of blog synced posts.
		 */
		return apply_filters( 'contentsync_get_synced_posts_of_blog', $posts, $blog_id, $filter_status, $query );
	}

	/**
	 * Extend the WP_Post object by attaching contentsync post-meta & language
	 *
	 * @return WP_Post|WP_Post[]    Depends on the input.
	 */
	public static function extend_post_object( $post, $current_blog = 0 ) {
		// multiple posts
		if ( is_array( $post ) && count( $post ) > 0 ) {
			foreach ( $post as $key => $_post ) {
				$post[ $key ] = self::extend_post_object( $_post, $current_blog );
			}
			return $post;
		}
		// single post
		elseif ( is_object( $post ) && isset( $post->ID ) ) {

			// attach meta
			$meta = array();
			foreach ( get_contentsync_meta_keys() as $meta_key ) {
				if ( $meta_key == 'contentsync_connection_map' ) {
					$meta_value = get_post_connection_map( $post->ID );
				} else {
					$meta_value = get_post_meta( $post->ID, $meta_key, true );
				}
				$meta[ $meta_key ] = $meta_value;
			}
			$post->meta = $meta;

			// attach language
			$post->language = get_post_language_code( $post );

			// attach blog id
			$post->blog_id = $current_blog ? $current_blog : get_current_blog_id();

			// attach theme used in blog
			$post->blog_theme = get_wp_template_theme( $post );
		}
		return $post;
	}

	/**
	 * Get post from current blog by global ID
	 *
	 * @param string $gid
	 *
	 * @return WP_Post|bool
	 */
	public static function get_local_post_by_gid( $gid, $post_type = 'any' ) {

		if ( ! $gid || empty( $gid ) ) {
			return false;
		}

		$local_post = false;

		if ( empty( $post_type ) || $post_type === 'any' ) {
			$post_type = \Contentsync\Utils\is_rest_request() ? 'any' : \Contentsync\get_export_post_types();
		}

		$result = get_unfiltered_posts(
			array(
				'posts_per_page' => 1,
				'post_type'      => $post_type,
				'meta_key'       => 'synced_post_id',
				'meta_value'     => $gid,
				'post_status'    => array( 'publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit' ),
			)
		);

		if ( is_array( $result ) && isset( $result[0] ) ) {
			$local_post = new_synced_post( $result[0] );
		}

		/**
		 * Filter to modify the local post retrieved by GID before returning.
		 *
		 * This filter allows developers to customize the local post object
		 * that is retrieved by global ID, enabling modifications to post
		 * data, structure, or additional processing.
		 *
		 * @filter contentsync_get_local_post_by_gid
		 *
		 * @param WP_Post|bool $local_post The local post object or false if not found.
		 * @param string $gid The global ID used for retrieval.
		 * @param string $post_type The post type filter used.
		 *
		 * @return WP_Post|bool The modified local post object or false.
		 */
		return apply_filters( 'contentsync_get_local_post_by_gid', $local_post, $gid, $post_type );
	}

	/**
	 * Find similar synced posts.
	 *
	 * Criteria:
	 * * not from current blog
	 * * same posttype
	 * * post_name has at least 90% similarity
	 *
	 * @param WP_Post $post
	 *
	 * @return array of similar posts
	 */
	public static function get_similar_synced_posts( $post ) {

		$found   = array();
		$blog_id = get_current_blog_id();
		$net_url = \Contentsync\Utils\get_network_url();

		if ( ! isset( $post->post_name ) ) {
			return $found;
		}

		// we're replacing ending numbers after a dash (footer-2 becomes footer)
		$regex     = '/\-[0-9]{0,2}$/';
		$post_name = preg_replace( $regex, '', $post->post_name );

		// find and list all similar posts
		$all_posts = self::get_all_synced_posts();

		foreach ( $all_posts as $synced_post ) {

			$synced_post = new_synced_post( $synced_post );
			$gid         = get_contentsync_meta_values( $synced_post, 'synced_post_id' );

			list( $_blog_id, $_post_id, $_net_url ) = self::explode_gid( $gid );

			// exclude posts from other posttypes
			if ( $post->post_type !== $synced_post->post_type ) {
				continue;
			}
			// exclude posts from current blog
			elseif ( empty( $_net_url ) && $blog_id == $_blog_id ) {
				continue;
			}
			// exclude if a connection to this site is already established
			elseif (
				( empty( $_net_url ) && isset( $synced_post->meta['contentsync_connection_map'][ $blog_id ] ) ) ||
				( ! empty( $_net_url ) && isset( $synced_post->meta['contentsync_connection_map'][ $net_url ][ $blog_id ] ) )
			) {
				continue;
			}

			// check the post_name for similarity
			$name = preg_replace( $regex, '', $synced_post->post_name );
			similar_text( $post_name, $name, $percent ); // store percentage in variable $percent

			// list, if similarity is at least 90%
			if ( intval( $percent ) >= 90 ) {

				// make sure to get the post links
				if ( empty( $synced_post->post_links ) ) {

					// retrieve the post including all post_links from url
					if ( ! empty( $_net_url ) ) {
						$synced_post = new_synced_post( self::get_synced_post( $gid ) );
					} else {
						$synced_post->post_links = get_local_post_links( $_blog_id, $_post_id );
					}
				}

				// add the post to the response
				$found[ $gid ] = $synced_post;
			}
		}

		return apply_filters( 'contentsync_get_similar_synced_posts', $found, $post );
	}

	/**
	 * Filter the permalink of a post considering its canonical urls.
	 *
	 * @param string  $post_link The post's permalink.
	 * @param WP_Post $post      The post in question.
	 * @param bool    $leavename Whether to keep the post name.
	 * @param bool    $sample    Is it a sample permalink.
	 *
	 * @return string First non-empty string:
	 *      (1) Yoast canonical url.
	 *      (2) Global canonical url.
	 *      (3) The default permalink.
	 */
	public static function get_global_permalink( $permalink, $post, $leavename = false, $sample = false ) {

		if ( ! is_object( $post ) || ! isset( $post->ID ) || empty( $post->ID ) ) {
			return $permalink;
		}

		$synced_post_status = esc_attr( get_post_meta( $post->ID, 'synced_post_status', true ) );
		if ( $synced_post_status !== 'linked' ) {

			/**
			 * @since 1.7.0 If the post is not linked, we don't need to do anything.
			 *
			 * @filter contentsync_get_global_permalink
			 *
			 * @param string  $permalink The new permalink.
			 * @param WP_Post $post      Post object.
			 * @param string  $permalink The original permalink (if any)
			 */
			return apply_filters( 'contentsync_get_global_permalink', $permalink, $post, $permalink );
		}

		$yoast_canonical_url = esc_attr( get_post_meta( $post->ID, '_yoast_wpseo_canonical', true ) );
		if ( ! empty( $yoast_canonical_url ) ) {

			/**
			 * (1) Yoast canonical url
			 *
			 * @filter contentsync_get_global_permalink
			 *
			 * @param string  $permalink The new permalink.
			 * @param WP_Post $post      Post object.
			 * @param string  $original  The original permalink (if any)
			 */
			return apply_filters( 'contentsync_get_global_permalink', $yoast_canonical_url, $post, $permalink );
		}

		$rankmath_canonical_url = esc_attr( get_post_meta( $post->ID, 'rank_math_canonical_url', true ) );
		if ( ! empty( $rankmath_canonical_url ) ) {

			/**
			 * (1) Rankmath canonical url
			 *
			 * @filter contentsync_get_global_permalink
			 *
			 * @param string  $permalink The new permalink.
			 * @param WP_Post $post      Post object.
			 * @param string  $original  The original permalink (if any)
			 */
			return apply_filters( 'contentsync_get_global_permalink', $rankmath_canonical_url, $post, $permalink );
		}

		$contentsync_canonical_url = esc_attr( get_post_meta( $post->ID, 'contentsync_canonical_url', true ) );
		if ( ! empty( $contentsync_canonical_url ) ) {

			/**
			 * (2) Global canonical URL
			 *
			 * @filter contentsync_get_global_permalink
			 *
			 * @param string  $permalink The new permalink.
			 * @param WP_Post $post      Post object.
			 * @param string  $original The original permalink (if any)
			 */
			return apply_filters( 'contentsync_get_global_permalink', $contentsync_canonical_url, $post, $permalink );
		}

		/**
		 * (3) The default permalink.
		 *
		 * @filter contentsync_get_global_permalink
		 *
		 * @param string  $permalink The new permalink.
		 * @param WP_Post $post      Post object.
		 * @param string  $permalink The original permalink (if any)
		 */
		return apply_filters( 'contentsync_get_global_permalink', $permalink, $post, $permalink );
	}


	/**
	 * =================================================================
	 *                          MISC
	 * =================================================================
	 */

	/**
	 * Function to check if current user is allowed to edit global contents.
	 * Permission is based on 'edit_posts' capability and can be overridden
	 * with the filter 'contentsync_user_can_edit'.
	 *
	 * @param string $status 'root' or 'linked'
	 *
	 * @return bool
	 */
	public static function current_user_can_edit_synced_posts( $status = '' ) {

		$can_edit = function_exists( 'current_user_can' ) ? current_user_can( 'edit_posts' ) : true;

		if ( $status === 'root' ) {

			/**
			 * Filter to allow editing of root posts.
			 *
			 * @param bool $can_edit
			 *
			 * @return bool
			 */
			$can_edit = apply_filters( 'contentsync_user_can_edit_root_posts', $can_edit );
		} elseif ( $status === 'linked' ) {

			/**
			 * Filter to allow editing of linked posts.
			 *
			 * @param bool $can_edit
			 *
			 * @return bool
			 */
			$can_edit = apply_filters( 'contentsync_user_can_edit_linked_posts', $can_edit );
		}

		/**
		 * Filter to allow editing of all synced posts, no matter the status.
		 *
		 * @param bool $can_edit
		 *
		 * @return bool
		 */
		return apply_filters( 'contentsync_user_can_edit_synced_posts', $can_edit, $status );
	}


	/**
	 * =================================================================
	 *                          Compatibility
	 * =================================================================
	 */

	/**
	 * Call function from core Post_Export class with backward compatiblity.
	 *
	 * @since 1.4.5
	 *
	 * @param string $function_name
	 * @param mixed  ...$args
	 *
	 * @return mixed
	 */
	public static function call_post_export_func( $function_name ) {

		$args = func_get_args();
		array_shift( $args );

		// Check for standalone functions first (new in refactored version)
		$function = '\Contentsync\\' . $function_name;
		if ( function_exists( $function ) ) {
			return count( $args ) === 0
				? call_user_func( $function )
				: call_user_func_array( $function, $args );
		}

		$method = '';

		// Check Translation_Manager (new in 2.19.0)
		if ( class_exists( '\Contentsync\Translation_Manager' ) && method_exists( '\Contentsync\Translation_Manager', $function_name ) ) {
			$method = '\Contentsync\Translation_Manager';
		} elseif ( method_exists( '\Contentsync\Post_Export', $function_name ) ) {
			$method = '\Contentsync\Post_Export';
		} elseif ( method_exists( '\Contentsync\Post_Import', $function_name ) ) {
			$method = '\Contentsync\Post_Import';
		}

		if ( ! empty( $method ) ) {
			return count( $args ) === 0
				? call_user_func( $method . '::' . $function_name )
				: call_user_func_array( $method . '::' . $function_name, $args );
		}

		return null;
	}

	/**
	 * Export posts with backward compatiblity and additional actions.
	 *
	 * @see \Contentsync\Post_Export::export_post()
	 *
	 * @param int   $post_id          Post ID to export.
	 * @param array $args             Array of arguments to export post.
	 *
	 * @return mixed                  Exported post.
	 */
	public static function export_post( $post_id, $args = array() ) {

		/**
		 * @action contentsync_before_export_synced_post
		 * @since 2.18.0
		 */
		do_action( 'contentsync_before_export_synced_post', $post_id, $args );

		$result = self::call_post_export_func( 'export_post', $post_id, $args );

		/**
		 * @action contentsync_after_export_synced_post
		 * @since 2.18.0
		 */
		do_action( 'contentsync_after_export_synced_post', $post_id, $args, $result );

		return $result;
	}

	/**
	 * Export posts with backward compatiblity and additional actions.
	 *
	 * @see \Contentsync\Post_Export::export_posts()
	 *
	 * @param array $post_ids_or_objects Array of post IDs or post objects to export.
	 * @param array $args                Array of arguments to export posts.
	 *
	 * @return mixed                     Array of exported posts.
	 */
	public static function export_posts( $post_ids_or_objects, $args = array() ) {

		/**
		 * @action contentsync_before_export_synced_posts
		 * @since 2.18.0
		 */
		do_action( 'contentsync_before_export_synced_posts', $post_ids_or_objects, $args );

		/**
		 * Use the new export_post_objects method if available.
		 *
		 * @see \Contentsync\Post_Export::export_post_objects()
		 *
		 * @since 2.18.0
		 *
		 * @param array $post_ids_or_objects Array of post IDs or post objects to export.
		 * @param array $args                Array of arguments to export posts.
		 *
		 * @return mixed                     Array of exported posts.
		 */
		if ( method_exists( '\Contentsync\Post_Export', 'export_post_objects' ) ) {
			$result = \Contentsync\Post_Export::export_post_objects( $post_ids_or_objects, $args );
		}
		// use the old export_posts method if not available
		else {

			// convert post IDs or objects to post IDs to provide backward compatibility
			// with greyd-plugin < 2.18.0
			$post_ids = array();
			foreach ( $post_ids_or_objects as $post_or_id ) {
				if ( is_object( $post_or_id ) ) {
					$post_ids[] = $post_or_id->ID;
				} else {
					$post_ids[] = $post_or_id;
				}
			}

			$result = self::call_post_export_func( 'export_posts', $post_ids, $args );
		}

		/**
		 * @action contentsync_after_export_synced_posts
		 * @since 2.18.0
		 */
		do_action( 'contentsync_after_export_synced_posts', $post_ids_or_objects, $args, $result );

		return $result;
	}

	/**
	 * Import posts with backward compatiblity and additional actions.
	 *
	 * @see \Contentsync\Post_Import::import_posts()
	 *
	 * @param array $posts             Array of posts to import.
	 * @param array $conflict_actions  Array of conflict actions.
	 *
	 * @return mixed                  True on success. WP_Error on failure.
	 */
	public static function import_posts( $posts, $conflict_actions = array() ) {

		/**
		 * @action contentsync_before_import_synced_posts
		 * @since 2.18.0
		 */
		do_action( 'contentsync_before_import_synced_posts', $posts, $conflict_actions );

		$result = self::call_post_export_func( 'import_posts', $posts, $conflict_actions );

		/**
		 * @action contentsync_after_import_synced_posts
		 * @since 2.18.0
		 */
		do_action( 'contentsync_after_import_synced_posts', $posts, $conflict_actions, $result );

		return $result;
	}
}
