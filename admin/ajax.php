<?php
/**
 * Content Syncs Ajax
 *
 * Handles the backend AJAX actions for global contents.
 *
 * The `Ajax` class exposes a single entry point, `handle_backend_ajax`, that
 * is triggered when the plugin receives a custom Greyd AJAX request. It
 * routes different actions—such as exporting posts, updating
 * connections or running distribution tasks—based on the `action`
 * parameter passed in the request data. This class also enables debug
 * logging so developers can troubleshoot asynchronous operations.
 * Extend or modify this class to add new AJAX handlers for your
 * Content Sync features.
 */

namespace Contentsync\Contents;

use Contentsync\Main_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

new Ajax();
class Ajax {

	const DEBUG = true;

	/**
	 * Init class
	 */
	public function __construct() {

		// add the backend ajax handler
		add_action( 'contentsync_ajax_mode_global_action', array( $this, 'handle_backend_ajax' ), 10, 1 );
	}

	/**
	 * Handle the backend ajax
	 *
	 * action is called via ../ajax.php
	 *
	 * @param array $data   Data of the custom action. Always has the key 'action'
	 *                      to identify the call. The other keys depend on the
	 *                      call itself.
	 */
	public function handle_backend_ajax( $data ) {
		debug( $data );

		if ( ! is_array( $data ) || empty( $data ) ) {
			wp_die();
		}

		$action = isset( $data['action'] ) ? $data['action'] : null;

		if ( empty( $action ) ) {
			$this->fail( 'action is invalid.' );
		}

		\Contentsync\post_export_enable_logs();

		/** EXPORT */
		if ( $action === 'contentsync_export' ) {

			if ( self::DEBUG ) {
				echo "\r\n" . '* try to export post...';
			}

			$post_id = isset( $data['post_id'] ) ? intval( $data['post_id'] ) : null;

			if ( empty( $post_id ) ) {
				$this->fail( 'post_id is not defined.' );
			}

			$args      = \Contentsync\get_contentsync_default_export_options();
			$form_data = isset( $data['form_data'] ) ? (array) $data['form_data'] : array();
			foreach ( $args as $k => $v ) {
				if ( isset( $form_data[ $k ] ) ) {
					$args[ $k ] = true;
				}
			}

			$gid = \Contentsync\make_post_global( $post_id, $args );

			// failure
			if ( ! $gid ) {
				$this->fail( 'post could not be exported globally...' );
			}
			// success
			else {
				$this->success( "post was exported with the global id of $gid" );
			}
		}

		/** UN-EXPORT */
		elseif ( $action === 'contentsync_unexport' ) {

			if ( self::DEBUG ) {
				echo "\r\n" . '* try to un-export post...';
			}

			$gid = isset( $data['gid'] ) ? esc_attr( $data['gid'] ) : null;

			if ( empty( $gid ) ) {
				$this->fail( 'global ID is not defined.' );
			}

			$result = \Contentsync\unlink_synced_root_post( $gid );

			// failure
			if ( ! $result ) {
				$this->fail( 'exported post could not be unlinked globally...' );
			}
			// success
			else {
				$this->success( 'post was unlinked and the synced post was removed' );
			}
		}

		/** CHECK IMPORT */
		elseif ( $action === 'contentsync_check_synced_post_import' ) {

			if ( self::DEBUG ) {
				echo "\r\n" . '* check to be imported post...';
			}

			$gid = isset( $data['gid'] ) ? strval( $data['gid'] ) : null;

			if ( empty( $gid ) ) {
				$this->fail( 'global ID is not defined.' );
			}

			$result = \Contentsync\check_synced_post_import( $gid );

			// failure
			if ( ! $result ) {
				$this->fail( 'post could not be checked for conflicts.' );
			}
			// success
			else {
				$this->success( $result );
			}
		} elseif ( $action === 'contentsync_check_synced_post_import_bulk' ) {

			if ( self::DEBUG ) {
				echo "\r\n" . '* check to be imported posts...';
			}

			$posts = isset( $data['posts'] ) ? (array) $data['posts'] : array();

			if ( empty( $posts ) ) {
				$this->fail( 'global IDs are not defined.' );
			}

			$results = array();
			foreach ( $posts as $post ) {
				$conflict = \Contentsync\check_synced_post_import( $post['gid'] );
				if ( $conflict ) {
					$results[] = array(
						'gid'      => $post['gid'],
						'conflict' => $conflict,
					);
				}
			}

			// failure
			if ( empty( $results ) ) {
				$this->fail( 'posts could not be checked for conflicts.' );
			}
			// success
			else {
				$this->success( json_encode( $results ) );
			}
		}

		/** IMPORT */
		elseif ( $action === 'contentsync_import' ) {

			if ( self::DEBUG ) {
				echo "\r\n" . '* try to import post...';
			}

			$gid = isset( $data['gid'] ) ? strval( $data['gid'] ) : null;

			if ( empty( $gid ) ) {
				$this->fail( 'global ID is not defined.' );
			}

			// get conflicts with current posts
			$conflicts        = isset( $data['form_data'] ) ? (array) $data['form_data'] : array();
			$conflict_actions = Main_Helper::call_post_export_func( 'import_get_conflict_actions_from_backend_form', $conflicts );

			$result = \Contentsync\import_synced_post( $gid, $conflict_actions );

			// failure
			if ( $result !== true ) {
				$this->fail( $result );
			}
			// success
			else {
				$this->success( 'post was imported!' );
			}
		}

		/** UN-IMPORT */
		elseif ( $action === 'contentsync_unimport' ) {

			if ( self::DEBUG ) {
				echo "\r\n" . '* try to unimport post...';
			}

			$post_id = isset( $data['post_id'] ) ? $data['post_id'] : null;

			if ( empty( $post_id ) ) {
				$this->fail( 'post_id is not defined.' );
			}

			// get synced post
			$result = \Contentsync\unlink_synced_post( $post_id );

			// failure
			if ( ! $result ) {
				$this->fail( 'post could not be made static...' );
			}
			// success
			else {
				$this->success( 'post was successfully made static' );
			}
		}

		/** OVERWRITE */
		elseif ( $action === 'contentsync_overwrite' ) {

			if ( self::DEBUG ) {
				echo "\r\n" . '* try to overwrite post...';
			}

			$post_id = isset( $data['post_id'] ) ? $data['post_id'] : null;
			$gid     = isset( $data['gid'] ) ? strval( $data['gid'] ) : null;

			if ( empty( $post_id ) || empty( $gid ) ) {
				$this->fail( 'post_id or gid is not defined.' );
			}

			$current_posts = array();
			$synced_post   = Main_Helper::get_synced_post( $gid );
			if ( $synced_post ) {
				$current_posts[ $synced_post->ID ] = array(
					'post_id' => $post_id,
					'action'  => 'replace',
				);
			}

			$result = \Contentsync\import_synced_post( $gid, $current_posts );

			// failure
			if ( ! $result ) {
				$this->fail( 'post could not be overwritten...' );
			}
			// success
			else {
				$this->success( 'post was successfully overwritten' );
			}
		}

		/** REPAIR */
		elseif ( $action === 'contentsync_repair' ) {

			if ( self::DEBUG ) {
				echo "\r\n" . '* try to repair post...';
			}

			$post_id = isset( $data['post_id'] ) ? $data['post_id'] : null;
			$blog_id = isset( $data['blog_id'] ) ? $data['blog_id'] : null;

			if ( empty( $post_id ) ) {
				$this->fail( 'post_id is not defined.' );
			}

			$error = \Contentsync\repair_post( $post_id, $blog_id, true );

			// no error
			if ( ! $error ) {
				$this->fail( 'post has no error.' );
			}
			// error found
			else {
				echo \Contentsync\get_error_repaired_log( $error );

				// success
				if ( \Contentsync\is_error_repaired( $error ) ) {
					$this->success( 'post was successfully repaired' );
				} else {
					$this->fail( $error->message );
				}
			}
		}

		/** TRASH */
		elseif ( $action === 'contentsync_trash' ) {

			if ( self::DEBUG ) {
				echo "\r\n" . '* try to trash post...';
			}

			$post_id = isset( $data['post_id'] ) ? $data['post_id'] : null;
			$blog_id = isset( $data['blog_id'] ) ? $data['blog_id'] : null;

			if ( empty( $post_id ) ) {
				$this->fail( 'post_id is not defined.' );
			}

			if ( $blog_id ) {
				\Contentsync\switch_blog( $blog_id );
			}
			$result = wp_trash_post( $post_id );
			if ( $blog_id ) {
				\Contentsync\restore_blog();
			}

			// failure
			if ( ! $result ) {
				$this->fail( 'post could not be trashed...' );
			}
			// success
			else {
				$this->success( 'post was successfully trashed' );
			}
		}

		/** DELETE ALL */
		elseif ( $action === 'contentsync_delete' ) {

			if ( self::DEBUG ) {
				echo "\r\n" . '* try to delete synced post...';
			}

			$gid = isset( $data['gid'] ) ? strval( $data['gid'] ) : null;

			if ( empty( $gid ) ) {
				$this->fail( 'global ID is not defined.' );
			}

			$result = \Contentsync\delete_synced_post( $gid );

			// failure
			if ( ! $result ) {
				$this->fail( 'post could not be deleted...' );
			}
			// success
			else {
				$this->success( 'post was successfully deleted' );
			}
		}

		/** CHECK POST CONNECTIONS */
		elseif ( $action === 'contentsync_check_post_connections' ) {

			if ( self::DEBUG ) {
				echo "\r\n" . '* try check connections...';
			}

			$post_id = isset( $data['post_id'] ) ? $data['post_id'] : null;

			if ( empty( $post_id ) ) {
				$this->fail( 'post_id is not defined.' );
			}

			$result = \Contentsync\check_connection_map( $post_id );

			// failure
			if ( ! $result ) {
				$this->fail( 'some corrupted connections were detected and fixed.' );
			}
			// success
			else {
				$this->success( 'there were no corrupted connections.' );
			}
		}

		/** SIMILAR POSTS */
		elseif ( $action === 'contentsync_similar_posts' ) {

			if ( self::DEBUG ) {
				echo "\r\n" . '* looking for similar posts...';
			}

			$post_id = isset( $data['post_id'] ) ? $data['post_id'] : null;

			if ( empty( $post_id ) ) {
				$this->fail( 'post_id is not defined.' );
			}

			if ( $post = get_post( $post_id ) ) {
				if ( $similar_posts = Main_Helper::get_similar_synced_posts( $post ) ) {
					$this->success( json_encode( $similar_posts ) );
				} else {
					$this->fail( 'No similar posts found.' );
				}
			} else {
				$this->fail( "Post with ID '$post_id' could not be found." );
			}
		}

		/** CONNECTION OPTIONS */
		elseif ( $action === 'contentsync_update_site_connections' ) {

			if ( self::DEBUG ) {
				echo "\r\n" . '* try to update connection options...';
			}

			$result     = false;
			$site_url   = isset( $data['site_url'] ) ? esc_attr( $data['site_url'] ) : null;
			$contents   = isset( $data['contents'] ) ? $data['contents'] === 'true' : true;
			$search     = isset( $data['search'] ) ? $data['search'] === 'true' : true;
			$connection = get_site_connection( $site_url );

			// debug( $connection );

			if ( $connection ) {
				$connection['contents'] = $contents;
				$connection['search']   = $search;
				$result                 = update_site_connection( $connection );
			}

			if ( $result ) {
				$this->success( 'connection options successfully saved.' );
			} else {
				$this->fail( 'connection options could not be saved.' );
			}
		}

		/** CHECK FOR ERRORS */
		elseif ( $action === 'contentsync_error_posts' ) {

			if ( self::DEBUG ) {
				echo "\r\n" . '* check for errors...';
			}

			$mode      = isset( $data['mode'] ) ? strval( $data['mode'] ) : null;
			$blog_id   = isset( $data['blog_id'] ) ? intval( $data['blog_id'] ) : null;
			$post_type = isset( $data['post_type'] ) ? intval( $data['post_type'] ) : null;

			if ( $mode === 'network' ) {
				$posts = \Contentsync\get_network_synced_posts_with_errors( false, array( 'post_type' => $post_type ) );
			} else {
				$posts = \Contentsync\get_synced_posts_of_blog_with_errors( $blog_id, false, array( 'post_type' => $post_type ) );
			}

			if ( count( $posts ) ) {
				$return = array_filter(
					$posts,
					function ( $post ) {
						return isset( $post->error ) && ! \Contentsync\is_error_repaired( $post->error );
					}
				);
				$this->success( json_encode( array_values( $return ) ) );
			} else {
				$this->fail( 'No errors found.' );
			}
		}

		/** REVIEW: APPROVE */
		elseif ( $action === 'contentsync_review_approve' ) {

			if ( self::DEBUG ) {
				echo "\r\n" . '* try to approve review...';
			}

			$review_id = isset( $data['review_id'] ) ? intval( $data['review_id'] ) : 0;
			$post_id   = isset( $data['post_id'] ) ? intval( $data['post_id'] ) : 0;

			$result = \Contentsync\approve_post_review( $review_id, $post_id );

			if ( $result ) {
				$this->success( 'review was approved.' );
			} else {
				$this->fail( 'review could not be approved.' );
			}
		}

		/** REVIEW: DENY */
		elseif ( $action === 'contentsync_review_deny' ) {

			if ( self::DEBUG ) {
				echo "\r\n" . '* try to deny review...';
			}

			$review_id = isset( $data['review_id'] ) ? intval( $data['review_id'] ) : 0;
			$post_id   = isset( $data['post_id'] ) ? intval( $data['post_id'] ) : 0;
			$message   = isset( $data['message'] ) ? esc_attr( $data['message'] ) : null;

			$result = \Contentsync\deny_post_review( $review_id, $post_id, $message );

			if ( $result ) {
				$this->success( 'review was denied.' );
			} else {
				$this->fail( 'review could not be denied.' );
			}
		}

		/** REVIEW: REVERT */
		elseif ( $action === 'contentsync_review_revert' ) {

			if ( self::DEBUG ) {
				echo "\r\n" . '* try to revert review...';
			}

			$review_id = isset( $data['review_id'] ) ? intval( $data['review_id'] ) : 0;
			$post_id   = isset( $data['post_id'] ) ? intval( $data['post_id'] ) : 0;
			$message   = isset( $data['message'] ) ? esc_attr( $data['message'] ) : null;

			$result = \Contentsync\revert_post_review( $review_id, $post_id, $message );

			if ( $result ) {
				$this->success( 'review was reverted.' );
			} else {
				$this->fail( 'review could not be reverted.' );
			}
		}

		wp_die();
	}

	public function success( $msg ) {
		wp_die( 'success::' . $msg );
	}

	public function fail( $msg ) {
		wp_die( 'error::' . $msg );
	}
}
