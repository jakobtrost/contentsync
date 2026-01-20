<?php
/**
 * Troubleshooting functions for synced posts
 *
 * These functions handle error detection and repair for synced posts
 * across multisite networks.
 */

namespace Contentsync\Admin;

use Contentsync\Utils\Multisite_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if a synced post has an error
 *
 * @param int|WP_Post $post     Either the post_id or the prepared post object.
 *
 * @return false|object         False if no error found, error object if found.
 */
function get_post_error( $post ) {
	if ( is_object( $post ) ) {
		if ( ! isset( $post->error ) || $post->error === null ) {
			$error = check_post_for_errors( $post );
		}
	} else {
		$error = check_post_for_errors( $post );
	}

	return $error;
}

/**
 * Get error message
 *
 * @return string
 */
function get_error_message( $error ) {
	return is_object( $error ) ? $error->message : '';
}

/**
 * Check if error is repaired
 *
 * @return bool Whether there was an error & it is repaired now.
 */
function is_error_repaired( $error ) {
	return is_object( $error ) ? $error->repaired : true;
}

/**
 * Get error repaired message
 *
 * @return string
 */
function get_error_repaired_log( $error ) {
	return is_error_repaired( $error ) ? $error->log : '';
}

/**
 * Check a synced post for errors.
 *
 * @param int|WP_Post                                              $post        Either the post_id or the prepared post object.
 * @param bool                                                     $autorepair  Autorepair simple errors, such as orphaned post connections.
 * @param bool                                                     $repair      Repair more complex errors, this can change meta infos or delete the post.
 *
 * @return false|object             False when no error is found, error object otherwise:
 *      @param string message       Description of the error.
 *      @param bool repaired        Whether it was repaired.
 *      @param string log           All repair logs as a single message.
 */
function check_post_for_errors( $post, $autorepair = true, $repair = false ) {

	$post = \Contentsync\Posts\Sync\new_synced_post( $post );
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
	$cur_net_url  = \Contentsync\Utils\get_network_url();

	list( $root_blog_id, $root_post_id, $root_net_url ) = \Contentsync\Posts\Sync\explode_gid( $gid );
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
			$error->message = __( 'The connection refers to this website.', 'contentsync' );

			if ( $autorepair || $repair ) {
				$new_gid    = $root_blog_id . '-' . $root_post_id;
				$update_gid = true;
			}
		} else {
			$connection = \Contentsync\Admin\get_site_connection( $root_net_url );

			// connection doesn't exist
			if ( ! $connection ) {
				$error->message = sprintf( __( 'The connection to the site %s does not exist.', 'contentsync' ), $root_net_url );

				if ( $autorepair || $repair ) {
					$convert_to_root = true;
				}
			}
			if ( ! $connection || ! isset( $connection['active'] ) || ! $connection['active'] ) {
				$error->message = sprintf( __( 'The connection to the website %s is inactive. The content cannot be synchronized.', 'contentsync' ), $root_net_url );

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
	Multisite_Manager::switch_blog( $blog_id );

	// this is a root post
	if ( $status == 'root' ) {
		if ( $root_blog_id != $blog_id || ! empty( $root_net_url ) ) {
			$error->message = __( 'The post is not originally from this page.', 'contentsync' );

			if ( $repair ) {
				if ( $root_post = \Contentsync\Posts\Sync\get_synced_post( $gid ) ) {
					$new_status         = 'linked';
					$restore_connection = true;
				} else {
					$convert_to_root = true;
				}
			}
		} elseif ( $root_post_id != $post_id ) {
			$error->message = __( 'The synced post ID is linked incorrectly.', 'contentsync' );

			if ( $repair ) {
				if ( $root_post = \Contentsync\Posts\Sync\get_synced_post( $gid ) ) {
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
			// Multisite_Manager::switch_blog( $imported_blog_id );
			// $imported_post_id = $post_connection['post_id'];
			// $imported_post = get_post( $imported_post_id );
			// if ( !$imported_post ) {
			// $error->message = __("Der Post hat noch mindestens eine verwaiste Verknüpfung zu einem gelöschten Post.", 'contentsync');

			// if ( $autorepair || $repair ) {
			// $orphan_connections[$imported_blog_id] = $imported_post_id;
			// }
			// }
			// Multisite_Manager::restore_blog();
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
				$error->message = __( 'This should be a global source post.', 'contentsync' );

				if ( $autorepair || $repair ) {
					$convert_to_root = true;
				}
			} else {
				$root_post = \Contentsync\Posts\Sync\get_post( $root_post_id );

				// root post found
				if ( $root_post ) {

					$error->message = sprintf(
						__( 'The source post is on the same page: %s', 'contentsync' ),
						"<a href='" . \Contentsync\Utils\get_edit_post_link( $root_post->ID ) . "' target='_blank'>{$root_post->post_title} (#{$root_post->ID})</a>"
					);

					if ( $repair ) {
						$delete_meta = true;
					}
				}
				// root post not found
				else {
					$error->message = __( 'The source post should be on the same page, but could not be found.', 'contentsync' );

					if ( $autorepair || $repair ) {
						$convert_to_root = true;
					}
				}
			}
		}
		// root comes from another blog
		else {

			$root_post = \Contentsync\Posts\Sync\get_synced_post( $gid );

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
					$error->message = __( 'The source post had no active connection to this blog.', 'contentsync' );

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
								__( 'The source post was linked to a deleted post on this page: %s', 'contentsync' ),
								"<a href='" . \Contentsync\Utils\get_edit_post_link( $connected_post_id_from_this_blog ) . "' target='_blank'>{$other_linked_post->post_title} (#{$other_linked_post->ID})</a>"
							);

							if ( $autorepair || $repair ) {
								$restore_connection = true;
							}
						} elseif ( $post->post_status === 'publish' && $other_linked_post->post_status !== 'publish' ) {
							$error->message = sprintf(
								__( 'The source post is linked to another (unpublished) post on this page: %s', 'contentsync' ),
								"<a href='" . \Contentsync\Utils\get_edit_post_link( $connected_post_id_from_this_blog ) . "' target='_blank'>{$other_linked_post->post_title} (#{$other_linked_post->ID})</a>"
							);

							if ( $repair ) {
								$restore_connection = true;
								$trash_other_post   = true;
							}
						} else {
							$error->message = sprintf(
								__( 'The source post is linked to another post on this page: %s', 'contentsync' ),
								"<a href='" . \Contentsync\Utils\get_edit_post_link( $connected_post_id_from_this_blog ) . "' target='_blank'>{$other_linked_post->post_title} (#{$other_linked_post->ID})</a>"
							);

							if ( $repair ) {
								$trash_post = true;
							}
						}
					}
					// post was not found
					else {
						$error->message = __( 'The source post still has an incorrect connection to a post of this website, which can no longer be found.', 'contentsync' );

						if ( $autorepair || $repair ) {
							$restore_connection = true;
						}
					}
				}
			}
			// root post not found
			else {

				$error->message = __( 'The original post has been deleted or moved.', 'contentsync' );

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
		$success = convert_post_to_root( $post_id, $gid );

		if ( $repaired === null ) {
			$repaired = (bool) $success;
		} elseif ( ! $success ) {
			$repaired = false;
		}

		if ( $success ) {
			$error->log[] = __( 'Post has been made the new source post.', 'contentsync' );
		} else {
			$error->log[] = __( 'Post could not be made the new source post.', 'contentsync' );
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
		$success = \Contentsync\Posts\Sync\delete_contentsync_meta_values( $post_id );

		if ( $repaired === null ) {
			$repaired = (bool) $success;
		} elseif ( ! $success ) {
			$repaired = false;
		}

		if ( $success ) {
			$error->log[] = __( 'The global meta information has been deleted.', 'contentsync' );
		} else {
			$error->log[] = __( 'The global meta information could not be deleted.', 'contentsync' );
		}
	}

	// delete orphaned connections
	if ( count( $orphan_connections ) ) {

		foreach ( $orphan_connections as $imported_blog_id => $imported_post_id ) {
			$success = \Contentsync\Posts\Sync\remove_post_connection_from_connection_map( $gid, $imported_blog_id, $imported_post_id );

			if ( $repaired === null ) {
				$repaired = (bool) $success;
			} elseif ( ! $success ) {
				$repaired = false;
			}

			if ( $success ) {
				$error->log[] = __( 'The connection has been deleted.', 'contentsync' );
			} else {
				$error->log[] = __( 'The connection could not be deleted.', 'contentsync' );
			}
		}
	}

	// restore the connection to the root post
	if ( $restore_connection ) {
		$success = \Contentsync\Posts\Sync\add_post_connection_to_connection_map(
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
			$error->log[] = __( 'The connection has been restored.', 'contentsync' );
		} else {
			$error->log[] = __( 'The connection could not be restored.', 'contentsync' );
		}
	}

	// trash other linked post
	if ( $trash_other_post && isset( $other_linked_post ) ) {
		$success = \Contentsync\Posts\Sync\delete_contentsync_meta_values( $other_linked_post->ID );
		$success = wp_trash_post( $other_linked_post->ID );
		$success = true;

		if ( $repaired === null ) {
			$repaired = (bool) $success;
		} elseif ( ! $success ) {
			$repaired = false;
		}

		if ( $success ) {
			$error->log[] = __( 'The other post was moved to the trash.', 'contentsync' );
		} else {
			$error->log[] = __( 'The other post could not be moved to the trash.', 'contentsync' );
		}
	}

	// trash post
	if ( $trash_post ) {
		$success = \Contentsync\Posts\Sync\delete_contentsync_meta_values( $post_id );
		$success = wp_trash_post( $post_id );
		$success = true;

		if ( $repaired === null ) {
			$repaired = (bool) $success;
		} elseif ( ! $success ) {
			$repaired = false;
		}

		if ( $success ) {
			$error->log[] = __( 'The post was moved to the trash.', 'contentsync' );
		} else {
			$error->log[] = __( 'The post could not be moved to the trash.', 'contentsync' );
		}
	}

	Multisite_Manager::restore_blog();

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
function repair_post( $post_id, $blog_id = null, $return_error = false ) {

	Multisite_Manager::switch_blog( $blog_id );

	$error = check_post_for_errors( $post_id, true, true );

	Multisite_Manager::restore_blog();

	return $return_error ? $error : is_error_repaired( $error );
}

/**
 * Get all synced posts with errors of a certain blog.
 *
 * @param int  $blog_id  ID of the blog, defaults to the current blog.
 * @param bool $repair  Repair errors, this can change meta infos or delete posts.
 *
 * @return Synced_Post[]    With @param object error
 */
function get_synced_posts_of_blog_with_errors( $blog_id = 0, $repair_posts = false, $query_args = null ) {

	$error_posts = array();

	Multisite_Manager::switch_blog( $blog_id );

	$posts = \Contentsync\Posts\Sync\get_synced_posts_of_blog( '', '', $query_args );

	foreach ( $posts as $post ) {
		$error = check_post_for_errors( $post, true, $repair_posts );
		if ( $error ) {
			$post->error   = $error;
			$error_posts[] = $post;
		}
	}

	Multisite_Manager::restore_blog();

	return $error_posts;
}

/**
 * Get all synced posts with errors of the whole network.
 *
 * @param bool $repair  Repair errors, this can change meta infos or delete posts.
 *
 * @return Synced_Post[]    With @param object error
 */
function get_network_synced_posts_with_errors( $repair_posts = false, $query_args = null ) {
	$error_posts = array();
	foreach ( Multisite_Manager::get_all_blogs() as $blog_id => $blog_args ) {
		$error_posts = array_merge(
			get_synced_posts_of_blog_with_errors( $blog_id, $repair_posts, $query_args ),
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
function convert_post_to_root( $post_id, $old_gid ) {

	$current_blog   = get_current_blog_id();
	$connection_map = array();
	$options        = array(
		'append_nested'  => true,
		'whole_posttype' => false,
		'all_terms'      => true,
		'resolve_menus'  => true,
		'translations'   => true,
	);

	if ( ! function_exists( 'make_post_synced' ) ) {
		return false;
	}

	$gid = \Contentsync\Posts\Sync\make_post_synced( $post_id, $options );

	// loop through all blogs and change the gid
	foreach ( Multisite_Manager::get_all_blogs() as $blog_id => $blog_args ) {

		if ( $blog_id == $current_blog ) {
			continue;
		}

		Multisite_Manager::switch_blog( $blog_id );
		$post = \Contentsync\Posts\Sync\get_local_post_by_gid( $old_gid );
		if ( $post ) {
			$connection_map[ $blog_id ] = \Contentsync\Posts\Sync\get_post_connection_map( $blog_id, $post->ID );
			update_post_meta( $post->ID, 'synced_post_id', $gid );
		}
		Multisite_Manager::restore_blog();
	}

	// update meta
	update_post_meta( $post_id, 'contentsync_export_options', $options );
	update_post_meta( $post_id, 'contentsync_connection_map', $connection_map );

	return true;
}
