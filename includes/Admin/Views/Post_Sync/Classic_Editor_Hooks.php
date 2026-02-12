<?php

/**
 * Content Sync Classic Editor Hooks
 *
 * This class handles hooks for the Classic Editor integration.
 */

namespace Contentsync\Admin\Views\Post_Sync;

use Contentsync\Utils\Hooks_Base;
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

class Classic_Editor_Hooks extends Hooks_Base {

	/**
	 * Holds the post error
	 *
	 * @var null|false|array Null on init, false when no error found, Array otherwise.
	 */
	public static $error = null;

	/**
	 * Register admin-only hooks.
	 */
	public function register_admin() {

		return;

		// styles & scripts
		add_filter( 'admin_body_class', array( $this, 'edit_body_class' ), 99 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 13 );

		// admin notices
		add_action( 'admin_notices', array( $this, 'add_global_notice' ) );

		// meta boxes
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'do_meta_boxes', array( $this, 'remove_revisions_meta_box' ), 10, 3 );
	}

	/**
	 * Add linked class to body
	 *
	 * @param  string $classes CSS classes string
	 *
	 * @return string
	 */
	public function edit_body_class( $classes ) {
		global $pagenow;

		if (
			'post.php' !== $pagenow && 'post-new.php' !== $pagenow
			|| empty( $_GET['post'] )
		) {
			return $classes;
		}

		$post_id = intval( $_GET['post'] );
		$status  = get_post_meta( $post_id, 'synced_post_status', true );

		if ( ! empty( $status ) ) {
			$classes .= ' contentsync_' . $status;
		}

		// add locked class if post is locked
		if ( $status === 'linked' ) {
			$classes .= ' contentsync-locked';
		}
		// add locked class if user cannot edit synced posts
		elseif ( ! empty( $status ) && ! Synced_Post_Service::current_user_can_edit_synced_posts( $status ) ) {
			$classes .= ' contentsync-locked';
		}

		return $classes;
	}

	/**
	 * Enqueue Classic Editor Styles & Scripts.
	 */
	public function enqueue_assets() {

		$screen = get_current_screen();
		if ( $screen->is_block_editor() ) {
			return;
		}

		// enqueue a style...
	}

	/**
	 * Add the locked notice
	 */
	public function add_global_notice() {

		$screen = get_current_screen();
		if ( $screen->is_block_editor() ) {
			return;
		}

		// classic post edit page
		if ( $screen->base === 'post' && $screen->action !== 'add' ) {
			$post_id = isset( $_GET['post'] ) ? $_GET['post'] : null;
			if ( ! empty( $post_id ) ) {

				$notice_content = self::get_global_notice_content( $post_id, 'classic' );

				if ( ! empty( $notice_content ) ) {
					echo $notice_content;
				}
			}
		}
	}

	/**
	 * Get notice for the post.
	 *
	 * @param int  $post_id
	 * @param bool $mode      'classic', 'block_editor' or 'site_editor'
	 *
	 * @return string|array   Notice content, as string for classic & block editor, as array for site editor.
	 */
	public static function get_global_notice_content( $post_id, $mode = 'classic' ) {

		if ( empty( $post_id ) ) {
			return null;
		}

		$gid = get_post_meta( $post_id, 'synced_post_id', true );
		if ( empty( $gid ) ) {
			return null;
		}

		// vars
		$text    = '';
		$buttons = array();
		$notice  = 'info';
		$icon    = 'lock';

		// check for error
		if ( self::$error === null ) {
			self::$error = Post_Error_Handler::get_post_error( $post_id );
		}

		// notice contents
		if ( ! Post_Error_Handler::is_error_repaired( self::$error ) ) {
			$notice = 'error';
			$icon   = 'warning color_red';
			$text   = Post_Error_Handler::get_error_message( self::$error );
			if ( Synced_Post_Service::current_user_can_edit_synced_posts() ) {
				$buttons = array(
					array(
						'label'   => __( 'Repair', 'contentsync' ),
						'onClick' => 'contentSync.repairPost(this, ' . esc_attr( $post_id ) . ')',
						'variant' => 'primary',
					),
				);
			}
		} else {
			$status = get_post_meta( $post_id, 'synced_post_status', true );

			if ( $status === 'linked' ) {

				$post_links = Post_Connection_Map::get_links_by_gid( $gid );

				$text = sprintf(
					__( 'This post is synced from the site %s', 'contentsync' ),
					'<strong>' . $post_links['nice'] . '</strong>'
				);
				if ( Synced_Post_Service::current_user_can_edit_synced_posts( 'linked' ) ) {
					$buttons = array(
						array(
							'label'   => __( 'Edit the original post', 'contentsync' ),
							'url'     => $post_links['edit'],
							'variant' => 'primary',
						),
						array(
							'label'     => __( 'Convert to local post', 'contentsync' ),
							'className' => 'button-ghost',
							'onClick'   => 'contentSync.unimportPost(this, ' . esc_attr( $post_id ) . ')',
							'variant'   => 'tertiary',
						),
					);
				}
			} elseif ( $status === 'root' ) {

				$connection_map = Post_Connection_Map::get( $post_id );

				/**
				 * Review Status
				 *
				 * The post can have different statuses: live, in review, denied, empty (new post)
				 */
				$review                = Post_Review_Service::get_post_review_by_post( $post_id, get_current_blog_id() );
				$default_review_status = empty( self::get_clusters_including_this_post( $post_id ) ) && empty( $connection_map ) ? 'new' : 'live';
				$review_status         = $review && $review->state ? $review->state : $default_review_status;

				/**
				 * @note reviewer messages must be checked for ', otherwise the notices will break and not show at all. May need to be raised in the Gutenberg repo.
				 */
				$reviewer                 = '';
				$reviewer_message         = '';
				$reviewer_message_content = '';
				if ( $review && $review->ID ) {
					$reviewer_message = Post_Review_Service::get_latest_message_by_post_review_id( $review->ID );
					if ( $reviewer_message && ! empty( $reviewer_message->content ) && $reviewer_message->action === 'denied' ) {
						$reviewer_message_content = $reviewer_message->get_content( true );
						$reviewer                 = $reviewer_message->get_reviewer();
					}
				}

				/**
				 * @todo Check if user is a assigned reviewer
				 * Check if the current user is found in the get_super_admins() array
				 * If the current user is found in the get_super_admins() array, the current user is a reviewer
				 * If the current user is not found in the get_super_admins() array, the current user is an editor
				 */
				$current_user = in_array( wp_get_current_user()->user_login, get_super_admins() ) && current_user_can( 'manage_network' ) ? 'reviewer' : 'editor';

				// Set status pill variables
				if ( $review_status === 'new' ) {
					$status_pill_class = ' status_pill_neutral';
					$status_pill_title = empty( self::get_clusters_including_this_post( $post_id ) ) ? __( 'New', 'contentsync' ) : __( 'Not published', 'contentsync' );
				} elseif ( $review_status === 'in_review' ) {
					$status_pill_class = ' status_pill_neutral';
					$status_pill_title = __( 'In Review', 'contentsync' );
				} elseif ( $review_status === 'denied' ) {
					$status_pill_class = ' status_pill_error';
					$status_pill_title = __( 'Not approved', 'contentsync' );
				} else {
					$status_pill_class = ' status_pill_success';
					$status_pill_title = __( 'Live', 'contentsync' );
				}
				$status_text = '';

				$post_count_text = __( 'No linked posts yet', 'contentsync' );
				if ( is_array( $connection_map ) && count( $connection_map ) > 0 ) {

					$count = 0;
					foreach ( $connection_map as $_blog_id => $post_con ) {
						if ( is_numeric( $_blog_id ) ) {
							++$count;
						} elseif ( is_array( $post_con ) ) {
							foreach ( $post_con as $__blog_id => $_post_con ) {
								++$count;
							}
						}
					}
					$post_count_text = sprintf(
						_n(
							'1 linked post',
							'%s linked posts',
							$count,
							'contentsync'
						),
						$count
					);
				} elseif ( ! empty( self::get_clusters_including_this_post( $post_id ) ) ) {
					$post_count_text = sprintf(
						_n(
							'1 active cluster',
							'%s active clusters',
							count( self::get_clusters_including_this_post( $post_id ) ),
							'contentsync'
						),
						count( self::get_clusters_including_this_post( $post_id ) )
					);
				}

				if ( $review_status === 'new' ) {
					if ( empty( self::get_clusters_including_this_post( $post_id ) ) ) {
						$status_text = __( 'This post has not been published to other sites yet.', 'contentsync' );
					} elseif ( $current_user === 'reviewer' ) {
							// this is a new post and it needs to be reviewed before being distributed
							$status_text = __( 'Changes to this post have not been published to other sites. Please review the changes to this post.', 'contentsync' );
					} else {
						// this is a new post and it needs to be reviewed before being distributed
						$status_text = __( 'Changes to this post have not been published to other sites. Reviewers will be notified automatically after you saved this post. You will be notified, as soon as the changes are approved or denied.', 'contentsync' );
					}
				} elseif ( $review_status === 'live' ) {
					if ( empty( self::get_clusters_including_this_post( $post_id ) ) ) {
						$status_text = sprintf(
							__( 'Changes to this post will be published on this site and <strong>will be distributed</strong> to other sites (%s).', 'contentsync' ),
							$post_count_text
						);
					} else {

						// whether one of the clusters has 'enable_reviews' set to true
						$cluster_has_reviews = false;
						foreach ( self::get_clusters_including_this_post( $post_id ) as $cluster ) {
							if ( $cluster->enable_reviews ) {
								$cluster_has_reviews = true;
								break;
							}
						}

						if ( $cluster_has_reviews ) {
							$status_text = sprintf(
								__( 'Changes to this post will only be published on this site and <strong>will be reviewed before being published</strong> on other sites (%s). Reviewers will be notified automatically after you saved this post. You will be notified, as soon as the changes are approved or denied.', 'contentsync' ),
								$post_count_text
							);
						} else {
							$status_text = sprintf(
								__( 'Changes to this post will be published on this site and <strong>will immediately be distributed</strong> to other sites (%s).', 'contentsync' ),
								$post_count_text
							);
						}
					}
				} elseif ( $review_status === 'in_review' ) {
					if ( $current_user === 'reviewer' ) {
						$status_text = sprintf(
							__( 'Changes to this post have not been published to connected sites (%s). Please review the changes to this post.', 'contentsync' ),
							$post_count_text
						);
					} else {
						$status_text = sprintf(
							__( 'Changes to this post have not been published to connected sites (%s), because they have not been approved yet. If you save this post, all changes will be added to the reviewed version.', 'contentsync' ),
							$post_count_text
						);
					}
				} elseif ( $review_status === 'denied' ) {

					if ( ! empty( $reviewer_message ) ) {
						$reviewer_message = '<br>' . sprintf( __( 'The reviewer (%s) left the following message:', 'contentsync' ), $reviewer ) . '<br><em>' . $reviewer_message_content . '</em>';
					} else {
						$reviewer_message = '<br>';
					}

					$status_text = sprintf(
						__( 'Changes to this post cannot be published to connected sites (%s). You need to make changes, before it can be reviewed again.', 'contentsync' ),
						$post_count_text
					) . $reviewer_message . __( 'By saving the post, reviewers will be notified again and the changes will be reviewed again.', 'contentsync' );
				} else {
					$status_text = sprintf(
						__( 'Each saved change is automatically applied to all linked posts (%s).', 'contentsync' ),
						$post_count_text
					);
				}

				$status_pill = '<span class="status_pill' . $status_pill_class . '">' . $status_pill_title . '</span>';

				$notice = 'purple';
				$icon   = 'admin-site-alt';
				$text   = '<p class="heading"><strong>' . __( 'Global Source Post', 'contentsync' ) . '</strong>' . $status_pill . '</p>';
				$text  .= '<p>' . $status_text . '</p>';

				if ( ! Synced_Post_Service::current_user_can_edit_synced_posts( $status ) ) {
					$text .= '<p>' . __( 'You are not allowed to edit this synced post.', 'contentsync' ) . '</p>';
				}

				if (
					$review
					&& $review->ID
					&& $current_user === 'reviewer'
					&& ( $review_status === 'in_review' || $review_status === 'new' )
				) {
					$buttons = array(
						array(
							'label'   => __( 'Approve changes', 'contentsync' ),
							'variant' => 'primary',
							'onClick' => 'contentSync.openReviewApprove(this, ' . esc_attr( $post_id ) . ', ' . esc_attr( $review->ID ) . ')',
						),
						array(
							'label'   => __( 'Request modification', 'contentsync' ),
							'onClick' => 'contentSync.openReviewDeny(this, ' . esc_attr( $post_id ) . ', ' . esc_attr( $review->ID ) . ')',
						),
						array(
							'label'   => __( 'Revert changes', 'contentsync' ),
							'onClick' => 'contentSync.openReviewRevert(this, ' . esc_attr( $post_id ) . ', ' . esc_attr( $review->ID ) . ')',
						),
					);
				}
			}
		}

		if ( ! empty( $text ) ) {

			// block editor notice
			if ( $mode === 'block_editor' ) {

				return '<script id="contentsync-notice__script">' .
					'( function ( wp ) {' .
						'wp.data.dispatch( \'core/notices\' ).createNotice(' .
							'\'' . ( $notice === 'info' ? 'success' : $notice ) . ' contentsync_components_notice\',' .
							'\'' . $text . '\',' .
							'{' .
								'className: \'contentsync-notice__element\',' .
								'isDismissible: false,' .
								'__unstableHTML: true,' .
								'icon: wp.element.createElement( wp.components.Icon, { icon: \'' . $icon . '\' } ),' .
								'actions: [' .
									implode(
										', ',
										array_map(
											function ( $button ) {
												return '{' . ( isset( $button['label'] ) ? 'label: \'' . $button['label'] . '\',' : '' ) . '' . ( isset( $button['url'] ) ? 'url: \'' . $button['url'] . '\',' : '' ) . '' . ( isset( $button['onClick'] ) ? 'onClick: () => {' . $button['onClick'] . '},' : '' ) . '' . ( isset( $button['variant'] ) ? 'variant: \'' . $button['variant'] . '\',' : '' ) . '' . ( isset( $button['className'] ) ? 'className: \'' . $button['className'] . ' contentsync-notice__action\'' : 'className: \'contentsync-notice__action\'' ) . ',}';
											},
											$buttons
										)
									) .
								'],' .
							'}' .
						');' .
					'} )( window.wp );' .
				'</script>';
			}
			// site editor notice arguments
			elseif ( $mode === 'site_editor' ) {
				return array(
					( $notice === 'info' ? 'success' : $notice ) . ' contentsync_components_notice',
					$text,
					array(
						'className'      => 'contentsync-notice__element',
						'isDismissible'  => false,
						'__unstableHTML' => true,
						'icon'           => $icon,
						'actions'        => array_map(
							function ( $button ) {
								return array(
									'label'     => isset( $button['label'] ) ? $button['label'] : '',
									'url'       => isset( $button['url'] ) ? $button['url'] : null,
									'onClick'   => isset( $button['onClick'] ) ? $button['onClick'] : null,
									'variant'   => isset( $button['variant'] ) ? $button['variant'] : '',
									'className' => isset( $button['className'] ) ? $button['className'] . ' contentsync-notice__action' : 'contentsync-notice__action',
								);
							},
							$buttons
						),
					),
				);
			}
			// classic editor admin notice
			else {
				$return = '<div class="notice notice-' . $notice . ' synced_post_notice">' .
					'<div>' .
					'<div><span class="dashicons dashicons-' . $icon . '"></span></div>' .
					'<div>' .
					'<p>' . $text . '</p>' .
					'</div>';
				if ( $buttons && ! empty( $buttons ) ) {
					$return .= '<div class="buttons">';
					foreach ( (array) $buttons as $button ) {
						if ( isset( $button['url'] ) ) {
							$return .= '<a class="button ' . ( isset( $button['className'] ) ? $button['className'] : '' ) . '" href="' . $button['url'] . '">' . $button['label'] . '</a>';
						} elseif ( isset( $button['onClick'] ) ) {
							$return .= '<span class="button ' . ( isset( $button['className'] ) ? $button['className'] : '' ) . '" onclick="' . $button['onClick'] . '">' . $button['label'] . '</span>';
						}
					}
					$return .= '</div>';
				}
				$return .= '</div></div>';
				return $return;
			}
		}

		return '';
	}


	/**
	 * =================================================================
	 *                          META BOXES
	 * =================================================================
	 */

	/**
	 * Holds the cluster objects including the current post.
	 *
	 * @var null|array  Null on init, Array with cluster objects otherwise.
	 */
	public static $clusters_including_this_post = null;

	public static function get_clusters_including_this_post( $post_id ) {
		if ( self::$clusters_including_this_post === null ) {
			self::$clusters_including_this_post = Cluster_Service::get_clusters_including_post( $post_id );
		}
		return self::$clusters_including_this_post;
	}

	/**
	 * Add Meta Boxes
	 */
	public function add_meta_box() {

		if ( get_current_screen()->action === 'add' ) {
			return false;
		}

		$posttypes = Post_Transfer_Service::get_supported_post_types();

		add_meta_box(
			/* ID       */            'contentsync_box',
			/* title    */ self::$args['title'],
			/* callback */ array( $this, 'render_global_meta_box' ),
			/* screen   */ $posttypes,
			/* context  */ 'side', // 'normal', 'side', 'advanced'
			/* priority */ 'core' // 'high', 'core', 'default', 'low'
		);
	}

	/**
	 * Render Meta Boxes
	 */
	public function render_global_meta_box( $post ) {

		// vars
		$post_id = $post->ID;

		echo self::get_global_metabox_content( $post_id );
	}

	public static function get_global_metabox_content( $post_id ) {

		if ( empty( $post_id ) ) {
			return null;
		}

		$status = get_post_meta( $post_id, 'synced_post_status', true );
		$gid    = get_post_meta( $post_id, 'synced_post_id', true );

		$return = '';

		// normal post
		if ( empty( $status ) || empty( $gid ) ) {

			// make global
			if ( Synced_Post_Service::current_user_can_edit_synced_posts( $status ) ) {
				$return .= '<p>' . __( 'Do you want this post to be available throughout all connected sites?', 'contentsync' ) . '</p>' .
					'<span class="button" onclick="contentSync.exportPost(this);" data-post_id="' . esc_attr( $post_id ) . '">' .
						__( 'Convert to synced post', 'contentsync' ) .
					'</span>';
			}

			/**
			 * Get similar posts via JS-ajax
			 */
			$return .= '<div id="contentsync_similar_posts">' .
				'<div class="found hidden">' .
					'<p class="singular hidden">' . __( 'A similar post is available globally:', 'contentsync' ) . '</p>' .
					'<p class="plural hidden">' . __( 'Similar posts are available globally:', 'contentsync' ) . '</p>' .
					'<ul class="contentsync_box_list" data-item="' . preg_replace(
						'/\s{2,}/',
						'',
						esc_attr(
							'<li>' .
							'<span class="flex">' .
								'<a href="{{href}}" target="_blank">{{post_title}}</a>' .
								'<span class="button button-ghost tiny" onclick="contentSync.overwritePost(this);" data-post_id="{{post_id}}" data-gid="{{gid}}">' . __( 'Use', 'contentsync' ) . '</span>' .
							'</span>' .
							'<small>{{nice_url}}</small>' .
							'</li>'
						)
					) . '"></ul>' .
				'</div>' .
				'<p class="not_found hidden"><i>' . __( 'No similar synced posts found.', 'contentsync' ) . '</i></p>' .
				'<p class="loading">' .
					'<span class="loader"></span>' .
				'</p>' .
			'</div>';

			// add warning for contentsync_export (shown via JS)
			self::$overlay_warnings['contentsync_export'] = '<div class="export_warning_similar_posts hidden" style="margin:1em 0 -2em;">' . Admin_Render::make_admin_info_box(
				array(
					'text'  => __( 'Similar content is already available globally. Are you sure you want to make this content global additionally?', 'contentsync' ),
					'style' => 'orange',
				)
			) . '</div>';
		}

		// synced post
		else {

			list( $root_blog_id, $root_post_id, $root_net_url ) = Synced_Post_Utils::explode_gid( $gid );

			// check for error
			if ( self::$error === null ) {
				self::$error = Post_Error_Handler::get_post_error( $post_id );
			}

			$return .= '<input type="hidden" name="_gid" value="' . $gid . '">';

			/**
			 * Error post
			 */
			if ( ! Post_Error_Handler::is_error_repaired( self::$error ) ) {
				$return .= Admin_Render::make_admin_icon_status_box( 'error', Post_Error_Handler::get_error_message( self::$error ) );
				if ( Synced_Post_Service::current_user_can_edit_synced_posts( $status ) ) {
					// repair button
					$return .= '<br><br><span class="button" onclick="contentSync.repairPost(this);" data-post_id="' . esc_attr( $post_id ) . '">' . __( 'Repair', 'contentsync' ) . '</span>';

					// unlink
					$return .= '<div class="contentsync-gray-box">' .
						'<p>' . __( 'Edit this post?', 'contentsync' ) . '</p>' .
						'<span class="button" onclick="contentSync.unimportPost(this);" data-post_id="' . esc_attr( $post_id ) . '">' .
							__( 'Convert to local post', 'contentsync' ) .
						'</span>' .
					'</div>';
				}
			}
			/**
			 * Root post
			 * this post was exported from here
			 */
			elseif ( $status === 'root' ) {

				$connection_map = Post_Connection_Map::get( $post_id );
				// debug( $connection_map, true );

				// render status
				$return .= Admin_Render::make_admin_icon_status_box( $status, __( 'Root post', 'contentsync' ) );

				if ( Synced_Post_Service::current_user_can_edit_synced_posts( $status ) ) {

					$options          = Post_Meta::get_values( $post_id, 'contentsync_export_options' );
					$editable_options = self::get_contentsync_export_options_for_post( $post_id );
					// debug( $options );

					$return .= '<input type="checkbox" class="hidden _contentsync_export_options_toggle" id="_contentsync_export_options_toggle1"/>' .
						'<label class="contentsync_export_options_toggle" for="_contentsync_export_options_toggle1"><span class="dashicons dashicons-admin-generic"></span></label>' .
						'<div class="editable_contentsync_export_options contentsync-gray-box">' .
						'<p><b>' . __( 'Edit options:', 'contentsync' ) . '</b><br>';

					foreach ( $editable_options as $option ) {
						$checked = isset( $options[ $option['name'] ] ) && $options[ $option['name'] ] ? 'checked="checked"' : '';
						$return .= '<input type="hidden" name="editable_contentsync_export_options[' . $option['name'] . ']" value="off" />' .
							'<label><input type="checkbox" name="editable_contentsync_export_options[' . $option['name'] . ']" ' . $checked . ' />' . $option['title'] . '</label><br>';
					}
					if ( is_array( $connection_map ) && count( $connection_map ) > 0 ) {
						$return .= Admin_Render::make_admin_info_box(
							array(
								'text'  => __( 'Subsequently changing the options has an impact on all imported content and can lead to unforeseen behavior, especially in combination with translations.', 'contentsync' ),
								'style' => 'warning',
							)
						);
					}

					$contentsync_canonical_url = esc_attr( get_post_meta( $post_id, 'contentsync_canonical_url', true ) );
					if ( empty( $contentsync_canonical_url ) ) {
						$contentsync_canonical_url = get_permalink( $post_id );
					}
					$return .= '<br><label>' . __( 'Global Canonical URL', 'contentsync' ) . '</label><br>' .
						'<input type="text" name="contentsync_canonical_url" value="' . $contentsync_canonical_url . '" style="width:100%"/><br>';
					$return .= '</p>';
					$return .= '</div>';
				}

				// render connections
				if ( is_array( $connection_map ) && count( $connection_map ) > 0 ) {

					$count     = 0;
					$post_list = '<ul class="contentsync_box_list">';
					foreach ( $connection_map as $_blog_id => $post_con ) {
						if ( is_numeric( $_blog_id ) ) {
							$post_list .= '<li>' . $post_con['nice'] . ' (<a href="' . $post_con['edit'] . '" target="_blank">' . __( 'To the post', 'contentsync' ) . '</a>)</li>';
							++$count;
						} elseif ( is_array( $post_con ) ) {
							foreach ( $post_con as $__blog_id => $_post_con ) {
								$post_list .= '<li>' . $_post_con['nice'] . ' (<a href="' . $_post_con['edit'] . '" target="_blank">' . __( 'To the post', 'contentsync' ) . '</a>)</li>';
								++$count;
							}
						}
					}
					$post_list .= '</ul>';

					$return .= '<p>' . sprintf(
						_n(
							'This post is in use on 1 other site.',
							'This post is in use on %s other sites.',
							$count,
							'contentsync'
						),
						$count
					) . '</p>';

					$return .= $post_list;
				} else {
					$return .= '<p>' . __( 'This post has not been published to other sites yet.', 'contentsync' ) . '</p>';
				}

				// if the post is in a cluster, show the cluster names
				if ( ! empty( self::get_clusters_including_this_post( $post_id ) ) ) {

					$post_list           = '<p><strong>' . __( 'Cluster', 'contentsync' ) . '</strong><br>' . __( 'This post is part of a cluster.', 'contentsync' ) . '</p>';
					$post_list          .= '<ul class="contentsync_box_list">';
					$cluster_has_reviews = false;
					foreach ( self::get_clusters_including_this_post( $post_id ) as $cluster ) {
						if ( $cluster->enable_reviews ) {
							$cluster_has_reviews = true;
						}

						$post_list .= '<li>';
						$post_list .= '<strong>' . $cluster->title . '</strong>:';
						$post_list .= '<ul style="margin:12px 0 0 4px;">';

						foreach ( $cluster->destination_ids as $blog_id ) {

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

					$return .= $post_list;

					// review history
					if ( $cluster_has_reviews ) {

						$return .= '<p style="margin-bottom:5px"><strong>' . __( 'Reviews', 'contentsync' ) . '</strong></p>';
						$return .= '<input type="checkbox" class="hidden _contentsync_export_options_toggle" id="_contentsync_export_options_toggle2"/>';
						$return .= '<label class="contentsync_export_options_toggle button" for="_contentsync_export_options_toggle2">' . __( 'Toggle review history', 'contentsync' ) . '</label>';
						$return .= '<ul class="editable_contentsync_export_options contentsync_box_list">';
						$reviews = Post_Review_Service::get_all_post_reviews_by_post( $post_id, get_current_blog_id() );
						// debug($reviews);
						foreach ( $reviews as $review ) {
							$details  = '';
							$messages = $review->get_messages();
							if ( ! empty( $messages ) ) {
								// debug($messages);
								// loop through the messages
								foreach ( $messages as $message ) {
									$reviewer = $message->get_reviewer();
									$inner    = $message->get_date() . '<br><strong>' . sprintf( __( '%1$s by %2$s', 'contentsync' ), $message->action, $reviewer ) . '</strong>';
									if ( ! empty( $message->content ) ) {
										$inner .= '<br><em style="display: block; margin: 4px 0">\'' . $message->get_content( true ) . '\'</em>';
									}
									$details .= '<li>' . $inner . '</li>';
								}
								$latest_message = end( $messages );
							}
							if ( empty( $messages ) || $latest_message->action != $review->state ) {
								$inner = $review->state;
								if ( $review->state != 'new' && $review->state != 'in_review' ) {
									$inner = sprintf( __( '%1$s by %2$s', 'contentsync' ), $review->state, 'N/A' ) . '<br>';
								}
								$details .= '<li>' . $inner . '</li>';
							}

							$return .= '<li>' .
											__( 'Edited by: ', 'contentsync' ) . $review->get_editor() . '<br>' .
											__( 'Last edited: ', 'contentsync' ) . $review->get_date() . '<br><br>' .
											'<strong>' . __( 'Review message history', 'contentsync' ) . ':</strong><br>' .
											'<ul style="margin-top: 8px">' . $details . '</ul>' .
										'</li>';
						}
						if ( count( $reviews ) == 0 ) {
							$return .= '<li><em>' . __( 'No reviews.', 'contentsync' ) . '</em></li>';
						}
						$return .= '</ul>';

					}
				}

				if ( Synced_Post_Service::current_user_can_edit_synced_posts( $status ) ) {
					// unexport
					$return .= '<div class="contentsync-gray-box">' .
						'<p>' . __( 'No longer make this post available globally?', 'contentsync' ) . '</p>' .
						'<span class="button button-ghost" onclick="contentSync.unexportPost(this);" data-post_id="' . esc_attr( $post_id ) . '" data-gid="' . esc_attr( $gid ) . '">' .
							__( 'Unlink', 'contentsync' ) .
						'</span>' .
					'</div>';
				}
			}
			/**
			 * Linked post
			 * this post was imported to this site
			 */
			elseif ( $status === 'linked' ) {

				$post_links = Post_Connection_Map::get_links_by_gid( $gid );

				// status
				$return .= Admin_Render::make_admin_icon_status_box( $status, __( 'Linked post', 'contentsync' ) );

				// import info
				$return                   .= '<p>' . sprintf(
					__( 'This post is synced from the site %s', 'contentsync' ),
					'<strong>' . $post_links['nice'] . '</strong>'
				) . '</p>';
				$contentsync_canonical_url = esc_attr( get_post_meta( $post_id, 'contentsync_canonical_url', true ) );
				if ( ! empty( $contentsync_canonical_url ) ) {
					$return .= '<p>' . sprintf(
						__( 'The canonical URL of this post was also set in the source post: %s', 'contentsync' ),
						'<code style="word-break: break-word;">' . $contentsync_canonical_url . '</code>'
					) . '</p>';
				}
				if ( Synced_Post_Service::current_user_can_edit_synced_posts( $status ) ) {
					$return .= '<a href="' . $post_links['edit'] . '" target="_blank">' . __( 'Go to the original post', 'contentsync' ) . '</a>';

					// unlink
					$return .= '<div class="contentsync-gray-box">' .
						'<p>' . __( 'Edit this post?', 'contentsync' ) . '</p>' .
						'<span class="button" onclick="contentSync.unimportPost(this);" data-post_id="' . esc_attr( $post_id ) . '">' .
							__( 'Convert to local post', 'contentsync' ) .
						'</span>' .
					'</div>';
				}
			}

			// display fix info
			if ( self::$error && Post_Error_Handler::is_error_repaired( self::$error ) ) {
				$return .= Admin_Render::make_admin_icon_status_box( 'info', Post_Error_Handler::get_error_repaired_log( self::$error ) );
			}
		}

		return $return;
	}
}
