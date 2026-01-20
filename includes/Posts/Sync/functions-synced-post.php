<?php

namespace Contentsync\Posts\Sync;

use Contentsync\Utils\Multisite_Manager;
use Contentsync\Posts\Transfer\Post_Export;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


/**
 * Get the global root post by gid
 *
 * @param string $gid
 *
 * @return Synced_Post|null
 */
function get_synced_post( $gid ) {

	// check object cache (local post)
	if ( $cache = wp_cache_get( 'gid_' . $gid, 'synced_posts' ) ) {
		return $cache;
	}

	// check persistent cache (remote post)
	if ( $cache = get_transient( 'gid_' . $gid ) ) {
		return $cache;
	}

	list( $blog_id, $post_id, $site_url ) = explode_gid( $gid );
	if ( $post_id === null ) {
		return null;
	}

	$post = null;

	// network post
	if ( empty( $site_url ) ) {
		Multisite_Manager::switch_blog( $blog_id );

		$status = get_post_meta( $post_id, 'synced_post_status', true );

		if ( $status === 'root' ) {
			$post = new_synced_post( $post_id );

			/**
			 * Set object cache for local post.
			 */
			wp_cache_set( 'gid_' . $gid, $post, 'synced_posts' );
		}

		Multisite_Manager::restore_blog();
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
function prepare_synced_post_for_import( $gid, $args = array() ) {

	list( $blog_id, $post_id, $site_url ) = explode_gid( $gid );
	if ( $post_id === null ) {
		return null;
	}

	$posts = null;

	// network post
	if ( empty( $site_url ) ) {

		Multisite_Manager::switch_blog( $blog_id );
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

				$posts = ( new Post_Export( $post_id, $args ) )->get_posts();
			}
		}

		Multisite_Manager::restore_blog();
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
function get_post_id_by_gid( $gid ) {
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
function get_all_synced_posts( $query = null, $network_url = null ) {

	$all_synced_posts = array();
	$current_network  = \Contentsync\Utils\get_network_url();

	// get network posts
	if ( empty( $network_url ) || $network_url === 'here' || $network_url === $current_network ) {
		$all_synced_posts = \Contentsync\get_all_synced_posts_from_current_network( $query );
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
function get_all_synced_posts_from_current_network( $query = null ) {

	$cache_key = empty( $query ) ? 'all_network_posts' : 'all_network_posts_' . md5( serialize( $query ) );

	// check cache (not persistent, as we need to update it frequently)
	if ( $cache = wp_cache_get( $cache_key, 'synced_posts' ) ) {
		return $cache;
	}

	// loop through all blogs and get the posts
	$all_network_posts = array();
	foreach ( Multisite_Manager::get_all_blogs() as $blog_id => $blog_args ) {
		$posts = \Contentsync\get_synced_posts_of_blog( $blog_id, 'root', $query );

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
function get_synced_posts_of_blog( $blog_id = '', $filter_status = '', $query = null ) {

	Multisite_Manager::switch_blog( $blog_id );

	$post_type = 'any';
	if ( empty( $post_type ) || $post_type === 'any' ) {
		$post_type = is_rest_request() ? 'any' : \Contentsync\get_export_post_types();
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

	$posts = \Contentsync\extend_post_object( get_unfiltered_posts( $args ) );

	Multisite_Manager::restore_blog();

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
function extend_post_object( $post, $current_blog = 0 ) {
	// multiple posts
	if ( is_array( $post ) && count( $post ) > 0 ) {
		foreach ( $post as $key => $_post ) {
			$post[ $key ] = \Contentsync\extend_post_object( $_post, $current_blog );
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
		$post->blog_theme = \Contentsync\Posts\get_wp_template_theme( $post );
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
function get_local_post_by_gid( $gid, $post_type = 'any' ) {

	if ( ! $gid || empty( $gid ) ) {
		return false;
	}

	$local_post = false;

	if ( empty( $post_type ) || $post_type === 'any' ) {
		$post_type = is_rest_request() ? 'any' : \Contentsync\get_export_post_types();
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
 * Whether we are in a REST REQUEST. Similar to is_admin().
 */
function is_rest_request() {
	return defined( 'REST_REQUEST' ) && REST_REQUEST;
}
