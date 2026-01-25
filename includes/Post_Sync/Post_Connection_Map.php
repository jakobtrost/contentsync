<?php

namespace Contentsync\Post_Sync;

use Contentsync\Connections\Site_Connection;
use Contentsync\Utils\Multisite_Manager;
use Contentsync\Utils\Urls;
use Contentsync\Api\Remote_Request;
use Contentsync\Post_Sync\Synced_Post_Utils;

defined( 'ABSPATH' ) || exit;

class Post_Connection_Map {

	/**
	 * Add a new internal post connection to a synced post
	 *
	 * @param string $gid           Global ID of the root post.
	 * @param int    $blog_id       Blog ID of the imported post.
	 * @param int    $post_id       Post ID of the imported post.
	 * @param string $post_site_url Site url (only via remote requests).
	 *
	 * @return bool
	 */
	public static function add( $gid, $blog_id, $post_id, $post_site_url = '' ) {
		return self::add_or_remove(
			$gid,
			array(
				$blog_id => self::create_array( $blog_id, $post_id ),
			),
			true,
			$post_site_url
		);
	}

	/**
	 * Remove a new internal connection from a synced post
	 *
	 * @param string $gid           Global ID of the root post.
	 * @param int    $blog_id       Blog ID of the imported post.
	 * @param int    $post_id       Post ID of the imported post.
	 * @param string $post_site_url Site url (only via remote requests).
	 *
	 * @return bool
	 */
	public static function remove( $gid, $blog_id, $post_id, $post_site_url = '' ) {
		return self::add_or_remove(
			$gid,
			array(
				$blog_id => self::create_array( $blog_id, $post_id ),
			),
			false,
			$post_site_url
		);
	}

	/**
	 * Log a new internal connection to a synced post
	 *
	 * @param string $gid           Global ID of the root post.
	 * @param array  $args          array( blog_id => post_connection_array, ... )
	 * @param bool   $add           Whether to add or remove the post connection.
	 * @param string $post_site_url Site url (only via remote requests).
	 *
	 * @return bool
	 */
	public static function add_or_remove( $gid, $args, $add = true, $post_site_url = '' ) {

		list( $blog_id, $post_id, $site_url ) = explode_gid( $gid );
		if ( $post_id === null ) {
			return false;
		}

		$result = false;

		// network post
		if ( ! $site_url ) {
			Multisite_Manager::switch_blog( $blog_id );
			$connection_map = $old_connection = self::get( $post_id );
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
				elseif ( empty( $post_site_url ) ) {
						unset( $connection_map[ $key ] );
				} elseif ( isset( $connection_map[ $post_site_url ] ) ) {
						unset( $connection_map[ $post_site_url ][ $key ] );
					if ( empty( $connection_map[ $post_site_url ] ) ) {
						unset( $connection_map[ $post_site_url ] );
					}
				}
			}

			if ( $connection_map == $old_connection ) {
				$result = true;
			} else {
				$result = update_post_meta( $post_id, 'contentsync_connection_map', $connection_map );
			}
			Multisite_Manager::restore_blog();
		}
		// remote post
		else {
			// the network url is the current network, as we just imported a post here
			$post_site_url = Urls::get_network_url();
			$result        = Remote_Request::update_remote_post_connection( $site_url, $gid, $args, $add, $post_site_url );
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
	public static function get( $post_id ) {

		$connection_map = get_post_meta( $post_id, 'contentsync_connection_map', true );
		if ( ! $connection_map || empty( $connection_map ) ) {

			// check if the post is an object or array
			if ( is_object( $post_id ) || is_array( $post_id ) ) {
				$post = (object) $post_id;
				if ( isset( $post->meta ) ) {
					$post->meta     = (array) $post->meta;
					$connection_map = isset( $post->meta['contentsync_connection_map'] ) ? $post->meta['contentsync_connection_map'] : null;
					if ( is_array( $connection_map ) && isset( $connection_map[0] ) ) {
						$connection_map = $connection_map[0];
					}
				}
			} else {
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
				$connection_map[ $blog_id_or_net_url ] = self::create_array( $blog_id_or_net_url, $post_id );
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
	public static function create_array( $blog_id, $post_id ) {
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
	public static function to_destination_ids( $connection_map ) {

		$destination_ids = array();

		if ( ! $connection_map ) {
			return $destination_ids;
		}

		foreach ( $connection_map as $blog_id_or_net_url => $post_array_or_blog_array ) {
			if ( is_numeric( $blog_id_or_net_url ) ) {
				$destination_ids[] = $blog_id_or_net_url;
			} else {
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

		Multisite_Manager::switch_blog( $blog_id );
		$edit_url = Urls::get_edit_post_link( $post_id );
		Multisite_Manager::restore_blog();

		$blog_url = get_site_url( $blog_id );
		$nice_url = strpos( $blog_url, '://' ) !== false ? explode( '://', $blog_url )[1] : $blog_url;
		return array(
			'edit' => $edit_url,
			'blog' => $blog_url,
			'nice' => $nice_url,
		);
	}

	/**
	 * Get edit, blog and nice url of a synced post by GID.
	 *
	 * @param string $gid
	 *
	 * @return array
	 */
	public static function get_links_by_gid( $gid ) {

		$post_links = array();

		$synced_post = get_synced_post( $gid );

		if ( ! $synced_post ) {
			return $post_links;
		}

		list( $root_blog_id, $root_post_id, $root_net_url ) = explode_gid( $gid );

		// local network post
		if ( empty( $root_net_url ) ) {
			$post_links = self::get_local_post_links( $root_blog_id, $root_post_id );
		}
		// remote post
		elseif ( $synced_post && $synced_post->post_links ) {
				$post_links = (array) $synced_post->post_links;
		} else {
			$post_links = array(
				'edit' => $root_net_url,
				'blog' => $root_net_url,
				'nice' => $root_net_url,
			);
		}
		return $post_links;
	}

	/**
	 * Get all imported version of remote posts on this multisite.
	 *
	 * @param string $gid           Global ID of the root post.
	 *
	 * @return array|false          @see create_array()
	 */
	public static function get_network_remote_by_gid( $gid ) {

		// loop through all blogs and get the posts
		$connection_map = array();
		foreach ( Multisite_Manager::get_all_blogs() as $blog_id => $blog_args ) {

			Multisite_Manager::switch_blog( $blog_id );
			$post = Synced_Post_Query::get_local_post_by_gid( $gid );
			if ( $post ) {
				$connection_map[ $blog_id ] = self::create_array( $blog_id, $post->ID );
			}
			Multisite_Manager::restore_blog();
		}

		return array(
			Urls::get_network_url() => $connection_map,
		);
	}

	/**
	 * Check for missing or deleted connections of a root post.
	 *
	 * @note   This function is usually called via js ajax.
	 *         JS function: contentSync.checkRootConnections();
	 *
	 * @update The function received a huge update in 1.7.0. It now checks for
	 *         missing connections on all sites and not only on the current site.
	 *         It also checks for missing connections on remote sites.
	 *
	 * @param string $post_id
	 *
	 * @return bool
	 */
	public static function check( $post_id ) {

		$status = Post_Meta::get_values( $post_id, 'synced_post_status' );
		if ( $status !== 'root' ) {
			return array(
				'status' => 'not_root_post',
				'text'   => __( 'This is not the source post.', 'contentsync' ),
			);
		}

		$gid            = Synced_Post_Utils::get_gid( $post_id );
		$connection_map = self::get( $post_id );
		$cur_net_url    = Urls::get_network_url();
		$return         = array();

		$updated_post_connection_map = array();

		/**
		 * get local connected posts
		 */
		$local_connected_posts = self::get_all_local_connections( $gid );
		if ( ! empty( $local_connected_posts ) ) {
			$updated_post_connection_map = $local_connected_posts;
		}

		/**
		 * get remote connected posts
		 */
		$remote_connected_posts = array();
		foreach ( Site_Connection::get_all() as $site_url => $connected_site ) {
			$remote_connected_posts = Remote_Request::get_all_remote_connected_posts( $connected_site, $gid );
			if ( ! empty( $remote_connected_posts ) ) {
				$remote_connected_posts                   = (array) $remote_connected_posts;
				$updated_post_connection_map[ $site_url ] = array_map(
					function ( $item ) {
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
					 */
					if ( ! isset( $local_connected_posts[ intval( $_blog_id ) ] ) ) {
						$return[] = sprintf(
							__( 'An orphaned post connection (%s) was deleted.', 'contentsync' ),
							"$_blog_id-{$_post_con['post_id']}"
						);
					}
				} else {

					$rem_net_url = $_blog_id;

					/**
					 * If posts from this remote connection are not found, we keep them
					 * around for now. Otherwise we would have to delete all posts from
					 * this remote connection, even if the connection maybe only went
					 * down for a short time.
					 */
					if ( ! isset( $updated_post_connection_map[ $rem_net_url ] ) ) {
						$updated_post_connection_map[ $rem_net_url ] = $_post_con;
						$return[]                                    = sprintf(
							__( 'Some orphaned remote post links (website: %s) were not found because no connection were found to the site. The links were not deleted for the time being.', 'contentsync' ),
							$rem_net_url
						);
					}
					/**
					 * If posts from this remote connection are found, we check if the
					 * post is still connected to the root post. If not, we delete the
					 * connection.
					 */
					else {
						$rem_gid = get_current_blog_id() . '-' . $post_id . '-' . $cur_net_url;
						foreach ( $_post_con as $rem_blog_id => $rem_post_con ) {
							if ( ! isset( $updated_post_connection_map[ $rem_net_url ][ intval( $rem_blog_id ) ] ) ) {
								$return[] = sprintf(
									__( 'An orphaned post connection (%s) was deleted.', 'contentsync' ),
									"$rem_net_url: $rem_blog_id-{$rem_post_con['post_id']}"
								);
							}
						}
					}
				}
			}
		}

		// update post meta
		if ( $connection_map != $updated_post_connection_map ) {
			$result   = update_post_meta( $post_id, 'contentsync_connection_map', $updated_post_connection_map );
			$return[] = $result ? sprintf(
				__( 'The post connections have been updated.', 'contentsync' )
			) : sprintf(
				__( 'The post connections could not be updated.', 'contentsync' )
			);
		}

		// return message to AJAX
		return empty( $return ) ? array(
			'status' => 'ok',
			'text'   => __( 'No missing connections.', 'contentsync' ),
		) : array(
			'status' => 'connections_repaired',
			'text'   => implode( ' ', $return ),
		);
	}

	/**
	 * Get all connected posts of a synced post on this network
	 * by using a multisite query.
	 *
	 * @param string $gid
	 *
	 * @return array[] Array of post connections, similar to Synced_Post, has:
	 *             $connection['post_id']
	 *             $connection['edit']
	 *             $connection['blog']
	 *             $connection['nice']
	 */
	public static function get_all_local_connections( $gid ) {

		$post_connections = array();
		$connected_posts  = self::get_all_local_linked_posts( $gid );

		if ( empty( $connected_posts ) ) {
			return array();
		}

		foreach ( $connected_posts as $result ) {
			$blog_id                      = intval( $result->blog_id );
			$post_connections[ $blog_id ] = self::convert_to_connection( $result );
		}

		return $post_connections;
	}

	/**
	 * Get all connected posts of a synced post on this network
	 * by using a multisite query.
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

		list( $root_blog_id, $root_post_id, $root_site_url ) = explode_gid( $gid );

		$network_url = Urls::get_network_url();

		// build sql query
		$results = array();
		foreach ( Multisite_Manager::get_all_blogs() as $blog_id => $blog_args ) {
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
				function ( $post ) use ( $root_blog_id, $root_post_id, $root_site_url ) {
					return $post->blog_id !== $root_blog_id || $post->ID !== $root_post_id;
				}
			);
		}

		return $results;
	}

	/**
	 * Convert the raw $wpdb result into a post connection array.
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
	public static function convert_to_connection( $result ) {

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
			);
		} elseif ( is_array( $result ) ) {
			return $result;
		}

		return array();
	}
}
