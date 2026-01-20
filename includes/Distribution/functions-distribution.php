<?php
/**
 * Distribution functions.
 *
 * These functions orchestrate the distribution of posts from the
 * originating site to connected blogs and remote networks. They schedule
 * asynchronous tasks using the Action Scheduler library, listen for
 * cron actions to process queued items, and provide methods to
 * distribute single posts or batches of posts. By centralising the
 * scheduling and execution logic in these functions, the plugin ensures that
 * distribution happens reliably even on large multisite installations.
 *
 * @since 2.17.0
 */

namespace Contentsync\Distribution;

use WP_Error;
use Contentsync\Utils\Logger;
use Contentsync\Posts\Transfer\Post_Export;
use Contentsync\Cluster\Cluster;
use Contentsync\Cluster\Content_Condition;
use Contentsync\Utils\Multisite_Manager;
use Contentsync\Distribution\Destinations\Remote_Destination
use Contentsync\Distribution\Destinations\Blog_Destination;

require_once CONTENTSYNC_PLUGIN_PATH . '/libs/action-scheduler/action-scheduler.php';

/**
 * =================================================================
 *                          PREPARATION
 * =================================================================
 */

/**
 * Distribute a single post to destination sites.
 *
 * @param int   $root_post                  Root post object or post ID.
 * @param array $destination_ids_or_arrays  Array of destination IDs or Arrays. (optional)
 * @param array $export_args                Export arguments. (optional)
 *
 * @return WP_Error|true  WP_Error on failure, true on success.
 */
function distribute_single_post( $root_post, $destination_ids_or_arrays = array(), $export_args = array() ) {

	// Logger::clear_log_file();

	// Logger::add( 'distribute_single_post' );
	// Logger::add( 'root_post', $root_post );
	// Logger::add( 'destination_ids_or_arrays', $destination_ids_or_arrays );
	// Logger::add( 'export_args', $export_args );

	if ( ! $root_post ) {
		return new WP_Error( 'post_not_found', __( 'Post not found.', 'global-contents' ) );
	}

	// Check if post is a root post.
	$root_post_status = get_contentsync_meta_values( $root_post, 'synced_post_status' );
	if ( $root_post_status !== 'root' ) {
		return new WP_Error( 'post_not_root', __( 'Post is not a root post.', 'global-contents' ) );
	}

	$root_post_id = is_object( $root_post ) ? $root_post->ID : $root_post;

	$post_ids = array( $root_post_id );

	// Build destination object.
	$destinations = get_destinations( $destination_ids_or_arrays, $root_post_id );

	// Prepare posts for distribution.
	$prepared_posts = prepare_posts_for_distribution(
		$post_ids,
		! empty( $export_args ) ? $export_args : get_contentsync_meta_values( $root_post, 'contentsync_export_options' ),
		$root_post_id
	);

	if ( is_wp_error( $prepared_posts ) ) {
		return $prepared_posts;
	}

	return schedule_post_distribution( $prepared_posts, $destinations );
}

/**
 * Distribute posts from one blog to destination sites.
 *
 * @param array $post_ids_or_objects        Array of post IDs or post objects.
 * @param array $destination_ids_or_arrays  Array of destination IDs. (optional)
 * @param array $export_args                Export arguments. (optional)
 *
 * @return WP_Error|true  WP_Error on failure, true on success.
 */
function distribute_posts( $post_ids_or_objects, $destination_ids_or_arrays = array(), $export_args = array() ) {

	Logger::add( 'distribute_posts' );
	// Logger::add( 'posts_or_ids', $post_ids_or_objects );
	// Logger::add( 'destination_ids_or_arrays', $destination_ids_or_arrays );
	// Logger::add( 'export_args', $export_args );

	if ( ! $post_ids_or_objects ) {
		return new WP_Error( 'invalid_posts', __( 'Invalid posts.', 'global-contents' ) );
	}

	// split the call into smaller chunks
	$chunk_size = defined( 'CONTENTSYNC_DISTRIBUTOR_CHUNK_SIZE' ) ? CONTENTSYNC_DISTRIBUTOR_CHUNK_SIZE : 10;
	if ( count( $post_ids_or_objects ) > $chunk_size ) {
		$chunks = array_chunk( $post_ids_or_objects, $chunk_size );
		$errors = array();

		foreach ( $chunks as $chunk ) {
			$result = distribute_posts( $chunk, $destination_ids_or_arrays, $export_args );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result;
			}
		}

		// error handling
		if ( ! empty( $errors ) ) {
			// combine error messages to one error
			$error_messages = array_map(
				function ( $error ) {
					return $error->get_error_message();
				},
				$errors
			);
			return new WP_Error( 'distribute_posts', implode( ', ', $error_messages ) );
		}

		return true;
	}

	// Build destination object.
	$destinations = get_destinations( $destination_ids_or_arrays );

	// Prepare posts for distribution.
	$prepared_posts = prepare_posts_for_distribution(
		$post_ids_or_objects,
		! empty( $export_args ) ? $export_args : array()
	);

	if ( is_wp_error( $prepared_posts ) ) {
		return $prepared_posts;
	}

	return schedule_post_distribution( $prepared_posts, $destinations );
}

/**
 * Distribute posts from different blogs to destination sites.
 *
 * @param array $posts_keyed_by_blog        Array of posts keyed by blog ID.
 * @param array $destination_ids_or_arrays  Array of destination IDs. (optional)
 * @param array $export_args                Export arguments. (optional)
 *
 * @return WP_Error|true  WP_Error on failure, true on success.
 */
function distribute_posts_per_blog( $posts_keyed_by_blog, $destination_ids_or_arrays = array(), $export_args = array() ) {

	$errors = array();

	Logger::add( 'distribute_posts_per_blog' );

	foreach ( $posts_keyed_by_blog as $blog_id => $posts ) {

		Multisite_Manager::switch_blog( $blog_id );

		$result = distribute_posts( $posts, $destination_ids_or_arrays, $export_args );

		if ( is_wp_error( $result ) ) {
			Logger::add( 'Error distributing posts to blog ' . $blog_id . ': ' . $result->get_error_message(), $posts, 'error' );
			$errors[] = $result;
		}
	}

	// error handling
	if ( ! empty( $errors ) ) {
		// combine error messages to one error
		$error_messages = array_map(
			function ( $error ) {
				return $error->get_error_message();
			},
			$errors
		);
		return new WP_Error( 'distribute_posts_per_blog', implode( ', ', $error_messages ) );
	}

	return true;
}

/**
 * Distribute cluster posts to all destinations.
 *
 * @param Cluster|int $cluster_or_cluster_id
 * @param array       $before
 *   @property array posts              Posts before the distribution, per blog_id.
 *   @property array destination_ids    Destination IDs before the distribution.
 *
 * @return WP_Error|true  WP_Error on failure, true on success.
 */
function distribute_cluster_posts( $cluster_or_cluster_id, $before = array() ) {

	Logger::add( 'distribute_cluster_posts' );
	// Logger::add( "Cluster or ID:", $cluster_or_cluster_id );
	// Logger::add( "Before:", $before );

	if ( ! $cluster_or_cluster_id instanceof \Contentsync\Cluster\Cluster ) {
		$cluster = get_cluster_by_id( $cluster_or_cluster_id );
		if ( ! $cluster ) {
			return false;
		}
	} else {
		$cluster = $cluster_or_cluster_id;
	}

	// format destinations
	$destination_arrays = array();
	foreach ( $cluster->destination_ids as $destination_id ) {
		if ( empty( $destination_id ) ) {
			continue;
		}
		$destination_arrays[ $destination_id ] = array();
	}

	$cluster_posts = get_cluster_posts_per_blog( $cluster );

	// Logger::add( 'Cluster:', $cluster );
	// Logger::add( 'Cluster posts:', $cluster_posts );

	/**
	 * If posts were in this cluster, but are not anymore, they need to be
	 * removed from all destinations.
	 */
	$cluster_posts_before = isset( $before['posts'] ) ? $before['posts'] : array();
	if ( ! empty( $cluster_posts_before ) ) {
		foreach ( $cluster_posts_before as $blog_id => $posts_before ) {

			// posts from an entire blog were removed from the cluster
			if ( ! isset( $cluster_posts[ $blog_id ] ) ) {
				$cluster_posts[ $blog_id ] = array();
				foreach ( $posts_before as $post_id => $post ) {
					$post->import_action                   = 'delete';
					$cluster_posts[ $blog_id ][ $post_id ] = $post;
				}
			} else {
				foreach ( $posts_before as $post_id => $post ) {
					// all posts that are not selected anymore, need to be removed
					if ( ! isset( $cluster_posts[ $blog_id ][ $post_id ] ) ) {
						$post->import_action                   = 'delete';
						$cluster_posts[ $blog_id ][ $post_id ] = $post;
					}
				}
			}
		}
	}

	/**
	 * If a destination was removed, all posts from this cluster need to be
	 * removed from that destination.
	 */
	$destination_ids_before = isset( $before['destination_ids'] ) ? $before['destination_ids'] : array();
	if ( ! empty( $destination_ids_before ) ) {
		foreach ( $destination_ids_before as $destination_id ) {
			if ( empty( $destination_id ) ) {
				continue;
			}
			if ( ! isset( $destination_arrays[ $destination_id ] ) ) {
				$destination_arrays[ $destination_id ] = array(
					'import_action' => 'delete',
				);
			}
		}
	}

	/**
	 * Distribute posts to all destinations, step by step per blog.
	 */
	$result = distribute_posts_per_blog( $cluster_posts, $destination_arrays );

	return $result;
}

/**
 * Distribute posts just for a specific condition.
 *
 * @param Content_Condition|int $condition_or_condition_id  The condition or the condition ID.
 * @param array                 $posts_before               Posts before the distribution.
 *
 * @return bool
 */
function distribute_cluster_content_condition_posts( $condition_or_condition_id, $posts_before = array() ) {

	Logger::add( 'distribute_cluster_content_condition_posts' );

	if ( ! $condition_or_condition_id instanceof \Contentsync\Cluster\Content_Condition ) {
		$condition = get_cluster_content_condition_by_id( $condition_or_condition_id );
		if ( ! $condition ) {
			return false;
		}
	} else {
		$condition = $condition_or_condition_id;
	}

	$cluster = get_cluster_by_id( $condition->contentsync_cluster_id );
	if ( ! $cluster ) {
		return false;
	}

	// format destinations
	$destination_arrays = array();
	foreach ( $cluster->destination_ids as $destination_id ) {
		if ( empty( $destination_id ) ) {
			continue;
		}
		$destination_arrays[ $destination_id ] = array();
	}

	// get posts for this condition
	$condition_posts = get_posts_by_cluster_content_condition( $condition );

	// export arguments
	$export_arguments = isset( $condition->export_arguments ) ? wp_parse_args(
		(array) $condition->export_arguments,
		array(
			'append_nested'  => false,
			'whole_posttype' => false,
			'all_terms'      => false,
			'resolve_menus'  => false,
			'translations'   => false,
		)
	) : array();

	/**
	 * If posts were in this condition, but are not anymore, they need to be
	 * removed from all destinations.
	 */
	if ( ! empty( $posts_before ) ) {
		foreach ( $posts_before as $post_id => $post ) {

			// all posts that are not selected anymore, need to be removed
			if ( ! isset( $condition_posts[ $post_id ] ) ) {
				$post->import_action         = 'delete';
				$condition_posts[ $post_id ] = $post;
			}
		}
	}

	// Distribute posts to all destinations
	$result = distribute_posts( $condition_posts, $destination_arrays, $export_arguments );

	return $result;
}

/**
 * Get all destinations.
 *
 * Map differences between the current connections (post meta: connection_map) and
 * new connections (cluster: destination_ids_or_arrays).
 *
 * @param array $destination_ids_or_arrays  Array of destination IDs or arrays.
 * @param int   $root_post_id               The root post ID. This is usually the post ID of the post that triggered
 *                                          the distribution. We use this to make sure this post will be updated and not
 *                                          skipped, as it probably has been changed.
 *
 * @return Destination[] Array of Blog_Destination and Remote_Destination objects.
 */
function get_destinations( $destination_ids_or_arrays, $root_post_id = 0 ) {

	Logger::add( 'get_destinations' );
	// Logger::add( 'destination_ids_or_arrays', $destination_ids_or_arrays );
	// Logger::add( 'root_post_id', $root_post_id );

	$destinations = array();

	/**
	 * Add new connections from destination_ids_or_arrays.
	 * The @param $destination_ids_or_arrays can be set in 2 formats:
	 *
	 * @example 1) simple array of strings.
	 * array(
	 *    '2',
	 *    '3|https://remote.site.com'
	 * )
	 *
	 * @example 2) array of arrays.
	 * array(
	 *   '2' => array(
	 *     'import_action'    => 'insert|draft|trash|delete',
	 *     'conflict_action'  => 'keep|replace|skip',
	 *     'export_arguments' => array( 'translations' => true )
	 *   ),
	 *   '3|https://remote.site.com' => array(
	 *     'import_action'    => 'insert|draft|trash|delete',
	 *     'conflict_action'  => 'keep|replace|skip',
	 *     'export_arguments' => array( 'translations' => true )
	 *   ),
	 *   ...
	 * )
	 *
	 * This can be used to delete all the posts from a blog:
	 * array(
	 *   '2' => array(
	 *     'import_action' => 'delete',
	 *   )
	 * )
	 */
	foreach ( $destination_ids_or_arrays as $destination_id_or_key => $destination_array_or_id ) {
		if ( is_array( $destination_array_or_id ) ) {
			$destination_id    = $destination_id_or_key;
			$destination_array = $destination_array_or_id;
		} else {
			$destination_id    = $destination_array_or_id;
			$destination_array = array();
		}

		if ( empty( $destination_id ) ) {
			continue;
		}

		// remote connection
		if ( strpos( $destination_id, '|' ) !== false ) {
			list( $remote_blog_id, $remote_network_url ) = explode( '|', $destination_id );

			$remote_network_url = \Contentsync\Utils\get_nice_url( $remote_network_url );

			if ( ! isset( $destinations[ $remote_network_url ] ) ) {
				$destinations[ $remote_network_url ] = new Remote_Destination( $remote_network_url );
			}

			$blog = $destinations[ $remote_network_url ]->set_blog( $remote_blog_id );

			// add destination properties to the blog
			if ( ! empty( $destination_array ) ) {
				$destinations[ $remote_network_url ]->blogs[ $remote_blog_id ]->set_properties( $destination_array );
			}
		}
		// local connection
		else {
			$blog_id = $destination_id;

			// We never distribute posts to the blog they are coming from.
			// This would potentially result in the root post being distributed to itself
			// which could result in it being deleted.
			if ( $blog_id == get_current_blog_id() ) {
				continue;
			}

			if ( ! isset( $destinations[ $blog_id ] ) ) {
				$destinations[ $blog_id ] = new Blog_Destination(
					$blog_id,
					array(
						'url' => get_home_url( $blog_id ),
					)
				);
			}

			// add destination properties to the blog
			if ( ! empty( $destination_array ) ) {
				$destinations[ $blog_id ]->set_properties( $destination_array );
			}
		}
	}

	if ( $root_post_id ) {
		$connection_map = get_post_connection_map( $root_post_id );

		// Logger::add( 'root post connection_map', $connection_map );
		// Logger::add( 'destinations so far:', $destinations );
		// return array();

		/**
		 * Map connection map
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
		 */
		foreach ( $connection_map as $blog_id_or_net_url => $post_connection_or_blogs ) {

			// local connection
			if ( is_numeric( $blog_id_or_net_url ) ) {
				$blod_id         = $blog_id_or_net_url;
				$post_connection = $post_connection_or_blogs;

				if ( ! isset( $destinations[ $blod_id ] ) ) {
					$destinations[ $blod_id ] = new Blog_Destination(
						$blod_id,
						array(
							'url' => get_home_url( $blod_id ),
						)
					);
				}

				// Logger::add( 'set post for blog '.$blod_id.':', $destinations[ $blod_id ] );

				$destinations[ $blod_id ]->set_post(
					$root_post_id,
					$post_connection['post_id'],
					array(
						'url' => $post_connection['edit'],
					)
				);
			}
			// remote connection
			else {
				$remote_network_url = $blog_id_or_net_url;
				$blogs              = $post_connection_or_blogs;

				if ( ! isset( $destinations[ $remote_network_url ] ) ) {
					$destinations[ $remote_network_url ] = new Remote_Destination( $remote_network_url );
				}

				foreach ( $blogs as $blog_id => $post_connection ) {
					$destinations[ $remote_network_url ]->set_blog( $blog_id )->set_post(
						$root_post_id,
						$post_connection['post_id'],
						array(
							'url' => $post_connection['edit'],
						)
					);
				}
			}
		}

		// Logger::add( 'destinations after connection_map:', $destinations );
	}

	return $destinations;
}

/**
 * Prepare post distribution.
 *
 * @param array $post_ids_or_objects Array of post IDs or post objects.
 *                                   If post objects are provided, they must have the property 'ID'.
 *                                   Also if set, the following properties are inherited to the prepared post:
 *                                       * 'import_action'
 *                                       * 'conflict_action'
 *                                       * 'export_arguments'
 * @param array $export_args         Export arguments.
 * @param int   $root_post_id        The root post ID. This is usually the post ID of the post that triggered
 *                                   the distribution. We use this to make sure this post will be updated and not
 *                                   skipped, as it probably has been changed.
 *
 * @return Prepared_Post[]|WP_Error  Preparred posts on success, WP_Error on failure.
 */
function prepare_posts_for_distribution( $post_ids_or_objects, $export_args = array(), $root_post_id = 0 ) {

	Logger::add( 'prepare_posts_for_distribution' );
	// Logger::add( 'posts_or_ids', $post_ids_or_objects );
	// Logger::add( 'export_args', $export_args );

	if ( ! is_array( $post_ids_or_objects ) ) {
		return new WP_Error( 'invalid_post_ids', __( 'Invalid post IDs.', 'global-contents' ) );
	}

	$prepared_posts = ( new Post_Export( $post_ids_or_objects, $export_args ) )->get_posts();

	$inherit_properties = array(
		'import_action',
		'conflict_action',
		'export_arguments',
		'is_contentsync_root_post',
	);

	/**
	 * Make sure all posts are synced posts.
	 */
	foreach ( $prepared_posts as $post_id => $post ) {
		$gid = isset( $post->meta['synced_post_id'] ) ? $post->meta['synced_post_id'][0] : '';

		/**
		 * If this is not a synced post yet, it has not been part of the
		 * export of the root post yet. So we need to make it a synced post.
		 */
		if ( empty( $gid ) ) {
			$gid = \Contentsync\Posts\Sync\make_post_global( $post_id, $export_args );

			/**
			 * We now manually set the global meta infos.
			 *
			 * @since 1.2
			 *
			 * This way, newly imported posts are automatically linked to the correct posts.
			 * If a post already exists on a blog (= the gid exists), it is skipped anyway
			 * and this change has no effect for that specific post.
			 */
			$prepared_posts[ $post_id ]->meta = array_merge(
				$prepared_posts[ $post_id ]->meta,
				array(
					'synced_post_id'     => array( $gid ),
					'synced_post_status' => array( 'linked' ),
				)
			);
		}

		/**
		 * Set fallback conflict action to 'replace', so that a conflicting post is
		 * always updated on the destination sites.
		 */
		$prepared_posts[ $post_id ]->conflict_action = 'replace';

		/**
		 * Set custom property to mark the root post.
		 *
		 * @var bool
		 */
		if ( $root_post_id && $root_post_id == $post_id ) {
			Logger::add( 'is_contentsync_root_post = true' );
			$prepared_posts[ $post_id ]->is_contentsync_root_post = true;
		}

		/**
		 * Inherit Properties from the $post_objects
		 */
		if ( isset( $post_objects[ $post_id ] ) ) {
			foreach ( $inherit_properties as $property ) {
				if ( isset( $post_objects[ $post_id ]->$property ) && ! empty( $post_objects[ $post_id ]->$property ) ) {
					$prepared_posts[ $post_id ]->$property = $post_objects[ $post_id ]->$property;
					// Logger::add( 'inherit property from post_objects: '.$property.' = ', $post_objects[ $post_id ]->$property );
				}
			}
		}

		/**
		 * @todo exclude post that are in review
		 */
	}

	/**
	 * Filter to modify the prepared posts before distribution.
	 *
	 * This filter allows developers to customize the posts that are prepared
	 * for distribution, enabling modifications to post data, structure, or
	 * filtering before the actual distribution process begins.
	 *
	 * @filter contentsync_prepared_posts_for_distribution
	 *
	 * @param Prepared_Post[] $prepared_posts Array of prepared posts for distribution.
	 * @param int[]           $post_ids        Array of post IDs being distributed.
	 * @param array           $export_args     Export arguments and configuration.
	 *
	 * @return Prepared_Post[] Modified array of prepared posts for distribution.
	 */
	return apply_filters( 'contentsync_prepared_posts_for_distribution', $prepared_posts, $post_ids_or_objects, $export_args );
}

/**
 * Schedule post distribution.
 *
 * @param Prepared_Post[] $prepared_posts  Array of prepared posts.
 * @param Destination[]   $destinations        Array of Blog_Destination and Remote_Destination objects.
 *
 * @return WP_Error|true  WP_Error on failure, true on success.
 */
function schedule_post_distribution( $prepared_posts, $destinations ) {

	Logger::add( 'schedule_post_distribution' );
	// Logger::add( 'prepared_posts', $prepared_posts );
	// Logger::add( 'destinations', $destinations );

	if ( empty( $prepared_posts ) || ! is_array( $prepared_posts ) ) {
		return new WP_Error( 'invalid_prepared_posts', __( 'Invalid prepared posts.', 'global-contents' ) );
	}

	if ( empty( $destinations ) || ! is_array( $destinations ) ) {
		return new WP_Error( 'invalid_destinations', __( 'Invalid destinations.', 'global-contents' ) );
	}

	$errors = array();

	foreach ( $destinations as $destination ) {

		$distribution_item_properties = array(
			'posts'       => $prepared_posts,
			'destination' => $destination,
		);

		$distribution_item = schedule_distribution_item( $distribution_item_properties );

		if ( is_wp_error( $distribution_item ) ) {
			$errors[] = $distribution_item;
		}
	}

	// error handling
	if ( ! empty( $errors ) ) {
		// combine error messages to one error
		$error_messages = array_map(
			function ( $error ) {
				return $error->get_error_message();
			},
			$errors
		);
		return new WP_Error( 'failed_to_schedule_distribution_items', implode( '<br>- ', $error_messages ) );
	}

	return true;
}

/**
 * Schedule a distribution item.
 *
 * @param array $distribution_item_properties  The distribution item properties.
 *   @property Prepared_Post[] $posts       Array of prepared posts.
 *   @property Destination      $destination The destination object
 *
 * @return WP_Error|int  WP_Error on failure, the action ID on success.
 */
function schedule_distribution_item( $distribution_item_properties ) {

	// create class instance
	$distribution_item = new Distribution_Item( $distribution_item_properties );

	// save it to the database
	$ID = $distribution_item->save();

	if ( ! $ID ) {
		return new WP_Error( 'failed_to_save_distribution_item', __( 'Failed to save distribution item.', 'global-contents' ) );
	}

	/**
	 * @return int The action ID. Zero if there was an error scheduling the action.
	 */
	$result = as_schedule_single_action(
		time(),
		'contentsync_distribute_item',
		// When scheduling the action, provide the arguments as an array.
		array( $ID )
	);

	if ( ! $result ) {

		$error = new WP_Error(
			'failed_to_schedule_distribution_item',
			sprintf(
				__( 'Failed to schedule posts to the destination (ID: %1$s, URL: %2$s)', 'global-contents' ),
				$distribution_item->destination->ID ?? '',
				$distribution_item->destination->url ?? '-'
			)
		);

		Logger::add( 'error', $error );

		// update the distribution item status to failed
		$result = $distribution_item->update(
			array(
				'status' => 'failed',
				'error'  => $error,
			)
		);

		if ( is_wp_error( $result ) ) {
			Logger::add( 'error', $result );
		}

		return $error;
	}

	return $distribution_item;
}

/**
 * Schedule a distribution item by ID.
 *
 * @param int $distribution_item_id  The ID of the distribution item to schedule.
 *
 * @return WP_Error|Distribution_Item  WP_Error on failure, the distribution item on success.
 */
function schedule_distribution_item_by_id( $distribution_item_id ) {

	// get the distribution item from the database
	$distribution_item = get_distribution_item( $distribution_item_id );

	if ( ! is_a( $distribution_item, 'Contentsync\Distribution_Item' ) ) {
		return new WP_Error( 'invalid_distribution_item', __( 'Invalid distribution item.', 'global-contents' ) );
	}

	/**
	 * @return int The action ID. Zero if there was an error scheduling the action.
	 */
	$result = as_schedule_single_action(
		time(),
		'contentsync_distribute_item',
		// When scheduling the action, provide the arguments as an array.
		array( $distribution_item_id )
	);

	if ( ! $result ) {

		$error = new WP_Error(
			'failed_to_schedule_distribution_item',
			sprintf(
				__( 'Failed to schedule posts to the destination (ID: %1$s, URL: %2$s)', 'global-contents' ),
				$distribution_item->destination->ID ?? '',
				$distribution_item->destination->url ?? '-'
			)
		);

		Logger::add( 'error', $error );

		// update the distribution item status to failed
		$result = $distribution_item->update(
			array(
				'status' => 'failed',
				'error'  => $error,
			)
		);

		if ( is_wp_error( $result ) ) {
			Logger::add( 'error', $result );
		}

		return $error;
	} else {
		$distribution_item->update(
			array(
				'status' => 'init',
				'error'  => null,
			)
		);
	}

	return $distribution_item;
}

/**
 * =================================================================
 *                          OPERATIONS
 * =================================================================
 */
add_action( 'contentsync_distribute_item', __NAMESPACE__ . '\\distribute_item', 10, 1 );

/**
 * Distribute a single item.
 *
 * Called by the action scheduler with the action 'contentsync_distribute_item'.
 *
 * @param int $item_id  The Distribution_Item ID.
 *
 * @return bool
 */
function distribute_item( $item_id ) {

	$item = get_distribution_item( $item_id );

	if ( ! is_a( $item, 'Contentsync\Distribution_Item' ) ) {
		return false;
	}

	$status = 'started';

	$result = $item->update(
		array(
			'status' => $status,
		)
	);

	// Distribute to local blog.
	if ( is_a( $item->destination, 'Contentsync\Destinations\Blog_Destination' ) ) {

		$result = distribute_to_blog( $item );

		if ( ! $result ) {
			$status = 'failed';
		} else {
			$status = 'success';
		}
	}

	// Distribute to remote site.
	if ( is_a( $item->destination, 'Contentsync\Destinations\Remote_Destination' ) ) {

		$result = distribute_to_remote_site( $item );

		if ( ! $result ) {
			$status = 'failed';
		} else {
			// item is not completed yet, as the remote site will start its own distribution
			// queue and will let us know when it is done separately.
			$status = 'started';
		}
	}

	Logger::add( 'Distribution to destination ' . $item->destination->ID . ' completed with status: ' . $status );

	$result = $item->update(
		array(
			'status' => $status,
		)
	);

	return $result;
}

/**
 * Distribute posts to local site.
 *
 * @param Distribution_Item $item  The distribution item.
 *
 * @return bool
 */
function distribute_to_blog( $item ) {

	Logger::add( 'Distributing to blog: ' . $item->destination->ID . ' - ' . $item->destination->url );

	Multisite_Manager::switch_blog( $item->destination->ID );

	/**
	 * This should be working, but it somehow influences the posts in
	 * following distribution tasks.
	 * Currently it is only used for the import_action 'delete'. See
	 * the comment below for the workaround.
	 */
	// $inherit_properties = array(
	// 'import_action',
	// 'conflict_action',
	// 'export_arguments',
	// );
	// // inherit properties from the destination
	// foreach( $inherit_properties as $property ) {
	// if ( isset($item->destination->$property) && ! empty($item->destination->$property) ) {
	// foreach ( $item->posts as $post_id => $prepared_post ) {
	// Logger::add( 'inherit property from destination: ' . $property . ' = ' . $item->destination->$property );
	// $item->posts[ $post_id ]->$property = $item->destination->$property;
	// }
	// }
	// }

	// find the local posts and delete them
	if ( isset( $item->destination->import_action ) && $item->destination->import_action == 'delete' ) {
		$result = delete_posts_from_blog( $item );
	} else {

		if ( isset( $item->destination->import_action ) && $item->destination->import_action != 'insert' ) {
			// map the import action to each post
			foreach ( $item->posts as $post_id => $prepared_post ) {
				$item->posts[ $post_id ]->import_action = $item->destination->import_action;
			}
		}

		$result = import_posts_to_blog( $item );
	}

	Multisite_Manager::restore_blog();

	return $result;
}

/**
 * Import posts to blog.
 *
 * @param Distribution_Item $item  The distribution item.
 *
 * @return bool
 */
function import_posts_to_blog( &$item ) {
	Logger::add( 'Importing posts to blog: ' . $item->destination->ID );

	// import the posts
	$result = Main_Helper::import_posts( $item->posts );

		// error importing posts
	if ( is_wp_error( $result ) ) {
		$item->destination->error = $result;
		return false;
	}

	// add imported post ids to destination
	$imported_post_ids = method_exists( '\Contentsync\Posts\Transfer\Post_Import', 'get_all_posts' ) ? \Contentsync\Posts\Transfer\Post_Import::get_all_posts() : false;
	if ( $imported_post_ids ) {
		foreach ( $imported_post_ids as $root_post_id => $imported_post_id ) {
			$item->destination->set_post(
				$root_post_id,
				$imported_post_id,
				array(
					'status' => 'success',
					'url'    => \Contentsync\Utils\get_edit_post_link( $imported_post_id ),
				)
			);
		}
	}

	$item->destination->status = 'success';

	return true;
}

/**
 * Delete posts from blog.
 *
 * @param Distribution_Item $item  The distribution item.
 *
 * @return bool
 */
function delete_posts_from_blog( &$item ) {
	Logger::add(
		'Deleting posts from blog: ' . $item->destination->ID,
		array_map(
			function ( $post ) {
				return $post->post_name;
			},
			$item->posts
		)
	);

	$errors = array();

	foreach ( $item->posts as $post_id => $prepared_post ) {
		$gid = isset( $prepared_post->meta['synced_post_id'] ) ? $prepared_post->meta['synced_post_id'][0] : false;
		// Logger::add( 'Deleting post with gid: ' . $gid );
		if ( $gid ) {
			$posttype   = $prepared_post->post_type;
			$local_post = \Contentsync\get_local_post_by_gid( $gid, $posttype );
			if ( $local_post ) {
				// Logger::add( 'Deleting local post with id: ' . $local_post->ID );
				$result = wp_delete_post( $local_post->ID, true );
				if ( $result ) {
					// Logger::add( 'Local post deleted' );
				} else {
					Logger::add( 'Error deleting local post' );
					$errors[] = 'Error deleting local post with gid: ' . $gid . ' ';
				}
			} else {
				Logger::add( 'Local post not found' );
				$errors[] = 'Local post could not be deleted, not found by gid: ' . $gid . ' ';
			}
		}
	}

	if ( ! empty( $errors ) ) {
		$item->destination->error = new \WP_Error( 'delete_posts_from_blog', implode( '. ', $errors ) );
		return false;
	}

	return true;
}

/**
 * Distribute posts to remote site.
 *
 * @param Distribution_Item $item  The distribution item.
 *
 * @return bool
 */
function distribute_to_remote_site( &$item ) {
	Logger::add( 'Distributing to remote site:', $item->destination->ID );

	$result = \Contentsync\Api\distribute_item_to_remote_site( $item->destination->ID, $item );

	Logger::add( 'Result: ', $result );

	if ( is_wp_error( $result ) ) {
		Logger::add( 'Error distributing to remote site: ' . $result->get_error_message() );
		$item->destination->error = $result;
		return false;
	}

	if ( is_object( $result ) && isset( $result->error ) ) {
		Logger::add( 'Error distributing to remote site: ' . $result->error );
		$item->destination->error = new \WP_Error( 'distribute_to_remote_site', $result->error );
		return false;
	}

	if ( is_array( $result ) && isset( $result['error'] ) ) {
		Logger::add( 'Error distributing to remote site: ' . $result['error'] );
		$item->destination->error = new \WP_Error( 'distribute_to_remote_site', $result['error'] );
		return false;
	}

	if ( ! $result ) {
		Logger::add( 'Not identified error distributing to remote site. The method "\Contentsync\Api\distribute_item_to_remote_site" returned NULL. This could be a timeout or a network error, or the connection feature or license is not enabled an the remote site.' );
		$item->destination->error = new \WP_Error( 'distribute_to_remote_site', __( 'Unknown error', 'global-contents' ) );
		return false;
	}

	return (bool) $result;
}

/**
 * =================================================================
 *                          SANITIZATION
 * =================================================================
 */
add_action( 'contentsync_before_import_synced_posts', __NAMESPACE__ . '\\before_import_synced_posts', 10, 2 );
add_action( 'contentsync_after_import_synced_posts', __NAMESPACE__ . '\\after_import_synced_posts', 10, 2 );

/**
 * Before import synced posts: Filter the HTML tags that are allowed for a given context.
 *
 * @param array $posts  The posts to import.
 * @param array $conflict_actions  The conflict actions.
 *
 * @return void
 */
function before_import_synced_posts( $posts, $conflict_actions ) {
	add_filter( 'wp_kses_allowed_html', __NAMESPACE__ . '\\filter_allowed_html_tags_during_distribution', 98, 2 );
}

/**
 * After import synced posts: Remove the filter for the HTML tags that are allowed for a given context.
 *
 * @param array $posts  The posts to import.
 * @param array $conflict_actions  The conflict actions.
 *
 * @return void
 */
function after_import_synced_posts( $posts, $conflict_actions ) {
	remove_filter( 'wp_kses_allowed_html', __NAMESPACE__ . '\\filter_allowed_html_tags_during_distribution', 98 );
}

/**
 * Filters the HTML tags that are allowed for a given context.
 *
 * HTML tags and attribute names are case-insensitive in HTML but must be
 * added to the KSES allow list in lowercase. An item added to the allow list
 * in upper or mixed case will not recognized as permitted by KSES.
 *
 * @param array[] $html    Allowed HTML tags.
 * @param string  $context Context name.
 */
function filter_allowed_html_tags_during_distribution( $html, $context ) {

	if ( $context !== 'post' ) {
		return $html;
	}

	$default_attributes = array(
		'id'            => true,
		'class'         => true,
		'href'          => true,
		'name'          => true,
		'target'        => true,
		'download'      => true,
		'data-*'        => true,
		'style'         => true,
		'title'         => true,
		'role'          => true,
		'onclick'       => true,
		'aria-*'        => true,
		'aria-expanded' => true,
		'aria-controls' => true,
		'aria-label'    => true,
		'tabindex'      => true,
	);

	// iframe
	$html['iframe'] = array_merge(
		isset( $html['iframe'] ) ? $html['iframe'] : array(),
		$default_attributes,
		array(
			'src'             => true,
			'width'           => true,
			'height'          => true,
			'frameborder'     => true,
			'allowfullscreen' => true,
		)
	);

	// script
	$html['script'] = array_merge(
		isset( $html['script'] ) ? $html['script'] : array(),
		$default_attributes,
		array(
			'src'   => true,
			'type'  => true,
			'async' => true,
			'defer' => true,
		)
	);

	return $html;
}
