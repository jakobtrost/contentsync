<?php
/**
 * Content Syncs Actions
 *
 * Handles all post actions, like import, export, update, delete, etc.
 *
 * This file exposes a collection of utility functions that implement
 * import, export and update operations for synced posts. It hooks into
 * filters and actions to manage conflict resolution, update post
 * metadata, and queue exports via the distribution system.
 */

namespace Contentsync\Posts\Sync;

use Contentsync\Posts\Transfer\Post_Export;
use Contentsync\Utils\Multisite_Manager;
use Contentsync\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
function make_post_synced( $post_id, $args ) {

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
		$connection_map = get_post_connection_map( $_post_id ) ?? array();
		update_post_meta( $_post_id, 'contentsync_connection_map', $connection_map );

		/**
		 * @since 2.3.0 set 'contentsync_canonical_url' to permalink
		 */
		update_post_meta( $_post_id, 'contentsync_canonical_url', get_permalink( $_post_id ) );
	}

	update_post_meta( $post_id, 'synced_post_id', $gid );
	update_post_meta( $post_id, 'synced_post_status', 'root' );
	update_post_meta( $post_id, 'contentsync_export_options', (array) $args );

	// if there already were connections, we don't want to loose these
	$connection_map = get_post_connection_map( $post_id ) ?? array();
	update_post_meta( $post_id, 'contentsync_connection_map', $connection_map );

	/**
	 * @since 2.3.0 set 'contentsync_canonical_url' to permalink
	 */
	update_post_meta( $post_id, 'contentsync_canonical_url', get_permalink( $post_id ) );

	return $gid;
}


/**
 * =================================================================
 *                          IMPORT
 * =================================================================
 */

/**
 * Check synced posts for conflicts on this page
 *
 * @param string $gid   Global ID.
 *
 * @return string   Encoded array of conflicts or post_title.
 */
function check_synced_post_import( $gid ) {

	$posts = prepare_synced_post_for_import( $gid );
	if ( ! $posts ) {
		return false;
	}

	// get conflicting posts
	$conflicts = Main_Helper::call_post_export_func( 'import_get_conflict_posts_for_backend_form', $posts );

	if ( $conflicts ) {
		return $conflicts;
	} else {
		// return post title when no conflicts found
		$root_post = array_shift( $posts );
		return $root_post->post_title;
	}
}

/**
 * Remove conflicts when same global ID is set. This means the posts
 * both depend on the same synced post.
 *
 * @filter 'contentsync_import_conflicts'
 *
 * @param array[WP_Post] $conflicts     WP_Post objects in conflict with importing posts.
 * @param array[WP_Post] $all_posts     All prepared WP_Post objects.
 */
function remove_conflict_when_same_gid( $conflicts, $all_posts ) {

	foreach ( $conflicts as $post_id => $post ) {

		$current_gid = get_gid( $post->ID );
		$import_gid  = get_gid( $all_posts[ $post_id ] );

		// remove the conflict if it is the same synced post
		if ( get_synced_post( $current_gid ) && $current_gid === $import_gid ) {
			unset( $conflicts[ $post_id ] );
		}
	}
	return $conflicts;
}

add_filter( 'import_synced_post_conflicts', __NAMESPACE__ . '\remove_conflict_when_same_gid', 10, 2 );

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
function import_synced_post( $gid, $conflict_actions = array() ) {

	$posts = prepare_synced_post_for_import( $gid );
	if ( ! $posts ) {
		return false;
	}

	Logger::add( '========= ALL POSTS PREPARED. NOW WE INSERT THEM =========' );

	$result = Main_Helper::import_posts( $posts, $conflict_actions );

	if ( is_wp_error( $result ) ) {
		return $result->get_error_message();
	}
	return true;
}

/**
 * Filter the conflict actions before every import, so all global
 * posts are matched with existing ones.
 *
 * @filter 'contentsync_import_conflict_actions'
 *
 * @param array $conflict_actions   Keyed by orig. ID, values contain 'post_id' & 'action'
 * @param array $all_posts          All posts to be imported.
 */
function match_synced_posts_before_import( $conflict_actions, $all_posts ) {

	if ( ! is_array( $all_posts ) ) {
		$all_posts = (array) $all_posts;
	}

	/**
	 * Look for existing local posts with the same gid and skip them.
	 */
	foreach ( $all_posts as $post_id => $post ) {

		if ( ! is_object( $post ) ) {
			$post = (object) $post;
		}

		// skip if already set
		if ( isset( $conflict_actions[ $post->ID ] ) ) {
			continue;
		}

		/**
		 * Filter to modify the GID (Global ID) used for conflict resolution during import.
		 *
		 * This filter allows developers to customize how the GID is generated or retrieved
		 * when checking for conflicts between synced posts during import operations.
		 *
		 * @filter filter_gid_for_conflict_action
		 *
		 * @param string $gid    The GID (Global ID) for the post.
		 * @param int    $post_id The post ID.
		 * @param object $post   The post object.
		 *
		 * @return string $gid   The modified GID for conflict resolution.
		 */
		$gid = apply_filters( 'filter_gid_for_conflict_action', get_gid( $post ), $post->ID, (object) $post );

		$existing_post = get_local_post_by_gid( $gid, $post->post_type );
		if ( $existing_post ) {

			$action = isset( $post->is_contentsync_root_post ) && $post->is_contentsync_root_post ? 'replace' : 'skip';
			// if ( isset( $post->is_contentsync_root_post ) && $post->is_contentsync_root_post ) {
			// Logger::log( 'Post is contentsync_root_post: ' . $post->is_contentsync_root_post );
			// }

			$conflict_actions[ $post->ID ] = array(
				'post_id' => $existing_post->ID,
				'action'  => $action,
			);
			Logger::add( sprintf( "Matching local post found with GID '%s' and post-type '%s'.", $gid, $post->post_type ) );
		}
	}

	return $conflict_actions;
}

add_filter( 'contentsync_import_conflict_actions', __NAMESPACE__ . '\match_synced_posts_before_import', 10, 2 );

/**
 * =================================================================
 *                          UNLINK
 * =================================================================
 */

/**
 * Convert synced post to static one
 *
 * @param int $gid
 *
 * @return bool
 */
function unlink_synced_root_post( $gid ) {

	list( $blog_id, $post_id, $net_url ) = explode_gid( $gid );

	// only local network posts
	if ( ! empty( $net_url ) ) {
		return false;
	}

	Multisite_Manager::switch_blog( $blog_id );

	$connection_map = get_post_connection_map( $post_id );

	// delete meta of imported posts
	if ( is_array( $connection_map ) && count( $connection_map ) > 0 ) {
		foreach ( $connection_map as $_blog_id => $post_connection ) {
			if ( is_numeric( $_blog_id ) ) {
				Multisite_Manager::switch_blog( $_blog_id );
				delete_contentsync_meta_values( $post_connection['post_id'] );
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
	delete_contentsync_meta_values( $post_id );

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
function unlink_synced_post( $post_id ) {

	$gid    = get_post_meta( $post_id, 'synced_post_id', true );
	$result = remove_post_connection_from_connection_map( $gid, get_current_blog_id(), $post_id );

	delete_contentsync_meta_values( $post_id );

	return true;
}

/**
 * =================================================================
 *                          TRASH & DELETE
 * =================================================================
 */

/**
 * Trash connected posts.
 *
 * @param int   $post_id  The ID of the post to trash.
 * @param array $connection_map  The connection map to use.
 *
 * @return bool
 */
function trash_connected_posts( $post_id, $connection_map = null ) {

	$result = true;

	if ( ! $connection_map ) {
		$connection_map = get_post_connection_map( $post_id );
	}
	Logger::add( 'trash_connected_posts', $post_id );
	Logger::add( 'connection_map', $connection_map );

	$destination_ids = convert_connection_map_to_destination_ids( $connection_map );
	Logger::add( 'destination_ids', $destination_ids );

	$destination_arrays = array();
	foreach ( $destination_ids as $destination_id ) {
		$destination_arrays[ $destination_id ] = array(
			'import_action' => 'trash',
		);
	}
	Logger::add( 'destination_arrays', $destination_arrays );

	if ( is_object( $post_id ) && is_a( $post_id, 'Contentsync\Prepared_Post' ) ) {
		$post_id->import_action = 'trash';
	}

	$result = \Contentsync\Distribution\distribute_single_post( $post_id, $destination_arrays );

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
function untrash_connected_posts( $post_id, $delete = false ) {

	$result = true;

	$root_gid = get_contentsync_meta_values( $post_id, 'synced_post_id' );
	// debug($root_gid);

	// debug("untrash linked posts");
	$destination_ids = array();
	foreach ( get_clusters_including_post( $post_id ) as $cluster ) {
		$destination_ids = array_merge( $destination_ids, $cluster->destination_ids );
	}
	$destination_ids = array_unique( $destination_ids );
	foreach ( $destination_ids as $destination_id ) {

		if ( empty( $destination_id ) ) {
			continue;
		}

		Multisite_Manager::switch_blog( $destination_id );
		$trashed = \Contentsync\Posts\get_unfiltered_posts(
			array(
				'numberposts' => -1,
				'post_status' => 'trash',
			)
		);
		// debug($trashed);

		if ( $trashed ) {
			foreach ( $trashed as $trash ) {
				$status = get_contentsync_meta_values( $trash->ID, 'synced_post_status' );
				$gid    = get_contentsync_meta_values( $trash->ID, 'synced_post_id' );
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
function delete_connected_posts( $post_id, $connection_map = null ) {

	if ( ! $connection_map ) {
		$connection_map = get_post_connection_map( $post_id );
	}
	Logger::log( 'delete_connected_posts', $post_id );
	Logger::log( 'connection_map', $connection_map );

	$destination_ids = convert_connection_map_to_destination_ids( $connection_map );
	Logger::log( 'destination_ids', $destination_ids );

	$destination_arrays = array();
	foreach ( $destination_ids as $destination_id ) {
		$destination_arrays[ $destination_id ] = array(
			'import_action' => 'delete',
		);
	}
	Logger::log( 'destination_arrays', $destination_arrays );

	$result = \Contentsync\Distribution\distribute_single_post( $post_id, $destination_arrays );

	return true;
}

/**
 * Unlink connected posts.
 *
 * @param int $post_id  The ID of the post to unlink.
 *
 * @return bool
 */
function unlink_connected_posts( $post_id ) {

	$result = true;

	$root_gid = get_contentsync_meta_values( $post_id, 'synced_post_id' );

	$destination_ids = array();
	foreach ( get_clusters_including_post( $post_id ) as $cluster ) {
		$destination_ids = array_merge( $destination_ids, $cluster->destination_ids );
	}
	$destination_ids = array_unique( $destination_ids );
	// debug($destination_ids);
	foreach ( $destination_ids as $destination_id ) {
		if ( empty( $destination_id ) ) {
			continue;
		}
		$result = remove_post_connection_from_connection_map( $root_gid, $destination_id, $post_id );
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
function delete_unlinked_posts( $post ) {

	if ( ! $connection_map ) {
		$connection_map = get_post_connection_map( $post );
	}
	Logger::log( 'delete_unlinked_posts', $post );
	Logger::log( 'connection_map', $connection_map );

	$destination_ids = convert_connection_map_to_destination_ids( $connection_map );
	Logger::log( 'destination_ids', $destination_ids );

	$destination_arrays = array();
	foreach ( $destination_ids as $destination_id ) {
		$destination_arrays[ $destination_id ] = array(
			'import_action' => 'delete',
		);
	}
	Logger::log( 'destination_arrays', $destination_arrays );

	$result = \Contentsync\Distribution\distribute_single_post( $post, $destination_arrays );

	return $result;
}

/**
 * Permanently delete a synced post and all it's linked posts.
 * The root post needs to be on the current network. This can not be undone.
 *
 * @todo REWORK with new distributor (test)
 *
 * @param string $gid
 *
 * @return WP_Post|false|null Post data on success, false or null on failure.
 */
function delete_synced_post( $gid, $keep_root_post = false ) {

	Logger::add( sprintf( "DELETE synced post with gid '%s'", $gid ) );

	// needs to be from this site
	list( $root_blog_id, $root_post_id, $root_net_url ) = explode_gid( $gid );
	if ( $root_post_id === null || ! empty( $root_net_url ) ) {
		return false;
	}

	// needs to be the root post
	$synced_post = get_synced_post( $gid );
	if ( ! $synced_post || $synced_post->meta['synced_post_status'] !== 'root' ) {
		return false;
	}

	$result = true;
	Multisite_Manager::switch_blog( $root_blog_id );

	// delete imported posts
	$connection_map = get_post_connection_map( $synced_post->ID );
	Logger::log( 'delete_synced_post', $synced_post->ID );
	Logger::log( 'connection_map', $connection_map );

	$destination_ids = convert_connection_map_to_destination_ids( $connection_map );
	Logger::log( 'destination_ids', $destination_ids );

	$destination_arrays = array();
	foreach ( $destination_ids as $destination_id ) {
		$destination_arrays[ $destination_id ] = array(
			'import_action' => 'delete',
		);
	}
	Logger::log( 'destination_arrays', $destination_arrays );

	$result = \Contentsync\Distribution\distribute_single_post( $post, $destination_arrays );

	// delete the root post
	if ( ! $keep_root_post ) {
		$result = wp_delete_post( $root_post_id, true );
	}

	// restore blog
	Multisite_Manager::restore_blog();

	return $result;
}
