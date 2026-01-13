<?php
/**
 * Helper functions for the global contents class
 * 
 * The `Main_Helper` class offers a suite of helper functions that underpin the global 
 * contents system. It encapsulates operations for retrieving global posts by their 
 * unique global ID, preparing posts for import, splitting global IDs into their 
 * component parts and aggregating posts across multisite networks or remote sites.  
 * By centralising these operations into static methods, the helper promotes reuse 
 * and reduces duplication throughout the distribution codebase.
 * 
 * An instance of `Main_Helper` is created when the file loads. The constructor adds 
 * a filter to merge additional meta keys into the export blacklist. Many of the 
 * methods are static and can be called without instantiating the class again. The 
 * `get_global_post` method accepts a global ID string, caches results in object and 
 * transient caches and uses `Remote_Operations` to fetch posts from remote networks 
 * when necessary. It returns a `Synced_Post` object or `null` and applies a filter to 
 * allow customisation of the result. The companion `prepare_global_post_for_import` 
 * method resolves all dependent posts for a given global ID and merges query 
 * arguments with options stored in post meta. On remote networks it delegates to 
 * `Remote_Operations` to fetch prepared data.
 * 
 * The helper also provides functions to derive and interpret global IDs. The 
 * `get_gid` method retrieves a global ID for a post using a filter for customisation, 
 * while `get_post_id_by_gid` finds a local post ID by global ID. The `explode_gid` 
 * method splits a global ID into blog ID, post ID and site URL components and exposes 
 * filters to adjust each part. Aggregation functions such as `get_all_global_posts` 
 * and `get_all_network_posts` return arrays of global posts from both the local 
 * multisite network and connected remote networks, using caching and remote operations 
 * to improve performance. Dozens of additional methods handle tasks like switching 
 * blogs, retrieving network URLs, collecting blog lists and creating connection maps.  
 * Because these functions interact closely with WordPress multisite APIs and remote 
 * requests, you should exercise care when using them and ensure that network 
 * connections and caches are correctly configured.
 */

namespace Contentsync;

use \Contentsync\Connections\Remote_Operations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

new Main_Helper();
class Main_Helper {

	public function __construct() {
		add_filter( 'contentsync_export_blacklisted_meta', array( $this, 'add_blacklisted_meta' ) );

		// add_filter( 'wp_get_attachment_url', array( $this, '__unstable_filter_global_attachment_url' ), 10, 2 );
		
		add_filter( 'upload_dir', array( $this, 'filter_wp_upload_dir' ), 98, 1 );

		// Mark Contentsync meta keys as protected to prevent syncing by translation plugins.
		// This is the most reliable method as it hooks into WordPress core.
		add_filter( 'is_protected_meta', array( $this, 'protect_contentsync_meta_keys' ), 99, 2 );

		// Polylang: Exclude Contentsync meta keys from being synced between translations.
		// This is a fallback in case is_protected_meta doesn't catch everything.
		add_filter( 'pll_copy_post_metas', array( $this, 'exclude_contentsync_metas_from_polylang_sync' ), 99, 1 );
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
	 * @return \Synced_Post|null
	 */
	public static function get_global_post( $gid ) {

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
			self::switch_to_blog( $blog_id );

			$status = get_post_meta( $post_id, 'synced_post_status', true );

			if ( $status === 'root' ) {
				$post = new_synced_post( $post_id );

				/**
				 * Set object cache for local post.
				 */
				wp_cache_set( 'gid_' . $gid, $post, 'synced_posts' );
			}

			self::restore_blog();
		}
		// remote post
		else {
			$response = Remote_Operations::get_remote_global_post( $site_url, $gid );
			if ( $response ) {
				$post = new_synced_post( $response );

				/**
				 * Set persistent cache for remote post, with 10 minutes expiration.
				 */
				set_transient( 'gid_' . $gid, $post, 10 * MINUTE_IN_SECONDS );
			}
		}

		/**
		 * Filter to modify the global post object before returning.
		 * 
		 * This filter allows developers to customize the global post object
		 * that is retrieved by GID, enabling modifications to post data,
		 * structure, or additional processing before the post is returned.
		 * 
		 * @filter contentsync_get_global_post
		 * 
		 * @param WP_Post|null $post The global post object or null if not found.
		 * @param string      $gid  The global ID of the post.
		 * 
		 * @return WP_Post|null The modified global post object or null.
		 */
		return apply_filters( 'contentsync_get_global_post', $post ?? null, $gid );
	}

	/**
	 * Get a global post with all it's dependant posts.
	 *
	 * @param string $gid
	 *
	 * @return WP_Post[]|null
	 */
	public static function prepare_global_post_for_import( $gid, $args = array() ) {

		list( $blog_id, $post_id, $site_url ) = self::explode_gid( $gid );
		if ( $post_id === null ) {
			return null;
		}

		$posts = null;

		// network post
		if ( empty( $site_url ) ) {

			self::switch_to_blog( $blog_id );
			$status = get_post_meta( $post_id, 'synced_post_status', true );

			if ( $status === 'root' ) {
				$post = new_synced_post( $post_id );
				if ( $post ) {
					$args = array_merge( self::get_contentsync_meta( $post, 'contentsync_options' ), $args );

					if ( $post->post_type === 'tp_posttypes' && $args['whole_posttype'] ) {
						$args['query_args'] = array(
							'meta_query'  => array(
								array(
									'key'     => 'synced_post_status',
									'value'   => 'root',
									'compare' => 'LIKE',
								)
							)
						);
					}

					$root_post = self::call_post_export_func( 'export_post', $post_id, $args );
					$posts     = self::call_post_export_func( 'get_all_posts' );
				}
			}

			self::restore_blog();
		}
		// remote post
		else {
			$posts = Remote_Operations::prepare_remote_global_post( $site_url, $gid );
		}

		return $posts ? (array) $posts : null;
	}

	/**
	 * Get the global ID.
	 *
	 * @param WP_Post|string $post  Preparred WP_Post Object or post ID.
	 *
	 * @return string|bool          Global ID on success, false on failure.
	 */
	/**
	 * Get the global ID.
	 *
	 * @param WP_Post|string $post  Preparred WP_Post Object or post ID.
	 *
	 * @return string|bool          Global ID on success, false on failure.
	 */
	public static function get_gid( $post ) {
		/**
		 * Filter to modify the global ID before returning.
		 * 
		 * This filter allows developers to customize how the global ID
		 * is retrieved or formatted, enabling modifications to the ID
		 * structure or additional processing before it's returned.
		 * 
		 * @filter contentsync_get_gid
		 * 
		 * @param string|bool $gid  The global ID or false if not found.
		 * @param WP_Post|string $post The post object or post ID.
		 * 
		 * @return string|bool The modified global ID or false.
		 */
		return apply_filters(
			'contentsync_get_gid',
			self::get_contentsync_meta( $post, 'synced_post_id' ),
			$post
		);
	}

	/**
	 * Get a WP_Post ID by global ID.
	 * 
	 * @param string $gid     eg. '1-1234' or '1-1234-http://example.com'
	 * 
	 * @return int            Post ID on success, 0 on failure.
	 */
	public static function get_post_id_by_gid( $gid ) {
		$result = self::get_posts( array(
			'posts_per_page' => 1,
			'post_type'      => 'any',
			'meta_key'       => 'synced_post_id',
			'meta_value'     => $gid,
			'fields'         => 'ids',
		) );
		return $result ? $result[0] : 0;
	}

	/**
	 * Get global ID args.
	 *
	 * @param string $gid
	 *
	 * @return array    array( 0 => {blog_id}, 1 => {post_id}, 2 => {site_url} )
	 */
	public static function explode_gid( $gid ) {

		$default = array(
			0 => null,
			1 => null,
			2 => null,
		);

		if ( is_string( $gid ) && strpos( $gid, '-' ) !== false ) {
			$exploded = array_replace( $default, explode( '-', $gid, 3 ) );
		} else {
			$exploded = $default;
		}

		/**
		 * Filter to modify the blog ID component of the exploded GID.
		 * 
		 * @filter contentsync_explode_gid_blog_id
		 * 
		 * @param string|null $blog_id The blog ID component of the GID.
		 * 
		 * @return string|null The modified blog ID component.
		 */
		$exploded[0] = apply_filters( 'contentsync_explode_gid_blog_id', $exploded[0] );
		
		/**
		 * Filter to modify the post ID component of the exploded GID.
		 * 
		 * @filter contentsync_explode_gid_post_id
		 * 
		 * @param string|null $post_id The post ID component of the GID.
		 * 
		 * @return string|null The modified post ID component.
		 */
		$exploded[1] = apply_filters( 'contentsync_explode_gid_post_id', $exploded[1] );
		
		/**
		 * Filter to modify the site URL component of the exploded GID.
		 * 
		 * @filter contentsync_explode_gid_site_url
		 * 
		 * @param string|null $site_url The site URL component of the GID.
		 * 
		 * @return string|null The modified site URL component.
		 */
		$exploded[2] = apply_filters( 'contentsync_explode_gid_site_url', $exploded[2] );

		/**
		 * Filter to modify the complete exploded GID array.
		 * 
		 * This filter allows developers to customize the complete array
		 * of exploded GID components after individual component filtering.
		 * 
		 * @filter contentsync_explode_gid
		 * 
		 * @param array $exploded Array containing [blog_id, post_id, site_url].
		 * 
		 * @return array The modified exploded GID array.
		 */
		return apply_filters( 'contentsync_explode_gid', $exploded );
	}

	/**
	 * Get all global posts for this multisite including connections
	 *
	 * @param string|array $query   Search query term or array of query args.
	 * @param string       $network_url   Filter by network url ('here' only retrieves posts from this network)
	 *
	 * @return array of all global posts
	 */
	public static function get_all_global_posts( $query = null, $network_url = null ) {

		$all_global_posts = array();
		$current_network  = self::get_network_url();

		// get network posts
		if ( empty( $network_url ) || $network_url === 'here' || $network_url === $current_network ) {
			$all_global_posts = self::get_all_network_posts( $query );
		}

		// get remote posts
		foreach ( self::get_content_connections() as $site_url => $connection ) {

			// continue if filter is different
			if ( ! empty( $network_url ) && $network_url !== $site_url ) {
				continue;
			}

			// don't add posts from the same network
			if ( self::get_nice_url( $site_url ) === $current_network ) {
				continue;
			}

			// load from persistant cache
			$_cache_key = "remote_posts_$site_url" . ( empty( $query ) ? '' : '_' . md5( serialize( $query ) ) );
			$_cache     = get_transient( $_cache_key );
			if ( is_array( $_cache ) && count( $_cache ) ) {
				$all_global_posts = array_merge( $all_global_posts, $_cache );
			}
			// load new
			else {
				$posts = Remote_Operations::get_remote_global_posts( $connection, $query );
				if ( is_array( $posts ) && count( $posts ) ) {

					// set persistent cache
					set_transient( $_cache_key, $posts, HOUR_IN_SECONDS );
					
					$all_global_posts = array_merge( $all_global_posts, $posts );
				}
			}
		}

		/**
		 * Filter to modify the complete array of global posts before returning.
		 * 
		 * This filter allows developers to customize the complete array of global
		 * posts retrieved from both network and remote sources, enabling
		 * modifications to post data, filtering, or additional processing.
		 * 
		 * @filter contentsync_get_all_global_posts
		 * 
		 * @param array $all_global_posts Array of all global posts.
		 * @param string|array $query Search query or query arguments.
		 * @param string $network_url Network URL filter.
		 * 
		 * @return array Modified array of all global posts.
		 */
		return apply_filters( 'contentsync_get_all_global_posts', $all_global_posts, $query, $network_url );
	}

	/**
	 * Get all global posts of this multisite
	 *
	 * @param string|array $query   Search query term or array of query args.
	 *
	 * @return array of all network posts
	 */
	public static function get_all_network_posts( $query = null ) {

		$cache_key = empty( $query ) ? 'all_network_posts' : 'all_network_posts_' . md5( serialize( $query ) );

		// check cache (not persistent, as we need to update it frequently)
		if ( $cache = wp_cache_get( $cache_key, 'synced_posts' ) ) {
			return $cache;
		}

		// loop through all blogs and get the posts
		$all_network_posts = array();
		foreach ( self::get_all_blogs() as $blog_id => $blog_args ) {
			$posts = self::get_blog_global_posts( $blog_id, 'root', $query );

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
		 * @filter contentsync_get_all_network_posts
		 * 
		 * @param array $all_network_posts Array of all network posts.
		 * @param string|array $query Search query or query arguments.
		 * 
		 * @return array Modified array of all network posts.
		 */
		return apply_filters( 'contentsync_get_all_network_posts', $all_network_posts, $query );
	}

	/**
	 * Get all global posts of a certain blog
	 *
	 * @param int          $blog_id       ID of the blog, defaults to the current blog.
	 * @param string       $filter_status Filter 'synced_post_status' meta. Leave empty for no filtering.
	 *                                    Either 'root' or 'linked'.
	 * @param string|array $query         Search query term or array of query args.
	 *
	 * @return array of posts
	 */
	public static function get_blog_global_posts( $blog_id = '', $filter_status = '', $query = null ) {

		self::switch_to_blog( $blog_id );

		$post_type = 'any';
		if ( empty( $post_type ) || $post_type === 'any' ) {
			$post_type = self::is_rest_request() ? 'any' : self::call_post_export_func( 'get_supported_post_types' );
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

		$posts = self::extend_post_object( self::get_posts( $args ) );

		self::restore_blog();

		/**
		 * Filter to modify the array of blog global posts before returning.
		 * 
		 * This filter allows developers to customize the array of global
		 * posts retrieved from a specific blog, enabling modifications
		 * to post data, filtering, or additional processing.
		 * 
		 * @filter contentsync_get_blog_global_posts
		 * 
		 * @param array $posts Array of global posts from the blog.
		 * @param int $blog_id The blog ID.
		 * @param string $filter_status The filter status used.
		 * @param string|array $query Search query or query arguments.
		 * 
		 * @return array Modified array of blog global posts.
		 */
		return apply_filters( 'contentsync_get_blog_global_posts', $posts, $blog_id, $filter_status, $query );
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
			$post_type = self::is_rest_request() ? 'any' : self::call_post_export_func( 'get_supported_post_types' );
		}

		$result = self::get_posts(
			array(
				'posts_per_page'   => 1,
				'post_type'        => $post_type,
				'meta_key'         => 'synced_post_id',
				'meta_value'       => $gid,
				'post_status'      => array( 'publish', 'pending', 'draft', 'auto-draft', 'future', 'private', 'inherit' )
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
	 * Find similar global posts.
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
	public static function get_similar_global_posts( $post ) {

		$found   = array();
		$blog_id = get_current_blog_id();
		$net_url = self::get_network_url();

		if ( ! isset( $post->post_name ) ) {
			return $found;
		}

		// we're replacing ending numbers after a dash (footer-2 becomes footer)
		$regex     = '/\-[0-9]{0,2}$/';
		$post_name = preg_replace( $regex, '', $post->post_name );

		// find and list all similar posts
		$all_posts = self::get_all_global_posts();

		foreach ( $all_posts as $global_post ) {

			$global_post = new_synced_post( $global_post );
			$gid         = self::get_contentsync_meta( $global_post, 'synced_post_id' );

			list( $_blog_id, $_post_id, $_net_url ) = self::explode_gid( $gid );

			// exclude posts from other posttypes
			if ( $post->post_type !== $global_post->post_type ) {
				continue;
			}
			// exclude posts from current blog
			elseif ( empty( $_net_url ) && $blog_id == $_blog_id ) {
				continue;
			}
			// exclude if a connection to this site is already established
			elseif (
				( empty( $_net_url ) && isset( $global_post->meta['contentsync_connection_map'][ $blog_id ] ) ) ||
				( ! empty( $_net_url ) && isset( $global_post->meta['contentsync_connection_map'][ $net_url ][ $blog_id ] ) )
			) {
				continue;
			}

			// check the post_name for similarity
			$name = preg_replace( $regex, '', $global_post->post_name );
			similar_text( $post_name, $name, $percent ); // store percentage in variable $percent

			// list, if similarity is at least 90%
			if ( intval( $percent ) >= 90 ) {

				// make sure to get the post links
				if ( empty( $global_post->post_links ) ) {

					// retrieve the post including all post_links from url
					if ( ! empty( $_net_url ) ) {
						$global_post = new_synced_post( self::get_global_post( $gid ) );
					} else {
						$global_post->post_links = self::get_local_post_links( $_blog_id, $_post_id );
					}
				}

				// add the post to the response
				$found[ $gid ] = $global_post;
			}
		}

		return apply_filters( 'contentsync_get_similar_global_posts', $found, $post );
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
	 *                          POST CONNECTION MAP
	 * =================================================================
	 */

	/**
	 * Add a new internal post connection to a global post
	 *
	 * @param string $gid           Global ID of the root post.
	 * @param int    $blog_id       Blog ID of the imported post.
	 * @param int    $post_id       Post ID of the imported post.
	 * @param string $post_site_url Site url (only via remote requests).
	 *
	 * @return bool
	 */
	public static function add_post_connection_to_connection_map( $gid, $blog_id, $post_id, $post_site_url = '' ) {
		return self::add_or_remove_post_connection_from_connection_map(
			$gid,
			array(
				$blog_id => self::create_post_connection_map_array( $blog_id, $post_id ),
			),
			true,
			$post_site_url
		);
	}

	/**
	 * Remove a new internal connection from a global post
	 *
	 * @param string $gid           Global ID of the root post.
	 * @param int    $blog_id       Blog ID of the imported post.
	 * @param int    $post_id       Post ID of the imported post.
	 * @param string $post_site_url Site url (only via remote requests).
	 *
	 * @return bool
	 */
	public static function remove_post_connection_from_connection_map( $gid, $blog_id, $post_id, $post_site_url = '' ) {
		return self::add_or_remove_post_connection_from_connection_map(
			$gid,
			array(
				$blog_id => self::create_post_connection_map_array( $blog_id, $post_id ),
			),
			false,
			$post_site_url
		);
	}

	/**
	 * Log a new internal connection to a global post
	 *
	 * @param string $gid           Global ID of the root post.
	 * @param array  $args          array( blog_id => post_connection_array, ... )
	 * @param bool   $add           Whether to add or remove the post connection.
	 * @param string $post_site_url Site url (only via remote requests).
	 *
	 * @return bool
	 */
	public static function add_or_remove_post_connection_from_connection_map( $gid, $args, $add = true, $post_site_url = '' ) {

		list( $blog_id, $post_id, $site_url ) = self::explode_gid( $gid );
		if ( $post_id === null ) {
			return false;
		}

		$result = false;

		// network post
		if ( ! $site_url ) {
			self::switch_to_blog( $blog_id );
			$connection_map = $old_connection = self::get_post_connection_map( $post_id );
			if ( ! is_array( $connection_map ) ) {
				$connection_map = array();
			}

			foreach ( $args as $key => $val ) {

				// add the connection
				if ( $add ) {
					if ( empty( $post_site_url ) ) {
						$connection_map[ $key ] = $val;
					} else {
						if ( ! isset( $connection_map[ $post_site_url ] ) ) {
							$connection_map[ $post_site_url ] = array();
						}
						$connection_map[ $post_site_url ][ $key ] = $val;
					}
				}

				// remove the connection
				else {
					if ( empty( $post_site_url ) ) {
						unset( $connection_map[ $key ] );
					} else {
						if ( isset( $connection_map[ $post_site_url ] ) ) {
							unset( $connection_map[ $post_site_url ][ $key ] );
							if ( empty( $connection_map[ $post_site_url ] ) ) {
								unset( $connection_map[ $post_site_url ] );
							}
						}
					}
				}
			}

			if ( $connection_map == $old_connection ) {
				$result = true;
			} else {
				$result = update_post_meta( $post_id, 'contentsync_connection_map', $connection_map );
			}
			self::restore_blog();
		}
		// remote post
		else {
			// the network url is the current network, as we just imported a post here
			$post_site_url = self::get_network_url();
			$result        = Remote_Operations::update_remote_post_connection( $site_url, $gid, $args, $add, $post_site_url );
		}

		return (bool) $result;
	}

	/**
	 * Get connections of a local root post.
	 *
	 * @param int $post_id
	 *
	 * @return array   Array of all post connections
	 * @example
	 * array(
	 *   $blog_id => array(
	 *     'post_id' => $post_id,
	 *     'edit'    => $edit_url,
	 *     'blog'    => $blog_url,
	 *     'nice'    => $nice_url,
	 *   ),
	 *   $network_url => array(
	 *     $blog_id => array(
	 *       'post_id' => $post_id,
	 *       'edit'    => $edit_url,
	 *       'blog'    => $blog_url,
	 *       'nice'    => $nice_url,
	 *      ),
	 *   ),
	 *   ...
	 * )
	 */
	public static function get_post_connection_map( $post_id ) {

		$connection_map = get_post_meta( $post_id, 'contentsync_connection_map', true );
		if ( ! $connection_map || empty( $connection_map ) ) {

			// check if the post is an object or array
			if ( is_object( $post_id ) || is_array( $post_id ) ) {
				$post = (object) $post_id;
				if ( isset( $post->meta ) ) {
					$post->meta = (array) $post->meta;
					$connection_map      = isset( $post->meta[ 'contentsync_connection_map' ] ) ? $post->meta[ 'contentsync_connection_map' ] : null;
					if ( is_array( $connection_map ) && isset( $connection_map[0] ) ) {
						$connection_map = $connection_map[0];
					}
				}
			}
			else {
				return array();
			}
		}

		if ( is_string( $connection_map ) ) {
			$connection_map = maybe_unserialize( $connection_map );
		} elseif ( is_object( $connection_map ) ) {
			$connection_map = json_decode( json_encode( $connection_map ), true );
		}

		if ( ! is_array( $connection_map ) ) {
			return array();
		}

		// // Example post_meta value:
		// array(
		// old way, deprecated but still supported
		// $blog_id => $post_id,
		// new way, @since 2.0
		// $blog_id => array(
		// 'post_id' => $post_id,
		// 'edit'    => $edit_url,
		// 'blog'    => $blog_url,
		// 'nice'    => $nice_url,
		// ),
		// remote connections, @since 2.0
		// $network_url => array(
		// $blog_id => array(
		// 'post_id' => $post_id,
		// 'edit'    => $edit_url,
		// 'blog'    => $blog_url,
		// 'nice'    => $nice_url,
		// )
		// )
		// );

		foreach ( $connection_map as $blog_id_or_net_url => $post_id_or_array ) {

			// modify remote connection array
			if ( is_array( $post_id_or_array ) && ! is_numeric( $blog_id_or_net_url ) ) {
				foreach ( $post_id_or_array as $_blog_id => $_post_id_or_array ) {
					if ( ! is_array( $_post_id_or_array ) ) {
						// fallback array if no info is set
						$connection_map[ $blog_id_or_net_url ][ $_blog_id ] = array(
							'post_id' => $_post_id_or_array,
							'edit'    => $blog_id_or_net_url,
							'blog'    => $blog_id_or_net_url,
							'nice'    => $blog_id_or_net_url,
						);
					}
				}
			}
			// modify old way to save a connection
			elseif ( is_numeric( $post_id_or_array ) ) {
				$connection_map[ $blog_id_or_net_url ] = self::create_post_connection_map_array( $blog_id_or_net_url, $post_id );
			}
		}

		return $connection_map ?? array();
	}

	/**
	 * Create single post connection array with post_id, edit, blog, nice.
	 *
	 * @param int $blog_id
	 * @param int $post_id
	 *
	 * @return array   The new post connection array
	 * @example
	 * array(
	 *     'post_id' => $post_id,
	 *     'edit'    => $edit_url,
	 *     'blog'    => $blog_url,
	 *     'nice'    => $nice_url
	 * )
	 */
	public static function create_post_connection_map_array( $blog_id, $post_id ) {
		return is_array( $post_id ) ? $post_id : array_merge(
			array(
				'post_id' => $post_id,
			),
			self::get_local_post_links( $blog_id, $post_id )
		);
	}

	/**
	 * Convert connection map to destination ids
	 *
	 * @param array $connection_map
	 *
	 * @example
	 * array(
	 *   $blog_id => array(
	 *     'post_id' => $post_id,
	 *     'edit'    => $edit_url,
	 *     'blog'    => $blog_url,
	 *     'nice'    => $nice_url,
	 *   ),
	 *   $network_url => array(
	 *     $blog_id => array(
	 *       'post_id' => $post_id,
	 *       'edit'    => $edit_url,
	 *       'blog'    => $blog_url,
	 *       'nice'    => $nice_url,
	 *      ),
	 *   ),
	 *   ...
	 *
	 * @return array $destination_ids
	 * @example
	 * array(
	 *    '2',
	 *    '3|https://remote.site.com'
	 * )
	 */
	public static function convert_connection_map_to_destination_ids( $connection_map ) {

		$destination_ids = array();

		if ( ! $connection_map ) {
			return $destination_ids;
		}

		foreach ( $connection_map as $blog_id_or_net_url => $post_array_or_blog_array ) {
			if ( is_numeric( $blog_id_or_net_url ) ) {
				$destination_ids[] = $blog_id_or_net_url;
			}
			else {
				$network_url = $blog_id_or_net_url;
				
				foreach ( $post_array_or_blog_array as $blog_id => $post_array ) {
					$destination_ids[] = $blog_id . '|' . $network_url;
				}
			}
		}

		return $destination_ids;
	}

	/**
	 * Get urls of a local post
	 *
	 * @param int $blog_id
	 * @param int $post_id
	 *
	 * @return array (edit, blog, nice)
	 */
	public static function get_local_post_links( $blog_id, $post_id ) {

		self::switch_to_blog( $blog_id );
		$edit_url = self::get_edit_post_link( $post_id );
		self::restore_blog();

		$blog_url = get_site_url( $blog_id );
		$nice_url = strpos( $blog_url, '://' ) !== false ? explode( '://', $blog_url )[1] : $blog_url;
		return array(
			'edit' => $edit_url,
			'blog' => $blog_url,
			'nice' => $nice_url,
		);
	}

	/**
	 * Get edit, blog and nice url of a global post by GID.
	 * 
	 * @param string $gid
	 * 
	 * @return array
	 */
	public static function get_post_links_by_gid( $gid ) {

		$post_links = array();

		$global_post = self::get_global_post($gid);

		if ( ! $global_post ) {
			return $post_links;
		}

		list( $root_blog_id, $root_post_id, $root_net_url ) = self::explode_gid( $gid );

		// local network post
		if ( empty( $root_net_url ) ) {
			$post_links = self::get_local_post_links($root_blog_id, $root_post_id);
		}
		// remote post
		else {
			if ( $global_post && $global_post->post_links ) {
				$post_links = (array) $global_post->post_links;
			}
			else {
				$post_links = array(
					"edit" => $root_net_url,
					"blog" => $root_net_url,
					"nice" => $root_net_url,
				);
			}
		}
		return $post_links;
	}

	/**
	 * Get all imported version of remote posts on this multisite.
	 *
	 * @param string $gid           Global ID of the root post.
	 *
	 * @return array|false          @see create_post_connection_map_array()
	 */
	public static function get_network_remote_connection_map_by_gid( $gid ) {

		// loop through all blogs and get the posts
		$connection_map = array();
		foreach ( self::get_all_blogs() as $blog_id => $blog_args ) {

			self::switch_to_blog( $blog_id );
			$post = self::get_local_post_by_gid( $gid );
			if ( $post ) {
				$connection_map[ $blog_id ] = self::create_post_connection_map_array( $blog_id, $post->ID );
			}
			self::restore_blog();
		}

		return array(
			self::get_network_url() => $connection_map,
		);
	}

	/**
	 * Check for missing or deleted connections of a root post.
	 *
	 * @note   This function is usually called via js ajax.
	 *         JS function: contentsync.checkRootConnections();
	 *
	 * @update The function received a huge update in 1.7.0. It now checks for
	 *         missing connections on all sites and not only on the current site.
	 *         It also checks for missing connections on remote sites.
	 *
	 * @param string $post_id
	 *
	 * @return bool
	 */
	public static function check_connection_map( $post_id ) {

		$status = self::get_contentsync_meta( $post_id, 'synced_post_status' );
		if ( $status !== 'root' ) {
			return array(
				'status' => 'not_root_post',
				'text'   => __( "This is not the source post.", 'contentsync' ),
			);
		}

		$gid            = self::get_gid( $post_id );
		$connection_map = self::get_post_connection_map( $post_id );
		$cur_net_url    = self::get_network_url();
		$return         = array();

		$updated_post_connection_map = array();

		/**
		 * get local connected posts
		 *
		 * @since 1.7.5
		 */
		$local_connected_posts = self::get_all_local_post_connections( $gid );
		if ( ! empty( $local_connected_posts ) ) {
			$updated_post_connection_map = $local_connected_posts;
		}

		/**
		 * get remote connected posts
		 *
		 * @since 1.7.5
		 */
		$remote_connected_posts = array();
		foreach ( self::get_content_connections() as $site_url => $connected_site ) {
			$remote_connected_posts = Remote_Operations::get_all_remote_connected_posts( $connected_site, $gid );
			if ( ! empty( $remote_connected_posts ) ) {
				$remote_connected_posts = (array) $remote_connected_posts;
				$updated_post_connection_map[ $site_url ] = array_map(
					function( $item ) {
						return (array) $item;
					},
					$remote_connected_posts
				);
			}
		}

		// check if any of the connected posts were deleted
		if ( $connection_map && is_array( $connection_map ) && count( $connection_map ) ) {
			foreach ( $connection_map as $_blog_id => $_post_con ) {

				/**
				 * If posts from this local connection are not found, we delete the
				 * connection.
				 */
				if ( is_numeric( $_blog_id ) ) {

					/**
					 * @since 1.7.5
					 */
					if ( ! isset( $local_connected_posts[ intval( $_blog_id ) ] ) ) {
						$return[] = sprintf(
							__( "An orphaned post connection (%s) was deleted.", 'contentsync' ),
							"$_blog_id-{$_post_con['post_id']}"
						);
					}

					/**
					 * @deprecated 1.7.0
					 */
					// switch_to_blog( $_blog_id );
					// $_post_id = $_post_con['post_id'];
					// if ( ! get_post( $_post_id ) || self::get_gid( $_post_id ) != $gid ) {
					// self::remove_post_connection_from_connection_map( $gid, $_blog_id, $_post_id );
					// $return[] = sprintf(
					// __( "An orphaned post connection (%s) was deleted.", 'contentsync' ),
					// "$_blog_id-$_post_id"
					// );
					// }
					// self::restore_blog();
				} else {

					$rem_net_url = $_blog_id;

					/**
					 * If posts from this remote connection are not found, we keep them
					 * around for now. Otherwise we would have to delete all posts from
					 * this remote connection, even if the connection maybe only went
					 * down for a short time.
					 *
					 * @since 1.7.5
					 */
					if ( ! isset( $updated_post_connection_map[ $rem_net_url ] ) ) {
						$updated_post_connection_map[ $rem_net_url ] = $_post_con;
						$return[]                                    = sprintf(
							__( "Some orphaned remote post links (website: %s) were not found because no connection were found to the site. The links were not deleted for the time being.", 'contentsync' ),
							$rem_net_url
						);
					}
					/**
					 * If posts from this remote connection are found, we check if the
					 * post is still connected to the root post. If not, we delete the
					 * connection.
					 *
					 * @since 1.7.5
					 */
					else {
						$rem_gid = get_current_blog_id() . '-' . $post_id . '-' . $cur_net_url;
						foreach ( $_post_con as $rem_blog_id => $rem_post_con ) {
							if ( ! isset( $updated_post_connection_map[ $rem_net_url ][ intval($rem_blog_id) ] ) ) {
								$return[] = sprintf(
									__( "An orphaned post connection (%s) was deleted.", 'contentsync' ),
									"$rem_net_url: $rem_blog_id-{$rem_post_con['post_id']}"
								);
							}
						}
					}
				}
			}
		}

		/**
		 * @deprecated 1.7.0
		 */
		// // check other blogs for orphaned connected posts
		// $other_blogs = array_diff(
		// array_keys( self::get_all_blogs() ),
		// is_array( $connection_map ) ? array_keys( $connection_map ) : array(),
		// array( get_current_blog_id() )
		// );
		// if ( is_array( $other_blogs ) && count( $other_blogs ) ) {
		// foreach ( $other_blogs as $_blog_id ) {
		// switch_to_blog( $_blog_id );
		// $_post = self::get_local_post_by_gid( $gid );
		// if ( $_post ) {
		// $_post_id = $_post->ID;
		// $status   = get_post_meta( $_post_id, 'synced_post_status', true );
		// if ( $status === 'linked' ) {
		// self::add_post_connection_to_connection_map( $gid, $_blog_id, $_post_id );
		// $return[] = sprintf(
		// __( 'Eine fehlende Post Verknüpfung (%s) wurde wiederhergestellt.', 'contentsync' ),
		// "$_blog_id-$_post_id"
		// );
		// }
		// }
		// self::restore_blog();
		// }
		// }

		// update post meta
		if ( $connection_map != $updated_post_connection_map ) {
			$result   = update_post_meta( $post_id, 'contentsync_connection_map', $updated_post_connection_map );
			$return[] = $result ? sprintf(
				__( "The post connections have been updated.", 'contentsync' )
			) : sprintf(
				__( "The post connections could not be updated.", 'contentsync' )
			);
		}

		// return message to AJAX
		return empty( $return ) ? array(
			'status' => 'ok',
			'text'   => __( "No missing connections.", 'contentsync' ),
		) : array(
			'status' => 'connections_repaired',
			'text'   => implode( ' ', $return ),
		);
	}

	/**
	 * Get all connected posts of a global post on this network
	 * by using a multisite query.
	 *
	 * @since 1.7.5
	 *
	 * @param string $gid
	 *
	 * @return array[] Array of post connections, similar to Synced_Post, has:
	 *             $connection['post_id']
	 *             $connection['edit']
	 *             $connection['blog']
	 *             $connection['nice']
	 */
	public static function get_all_local_post_connections( $gid ) {

		$post_connections = array();
		$connected_posts  = self::get_all_local_linked_posts( $gid );

		if ( empty( $connected_posts ) ) {
			return array();
		}

		foreach ( $connected_posts as $result ) {
			$blog_id                      = intval( $result->blog_id );
			$post_connections[ $blog_id ] = self::convert_synced_post_into_post_connection( $result );
		}

		return $post_connections;
	}

	/**
	 * Get all connected posts of a global post on this network
	 * by using a multisite query.
	 *
	 * @since 1.7.5
	 *
	 * @param string $gid
	 *
	 * @return object[] Array of $wpdb results, simlar to Synced_Post, has:
	 *                  $result->ID
	 *                  $result->site_url
	 *                  $result->blog_id
	 */
	public static function get_all_local_linked_posts( $gid ) {

		global $wpdb;

		list( $root_blog_id, $root_post_id, $root_site_url ) = self::explode_gid( $gid );

		$network_url = self::get_network_url();

		// build sql query
		$results = array();
		foreach ( self::get_all_blogs() as $blog_id => $blog_args ) {
			$prefix   = $blog_args['prefix'];
			$site_url = $blog_args['site_url'];
			$query    = "
				SELECT ID, post_name, post_type, post_status, post_title, post_date, meta_value as synced_post_id, '{$blog_id}' as blog_id, '{$site_url}' as site_url, '{$network_url}' as network_url
				FROM {$prefix}posts
				LEFT JOIN {$prefix}postmeta ON {$prefix}posts.ID = {$prefix}postmeta.post_id

				WHERE {$prefix}postmeta.meta_key = 'synced_post_id'
					AND {$prefix}postmeta.meta_value = '{$gid}'
				AND {$prefix}posts.post_status <> 'trash'
			";
			$result   = $wpdb->get_results( $query );

			if ( count( $result ) > 0 ) {
				$results = array_merge( $results, $result );
			}
		}

		if ( empty( $results ) ) {
			return array();
		}

		// filter out the root post
		if ( empty( $root_site_url ) ) {
			$results = array_filter(
				$results,
				function( $post ) use ( $root_blog_id, $root_post_id, $root_site_url ) {
					return $post->blog_id !== $root_blog_id || $post->ID !== $root_post_id;
				}
			);
		}

		return $results;
	}

	/**
	 * Convert the raw $wpdb result into a post connection array.
	 *
	 * @since 1.7.5
	 *
	 * @param Synced_Post needs to have:
	 *               $result->ID
	 *               $result->site_url
	 *               $result->blog_id
	 *
	 * @return array $connection
	 *               $connection['post_id']
	 *               $connection['edit']
	 *               $connection['blog']
	 *               $connection['nice']
	 */
	public static function convert_synced_post_into_post_connection( $result ) {

		// $result is a valid $wbdb result
		if ( is_object( $result ) && isset( $result->ID ) && isset( $result->site_url ) ) {
			$post_id  = $result->ID;
			$site_url = $result->site_url;
			$edit_url = esc_url( trailingslashit( $site_url ) . 'wp-admin/post.php?post=' . $post_id . '&action=edit' );
			$blog_url = esc_url( trailingslashit( $site_url ) );
			$nice_url = strpos( $blog_url, '://' ) !== false ? explode( '://', $blog_url )[1] : $blog_url;
			return array(
				'post_id' => $post_id,
				'edit'    => $edit_url,
				'blog'    => $blog_url,
				'nice'    => $nice_url,
				// 'blog_id' => $result->blog_id,
			);
		} elseif ( is_array( $result ) ) {
			return $result;
		}

		return array();
	}


	/**
	 * =================================================================
	 *                          META OPTIONS
	 * =================================================================
	 */

	/**
	 * Returns list of contentsync meta keys
	 *
	 * @return array $meta_keys
	 */
	public static function contentsync_meta() {
		return array(
			'synced_post_status',
			'synced_post_id',
			'contentsync_connection_map',
			'contentsync_options',
			'contentsync_canonical_url',
			// 'contentsync_nested',
		);
	}

	/**
	 * Returns list of blacklisted contentsync meta keys
	 *
	 * @return array $meta_keys
	 */
	public static function blacklisted_meta() {
		return array(
			'contentsync_connection_map',
			'contentsync_options',
			// 'contentsync_nested',
		);
	}

	/**
	 * Add blacklisted meta for Post_Export class.
	 *
	 * @filter 'contentsync_export_blacklisted_meta'.
	 *
	 * @return array $meta_keys
	 */
	public function add_blacklisted_meta( $meta_values ) {
		return array_merge( $meta_values, self::blacklisted_meta() );
	}

	/**
	 * Mark Contentsync meta keys as protected to prevent syncing by translation plugins.
	 *
	 * WordPress considers meta keys starting with '_' as protected by default.
	 * This filter allows us to mark our contentsync_ prefixed meta keys as protected too,
	 * which prevents Polylang (and other plugins) from syncing them.
	 *
	 * @filter is_protected_meta
	 *
	 * @param bool   $protected Whether the meta key is protected.
	 * @param string $meta_key  The meta key being checked.
	 * @return bool Whether the meta key should be protected.
	 */
	public function protect_contentsync_meta_keys( $protected, $meta_key ) {
		if ( in_array( $meta_key, self::contentsync_meta(), true ) ) {
			return true;
		}
		return $protected;
	}

	/**
	 * Exclude Content Syncs meta keys from Polylang synchronization.
	 *
	 * Polylang syncs custom fields between translations, but Contentsync meta values
	 * are unique per post and should never be copied or synced.
	 *
	 * @filter pll_copy_post_metas
	 *
	 * @param string[] $metas List of meta keys to be synced.
	 * @return string[] Filtered list of meta keys.
	 */
	public function exclude_contentsync_metas_from_polylang_sync( $metas ) {
		return array_diff( $metas, self::contentsync_meta() );
	}

	/**
	 * get_post_meta() but with default values.
	 *
	 * @param int|WP_Post $post_id  Post ID or Preparred post object.
	 * @param string      $meta_key Key of the meta option.
	 *
	 * @return mixed
	 */
	public static function get_contentsync_meta( $post_id, $meta_key ) {
		$value = null;
		if ( is_object( $post_id ) || is_array( $post_id ) ) {
			$post = (object) $post_id;
			if ( isset( $post->meta ) ) {
				$post->meta = (array) $post->meta;
				$value      = isset( $post->meta[ $meta_key ] ) ? $post->meta[ $meta_key ] : null;
				if ( is_array( $value ) && isset( $value[0] ) ) {
					$value = $value[0];
				}
			}
		} else {
			if ( $meta_key == 'contentsync_connection_map' ) {
				$value = self::get_post_connection_map( $post_id );
			} else {
				$value = get_post_meta( $post_id, $meta_key, true );
			}
		}
		$default = self::default_meta_values()[ $meta_key ];
		if ( ! $value ) {
			$value = $default;
		} elseif ( is_array( $default ) ) {
			$value = (array) $value;
		}
		return $value;
	}

	/**
	 * Return the default export options
	 */
	public static function default_export_args() {
		return self::default_meta_values()['contentsync_options'];
	}

	/**
	 * @return array $default_values
	 */
	public static function default_meta_values() {
		return array(
			'synced_post_status'    => null,
			'synced_post_id'        => null,
			'contentsync_connection_map' => array(),
			'contentsync_options'        => array(
				'append_nested'   => true,
				'whole_posttype'  => false,
				'all_terms'       => false,
				'resolve_menus'   => true,
				'translations'    => true,
			),
		);
	}

	/**
	 * Extend the WP_Post object by attaching contentsync post-meta & language
	 *
	 * @return WP_Post|WP_Post[]    Depends on the input.
	 */
	public static function extend_post_object( $post, $current_blog=0 ) {
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
			foreach ( self::contentsync_meta() as $meta_key ) {
				if ( $meta_key == 'contentsync_connection_map' ) {
					$meta_value = self::get_post_connection_map( $post->ID );
				} else {
					$meta_value = get_post_meta( $post->ID, $meta_key, true );
				}
				$meta[ $meta_key ] = $meta_value;
			}
			$post->meta = $meta;

			// attach language
			$post->language = self::get_post_language_code( $post );

			// attach blog id
			$post->blog_id = $current_blog ? $current_blog : get_current_blog_id();

			// attach theme used in blog
			$post->blog_theme = self::get_wp_template_theme( $post );
		}
		return $post;
	}

	/**
	 * Delete all global meta infos.
	 * Used to unimport & unexport posts.
	 */
	public static function delete_contentsync_meta( $post_id ) {
		$return = true;
		foreach ( self::contentsync_meta() as $meta_key ) {
			$result = delete_post_meta( $post_id, $meta_key );
			if ( ! $result ) {
				$return = false;
			}
		}
		return $return;
	}


	/**
	 * =================================================================
	 *                          ERROR HANDLING
	 * =================================================================
	 */

	/**
	 * Check if a global post has an error
	 *
	 * @param int|WP_Post $post     Either the post_id or the preparred post object.
	 *
	 * @return false|object         False if no error found, error object if found.
	 */
	public static function get_post_error( $post ) {
		if ( is_object( $post ) ) {
			if ( ! isset( $post->error ) || $post->error === null ) {
				$error = self::check_post_for_errors( $post );
			}
		} else {
			$error = self::check_post_for_errors( $post );
		}

		return $error;
	}

	/**
	 * Get error message
	 *
	 * @return string
	 */
	public static function get_error_message( $error ) {
		return is_object( $error ) ? $error->message : '';
	}

	/**
	 * Check if error is repaired
	 *
	 * @return bool Whether there was an error & it is repaired now.
	 */
	public static function is_error_repaired( $error ) {
		return is_object( $error ) ? $error->repaired : true;
	}

	/**
	 * Get error repaired message
	 *
	 * @return string
	 */
	public static function get_error_repaired_log( $error ) {
		return self::is_error_repaired( $error ) ? $error->log : '';
	}

	/**
	 * Check a global post for errors.
	 *
	 * @param int|WP_Post  $post        Either the post_id or the preparred post object.
	 * @param bool         $autorepair  Autorepair simple errors, such as orphaned post connections.
	 * @param bool         $repair      Repair more complex errors, this can change meta infos or delete the post.
	 *
	 * @return false|object             False when no error is found, error object otherwise:
	 *      @param string message       Description of the error.
	 *      @param bool repaired        Whether it was repaired.
	 *      @param string log           All repair logs as a single message.
	 */
	public static function check_post_for_errors( $post, $autorepair = true, $repair = false ) {

		$post = new_synced_post( $post );
		if ( ! $post ) {
			return false;
		}

		$error = (object) array(
			'message'  => '',
			'repaired' => false,
			'log'      => array(), // will be imploded on return
		);

		/**
		 * Get all the data
		 */
		$post_id      = intval( $post->ID );
		$gid          = $post->meta['synced_post_id'];
		$status       = $post->meta['synced_post_status'];
		$current_blog = get_current_blog_id();
		$blog_id      = $post->blog_id ? $post->blog_id : $current_blog;
		$cur_net_url  = self::get_network_url();

		list( $root_blog_id, $root_post_id, $root_net_url ) = self::explode_gid( $gid );
		if ( $root_post_id === null ) {
			return false;
		}

		// repair actions
		$new_status         = null;
		$restore_connection = false;
		$update_gid         = false;
		$convert_to_root    = false;
		$delete_meta        = false;
		$trash_post         = false;
		$trash_other_post   = false;
		$orphan_connections = array();

		// check the network connection
		if ( ! empty( $root_net_url ) ) {

			if ( $root_net_url == $cur_net_url ) {
				$error->message = __( "The connection refers to this website.", 'contentsync' );

				if ( $autorepair || $repair ) {
					$new_gid = $root_blog_id . '-' . $root_post_id;
					$update_gid = true;
				}
			}
			else {
				$connection = Main_Helper::call_connections_func( 'get_connection', $root_net_url );
	
				// connection doesn't exist
				if ( ! $connection ) {
					$error->message = sprintf( __( "The connection to the site %s does not exist.", 'contentsync' ), $root_net_url );
	
					if ( $autorepair || $repair ) {
						$convert_to_root = true;
					}
				}
				if ( ! $connection || ! isset( $connection['active'] ) || ! $connection['active'] ) {
					$error->message = sprintf( __( "The connection to the website %s is inactive. The content cannot be synchronized.", 'contentsync' ), $root_net_url );
	
					if ( $repair ) {
						$convert_to_root = true;
					} else {
						return $error;
					}
				}
			}
		}

		/**
		 * Get the errors
		 */

		// switch blog to prevent errors
		self::switch_to_blog( $blog_id );

		// this is a root post
		if ( $status == 'root' ) {
			if ( $root_blog_id != $blog_id || ! empty( $root_net_url ) ) {
				$error->message = __( "The post is not originally from this page.", 'contentsync' );

				if ( $repair ) {
					if ( $root_post = self::get_global_post( $gid ) ) {
						$new_status         = 'linked';
						$restore_connection = true;
					} else {
						$convert_to_root = true;
					}
				}
			} elseif ( $root_post_id != $post_id ) {
				$error->message = __( "The global post ID is linked incorrectly.", 'contentsync' );

				if ( $repair ) {
					if ( $root_post = self::get_global_post( $gid ) ) {
						$delete_meta = true;
					} else {
						$convert_to_root = true;
					}
				}
			} else {
				// check the post's connections
				// $post_connections = isset($post->meta['contentsync_connection_map']) ? $post->meta['contentsync_connection_map'] : null;
				// if ( is_array($post_connections) && count($post_connections) ) {

				// foreach( $post_connections as $imported_blog_id => $post_connection ) {

				// if ( is_numeric($imported_blog_id) && $current_blog != $imported_blog_id ) {
				// switch_to_blog( $imported_blog_id );
				// $imported_post_id = $post_connection['post_id'];
				// $imported_post = get_post( $imported_post_id );
				// if ( !$imported_post ) {
				// $error->message = __("Der Post hat noch mindestens eine verwaiste Verknüpfung zu einem gelöschten Post.", 'contentsync');

				// if ( $autorepair || $repair ) {
				// $orphan_connections[$imported_blog_id] = $imported_post_id;
				// }
				// }
				// self::restore_blog();
				// }
				// else if ( !is_numeric($imported_blog_id) ) {
				// **
				// * @todo check imported post from other networks
				// */
				// }
				// }
				// }
			}
		}
		// this is a linked post
		elseif ( $status == 'linked' ) {

			// root post comes from this blog
			if ( $root_blog_id == $blog_id && empty( $root_net_url ) ) {

				// this should be the root
				if ( $root_post_id === $post_id ) {
					$error->message = __( "This should be a global source post.", 'contentsync' );

					if ( $autorepair || $repair ) {
						$convert_to_root = true;
					}
				} else {
					$root_post = get_post( $root_post_id );

					// root post found
					if ( $root_post ) {

						$error->message = sprintf(
							__( "The source post is on the same page: %s", 'contentsync' ),
							"<a href='" . self::get_edit_post_link( $root_post->ID ) . "' target='_blank'>{$root_post->post_title} (#{$root_post->ID})</a>"
						);

						if ( $repair ) {
							$delete_meta = true;
						}
					}
					// root post not found
					else {
						$error->message = __( "The source post should be on the same page, but could not be found.", 'contentsync' );

						if ( $autorepair || $repair ) {
							$convert_to_root = true;
						}
					}
				}
			}
			// root comes from another blog
			else {

				$root_post = self::get_global_post( $gid );

				// root post found
				if ( $root_post ) {

					$connection_map = $root_post->meta['contentsync_connection_map'];

					// get the connection
					if ( empty( $root_net_url ) ) {
						$connection_to_this_blog = isset( $connection_map[ $blog_id ] ) ? $connection_map[ $blog_id ] : array();
					} else {
						$connection_to_this_blog = isset( $connection_map[ $cur_net_url ][ $blog_id ] ) ? $connection_map[ $cur_net_url ][ $blog_id ] : array();
					}

					$connected_post_id_from_this_blog = isset( $connection_to_this_blog['post_id'] ) ? intval( $connection_to_this_blog['post_id'] ) : 0;

					// there is no connection to this blog at all
					if ( ! $connected_post_id_from_this_blog ) {
						$error->message = __( "The source post had no active connection to this blog.", 'contentsync' );

						if ( $autorepair || $repair ) {
							$restore_connection = true;
						}
					}
					// there is a connection, but not to this post
					elseif ( $connected_post_id_from_this_blog != $post_id ) {

						// get the other connected post
						$other_linked_post = get_post( $connected_post_id_from_this_blog );

						if ( $other_linked_post ) {

							// add the connection if the other post is trashed
							if ( $other_linked_post->post_status === 'trash' ) {
								$error->message = sprintf(
									__( "The source post was linked to a deleted post on this page: %s", 'contentsync' ),
									"<a href='" . self::get_edit_post_link( $connected_post_id_from_this_blog ) . "' target='_blank'>{$other_linked_post->post_title} (#{$other_linked_post->ID})</a>"
								);

								if ( $autorepair || $repair ) {
									$restore_connection = true;
								}
							} elseif ( $post->post_status === 'publish' && $other_linked_post->post_status !== 'publish' ) {
								$error->message = sprintf(
									__( "The source post is linked to another (unpublished) post on this page: %s", 'contentsync' ),
									"<a href='" . self::get_edit_post_link( $connected_post_id_from_this_blog ) . "' target='_blank'>{$other_linked_post->post_title} (#{$other_linked_post->ID})</a>"
								);

								if ( $repair ) {
									$restore_connection = true;
									$trash_other_post   = true;
								}
							} else {
								$error->message = sprintf(
									__( "The source post is linked to another post on this page: %s", 'contentsync' ),
									"<a href='" . self::get_edit_post_link( $connected_post_id_from_this_blog ) . "' target='_blank'>{$other_linked_post->post_title} (#{$other_linked_post->ID})</a>"
								);

								if ( $repair ) {
									$trash_post = true;
								}
							}
						}
						// post was not found
						else {
							$error->message = __( "The source post still has an incorrect connection to a post of this website, which can no longer be found.", 'contentsync' );

							if ( $autorepair || $repair ) {
								$restore_connection = true;
							}
						}
					}
				}
				// root post not found
				else {

					$error->message = __( "The original post has been deleted or moved.", 'contentsync' );

					if ( $repair ) {
						$convert_to_root = true;
					}
				}
			}
		}

		/**
		 * Apply repair actions
		 */
		$repaired = null;

		// convert to root
		if ( $convert_to_root ) {
			$success = self::convert_post_to_root( $post_id, $gid );

			if ( $repaired === null ) {
				$repaired = (bool) $success;
			} elseif ( ! $success ) {
				$repaired = false;
			}

			if ( $success ) {
				$error->log[] = __( "Post has been made the new source post.", 'contentsync' );
			} else {
				$error->log[] = __( "Post could not be made the new source post.", 'contentsync' );
			}
		}

		// update the gid
		if ( $update_gid ) {
			$success = update_post_meta( $post_id, 'synced_post_id', $new_gid );

			if ( $repaired === null ) {
				$repaired = (bool) $success;
			} elseif ( ! $success ) {
				$repaired = false;
			}

			if ( $success ) {
				$error->log[] = sprintf( __( "Post has been given the new global ID '%s'.", 'contentsync' ), $new_gid );
			} else {
				$error->log[] = sprintf( __( "Post could not be given the new global ID '%s'.", 'contentsync' ), $new_gid );
			}
		}

		// update the status
		if ( $new_status ) {
			$success = update_post_meta( $post_id, 'synced_post_status', $new_status );

			if ( $repaired === null ) {
				$repaired = (bool) $success;
			} elseif ( ! $success ) {
				$repaired = false;
			}

			if ( $success ) {
				$error->log[] = sprintf( __( "Post has been given a new status of '%s'.", 'contentsync' ), $new_status );
			} else {
				$error->log[] = sprintf( __( "Post could not be given new status '%s'.", 'contentsync' ), $new_status );
			}
		}

		// delete meta
		if ( $delete_meta ) {
			$success = self::delete_contentsync_meta( $post_id );

			if ( $repaired === null ) {
				$repaired = (bool) $success;
			} elseif ( ! $success ) {
				$repaired = false;
			}

			if ( $success ) {
				$error->log[] = __( "The global meta information has been deleted.", 'contentsync' );
			} else {
				$error->log[] = __( "The global meta information could not be deleted.", 'contentsync' );
			}
		}

		// delete orphaned connections
		if ( count( $orphan_connections ) ) {

			foreach ( $orphan_connections as $imported_blog_id => $imported_post_id ) {
				$success = self::remove_post_connection_from_connection_map( $gid, $imported_blog_id, $imported_post_id );

				if ( $repaired === null ) {
					$repaired = (bool) $success;
				} elseif ( ! $success ) {
					$repaired = false;
				}

				if ( $success ) {
					$error->log[] = __( "The connection has been deleted.", 'contentsync' );
				} else {
					$error->log[] = __( "The connection could not be deleted.", 'contentsync' );
				}
			}
		}

		// restore the connection to the root post
		if ( $restore_connection ) {
			$success = self::add_post_connection_to_connection_map(
				$gid,
				$blog_id,
				$post_id,
				empty( $root_net_url ) ? null : $cur_net_url
			);

			if ( $repaired === null ) {
				$repaired = (bool) $success;
			} elseif ( ! $success ) {
				$repaired = false;
			}

			if ( $success ) {
				$error->log[] = __( "The connection has been restored.", 'contentsync' );
			} else {
				$error->log[] = __( "The connection could not be restored.", 'contentsync' );
			}
		}

		// trash other linked post
		if ( $trash_other_post && isset( $other_linked_post ) ) {
			$success = self::delete_contentsync_meta( $other_linked_post->ID );
			$success = wp_trash_post( $other_linked_post->ID );
			$success = true;

			if ( $repaired === null ) {
				$repaired = (bool) $success;
			} elseif ( ! $success ) {
				$repaired = false;
			}

			if ( $success ) {
				$error->log[] = __( "The other post was moved to the trash.", 'contentsync' );
			} else {
				$error->log[] = __( "The other post could not be moved to the trash.", 'contentsync' );
			}
		}

		// trash post
		if ( $trash_post ) {
			$success = self::delete_contentsync_meta( $post_id );
			$success = wp_trash_post( $post_id );
			$success = true;

			if ( $repaired === null ) {
				$repaired = (bool) $success;
			} elseif ( ! $success ) {
				$repaired = false;
			}

			if ( $success ) {
				$error->log[] = __( "The post was moved to the trash.", 'contentsync' );
			} else {
				$error->log[] = __( "The post could not be moved to the trash.", 'contentsync' );
			}
		}

		self::restore_blog();

		$error->repaired = (bool) $repaired;
		$error->log      = implode( ' ', $error->log );

		return empty( $error->message ) ? false : $error;
	}

	/**
	 * Repair possible errors
	 *
	 * @param int  $post_id
	 * @param int  $blog_id          Optional. @since global Hub
	 * @param bool $return_error    Whether to return the error object.
	 *
	 * @return bool|object          True|False or Error-object.
	 */
	public static function repair_post( $post_id, $blog_id = null, $return_error = false ) {

		self::switch_to_blog( $blog_id );

		$error = self::check_post_for_errors( $post_id, true, true );

		self::restore_blog();

		return $return_error ? $error : self::is_error_repaired( $error );
	}

	/**
	 * Get all global posts with errors of a certain blog.
	 *
	 * @param int  $blog_id  ID of the blog, defaults to the current blog.
	 * @param bool $repair  Repair errors, this can change meta infos or delete posts.
	 *
	 * @return Synced_Post[]    With @param object error
	 */
	public static function get_blog_global_posts_with_errors( $blog_id = 0, $repair_posts = false, $query_args = null ) {

		$error_posts  = array();

		self::switch_to_blog( $blog_id );

		$posts = self::get_blog_global_posts( '', '', $query_args );

		foreach ( $posts as $post ) {
			$error = self::check_post_for_errors( $post, true, $repair_posts );
			if ( $error ) {
				$post->error   = $error;
				$error_posts[] = $post;
			}
		}

		self::restore_blog();

		return $error_posts;
	}

	/**
	 * Get all global posts with errors of the whole network.
	 *
	 * @param bool $repair  Repair errors, this can change meta infos or delete posts.
	 *
	 * @return Synced_Post[]    With @param object error
	 */
	public static function get_network_global_posts_with_errors( $repair_posts = false, $query_args = null ) {
		$error_posts = array();
		foreach ( self::get_all_blogs() as $blog_id => $blog_args ) {
			$error_posts = array_merge(
				self::get_blog_global_posts_with_errors( $blog_id, $repair_posts, $query_args ),
				$error_posts
			);
		}
		return $error_posts;
	}

	/**
	 * Convert a post to the new root post and add the new
	 * gid to all linked posts.
	 *
	 * @param int    $post_id      WP_Post ID.
	 * @param string $old_gid   Old Global ID.
	 */
	public static function convert_post_to_root( $post_id, $old_gid ) {

		$current_blog   = get_current_blog_id();
		$connection_map = array();
		$options        = array(
			'append_nested'  => true,
			'whole_posttype' => false,
			'all_terms'       => true,
			'resolve_menus'   => true,
			'translations'    => true,
		);

		if ( method_exists( '\Contentsync\Post_Export_Helper', 'enable_logs' ) ) {
			\Contentsync\Post_Export_Helper::enable_logs( false );
		}

		if ( ! method_exists( '\Contentsync\Contents\Actions', 'make_post_global' ) ) {
			return false;
		}

		$gid = \Contentsync\Contents\Actions::make_post_global( $post_id, $options );

		// loop through all blogs and change the gid
		foreach ( self::get_all_blogs() as $blog_id => $blog_args ) {

			if ( $blog_id == $current_blog ) {
				continue;
			}

			self::switch_to_blog( $blog_id );
			$post = self::get_local_post_by_gid( $old_gid );
			if ( $post ) {
				$connection_map[ $blog_id ] = self::create_post_connection_map_array( $blog_id, $post->ID );
				update_post_meta( $post->ID, 'synced_post_id', $gid );
			}
			self::restore_blog();
		}

		// update meta
		update_post_meta( $post_id, 'contentsync_options', $options );
		update_post_meta( $post_id, 'contentsync_connection_map', $connection_map );

		return true;
	}


	/**
	 * =================================================================
	 *                          MISC
	 * =================================================================
	 */

	/**
	 * Hold the origin site url.
	 * 
	 * This is necessary to make sure the upload url is returned correctly when
	 * switching to another blog.
	 * 
	 * This is related to a core issue open since 2013:
	 * @see https://core.trac.wordpress.org/ticket/25650
	 * 
	 * @var string|null
	 */
	public static $origin_site_url = null;

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
	public static function switch_to_blog( $blog_id ) {
		if ( empty( $blog_id ) ) {
			return false;
		}
		if ( ! is_multisite() ) {
			return true;
		}

		if ( empty( self::$origin_site_url ) ) {
			self::$origin_site_url = get_site_url();
		}

		switch_to_blog( $blog_id );

		// Reset translation tool cache to detect the correct tool for this blog
		self::call_post_export_func( 'reset_translation_tool' );

		/**
		 * Ensures the translation environment is ready for use.
		 * This is important because translation plugins might not be loaded
		 * or still be loaded while not being active on the current blog.
		 * 
		 * @see \Contentsync\Translation_Manager::init_translation_environment()
		 */
		self::call_post_export_func( 'init_translation_environment' );
		
		// remove filters from the query args within the export process
		add_filter( 'contentsync_export_post_query_args', array( __CLASS__, 'remove_filters_from_query_args' ) );

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
		restore_current_blog();
		self::$origin_site_url = null;

		/**
		 * Reset translation tool cache to detect the correct tool for this blog.
		 * This also handles reloading translation tool hooks if they were unloaded.
		 * 
		 * @see \Contentsync\Translation_Manager::reset_translation_tool()
		 */
		self::call_post_export_func( 'reset_translation_tool' );
		
		return true;
	}

	/**
	 * Filter the wp the upload url.
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
	public function filter_wp_upload_dir( $upload_dir ) {

		if ( ! empty( self::$origin_site_url ) ) {

			$current_site_url = get_site_url();

			// if the current site url is different from the origin site url, we need to replace the url and baseurl
			if ( $current_site_url !== self::$origin_site_url ) {
				$upload_dir['url'] = str_replace( self::$origin_site_url, $current_site_url, $upload_dir['url'] );
				$upload_dir['baseurl'] = str_replace( self::$origin_site_url, $current_site_url, $upload_dir['baseurl'] );
			}
		}

		return $upload_dir;
	}

	public static function remove_filters_from_query_args( $args ) {
		$parsed_args = wp_parse_args( $args, array(
			'suppress_filters' => true,
			'lang'             => '',
		) );

		if (
			isset( $parsed_args['post_type'] )
			&& $parsed_args['post_type'] === 'attachment'
			&& isset( $parsed_args['post_status'] )
		) {
			if ( is_array( $parsed_args['post_status'] ) ) {
				$parsed_args['post_status'][] = 'inherit';
			} else {
				$parsed_args['post_status'] = array_merge( array( 'inherit' ), explode( ',', $parsed_args['post_status'] ) );
			}
		}

		return $parsed_args;
	}

	/**
	 * Get posts without filters.
	 * 
	 * This function unifies the get_posts() function in order to prevent
	 * errors with dynamic post types or filtered queries through plugins
	 * like WPML or Polylang, whose filters are falsely applied on other blogs.
	 * 
	 * @param array $args
	 * 
	 * @return WP_Post[]
	 */
	public static function get_posts( $args ) {

		$args = self::remove_filters_from_query_args( $args );

		/**
		 * Filter arguments for the get_post() function.
		 * 
		 * @param array $args
		 * 
		 * @return array $args
		 */
		$parsed_args = apply_filters( 'contentsync_get_posts_args', $args );
		
		$posts = get_posts( $args );

		/**
		 * Filter the posts after the get_posts() function.
		 * 
		 * @param WP_Post[] $posts
		 * @param array $args
		 * 
		 * @return WP_Post[] $posts
		 */
		$posts = apply_filters( 'contentsync_get_posts', $posts, $args );

		return $posts;
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

	/**
	 * Get network url without protocol and trailing slash.
	 *
	 * @return string
	 */
	public static function get_network_url() {
		return Main_Helper::call_connections_func( 'get_network_url' );
	}

	/**
	 * Get url without protocol and trailing slash.
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	public static function get_nice_url( $url ) {
		return untrailingslashit( preg_replace( "/^(http|https):\/\/(www.)?/", "", strval( $url ) ) );
	}

	/**
	 * Render the contentsync status box
	 */
	public static function render_status_box( $status = 'export', $text = '' ) {
		$status = $status === 'root' ? 'export' : ( $status === 'linked' ? 'import' : $status );
		$titles = array(
			'export' => __( "Root post", 'contentsync' ),
			'import' => __( "Linked post", 'contentsync' ),
			'error'  => __( "Error", 'contentsync' ),
		);
		$title  = isset( $titles[ $status ] ) ? $titles[ $status ] : $status;
		$color  = 'red';
		if ( $status === 'export' ) {
			$color = 'purple';
		} elseif ( $status === 'import' ) {
			$color = 'green';
		} elseif ( $status === 'info' ) {
			$color = 'blue';
		}
		return sprintf(
			'<span data-title="%1$s" class="contentsync_info_box %2$s contentsync_status"><img src="%3$s" style="width:auto;height:16px;">%4$s</span>',
			/* title    */ preg_replace( '/\s{1}/', '&nbsp;', $title ),
			/* color    */ $color,
			/* icon src */ esc_url( plugins_url( 'assets/icon/' . $status . '.svg', __DIR__ ) ),
			/* text     */ ! empty( $text ) ? '<span>' . $text . '</span>' : ''
		);
	}

	/**
	 * Get the translation tool of this stage.
	 *
	 * @see Post_Export_Helper::get_translation_tool()
	 */
	public static function get_translation_tool() {
		return self::call_post_export_func( 'get_translation_tool' );
	}

	/**
	 * Get all language codes
	 *
	 * @see Post_Export_Helper::get_languages_codes()
	 */
	public static function get_languages_codes() {
		return self::call_post_export_func( 'get_languages_codes' ) ?? array();
	}

	/**
	 * Get language code of a post.
	 * 
	 * @see Post_Export_Helper::get_post_language_info( $post )
	 *
	 * @param WP_Post $post
	 * @return string Empty string if no language was found.
	 */
	public static function get_post_language_code( $post ) {
		$language_details = self::call_post_export_func( 'get_post_language_info', $post );
		if ( is_array( $language_details) && isset( $language_details['language_code']) ) {
			return $language_details['language_code'];
		}
		return '';
	}

	/**
	 * Function to check if current user is allowed to edit global contents.
	 * Permission is based on 'edit_posts' capability and can be overridden 
	 * with the filter 'contentsync_user_can_edit'.
	 * 
	 * @param string $status 'root' or 'linked'
	 * 
	 * @return bool
	 */
	public static function current_user_can_edit_global_posts( $status = '' ) {

		$can_edit = function_exists( 'current_user_can' ) ? current_user_can('edit_posts') : true;

		if ( $status === 'root' ) {

			/**
			 * Filter to allow editing of root posts.
			 * 
			 * @param bool $can_edit
			 * 
			 * @return bool
			 */
			$can_edit = apply_filters( 'contentsync_user_can_edit_root_posts', $can_edit );
		}
		else if ( $status === 'linked' ) {

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
		 * Filter to allow editing of all global posts, no matter the status.
		 * 
		 * @param bool $can_edit
		 * 
		 * @return bool
		 */
		return apply_filters( 'contentsync_user_can_edit_global_posts', $can_edit, $status );
	}

	/**
	 * =================================================================
	 *                          Connections
	 * =================================================================
	 */

	/**
	 * Get the current connections
	 *
	 * @return array $connection_map   All saved connections.
	 */
	public static function get_connections() {
		return Main_Helper::call_connections_func( 'get_connections' );
	}

	/**
	 * Get all connections for global contents
	 */
	public static function get_content_connections() {
		$connections = Main_Helper::call_connections_func( 'get_connections' );
		if ( ! $connections ) {
			return array();
		}
		return array_filter(
			$connections,
			function( $connection ) {
				return ! isset( $connection['contents'] ) || $connection['contents'] === true;
			}
		);
	}

	/**
	 * Get all connections for global search
	 */
	public static function get_search_connections() {
		return array_filter(
			Main_Helper::call_connections_func( 'get_connections' ),
			function( $connection ) {
				return ! isset( $connection['search'] ) || $connection['search'] === true;
			}
		);
	}


	/**
	 * =================================================================
	 *                          Compatibility
	 * =================================================================
	 */

	/**
	 * Whether we are in a REST REQUEST. Similar to is_admin().
	 */
	public static function is_rest_request() {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

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

		$method = '';

		// Check Translation_Manager first (new in 2.19.0)
		if ( class_exists( '\Contentsync\Translation_Manager' ) && method_exists( '\Contentsync\Translation_Manager', $function_name ) ) {
			$method = '\Contentsync\Translation_Manager';
		}
		else if ( method_exists( '\Contentsync\Post_Export_Helper', $function_name ) ) {
			$method = '\Contentsync\Post_Export_Helper';
		}
		else if ( method_exists( '\Contentsync\Post_Export', $function_name ) ) {
			$method = '\Contentsync\Post_Export';
		}
		else if ( method_exists( '\Contentsync\Post_Import', $function_name ) ) {
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
	 * @see \Contentsync\Post_Export::export_post()
	 * 
	 * @param int   $post_id          Post ID to export.
	 * @param array $args             Array of arguments to export post.
	 * 
	 * @return mixed                  Exported post.
	 */
	public static function export_post( $post_id, $args = array() ) {

		/**
		 * @action contentsync_before_export_global_post
		 * @since 2.18.0
		 */
		do_action( 'contentsync_before_export_global_post', $post_id, $args );

		$result = self::call_post_export_func( 'export_post', $post_id, $args );

		/**
		 * @action contentsync_after_export_global_post
		 * @since 2.18.0
		 */
		do_action( 'contentsync_after_export_global_post', $post_id, $args, $result );

		return $result;
	}

	/**
	 * Export posts with backward compatiblity and additional actions.
	 * @see \Contentsync\Post_Export::export_posts()
	 * 
	 * @param array $post_ids_or_objects Array of post IDs or post objects to export.
	 * @param array $args                Array of arguments to export posts.
	 * 
	 * @return mixed                     Array of exported posts.
	 */
	public static function export_posts( $post_ids_or_objects, $args = array() ) {

		/**
		 * @action contentsync_before_export_global_posts
		 * @since 2.18.0
		 */
		do_action( 'contentsync_before_export_global_posts', $post_ids_or_objects, $args );

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
		 * @action contentsync_after_export_global_posts
		 * @since 2.18.0
		 */
		do_action( 'contentsync_after_export_global_posts', $post_ids_or_objects, $args, $result );

		return $result;
	}

	/**
	 * Import posts with backward compatiblity and additional actions.
	 * @see \Contentsync\Post_Import::import_posts()
	 * 
	 * @param array $posts             Array of posts to import.
	 * @param array $conflict_actions  Array of conflict actions.
	 * 
	 * @return mixed                  True on success. WP_Error on failure.
	 */
	public static function import_posts( $posts, $conflict_actions=array() ) {

		/**
		 * @action contentsync_before_import_global_posts
		 * @since 2.18.0
		 */
		do_action( 'contentsync_before_import_global_posts', $posts, $conflict_actions );

		$result = self::call_post_export_func( 'import_posts', $posts, $conflict_actions );

		/**
		 * @action contentsync_after_import_global_posts
		 * @since 2.18.0
		 */
		do_action( 'contentsync_after_import_global_posts', $posts, $conflict_actions, $result );

		return $result;
	}

	/**
	 * Call function from connections feature with backward compatiblity.
	 *
	 * @since 1.4.5
	 *
	 * @param string $function_name
	 * @param mixed  ...$args
	 *
	 * @return mixed
	 */
	public static function call_connections_func( $function_name ) {

		$args = func_get_args();
		array_shift( $args );

		$method = '';

		if ( method_exists( '\Greyd\Connections\Connections_Helper', $function_name ) ) {
			$method = '\Greyd\Connections\Connections_Helper';
		} else if ( method_exists( '\Greyd\Hub\Admin', $function_name ) ) {
			$method = '\Greyd\Hub\Admin';
		}

		if ( empty( $method ) && defined( 'GREYD_PLUGIN_PATH' ) ) {
			require_once GREYD_PLUGIN_PATH . '/src/features/connections/init.php';
			if ( method_exists( '\Greyd\Connections\Connections_Helper', $function_name ) ) {
				$method = '\Greyd\Connections\Connections_Helper';
			}
		}

		if ( ! empty( $method ) ) {
			return count( $args ) === 0
				? call_user_func( $method . '::' . $function_name )
				: call_user_func_array( $method . '::' . $function_name, $args );
		}

		return null;
	}

	/**
	 * Retrieves the edit post link for post.
	 * 
	 * @see wp-includes/link-template.php
	 * 
	 * @param int|WP_Post $post Post ID or post object.
	 * 
	 * @return string The edit post link URL for the given post.
	 */
	public static function get_edit_post_link( $post ) {

		if ( !is_object($post) ) {
			$post = get_post($post);
		}

		switch ( $post->post_type ) {
			case 'wp_global_styles':

				// do not allow editing of global styles and font families from other themes
				if ( self::get_wp_template_theme( $post ) != get_option( 'stylesheet' ) ) {
					return null;
				}

				// wp-admin/site-editor.php?path=/wp_global_styles&canvas=edit
				return add_query_arg(
					array(
						// 'path'   => '/wp_global_styles',
						// 'canvas' => 'edit',
						'p' => '/styles',
					),
					admin_url( 'site-editor.php' )
				);
				break;
			case 'wp_template':
				// wp-admin/site-editor.php?postType=wp_template&postId=greyd-theme//404&canvas=edit
				return add_query_arg(
					array(
						'postType' => $post->post_type,
						'postId'   => self::get_wp_template_theme( $post ) . '//' . $post->post_name,
						'canvas'   => 'edit',
					),
					admin_url( 'site-editor.php' )
				);
				break;
			case 'wp_template_part':
				// wp-admin/site-editor.php?postType=wp_template_part&postId=greyd-theme//footer&categoryId=footer&categoryType=wp_template_part&canvas=edit
				return add_query_arg(
					array(
						'postType'      => $post->post_type,
						'postId'        => self::get_wp_template_theme( $post ) . '//' . $post->post_name,
						'categoryId'    => $post->ID,
						'categoryType'  => $post->post_type,
						'canvas'        => 'edit',
					),
					admin_url( 'site-editor.php' )
				);
				break;
			case 'wp_navigation':
				// wp-admin/site-editor.php?postId=169&postType=wp_navigation&canvas=edit
				return add_query_arg(
					array(
						'postId'   => $post->ID,
						'postType' => $post->post_type,
						'canvas'   => 'edit',
					),
					admin_url( 'site-editor.php' )
				);
				break;
			case 'wp_block':
				// wp-admin/edit.php?post_type=wp_block
				return add_query_arg(
					array(
						'post'      => $post->ID,
						'action'    => 'edit',
					),
					admin_url( 'post.php' )
				);
				break;
			case 'wp_font_family':
				// wp-admin/site-editor.php?path=/wp_global_styles&canvas=edit
				return add_query_arg(
					array(
						// 'path'   => '/wp_global_styles',
						// 'canvas' => 'edit',
						'p' => '/styles',
					),
					admin_url( 'site-editor.php' )
				);
				break;
			default:
				return html_entity_decode( get_edit_post_link( $post ) );
				// return add_query_arg(
				// 	array(
				// 		'post'      => $post->ID,
				// 		'action'    => 'edit',
				// 	),
				// 	admin_url( 'post.php' )
				// );
				break;
		}
		return '';
	}

	/**
	 * Retrieves the theme slug for a wp_template post.
	 * 
	 * @param WP_Post $post Post object.
	 * 
	 * @return string The theme slug for the given template.
	 */
	public static function get_wp_template_theme( $post ) {
		$theme = wp_get_post_terms( $post->ID, 'wp_theme' );
		if ( $theme && is_array($theme) && isset($theme[0]) ) {
			return $theme[0]->name;
		}
		return '';
	}


	/**
	 * =================================================================
	 *                          Unstable
	 * =================================================================
	 */

	/**
	 * Filters the attachment URL.
	 *
	 * @since 1.7.4
	 *
	 * @param string $url           URL for the given attachment.
	 * @param int    $attachment_id Attachment post ID.
	 *
	 * @return string
	 */
	public function __unstable_filter_global_attachment_url( $url, $attachment_id ) {

		// check if url is an mp4 file
		if ( strpos( $url, '.mp4' ) === false ) {
			return $url;
		}

		// only for linked posts
		$status = get_post_meta( $attachment_id, 'synced_post_status', true );
		if ( $status !== 'linked' ) {
			return $url;
		}

		// get the global id
		$gid = get_post_meta( $attachment_id, 'synced_post_id', true );
		if ( ! $gid ) {
			return $url;
		}

		// get the global post
		$global_post = self::get_global_post( $gid );
		if ( $global_post && isset( $global_post->guid ) ) {
			return $global_post->guid;
		}

		return $url;
	}


	/**
	 * =================================================================
	 *                          Migrated
	 * =================================================================
	 */

	/**
	 * Retrieves the terms of the taxonomy that are attached to the post.
	 *
	 * @since 1.3.0 (contentsync_suite)
	 * Usually we would use the core function get_the_terms(). However it sometimes returns
	 * terms of completely different taxonomies - without returning an error. To retrieve the
	 * terms directly from the database seems to work more consistent in those cases.
	 * 
	 * @see get_the_terms()
	 * @see https://developer.wordpress.org/reference/functions/get_the_terms/
	 * @see this function was copied from contentsync_tp_management/inc/post_export.php
	 *
	 * @param int       $post_id    Post ID.
	 * @param string    $taxonomy   Taxonomy name.
	 * @return WP_Term[]|null       Array of WP_Term objects on success, null if there are no terms
	 *                              or the post does not exist.
	 */
	public static function get_post_taxonomy_terms( $post_id, string $taxonomy ) {

		if ( ! is_numeric( $post_id ) || ! is_string( $taxonomy ) ) {
			return null;
		}

		global $wpdb;
		$results = $wpdb->get_results(
			"
			SELECT {$wpdb->terms}.term_id, name, slug, term_group, {$wpdb->term_relationships}.term_taxonomy_id, taxonomy, description, parent, count FROM {$wpdb->terms}
				LEFT JOIN {$wpdb->term_relationships} ON
					({$wpdb->terms}.term_id = {$wpdb->term_relationships}.term_taxonomy_id)
				LEFT JOIN {$wpdb->term_taxonomy} ON
					({$wpdb->term_relationships}.term_taxonomy_id = {$wpdb->term_taxonomy}.term_taxonomy_id)
			WHERE {$wpdb->term_relationships}.object_id = {$post_id}
				AND {$wpdb->term_taxonomy}.taxonomy = '{$taxonomy}'
		"
		);
		if ( $results && is_array( $results ) && count( $results ) ) {
			return array_map(
				function( $term ) {
					return new \WP_Term( $term );
				},
				$results
			);
		}
		return null;
	}

	/**
	 * Get all active Plugins from Option.
	 * Including all active sitewide Plugins.
	 * @param string $mode  all|site|global (default: all)
	 */
	public static function active_plugins($mode = 'all') {

		$plugins = array();

		// get all active plugins
		if ( $mode == 'all' || $mode == 'site' ) {
			$plugins = get_option('active_plugins');
			if ( !is_array($plugins) ) {
				$plugins = array();
			}
		}

		// on multisite, get all active sitewide plugins as well
		if (
			is_multisite()
			&& ( $mode == 'all' || $mode == 'global' )
		) {
			$plugins_multi = get_site_option('active_sitewide_plugins');
			if ( is_array($plugins_multi) && !empty($plugins_multi) ) {
				foreach ($plugins_multi as $key => $value) {
					$plugins[] = $key;
				}
				$plugins = array_unique($plugins);
				sort($plugins);
			}
		}

		return $plugins;
	}

	/**
	 * Check if single Plugin is active.
	 */
	public static function is_active_plugin($file) {
		// check for active plugins
		$plugins = self::active_plugins();
		$active = false;
		if (in_array($file, $plugins)) $active = true;
		return $active;
	}

	/**
	 * Render a frontend message box.
	 * @param string $msg   The message to show.
	 * @param string $mode  Style of the notice (error, warning, success, info).
	 */
	public static function show_frontend_message($msg, $mode="info") {
		if ($mode != "info" && $mode != "success" && $mode != "danger") $mode = "info";
		return "<div class='message {$mode}'>{$msg}</div>";
	}

	/**
	 * Show wordpress style notice in top of page.
	 * @param string $msg   The message to show.
	 * @param string $mode  Style of the notice (error, warning, success, info).
	 * @param bool $list    Add to hub msg list (default: false).
	 */
	public static function show_message($msg, $mode='info', $list=false) {
		if (empty($msg)) return;
		if ($list) echo "<p class='hub_msg msg_list {$mode}'>{$msg}</p>";
		else echo "<div class='notice notice-{$mode} is-dismissible'><p>{$msg}</p></div>";
	}

	/**
	 * Render Infobox in Backend.
	 * @param array $atts
	 *      @property string above      Bold Headline.
	 *      @property string text       Infotext.
	 *      @property string class      Extra class(es)).
	 *      @property string style      Style of the notice (success, warning, alert, new).
	 *      @property string styling    Color Style of the notice (green, orange, red).
	 * @param bool $echo    Directly output the Content, or return contents (default: false).
	 */
	public static function render_info_box($atts=[], $echo=false) {

		$above      = isset($atts['above']) ? '<b>'.esc_attr($atts['above']).'</b>' : '';
		$text       = isset($atts['text']) ? '<span>'.html_entity_decode(esc_attr($atts['text'])).'</span>' : '';
		$class      = isset($atts['class']) ? esc_attr($atts['class']) : '';

		$styling    = isset($atts['style']) ? esc_attr($atts['style']) : ( isset($atts['styling']) ? esc_attr($atts['styling']) : '' );
		if ($styling == 'success' || $styling == 'green') $info_icon = 'dashicons-yes';
		else if ($styling == 'warning' || $styling == 'orange') $info_icon = 'dashicons-warning';
		else if ($styling == 'alert' || $styling == 'red' || $styling == 'danger' || $styling == 'error') $info_icon = 'dashicons-warning';
		else if ($styling == 'new') $info_icon = 'dashicons-megaphone';
		else $info_icon = 'dashicons-info';

		$return = "<div class='contentsync_info_box {$styling} {$class}'><span class='dashicons {$info_icon}'></span><div>{$above}{$text}</div></div>";
		if ($echo) echo $return;
		return $return;
	}

	/**
	 * Render small Infopopup with toggle in Backend.
	 * @param string $content   Infotext.
	 * @param string $className Extra class names.
	 * @param bool $echo        Directly output the Content, or return contents (default: false).
	 */
	public static function render_info_popup($content="", $className="", $echo=false) {
		if ( empty($content) ) return false;
		$return = "<span class='contentsync_popup_wrapper'>".
			"<span class='toggle dashicons dashicons-info'></span>".
			"<span class='popup {$className}'>{$content}</span>".
		"</span>";
		if ($echo) echo $return;
		return $return;
	}

	/**
	 * Similar to render_info_popup but bigger.
	 * @param string $content   Infotext.
	 * @param string $className Extra class names.
	 * @param bool $echo        Directly output the Content, or return contents (default: false).
	 */
	public static function render_info_dialog($content="", $className="", $echo=false) {
		$return = "<span class='contentsync_popup_wrapper'>".
			"<span class='toggle dashicons dashicons-info'></span>".
			"<dialog class='{$className}'>{$content}</dialog>".
		"</span>";
		if ($echo) echo $return;
		return $return;
	}

	/**
	 * Render a Dashicon.
	 * https://developer.wordpress.org/resource/dashicons
	 * @param string $icon  Dashicon slug.
	 * @param bool $echo    Directly output the Content, or return contents (default: false).
	 */
	public static function render_dashicon($icon, $echo=false) {
		$icon = str_replace( "dashicons-", "", $icon );
		$return = "<span class='dashicons dashicons-$icon'></span>";
		if ($echo) echo $return;
		return $return;
	}
}
