<?php

/**
 * Display & integrate Content Syncs into the WordPress backend
 *
 * This file defines the `Admin` class used to integrate Content Sync
 * functionality into the WordPress admin UI. It registers admin and
 * network admin menu pages, modifies the post edit and list screens to
 * surface global status and actions, adds meta boxes and overlay
 * interfaces for managing synced posts, and handles screen options and
 * admin bar entries. When extending this class, you can add new UI
 * components or adjust existing ones to suit custom workflows.
 */

namespace Contentsync\Admin;

use Contentsync\Post_Sync\Post_Error_Handler;
use Contentsync\Admin\Utils\Admin_Render;
use Contentsync\Cluster\Cluster_Service;
use Contentsync\Post_Sync\Post_Connection_Map;
use Contentsync\Post_Sync\Post_Meta;
use Contentsync\Post_Sync\Synced_Post_Service;
use Contentsync\Post_Sync\Synced_Post_Utils;
use Contentsync\Post_Transfer\Post_Transfer_Service;
use Contentsync\Reviews\Post_Review_Service;
use Contentsync\Translations\Translation_Manager;
use Contentsync\Admin\Views\Post_Sync\Global_List_Table;

defined( 'ABSPATH' ) || exit;

new Admin();
class Admin {

	/**
	 * holds the posttype args
	 *
	 * set via function 'init_args'
	 */
	public static $args = array();

	/**
	 * Holds instance of Global_List_Table
	 */
	public $Global_List_Table = null;

	/**
	 * holds the overlay contents
	 */
	public static $overlay_contents = array();

	/**
	 * holds optional warnings for overlay contents
	 */
	public static $overlay_warnings = array();

	/**
	 * Holds the post error
	 *
	 * @var null|false|array Null on init, false when no error found, Array otherwise.
	 */
	public static $error = null;

	/**
	 * Init class
	 */
	public function __construct() {

		// modify the post.php pages
		// add_action( 'admin_notices', array( $this, 'add_global_notice' ) );
		// add_filter( 'admin_body_class', array( $this, 'edit_body_class' ), 99 );
		// add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		// add_action( 'do_meta_boxes', array( $this, 'remove_revisions_meta_box' ), 10, 3 );

		// modify the post overview pages
		// add_action( 'admin_init', array( $this, 'setup_columns' ) );
		// add_filter( 'post_row_actions', array( $this, 'filter_row_actions' ), 10, 2 );

		// add overlay contents
		// add_filter( 'contentsync_overlay_contents', array( $this, 'add_overlay_contents' ) );
	}


	/**
	 * =================================================================
	 *                          OVERLAY
	 * =================================================================
	 */

	/**
	 * Add overlay contents
	 *
	 * @filter 'contentsync_overlay_contents'
	 *
	 * @param array $contents
	 * @return array $contents
	 */
	public function add_overlay_contents( $contents ) {

		$post_id = isset( $_GET['post'] ) ? $_GET['post'] : null;

		$export_options = self::get_contentsync_export_options_for_post( $post_id );

		// build the form
		$export_form = '<form id="contentsync_export_form" class="' . ( count( $export_options ) ? 'inner_content' : '' ) . '">';
		foreach ( $export_options as $option ) {
			$name         = $option['name'];
			$checked      = isset( $option['checked'] ) && $option['checked'] ? "checked='checked'" : '';
			$export_form .= '<label for="' . $name . '">' .
				'<input type="checkbox" id="' . $name . '" name="' . $name . '" ' . $checked . ' />' .
				'<span>' . $option['title'] . '</span>' .
				'<small>' . $option['descr'] . '</small>' .
			'</label>';
		}
		$export_form .= '</form>';

		/**
		 * Build the import form
		 */
		$options = '';
		foreach ( array(
			'replace' => __( 'Replace', 'contentsync' ),
			'skip'    => __( 'Skip', 'contentsync' ),
			'keep'    => __( 'Keep both', 'contentsync' ),
		) as $name => $value ) {
			$options .= '<option value="' . $name . '">' . $value . '</option>';
		}
		$options = urlencode( $options );

		$import_form = '<form id="contentsync_export_form" class="inner_content">' .
			'<label for="handle_conflicts">' .
				__( 'What should be done with ', 'contentsync' ) .
			'</label>' .
			'<select id="handle_conflicts" name="handle_conflicts">' .
				'<option value="replace">' . __( 'Replace', 'contentsync' ) . '</option>' .
				'<option value="keep">' . __( 'Keep both', 'contentsync' ) . '</option>' .
			'</select>' .
		'</form>';

		/**
		 * Get connected posts
		 */
		$connection_map = Post_Connection_Map::get( $post_id );
		$post_list      = '';

		if ( is_array( $connection_map ) && count( $connection_map ) > 0 ) {

			$count     = 0;
			$post_list = '<ul class="contentsync_box_list" style="margin:0px 0 10px">';
			foreach ( $connection_map as $_blog_id => $post_con ) {
				if ( is_numeric( $_blog_id ) ) {
					$post_list .= '<li>' . $post_con['nice'] . ' (<a href="' . $post_con['edit'] . '" target="_blank">' . __( 'to the post', 'contentsync' ) . '</a>)</li>';
					++$count;
				} elseif ( is_array( $post_con ) ) {
					foreach ( $post_con as $__blog_id => $_post_con ) {
						$post_list .= '<li>' . $_post_con['nice'] . ' (<a href="' . $_post_con['edit'] . '" target="_blank">' . __( 'to the post', 'contentsync' ) . '</a>)</li>';
						++$count;
					}
				}
			}
			$post_list .= '</ul>';
		} elseif ( $post_id ) {

			// if the post is in a cluster, show the cluster names
			if ( ! empty( self::get_clusters_including_this_post( $post_id ) ) ) {
				$post_list = '<ul class="contentsync_box_list" style="margin:0px 0 10px">';
				foreach ( self::get_clusters_including_this_post( $post_id ) as $cluster ) {
					$post_list .= '<li>';
					$post_list .= '<strong>' . sprintf( __( 'Cluster \'%s\'', 'contentsync' ), $cluster->title ) . '</strong>:';
					$post_list .= '<ul style="margin:12px 0 0 4px;">';
					foreach ( $cluster->destination_ids as $blog_id ) {
						// $post_list .= '<li>' . sprintf( __( 'Site %s', 'contentsync' ), '<a href="' . get_site_url( $blog_id ) . '">' . get_blog_details( $blog_id )->blogname . '</a>' ) . '</li>';
						if ( strpos( $blog_id, '|' ) == ! false ) {
							$tmp        = explode( '|', $blog_id );
							$connection = isset( $connection_map[ $tmp[1] ] ) ? $connection_map[ $tmp[1] ] : 'unknown';
							$blog       = isset( $connection[ intval( $tmp[0] ) ] ) ? $connection[ intval( $tmp[0] ) ] : 'unknown';

							if ( isset( $blog['blog'] ) ) {
								$post_list .= '<li>' . sprintf( __( 'Site %s', 'contentsync' ), '<a href="' . $blog['blog'] . '" target="_blank">' . $blog['nice'] . '</a>' ) . '</li>';
							}
						} else {
							$post_list .= '<li>' . sprintf( __( 'Site %s', 'contentsync' ), '<a href="' . get_site_url( $blog_id ) . '">' . get_blog_details( $blog_id )->blogname . '</a>' ) . '</li>';
						}
					}
					$post_list .= '</ul>';
					$post_list .= '</li>';
				}
				$post_list .= '</ul>';
			} else {
				$post_list = '<p>' . __( 'This post has not been published to other sites yet.', 'contentsync' ) . '</p>';
			}
		}

		/**
		 * Message Box to deny the review
		 */
		$reviewer_message_deny = '<form id="contentsync_review_message_form_deny" class="contentsync_review_message_forms">' .
			'<label for="review_message_deny">' . __( 'Message to the editor (required)', 'contentsync' ) . '</label>' .
			'<textarea id="review_message_deny" name="review_message_deny" placeholder="' . __( 'Please enter a message for the editor.', 'contentsync' ) . '" rows="4"></textarea></form>';

		/**
		 * Message Box to revert the review
		 */
		$reviewer_message_revert = '<form id="contentsync_review_message_form_revert" class="contentsync_review_message_forms">' .
			'<label for="review_message_revert">' . __( 'Message to the editor (optional)', 'contentsync' ) . '</label>' .
			'<textarea id="review_message_revert" name="review_message_revert" placeholder="' . __( 'Please enter a message for the editor.', 'contentsync' ) . '" rows="4"></textarea></form>';

		/**
		 * Add all the contents
		 */
		$overlay_contents = array(
			'contentsync_export'         => array(
				'confirm' => array(
					'title'   => __( 'Convert to synced post', 'contentsync' ),
					'descr'   => sprintf( __( 'This will make the post "%s" available on all connected sites.', 'contentsync' ), '<strong class="replace"></strong>' ),
					'content' => $export_form,
					'button'  => __( 'Convert now', 'contentsync' ),
				),
				'loading' => array(
					'descr' => __( 'Converting post.', 'contentsync' ),
				),
				'reload'  => array(
					'title' => __( 'Successfully converted.', 'contentsync' ),
					'descr' => __( 'The post has been converted to synced post.', 'contentsync' ),
				),
				'success' => array(
					'title' => __( 'Successfully converted.', 'contentsync' ),
					'descr' => __( 'The post has been converted to synced post.', 'contentsync' ),
				),
				'fail'    => array(
					'title' => __( 'Convert failed.', 'contentsync' ),
					'descr' => __( 'The post could not be converted to synced post.', 'contentsync' ),
				),
			),
			'contentsync_import'         => array(
				'check_post' => array(
					'title'   => __( 'Please wait', 'contentsync' ),
					'descr'   => __( 'Checking content.', 'contentsync' ),
					'content' => '<div class="loading"><div class="loader"></div></div><a href="javascript:window.location.href=window.location.href" class="color_light escape">' . __( 'Cancel', 'contentsync' ) . '</a>',
				),
				'confirm'    => array(
					'title'   => __( 'Import content on this site', 'contentsync' ),
					'content' => '<form id="contentsync_import_form">' .
									'<div class="conflicts">' .
										'<p>' . __( '<b>Attention:</b> Some content already seems to exist on this site. Choose what to do with it.', 'contentsync' ) . '</p>' .
										'<div class="inner_content" data-multioption="' . __( 'Multiselect', 'contentsync' ) . '" data-options="' . $options . '"></div>' .
									'</div>' .
									'<div class="new">' .
										'<p>' . sprintf( __( 'No conflicts found. Do you want to make the post "%s" available on this site now?', 'contentsync' ), '<strong class="post_title"></strong>' ) . '</p>' .
									'</div>' .
								'</form>',
					'button'  => __( 'Import now', 'contentsync' ),
				),
				'loading'    => array(
					'descr' => __( 'Importing content.', 'contentsync' ),
				),
				'reload'     => array(
					'title' => __( 'Import successful.', 'contentsync' ),
					'descr' => __( 'The post has been imported on this site.', 'contentsync' ),
				),
				'fail'       => array(
					'title' => __( 'Import failed.', 'contentsync' ),
					'descr' => __( 'The post could not be imported on the site.', 'contentsync' ),
				),
			),
			'contentsync_import_bulk'    => array(
				'imported'   => array(
					'title' => __( 'Import content', 'contentsync' ),
					'descr' => sprintf( __( 'Post "%s" is already imported.', 'contentsync' ), '<strong class="replace"></strong>' ),
				),
				'check_post' => array(
					'title'   => __( 'Please wait', 'contentsync' ),
					'descr'   => __( 'Checking content.', 'contentsync' ),
					'content' => '<div class="loading"><div class="loader"></div></div><a href="javascript:window.location.href=window.location.href" class="color_light escape">' . __( 'cancel', 'contentsync' ) . '</a>',
				),
				'confirm'    => array(
					'title'   => __( 'Import content on this site', 'contentsync' ),
					'content' => '<form id="contentsync_import_bulk_form">' .
									'<div class="new">' .
										'<p>' . sprintf( __( 'No conflicts found. Do you want to make the posts available on this site now?', 'contentsync' ), '<strong class="post_title"></strong>' ) . '</p>' .
									'</div>' .
									'<div class="conflicts">' .
										'<p>' . __( '<b>Attention:</b> Some content already seems to exist on this site. Choose what to do with it.', 'contentsync' ) . '</p>' .
										'<div class="inner_content" data-multioption="' . __( 'Multiselect', 'contentsync' ) . '" data-options="' . $options . '" data-unused="' . __( 'No conflicts', 'contentsync' ) . '" data-import="' . __( 'Already imported', 'contentsync' ) . '" data-success="' . __( 'Import successful', 'contentsync' ) . ' ✅" data-fail="' . __( 'Import failed', 'contentsync' ) . ' ❌"></div>' .
									'</div>' .
								'</form>',
					'button'  => __( 'import now', 'contentsync' ),
				),
				'loading'    => array(
					'content' => '<div class="import_bulk conflicts" style="margin-bottom: 20px;"><div class="inner_content" data-multioption="' . __( 'Multiselect', 'contentsync' ) . '" data-options="' . $options . '" data-unused="' . __( 'No conflicts', 'contentsync' ) . '" data-import="' . __( 'Already imported', 'contentsync' ) . '" data-success="' . __( 'Import successful', 'contentsync' ) . ' ✅" data-fail="' . __( 'Import failed', 'contentsync' ) . ' ❌"></div></div>',
				),
				'success'    => array(
					'title'   => __( 'Import successful.', 'contentsync' ),
					'content' => '<div class="import_bulk conflicts" style="margin-bottom: 20px;"><div class="inner_content" data-multioption="' . __( 'Multiselect', 'contentsync' ) . '" data-options="' . $options . '" data-unused="' . __( 'No conflicts', 'contentsync' ) . '" data-import="' . __( 'Already imported', 'contentsync' ) . '" data-success="' . __( 'Import successful', 'contentsync' ) . ' ✅" data-fail="' . __( 'Import failed', 'contentsync' ) . ' ❌"></div></div>',
				),
				'fail'       => array(
					'title'   => __( 'Import failed.', 'contentsync' ),
					'descr'   => '<strong class="replace">' . __( 'At least some posts failed to import, please check the log:', 'contentsync' ) . '</strong>',
					'content' => '<div class="import_bulk" style="margin-bottom: 20px;"></div>',
				),
			),
			'contentsync_unexport'       => array(
				'confirm' => array(
					'title'  => __( 'Unlink', 'contentsync' ),
					'descr'  => __( 'As a result, the content is no longer available globally. It is converted into a local post everywhere.<br><br>The content of the posts remains unaffected.', 'contentsync' ),
					'button' => __( 'Unlink now', 'contentsync' ),
				),
				'loading' => array(
					'descr' => __( 'Unlinking.', 'contentsync' ),
				),
				'reload'  => array(
					'title' => __( 'Successfully unlinked.', 'contentsync' ),
					'descr' => __( 'This post is no longer available on other sites.', 'contentsync' ),
				),
				'success' => array(
					'title' => __( 'Successfully unlinked.', 'contentsync' ),
					'descr' => __( 'This post is no longer available on other sites.', 'contentsync' ),
				),
				'fail'    => array(
					'title' => __( 'Unlinking failed.', 'contentsync' ),
					'descr' => __( 'The post could not me converted to static content.', 'contentsync' ),
				),
			),
			'contentsync_unimport'       => array(
				'confirm' => array(
					'title'  => __( 'Convert to local post', 'contentsync' ),
					'descr'  => __( 'This post will be detached from its source and no longer synchronized.<br><br>All content, terms, metadata, and taxonomy will remain exactly as-is. The post will become a local, fully editable entry.', 'contentsync' ),
					'button' => __( 'Unlink now', 'contentsync' ),
				),
				'loading' => array(
					'descr' => __( 'Unlinking.', 'contentsync' ),
				),
				'reload'  => array(
					'title' => __( 'Successfully converted.', 'contentsync' ),
					'descr' => __( 'This post is now static and can be edited.', 'contentsync' ),
				),
				'success' => array(
					'title' => __( 'Successfully converted.', 'contentsync' ),
					'descr' => __( 'This post is now static and can be edited.', 'contentsync' ),
				),
				'fail'    => array(
					'title' => __( 'Convert failed.', 'contentsync' ),
					'descr' => __( 'The post could not be converted to static content.', 'contentsync' ),
				),
			),
			'contentsync_overwrite'      => array(
				'confirm' => array(
					'title'  => __( 'Overwrite this post', 'contentsync' ),
					'descr'  => sprintf( __( 'This post will be overwritten with the synced post "%s".<br><br>The current post will be replaced with the synced post’s content, metadata, and taxonomy. The post will become a Linked Post (synced) and the editor will reload.', 'contentsync' ), '<strong class="replace"></strong>' ),
					'button' => __( 'Overwrite now', 'contentsync' ),
				),
				'loading' => array(
					'descr' => __( 'Overwriting post.', 'contentsync' ),
				),
				'reload'  => array(
					'title' => __( 'Successfully overwritten.', 'contentsync' ),
					'descr' => __( 'This post has been overwritten with the synced post.', 'contentsync' ),
				),
				'fail'    => array(
					'title' => __( 'Overwrite failed.', 'contentsync' ),
					'descr' => __( 'The post could not be overwritten.', 'contentsync' ),
				),
			),
			'contentsync_unsaved'        => array(
				'confirm' => array(
					'button' => __( 'Repair now', 'contentsync' ),
				),
				'loading' => array(
					'descr' => __( 'Repairing post.', 'contentsync' ),
				),
				'reload'  => array(
					'title' => __( 'Successfully repaired.', 'contentsync' ),
					'descr' => __( 'This post has been repaired in the best possible way. For further bug fixes, please contact an administrator.', 'contentsync' ),
				),
				'success' => array(
					'title' => __( 'Successfully repaired.', 'contentsync' ),
					'descr' => __( 'This post has been repaired in the best possible way. For further bug fixes, please contact an administrator.', 'contentsync' ),
				),
				'fail'    => array(
					'title' => __( 'Repair failed.', 'contentsync' ),
					'descr' => __( 'The post could not be repaired.', 'contentsync' ),
				),
			),
			'contentsync_trash'          => array(
				'confirm' => array(
					'title'        => __( 'Move post to the trash', 'contentsync' ),
					'descr'        => sprintf(
						__( 'On other sites, this content remains unaffected. If you want to delete the content on all sites instead, select %s in the network overview.', 'contentsync' ),
						'<strong>' . __( 'Delete everywhere', 'contentsync' ) . '</strong>'
					),
					'button'       => __( 'Trash', 'contentsync' ),
					'button_class' => 'danger',
				),
				'loading' => array(
					'descr' => __( 'Post is moved to the trash.', 'contentsync' ),
				),
				'reload'  => array(
					'title' => __( 'Successfully moved to the trash.', 'contentsync' ),
					'descr' => __( 'This post was only placed in the trash on this site.', 'contentsync' ),
				),
				'fail'    => array(
					'title' => __( 'Failed.', 'contentsync' ),
					'descr' => __( 'The post could not be moved to the trash.', 'contentsync' ),
				),
			),
			'contentsync_delete'         => array(
				'confirm' => array(
					'title'        => __( 'Permanently delete synced post', 'contentsync' ),
					'descr'        => sprintf(
						__( 'The synced post is permanently deleted on all pages. If you want to make the posts static instead, select %s.', 'contentsync' ),
						'<strong>' . __( 'Unlink', 'contentsync' ) . '</strong>'
					),
					'content'      => Admin_Render::make_admin_info_box(
						array(
							'text'  => __( 'This action cannot be undone.', 'contentsync' ),
							'style' => 'red',
						)
					),
					'button'       => __( 'Delete permanently', 'contentsync' ),
					'button_class' => 'danger',
				),
				'loading' => array(
					'descr' => __( 'Posts will be deleted permanently.', 'contentsync' ),
				),
				'reload'  => array(
					'title' => __( 'Successfully deleted synced post.', 'contentsync' ),
					'descr' => __( 'The synced post has been permanently deleted on all sites.', 'contentsync' ),
				),
				'fail'    => array(
					'title' => __( 'Deletion failed.', 'contentsync' ),
					'descr' => __( 'The synced post could not be deleted on all sites.', 'contentsync' ),
				),
			),
			'contentsync_review_approve' => array(
				'confirm' => array(
					'title'   => __( 'Approve changes', 'contentsync' ),
					'descr'   => __( 'The current version of this post is going to be published on the following sites. Afterwards you can still revert the changes, if anything goes wrong.', 'contentsync' ),
					'content' => '<div class="inner_content">' . $post_list . '</div>',
					'button'  => __( 'Publish changes', 'contentsync' ),
				),
				'loading' => array(
					'title' => __( 'Publish changes', 'contentsync' ),
					'descr' => __( 'Please wait. Changes are being published.', 'contentsync' ),
				),
				'reload'  => array(
					'title' => __( 'Changes published', 'contentsync' ),
					'descr' => __( 'The changes have been published to all sites.', 'contentsync' ),
				),
				'fail'    => array(
					'title' => __( 'Error approving and publishing review', 'contentsync' ),
					'descr' => __( 'The review could not be approved and published at the moment.', 'contentsync' ),
				),
				// 'fail' => array(
				// 'title'     => __("Error publishing changes", 'contentsync'),
				// 'descr'     => __("Errors occured on the following sites:", 'contentsync'),
				// TODO: show content on failure
				// )
			),
			'contentsync_review_deny'    => array(
				'confirm' => array(
					'title'   => __( 'Request modification', 'contentsync' ),
					'descr'   => __( 'Tell the editor what needs to be changed.', 'contentsync' ),
					'content' => '<div class="inner_content">' . $reviewer_message_deny . '</div>',
					'button'  => __( 'Send to the editor', 'contentsync' ),
				),
				'loading' => array(
					'title' => __( 'Sending modification request', 'contentsync' ),
					'descr' => __( 'Please wait. The review is being saved.', 'contentsync' ),
				),
				'reload'  => array(
					'title' => __( 'Modification request sent', 'contentsync' ),
					'descr' => __( 'The review has been saved and the editor will be notified.', 'contentsync' ),
				),
				'fail'    => array(
					'title' => __( 'Error denying review', 'contentsync' ),
					'descr' => __( 'The review could not be denied at the moment.', 'contentsync' ),
				),
			),
			'contentsync_review_revert'  => array(
				'confirm' => array(
					'title'   => __( 'Revert changes', 'contentsync' ),
					'descr'   => __( 'Reset the post to the previous version.', 'contentsync' ),
					'content' => '<div class="inner_content">' . $reviewer_message_revert . '</div>',
					'button'  => __( 'Reset post', 'contentsync' ),
				),
				'loading' => array(
					'title' => __( 'Reverting post', 'contentsync' ),
					'descr' => __( 'Please wait. The post is being reverted.', 'contentsync' ),
				),
				'reload'  => array(
					'title' => __( 'Post reverted', 'contentsync' ),
					'descr' => __( 'The post has been reverted and the editor will be notified.', 'contentsync' ),
				),
				'fail'    => array(
					'title' => __( 'Error reverting post', 'contentsync' ),
					'descr' => __( 'The post could not be reverted at the moment.', 'contentsync' ),
				),
			),
		);

		/**
		 * Only render the contents set in the class var $overlay_contents
		 */
		$final = array();
		if ( ! empty( self::$overlay_contents ) && is_array( self::$overlay_contents ) ) {
			foreach ( self::$overlay_contents as $type ) {
				if ( isset( $overlay_contents[ $type ] ) ) {

					// add warnings to confirm screen
					if ( isset( self::$overlay_warnings[ $type ] ) && isset( $overlay_contents[ $type ]['confirm'] ) ) {
						$confirm_content                                 = isset( $overlay_contents[ $type ]['confirm']['content'] ) ? $overlay_contents[ $type ]['confirm']['content'] : '';
						$confirm_content                                .= self::$overlay_warnings[ $type ];
						$overlay_contents[ $type ]['confirm']['content'] = $confirm_content;
					}

					$final[ $type ] = $overlay_contents[ $type ];
				}
			}
			$contents = array_merge( $contents, $final );
		}
		return $contents;
	}

	/**
	 * Get all supported global options for this post
	 *
	 * @param mixed $post_id_or_object
	 *
	 * @return array
	 */
	public static function get_contentsync_export_options_for_post( $post_id_or_object = null ) {

		$contentsync_export_options = array(
			'append_nested' => array(
				'name'    => 'append_nested',
				'title'   => __( 'Include nested content', 'contentsync' ),
				'descr'   => __( 'Templates, media, etc. are also made available globally, so that used images, backgrounds, etc. are displayed correctly on the target site.', 'contentsync' ),
				'checked' => true,
			),
			'resolve_menus' => array(
				'name'  => 'resolve_menus',
				'title' => __( 'Resolve menus', 'contentsync' ),
				'descr' => __( 'All menus will be converted to static links.', 'contentsync' ),
			),
		);

		$post = get_post( $post_id_or_object );
		if ( $post ) {

			if ( $post->post_type === 'attachment' ) {
				unset( $contentsync_export_options['append_nested'], $contentsync_export_options['resolve_menus'] );
			}

			/**
			 * add option to include translations when translation tool is active
			 */
			if ( ! empty( Translation_Manager::get_translation_tool() ) ) {
				$contentsync_export_options['translations'] = array(
					'name'  => 'translations',
					'title' => __( 'Include translations', 'contentsync' ),
					'descr' => __( 'All translations of the post are automatically made available globally.', 'contentsync' ),
				);
			}
		}
		return $contentsync_export_options;
	}
}
