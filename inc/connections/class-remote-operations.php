<?php

/**
 * Remote operations for connected networks.
 *
 * This file defines the `Remote_Operations` class, which encapsulates a
 * variety of static methods for interacting with remote networks and
 * sites connected to the Content Sync system. It provides functions
 * to query global posts across all blogs, build SQL queries for remote
 * searches, fetch posts and metadata from remote networks, and register
 * endpoints used by other parts of the plugin. By centralising remote
 * data access here, the rest of the plugin can work with consistent
 * abstractions rather than dealing with low‑level database details.
 * Extend or modify this class when adding new remote queries or
 * connection types.
 */

namespace Contentsync\Connections;

use \Contentsync\Main_Helper;

if(!defined('ABSPATH')) exit;

new Remote_Operations;
class Remote_Operations {

	/**
	 * Get all global posts of this installation.
	 * This function is usually called via a REST API call.
	 *
	 * @param string|array $query   Search query term or array of query args.
	 *
	 * @return array of all global posts
	 */
	public static function get_global_posts_for_endpoint( $query_args = null ) {

		global $wpdb;
		$all_posts             = $query_parts = $removed_posts = array();
		$additional_conditions = '';

		// handle $query_args
		if ( ! empty( $query_args ) ) {
			if ( is_string( $query_args ) ) {
				// simple text search, same as $query['s']
				$additional_conditions .= "AND (
					LOWER({prefix}posts.post_content) LIKE LOWER('%{$query_args}%')
					OR LOWER({prefix}posts.post_title) LIKE LOWER('%{$query_args}%')
				)";
			} elseif ( is_array( $query_args ) || is_object( $query_args ) ) {
				foreach ( (array) $query_args as $key => $value ) {
					if ( empty( $value ) ) {
						continue;
					}

					if ( $key === 's' ) {
						// simple text search
						$additional_conditions .= "AND (
							LOWER({prefix}posts.post_content) LIKE LOWER('%{$value}%')
							OR LOWER({prefix}posts.post_title) LIKE LOWER('%{$value}%')
						)";
					} else {
						// object attribute search, eg. 'post_type' => 'dynamic_template'
						$additional_conditions .= "AND {prefix}posts.{$key} = '{$value}'";
					}
				}
			}
		}

		$network_url = Main_Helper::get_network_url();

		// build sql query
		$results = array();
		foreach ( Main_Helper::get_all_blogs() as $blog_id => $blog_args ) {
			$prefix   = $blog_args['prefix'];
			$site_url = $blog_args['site_url'];
			$query    = "
				SELECT ID, post_name, post_type, post_status, post_title, post_date, meta_value as synced_post_id, '{$blog_id}' as blog_id, '{$site_url}' as site_url, '{$network_url}' as network_url
				FROM {$prefix}posts
				LEFT JOIN {$prefix}postmeta ON {$prefix}posts.ID = {$prefix}postmeta.post_id

				WHERE {$prefix}postmeta.meta_key = 'synced_post_id'
					AND {$prefix}postmeta.meta_value = CONCAT('{$blog_id}-', {$prefix}posts.ID)
				" . str_replace( '{prefix}', $prefix, $additional_conditions ) . "
				AND {$prefix}posts.post_status <> 'trash'
			";
			$result   = $wpdb->get_results( $query );
			if ( count( $result ) > 0 ) {
				$results = array_merge( $results, $result );
			}
		}
		usort(
			$results,
			function( $a, $b ) {
				return strtotime( $b->post_date ) - strtotime( $a->post_date );
			}
		);
		// debug($results);

		if ( empty( $results ) ) {
			return array();
		}

		return $results;
	}

	/**
	 * Prepare a global ID for inclusion in a URL.
	 *
	 * Remote REST endpoints expect global IDs (GIDs) to be URL safe. This
	 * helper converts any forward slashes in the GID to dashes and then
	 * applies `urlencode()` to ensure the string may be safely embedded
	 * in a URL path segment. It does not split or validate the GID.
	 *
	 * @param string $gid The global ID to encode.
	 *
	 * @return string Encoded GID safe for use in URLs.
	 */
	public static function prepare_gid_for_url( $gid ) {
		// list( $blog_id, $post_id, $net_url ) = Main_Helper::explode_gid( $gid );
		// $_gid  = $blog_id . '-' . $post_id;
		return urlencode( str_replace( '/', '-', $gid ) );
	}

	/**
	 * =================================================================
	 *                          Call Endpoints
	 * =================================================================
	 */

	/**
	 * Get all remote global posts
	 *
	 * @param array|string $connection_or_site_url
	 *
	 * @return mixed
	 */
	public static function get_remote_global_posts( $connection_or_site_url, $query_args = null ) {
		return Main_Helper::call_connections_func( 'send_request',
			$connection_or_site_url,
			'posts',
			array(
				'args' => $query_args,
			)
		);
	}

	/**
	 * Get a remote global post
	 *
	 * @param array|string $connection_or_site_url
	 *
	 * @return mixed
	 */
	public static function get_remote_global_post( $connection_or_site_url, $gid ) {
		return Main_Helper::call_connections_func( 'send_request', $connection_or_site_url, 'posts/' . self::prepare_gid_for_url( $gid ) );
	}

	/**
	 * Prepare a remote global post for import
	 *
	 * @param array|string $connection_or_site_url
	 *
	 * @return mixed
	 */
	public static function prepare_remote_global_post( $connection_or_site_url, $gid ) {
		return Main_Helper::call_connections_func( 'send_request', $connection_or_site_url, 'posts/' . self::prepare_gid_for_url( $gid ) . '/prepare' );
	}

	/**
	 * Update a post connection_map of a remote post
	 *
	 * @param array|string $connection_or_site_url
	 * @param string       $gid           Global ID of the root post.
	 * @param array        $args          array( 'blog_id' => 'post_id', ... )
	 * @param bool         $add           Whether to add or remove the post connection.
	 * @param string       $post_site_url Site url (for remote posts).
	 *
	 * @return mixed
	 */
	public static function update_remote_post_connection( $connection_or_site_url, $gid, $args, $add, $post_site_url ) {
		return Main_Helper::call_connections_func( 'send_request',
			$connection_or_site_url,
			'posts/' . self::prepare_gid_for_url( $gid ) . '/connections',
			array(
				'site_url' => $post_site_url,
				'args'     => $args,
			),
			$add ? 'POST' : 'DELETE'
		);
	}

	/**
	 * Get connected posts to a root post from an entire remote network.
	 *
	 * @since 1.7.5
	 *
	 * @param array|string $connection_or_site_url
	 * @param string       $gid               Global ID of the root post.
	 *
	 * @return array|false Array of post connections on success, false on failure.
	 */
	public static function get_all_remote_connected_posts( $connection_or_site_url, $gid ) {
		return Main_Helper::call_connections_func( 'send_request',
			$connection_or_site_url,
			'connected_posts',
			array(
				'gid' => $gid,
			),
			'GET'
		);
	}

	/**
	 * Delete all connected posts of a global post from a certain connection
	 *
	 * @see \Contentsync\Contents\Actions->delete_global_post()
	 *
	 * @param array|string $connection_or_site_url
	 * @param string       $gid               Global ID of the root post with an appended network_url.
	 * @param array        $connected_posts    Connections from this network, usually created by Main_Helper::create_post_connection_map_array()
	 */
	public static function delete_all_remote_connected_posts( $connection_or_site_url, $gid, $connected_posts ) {
		return Main_Helper::call_connections_func( 'send_request',
			$connection_or_site_url,
			'connected_posts',
			array(
				'gid'  => $gid,
				'args' => $connected_posts,
			),
			'DELETE'
		);
	}

	/**
	 * =================================================================
	 *                          Distribution
	 * =================================================================
	 */

	/**
	 * Distribute a Destination_Item to a Remote_Destination.
	 * 
	 * This function calles the endpoint 'distribution/distribute-item' on the remote site.
	 * 
	 * @param array|string $connection_or_site_url
	 * @param Remote_Distribution_Item $remote_distribution_item
	 * 
	 * @return mixed Decoded response data on success, WP_Error on failure.
	 */
	public static function distribute_item_to_remote_site( $connection_or_site_url, $remote_distribution_item ) {

		return Main_Helper::call_connections_func( 'send_request',
			$connection_or_site_url,
			'distribution/distribute-item',
			array(
				'distribution_item' => json_encode( $remote_distribution_item ),
				'origin'            => \Contentsync\Main_Helper::get_network_url(),
			),
			'POST',
			array(
				'wp_error' => true,
			)
		);
	}

	/**
	 * Update the Remote_Destination object of a Distribution_Item.
	 * 
	 * This function is UNUSED right now, as the background processing is not working
	 * inside a rest request. Instead, the update is done directly after the distribution
	 * to the local sites.
	 * 
	 * @see Distribution_Endpoint->handle_distribute_item_request()
	 * 
	 * This function calles the endpoint 'distribution/update-item' on the origin site.
	 * 
	 * @param array|string       $connection_or_site_url  The connection or site URL.
	 * @param int                $distribution_item_id    The Distribution_Item ID.
	 * @param string             $status                  The status of the distribution.
	 * @param Remote_Destination $destination             The destination object.
	 */
	public static function update_distribution_item( $connection_or_site_url, $distribution_item_id, $status, $destination ) {
		return Main_Helper::call_connections_func( 'send_request',
			$connection_or_site_url,
			'distribution/update-item',
			array(
				'distribution_item_id'  => $distribution_item_id,
				'destination'           => $destination,
				'status'                => $status,
			),
			'POST'
		);
	}


	/**
	 * =================================================================
	 *                          Unused Functions
	 * =================================================================
	 */

	/**
	 * Export a single post to all connected sites of a root post.
	 *
	 * @see \Contentsync\Connections\Endpoints\Connected_Posts->export_post_to_all_connections()
	 *
	 * @param array|string $connection_or_site_url
	 * @param string       $gid               Global ID of the root post.
	 * @param array        $connected_posts    Connections from this network, usually created by Main_Helper::create_post_connection_map_array()
	 */
	public static function export_post_to_all_remote_connections( $connection_or_site_url, $gid, $connected_posts ) {
		return Main_Helper::call_connections_func( 'send_request',
			$connection_or_site_url,
			'connected_posts/export',
			array(
				'gid'  => $gid,
				'args' => $connected_posts,
			),
			'POST'
		);
	}

	/**
	 * Update posts on all connected sites of a root post.
	 *
	 * @see \Contentsync\Connections\Endpoints\Connected_Posts->update_all_connected_posts()
	 *
	 * @param array|string $connection_or_site_url
	 * @param string       $gid               Global ID of the root post.
	 * @param array        $connected_posts    Connections from this network, usually created by Main_Helper::create_post_connection_map_array()
	 * @param array        $all_posts          all posts to be imported
	 */
	public static function update_all_remote_connected_posts( $connection_or_site_url, $gid, $connected_posts, $all_posts ) {
		return Main_Helper::call_connections_func( 'send_request',
			$connection_or_site_url,
			'connected_posts',
			array(
				'gid'   => $gid,
				'args'  => $connected_posts,
				'posts' => $all_posts,
			),
			'POST'
		);
	}

	public static function export_post_to_remote_destination( $remote_network_url, $root_gid, $all_posts, $blog_id ) {
		return Main_Helper::call_connections_func( 'send_request',
			$remote_network_url,
			'connected_posts/export_post_to_destination',
			array(
				'gid'   => $root_gid,
				'posts' => $all_posts,
				'blog_id'  => $blog_id,
			),
			'POST'
		);
	}
}