<?php
/**
 * Synced Post Service
 *
 * This service class provides action methods for managing synced posts,
 * including import, export, update, delete, and linking/unlinking operations.
 */

namespace Contentsync\Post_Sync;

use Exception;
use Contentsync\Cluster\Cluster_Service;
use Contentsync\Distribution\Distributor;
use Contentsync\Utils\Post_Query;
use Contentsync\Post_Transfer\Post_Export;
use Contentsync\Post_Transfer\Post_Import;
use Contentsync\Utils\Logger;
use Contentsync\Utils\Multisite_Manager;

defined( 'ABSPATH' ) || exit;

/**
 * Class Synced_Post_Service
 *
 * Static helper class providing synced post action operations.
 */
class Synced_Post_Service {

	/**
	 * Create and return a new `Synced_Post` wrapper for a WordPress post.
	 *
	 * This convenience function instantiates the `Synced_Post` class using
	 * either a `WP_Post` object or a post ID. Any exceptions thrown
	 * during instantiation (for example if the post does not exist or
	 * does not qualify as a synced post) are caught and the function
	 * returns `false`. On success the new `Synced_Post` instance is
	 * returned to the caller.
	 *
	 * @param WP_Post|object|int $post Post object or post ID to wrap.
	 *
	 * @return Synced_Post|false A `Synced_Post` on success, or `false` if instantiation fails.
	 */
	public static function new_synced_post( $post ) {
		try {
			$synced_post = new Synced_Post( $post );
		} catch ( Exception $e ) {
			return false;
		}
		return $synced_post;
	}

	/**
	 * Make a post globally synced.
	 *
	 * @formerly contentsync_export_post
	 *
	 * This sets the default post meta for synced posts
	 *
	 * @param int   $post_id    Post ID.
	 * @param array $args       Export arguments.
	 *
	 * @return string   $gid
	 */
	public static function make_root_post( $post_id, $args ) {

		$first_post  = null;
		$post_export = new Post_Export( $post_id, $args );
		$posts       = $post_export->get_posts();
		$gid         = get_current_blog_id() . '-' . strval( $post_id );

		// loop through all the nested posts and make them global.
		foreach ( $posts as $_post_id => $_post ) {

			if ( $_post_id == $post_id ) {
				continue;
			}

			// don't make global if it is somehow already global
			if (
				! empty( get_post_meta( $_post_id, 'synced_post_id', true ) )
				|| ! empty( get_post_meta( $_post_id, 'synced_post_status', true ) )
			) {
				continue;
			}

			$_gid = get_current_blog_id() . '-' . strval( $_post_id ); // The global ID. Format {{blog_id}}-{{post_id}}

			update_post_meta( $_post_id, 'synced_post_id', $_gid );
			update_post_meta( $_post_id, 'synced_post_status', 'root' );
			update_post_meta( $_post_id, 'contentsync_export_options', (array) $args );

			// if there already were connections, we don't want to loose these
			$connection_map = Post_Connection_Map::get( $_post_id ) ?? array();
			update_post_meta( $_post_id, 'contentsync_connection_map', $connection_map );

			/**
			 */
			update_post_meta( $_post_id, 'contentsync_canonical_url', get_permalink( $_post_id ) );
		}

		update_post_meta( $post_id, 'synced_post_id', $gid );
		update_post_meta( $post_id, 'synced_post_status', 'root' );
		update_post_meta( $post_id, 'contentsync_export_options', (array) $args );

		// if there already were connections, we don't want to loose these
		$connection_map = Post_Connection_Map::get( $post_id ) ?? array();
		update_post_meta( $post_id, 'contentsync_connection_map', $connection_map );

		/**
		 */
		update_post_meta( $post_id, 'contentsync_canonical_url', get_permalink( $post_id ) );

		return $gid;
	}

	/**
	 * Import a synced post to the current blog
	 *
	 * @param string $gid               The global ID. Format {{blog_id}}-{{post_id}}
	 * @param array  $conflict_actions   Array of posts that already exist on the current blog.
	 *                                   Keyed by the same ID as in the @param $posts.
	 *                                  @property post_id: ID of the current post.
	 *                                  @property action: Action to be done (skip|replace|keep)
	 *
	 * @return bool|string  True on success. False or error message on failure.
	 */
	public static function import_synced_post( $gid, $conflict_actions = array() ) {

		$posts = Synced_Post_Query::prepare_synced_post_for_import( $gid );
		if ( ! $posts ) {
			return false;
		}

		Logger::add( '========= ALL POSTS PREPARED. NOW WE INSERT THEM =========' );

		$post_import = new Post_Import( $posts, $conflict_actions );
		$result      = $post_import->import_posts();

		if ( is_wp_error( $result ) ) {
			return $result->get_error_message();
		}
		return true;
	}

	/**
	 * Convert synced post to static one
	 *
	 * @param int $gid
	 *
	 * @return bool
	 */
	public static function unlink_root_post( $gid ) {

		list( $blog_id, $post_id, $net_url ) = Synced_Post_Utils::explode_gid( $gid );

		// only local network posts
		if ( ! empty( $net_url ) ) {
			return false;
		}

		Multisite_Manager::switch_blog( $blog_id );

		$connection_map = Post_Connection_Map::get( $post_id );

		// delete meta of imported posts
		if ( is_array( $connection_map ) && count( $connection_map ) > 0 ) {
			foreach ( $connection_map as $_blog_id => $post_connection ) {
				if ( is_numeric( $_blog_id ) ) {
					Multisite_Manager::switch_blog( $_blog_id );
					Post_Meta::delete_values( $post_connection['post_id'] );
					Multisite_Manager::restore_blog();
				} else {
					/**
					 * @todo handle remote connections
					 *
					 * ...for now, we do not delete remote post meta values.
					 */
				}
			}
		}

		// delete meta of exported post
		Post_Meta::delete_values( $post_id );

		Multisite_Manager::restore_blog();

		return true;
	}

	/**
	 * Unlink imported post from the synced post
	 *
	 * @param int $post_id  The ID of the imported post.
	 *
	 * @return bool
	 */
	public static function unlink_synced_post( $post_id ) {

		$gid    = get_post_meta( $post_id, 'synced_post_id', true );
		$result = Post_Connection_Map::remove( $gid, get_current_blog_id(), $post_id );

		Post_Meta::delete_values( $post_id );

		return true;
	}

	/**
	 * Trash connected posts.
	 *
	 * @param int   $post_id  The ID of the post to trash.
	 * @param array $connection_map  The connection map to use.
	 *
	 * @return bool
	 */
	public static function trash_connected_posts( $post_id, $connection_map = null ) {

		$result = true;

		if ( ! $connection_map ) {
			$connection_map = Post_Connection_Map::get( $post_id );
		}
		Logger::add( 'trash_connected_posts', $post_id );
		Logger::add( 'connection_map', $connection_map );

		$destination_ids = Post_Connection_Map::to_destination_ids( $connection_map );
		Logger::add( 'destination_ids', $destination_ids );

		$destination_arrays = array();
		foreach ( $destination_ids as $destination_id ) {
			$destination_arrays[ $destination_id ] = array(
				'import_action' => 'trash',
			);
		}
		Logger::add( 'destination_arrays', $destination_arrays );

		if ( is_object( $post_id ) && is_a( $post_id, 'Contentsync\Post_Transfer\Prepared_Post' ) ) {
			$post_id->import_action = 'trash';
		}

		$result = Distributor::distribute_single_post( $post_id, $destination_arrays );

		return $result;
	}

	/**
	 * Untrash connected posts.
	 *
	 * @todo maybe rework with new distribution system?
	 *
	 * @param int  $post_id  The ID of the post to untrash.
	 * @param bool $delete  Whether to delete the posts.
	 *
	 * @return bool
	 */
	public static function untrash_connected_posts( $post_id, $delete = false ) {

		$result = true;

		$root_gid = Post_Meta::get_values( $post_id, 'synced_post_id' );
		// debug($root_gid);

		// debug("untrash linked posts");
		$destination_ids = array();
		foreach ( Cluster_Service::get_clusters_including_post( $post_id ) as $cluster ) {
			$destination_ids = array_merge( $destination_ids, $cluster->destination_ids );
		}
		$destination_ids = array_unique( $destination_ids );
		foreach ( $destination_ids as $destination_id ) {

			if ( empty( $destination_id ) ) {
				continue;
			}

			Multisite_Manager::switch_blog( $destination_id );
			$trashed = Post_Query::get_unfiltered_posts(
				array(
					'numberposts' => -1,
					'post_status' => 'trash',
				)
			);
			// debug($trashed);

			if ( $trashed ) {
				foreach ( $trashed as $trash ) {
					$status = Post_Meta::get_values( $trash->ID, 'synced_post_status' );
					$gid    = Post_Meta::get_values( $trash->ID, 'synced_post_id' );
					if ( $status == 'linked' && $gid == $root_gid ) {
						// debug($trash);
						if ( ! $delete ) {
							$result = wp_untrash_post( $trash->ID, true );
							if ( $result ) {
								Logger::add( "post {$trash->ID} on blog {$destination_id} untrashed." );
							} else {
								Logger::add( "post {$trash->ID} on blog {$destination_id} could NOT be untrashed." );
							}
						} else {
							$result = wp_delete_post( $trash->ID, true );
							if ( $result ) {
								Logger::add( "post {$trash->ID} on blog {$destination_id} deleted." );
							} else {
								Logger::add( "post {$trash->ID} on blog {$destination_id} could NOT be deleted." );
							}
						}
					}
				}
			}

			Multisite_Manager::restore_blog();
		}

		return $result;
	}

	/**
	 * Delete connected posts.
	 *
	 * @param int   $post_id  The ID of the post to delete.
	 * @param array $connection_map  The connection map to use.
	 *
	 * @return bool
	 */
	public static function delete_connected_posts( $post_id, $connection_map = null ) {

		if ( ! $connection_map ) {
			$connection_map = Post_Connection_Map::get( $post_id );
		}
		Logger::log( 'delete_connected_posts', $post_id );
		Logger::log( 'connection_map', $connection_map );

		$destination_ids = Post_Connection_Map::to_destination_ids( $connection_map );
		Logger::log( 'destination_ids', $destination_ids );

		$destination_arrays = array();
		foreach ( $destination_ids as $destination_id ) {
			$destination_arrays[ $destination_id ] = array(
				'import_action' => 'delete',
			);
		}
		Logger::log( 'destination_arrays', $destination_arrays );

		$result = Distributor::distribute_single_post( $post_id, $destination_arrays );

		return true;
	}

	/**
	 * Unlink connected posts.
	 *
	 * @param int $post_id  The ID of the post to unlink.
	 *
	 * @return bool
	 */
	public static function unlink_connected_posts( $post_id ) {

		$result = true;

		$root_gid = Post_Meta::get_values( $post_id, 'synced_post_id' );

		$destination_ids = array();
		foreach ( Cluster_Service::get_clusters_including_post( $post_id ) as $cluster ) {
			$destination_ids = array_merge( $destination_ids, $cluster->destination_ids );
		}
		$destination_ids = array_unique( $destination_ids );
		// debug($destination_ids);
		foreach ( $destination_ids as $destination_id ) {
			if ( empty( $destination_id ) ) {
				continue;
			}
			$result = Post_Connection_Map::remove( $root_gid, $destination_id, $post_id );
		}

		return $result;
	}

	/**
	 * Delete unlinked posts.
	 *
	 * @param int $post  The post to delete.
	 *
	 * @return bool
	 */
	public static function delete_unlinked_posts( $post ) {

		if ( ! $connection_map ) {
			$connection_map = Post_Connection_Map::get( $post );
		}
		Logger::log( 'delete_unlinked_posts', $post );
		Logger::log( 'connection_map', $connection_map );

		$destination_ids = Post_Connection_Map::to_destination_ids( $connection_map );
		Logger::log( 'destination_ids', $destination_ids );

		$destination_arrays = array();
		foreach ( $destination_ids as $destination_id ) {
			$destination_arrays[ $destination_id ] = array(
				'import_action' => 'delete',
			);
		}
		Logger::log( 'destination_arrays', $destination_arrays );

		$result = Distributor::distribute_single_post( $post, $destination_arrays );

		return $result;
	}

	/**
	 * Permanently delete a synced post and all it's linked posts.
	 * The root post needs to be on the current network. This can not be undone.
	 *
	 * @todo REWORK with new distributor (test)
	 *
	 * @param string $gid
	 * @param bool   $keep_root_post  Whether to keep the root post.
	 *
	 * @return WP_Post|false|null Post data on success, false or null on failure.
	 */
	public static function delete_root_post_and_connected_posts( $gid, $keep_root_post = false ) {

		Logger::add( sprintf( "DELETE synced post with gid '%s'", $gid ) );

		// needs to be from this site
		list( $root_blog_id, $root_post_id, $root_net_url ) = Synced_Post_Utils::explode_gid( $gid );
		if ( $root_post_id === null || ! empty( $root_net_url ) ) {
			return false;
		}

		// needs to be the root post
		$synced_post = Synced_Post_Query::get_synced_post( $gid );
		if ( ! $synced_post || $synced_post->meta['synced_post_status'] !== 'root' ) {
			return false;
		}

		$result = true;
		Multisite_Manager::switch_blog( $root_blog_id );

		// delete imported posts
		$connection_map = Post_Connection_Map::get( $synced_post->ID );
		Logger::log( 'delete_root_post_and_connected_posts', $synced_post->ID );
		Logger::log( 'connection_map', $connection_map );

		$destination_ids = Post_Connection_Map::to_destination_ids( $connection_map );
		Logger::log( 'destination_ids', $destination_ids );

		$destination_arrays = array();
		foreach ( $destination_ids as $destination_id ) {
			$destination_arrays[ $destination_id ] = array(
				'import_action' => 'delete',
			);
		}
		Logger::log( 'destination_arrays', $destination_arrays );

		$result = Distributor::distribute_single_post( $post, $destination_arrays );

		// delete the root post
		if ( ! $keep_root_post ) {
			$result = wp_delete_post( $root_post_id, true );
		}

		// restore blog
		Multisite_Manager::restore_blog();

		return $result;
	}

	/**
	 * Function to check if current user is allowed to edit synced postss.
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
}
