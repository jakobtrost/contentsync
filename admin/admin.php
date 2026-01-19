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

namespace Contentsync\Contents;

use Contentsync\Main_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

new Admin();
class Admin {

	/**
	 * holds the posttype args
	 *
	 * set via function 'init_args'
	 */
	public static $args = array();

	/**
	 * Holds instance of the class 'Global_List_Table'
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

		// add the menu items & pages
		add_action( 'admin_menu', array( $this, 'init' ), 3 );
		add_action( 'network_admin_menu', array( $this, 'init' ), 3 );
		add_action( 'admin_bar_menu', array( $this, 'add_network_adminbar_items' ), 11 );
		add_filter( 'set-screen-option', array( $this, 'save_overview_screen_options' ), 10, 3 );

		// modify the post.php pages
		add_action( 'admin_notices', array( $this, 'add_global_notice' ) );
		add_filter( 'admin_body_class', array( $this, 'edit_body_class' ), 99 );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'do_meta_boxes', array( $this, 'remove_revisions_meta_box' ), 10, 3 );

		// modify the post overview pages
		add_action( 'admin_init', array( $this, 'setup_columns' ) );
		add_filter( 'post_row_actions', array( $this, 'filter_row_actions' ), 10, 2 );

		// add overlay contents
		add_filter( 'contentsync_overlay_contents', array( $this, 'add_overlay_contents' ) );

		if ( isset( $_GET['unsynced'] ) ) {
			add_action(
				'admin_init',
				function () {
					debug( Main_Helper::__unstable_get_unsynced_posts() );
				}
			);
		}
	}


	/**
	 * =================================================================
	 *                          INIT
	 * =================================================================
	 */

	/**
	 * Init the class vars & add the menu
	 */
	public function init() {

		self::$args = array(
			'slug'           => 'global_contents',
			'title'          => __( 'Content Sync', 'contentsync' ),
			'singular'       => __( 'Synced Post', 'contentsync' ),
			'plural'         => __( 'Content Sync', 'contentsync' ),
			'admin_url'      => admin_url( 'admin.php?page=global_contents' ),
			'network_url'    => network_admin_url( 'admin.php?page=global_contents' ),
			'posts_per_page' => 20,
			'option'         => 'contentsync_setup',
		);

		/**
		 * add the main page
		 */
		$hook = add_menu_page(
			self::$args['title'], // page title
			self::$args['title'], // menu title
			'manage_options', // capability
			self::$args['slug'], // slug
			array( $this, 'render_overview_page' ), // function
			CONTENTSYNC_PLUGIN_URL . '/assets/icon/greyd-menuicon-contentsync.svg', // icon url
			72 // position
		);
		add_action( "load-$hook", array( $this, 'add_overview_screen_options' ) );

		/**
		 * add the ghost submenu item
		 */
		add_submenu_page(
			self::$args['slug'], // parent slug
			self::$args['title'],  // page title
			is_network_admin() ? __( 'Manage content at network level', 'contentsync' ) : __( 'Manage content on this website', 'contentsync' ), // menu title
			'manage_options', // capability
			self::$args['slug'], // slug
			'', // function
			1 // position
		);

		if ( is_multisite() && is_super_admin() ) {

			/**
			 * add the network link
			 */
			if ( ! is_network_admin() ) {
				add_submenu_page(
					self::$args['slug'], // parent slug
					__( 'Manage content at network level', 'contentsync' ),  // page title
					__( 'Manage content at network level', 'contentsync' ), // menu title
					'manage_options', // capability
					self::$args['network_url'], // slug
					'', // function
					50 // position
				);
			}
		}
	}

	/**
	 * Network adminbar
	 */
	public function add_network_adminbar_items( $wp_admin_bar ) {
		$wp_admin_bar->add_node(
			array(
				'id'     => 'contentsync',
				'title'  => __( 'Content Sync', 'contentsync' ),
				'parent' => 'network-admin',
				'href'   => network_admin_url( 'admin.php?page=global_contents' ),
			)
		);
	}


	/**
	 * =================================================================
	 *                          OVERVIEW PAGE
	 * =================================================================
	 */

	/**
	 * Screen options for the admin pages
	 */
	public function add_overview_screen_options() {
		$args = array(
			'label'   => __( 'Entries per page:', 'contentsync' ),
			'default' => self::$args['posts_per_page'],
			'option'  => 'globals_per_page',
		);

		add_screen_option( 'per_page', $args );

		$this->Global_List_Table = new Global_List_Table( self::$args['posts_per_page'] );
	}

	/**
	 * Save the admin screen option
	 */
	public function save_overview_screen_options( $status, $option, $value ) {

		if ( 'globals_per_page' == $option ) {
			return $value;
		}

		return $status;
	}


	/**
	 * Render the admin pages
	 *
	 * on network & site level
	 */
	public function render_overview_page() {

		$this->Global_List_Table->render_page( self::$args['title'] );

		self::$overlay_contents = array_merge(
			self::$overlay_contents,
			array(
				'contentsync_import',
				'contentsync_import_bulk',
				'contentsync_unimport',
				'contentsync_unexport',
				'contentsync_trash',
				'contentsync_delete',
				'contentsync_repair',
			)
		);
	}


	/**
	 * =================================================================
	 *                          POST PAGE
	 * =================================================================
	 */

	/**
	 * Add the locked notice
	 */
	public function add_global_notice() {

		$screen = get_current_screen();

		// classic post edit page
		if ( $screen->base === 'post' && $screen->action !== 'add' ) {
			$post_id = isset( $_GET['post'] ) ? $_GET['post'] : null;
			if ( ! empty( $post_id ) ) {

				$notice_content = self::get_global_notice_content( $post_id, $screen->is_block_editor() ? 'block_editor' : 'classic' );

				if ( ! empty( $notice_content ) ) {
					echo $notice_content;
				}
			}
		}

		if ( $screen->base === 'site-editor' ) {
			self::$overlay_contents[] = 'contentsync_export';
			self::$overlay_contents[] = 'contentsync_overwrite';
			self::$overlay_contents[] = 'contentsync_repair';
			self::$overlay_contents[] = 'contentsync_unimport';
			self::$overlay_contents[] = 'contentsync_unexport';
			self::$overlay_contents[] = 'contentsync_unsaved';
			self::$overlay_contents[] = 'contentsync_review_approve';
			self::$overlay_contents[] = 'contentsync_review_deny';
			self::$overlay_contents[] = 'contentsync_review_revert';
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
			self::$error = \Contentsync\get_post_error( $post_id );
		}

		// notice contents
		if ( ! \Contentsync\is_error_repaired( self::$error ) ) {
			$notice = 'error';
			$icon   = 'warning color_red';
			$text   = \Contentsync\get_error_message( self::$error );
			if ( Main_Helper::current_user_can_edit_synced_posts() ) {
				$buttons = array(
					array(
						'label'   => __( 'Repair', 'contentsync' ),
						'onClick' => 'contentsync.repairPost(this, ' . esc_attr( $post_id ) . ')',
						'variant' => 'primary',
					),
				);
			}
		} else {
			$status = get_post_meta( $post_id, 'synced_post_status', true );

			if ( $status === 'linked' ) {

				$post_links = \Contentsync\get_post_links_by_gid( $gid );

				$text = sprintf(
					__( 'This post is synced from the site %s', 'contentsync' ),
					'<strong>' . $post_links['nice'] . '</strong>'
				);
				if ( Main_Helper::current_user_can_edit_synced_posts( 'linked' ) ) {
					$buttons = array(
						array(
							'label'   => __( 'Edit the original post', 'contentsync' ),
							'url'     => $post_links['edit'],
							'variant' => 'primary',
						),
						array(
							'label'     => __( 'Convert to local post', 'contentsync' ),
							'className' => 'button-ghost',
							'onClick'   => 'contentsync.unimportPost(this, ' . esc_attr( $post_id ) . ')',
							'variant'   => 'tertiary',
						),
					);
				}
			} elseif ( $status === 'root' ) {

				$connection_map = \Contentsync\get_post_connection_map( $post_id );

				/**
				 * Review Status
				 *
				 * The post can have different statuses: live, in review, denied, empty (new post)
				 */
				$review                = get_post_review_by_post( $post_id, get_current_blog_id() );
				$default_review_status = empty( self::get_clusters_including_this_post( $post_id ) ) && empty( $connection_map ) ? 'new' : 'live';
				$review_status         = $review && $review->state ? $review->state : $default_review_status;

				/**
				 * @note reviewer messages must be checked for ', otherwise the notices will break and not show at all. May need to be raised in the Gutenberg repo.
				 */
				$reviewer                 = '';
				$reviewer_message         = '';
				$reviewer_message_content = '';
				if ( $review && $review->ID ) {
					$reviewer_message = get_latest_message_by_post_review_id( $review->ID );
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

				if ( ! Main_Helper::current_user_can_edit_synced_posts( $status ) ) {
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
							'onClick' => 'contentsync.openReviewApprove(this, ' . esc_attr( $post_id ) . ', ' . esc_attr( $review->ID ) . ')',
						),
						array(
							'label'   => __( 'Request modification', 'contentsync' ),
							'onClick' => 'contentsync.openReviewDeny(this, ' . esc_attr( $post_id ) . ', ' . esc_attr( $review->ID ) . ')',
						),
						array(
							'label'   => __( 'Revert changes', 'contentsync' ),
							'onClick' => 'contentsync.openReviewRevert(this, ' . esc_attr( $post_id ) . ', ' . esc_attr( $review->ID ) . ')',
						),
					);
				}

				self::$overlay_contents[] = 'contentsync_review_approve';
				self::$overlay_contents[] = 'contentsync_review_deny';
				self::$overlay_contents[] = 'contentsync_review_revert';
			}
		}

		if ( ! empty( $text ) ) {

			// block editor notice
			if ( $mode === 'block_editor' ) {

				return "<script id='contentsync-notice__script'>
					( function ( wp ) {
						wp.data.dispatch( 'core/notices' ).createNotice(
							'" . ( $notice === 'info' ? 'success' : $notice ) . " contentsync_components_notice',
							'" . $text . "',
							{
								className: 'contentsync-notice__element',
								isDismissible: false,
								__unstableHTML: true,
								icon: wp.element.createElement( wp.components.Icon, { icon: '$icon' } ),
								actions: [
									" . implode(
				', ',
				array_map(
					function ( $button ) {
							return '{
                                                                                                                                                                                                                                                                                                                                                                                                                                ' . ( isset( $button['label'] ) ? "label: '{$button['label']}'," : '' ) . '
                                                                                                                                                                                                                                                                                                                                                                                                                                ' . ( isset( $button['url'] ) ? "url: '{$button['url']}'," : '' ) . '
                                                                                                                                                                                                                                                                                                                                                                                                                                ' . ( isset( $button['onClick'] ) ? "onClick: () => {{$button['onClick']}}," : '' ) . '
                                                                                                                                                                                                                                                                                                                                                                                                                                ' . ( isset( $button['variant'] ) ? "variant: '{$button['variant']}'," : '' ) . '
                                                                                                                                                                                                                                                                                                                                                                                                                                ' . ( isset( $button['className'] ) ? "className: '{$button['className']} contentsync-notice__action'" : "className: 'contentsync-notice__action'" ) . ',
                                                                                                                                                                                                                                                                                                                                                                                                                            }';
					},
        $buttons
				)
				) . '
								],
							}
						);
					} )( window.wp );
				</script>';
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
				$return = "<div class='notice notice-$notice synced_post_notice'>" .
					'<div>' .
						"<div><span class='dashicons dashicons-$icon'></span></div>" .
						'<div>' .
							'<p>' . $text . '</p>' .
						'</div>';
				if ( $buttons && ! empty( $buttons ) ) {
					$return .= "<div class='buttons'>";
					foreach ( (array) $buttons as $button ) {
						if ( isset( $button['url'] ) ) {
							$return .= "<a class='button " . ( isset( $button['className'] ) ? $button['className'] : '' ) . "' href='{$button['url']}'>{$button['label']}</a>";
						} elseif ( isset( $button['onClick'] ) ) {
							$return .= "<span class='button " . ( isset( $button['className'] ) ? $button['className'] : '' ) . "' onclick='{$button['onClick']}'>{$button['label']}</span>";
						}
					}
							$return .= '</div>';
				}
					$return .= '</div>' .
				'</div>';
				return $return;
			}
		}

		return '';
	}

	/**
	 * Add Meta Boxes
	 */
	public function add_meta_box() {

		if ( get_current_screen()->action === 'add' ) {
			return false;
		}

		$posttypes = \Contentsync\get_export_post_types();

		add_meta_box(
			/* ID       */            'global_content_box',
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

		self::$overlay_contents[] = 'contentsync_unsaved';
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
			if ( Main_Helper::current_user_can_edit_synced_posts( $status ) ) {
				$return .= '<p>' . __( 'Do you want this post to be available throughout all connected sites?', 'contentsync' ) . "</p>
					<span class='button' onclick='contentsync.exportPost(this);' data-post_id='" . esc_attr( $post_id ) . "'>" .
						__( 'Convert to global content', 'contentsync' ) .
					'</span>';
			}

			/**
			 * Get similar posts via JS-ajax
			 */
			$return .= "<div id='contentsync_similar_posts'>
				<div class='found hidden'>
					<p class='singular hidden'>" . __( 'A similar post is available globally:', 'contentsync' ) . "</p>
					<p class='plural hidden'>" . __( 'Similar posts are available globally:', 'contentsync' ) . "</p>
					<ul class='contentsync_box_list' data-item='" . preg_replace(
						'/\s{2,}/',
						'',
						esc_attr(
							"<li>
							<span class='flex'>
								<a href='{{href}}' target='_blank'>{{post_title}}</a>
								<span class='button button-ghost tiny' onclick='contentsync.overwritePost(this);' data-post_id='{{post_id}}' data-gid='{{gid}}'>" . __( 'Use', 'contentsync' ) . '</span>
							</span>
							<small>{{nice_url}}</small>
						</li>'
						)
					) . "'></ul>
				</div>
				<p class='not_found hidden'><i>" . __( 'No similar global contents found.', 'contentsync' ) . "</i></p>
				<p class='loading'>
					<span class='loader'></span>
				</p>
			</div>";

			// add warning for contentsync_export (shown via JS)
			self::$overlay_warnings['contentsync_export'] = "<div class='export_warning_similar_posts hidden' style='margin:1em 0 -2em;'>" . \Contentsync\Utils\make_admin_info_box(
				array(
					'text'  => __( 'Similar content is already available globally. Are you sure you want to make this content global additionally?', 'contentsync' ),
					'style' => 'orange',
				)
			) . '</div>';

			self::$overlay_contents[] = 'contentsync_export';
			self::$overlay_contents[] = 'contentsync_overwrite';
		}

		// synced post
		else {

			list( $root_blog_id, $root_post_id, $root_net_url ) = Main_Helper::explode_gid( $gid );

			// check for error
			if ( self::$error === null ) {
				self::$error = \Contentsync\get_post_error( $post_id );
			}

			$return .= "<input type='hidden' name='_gid' value='$gid'>";

			/**
			 * Error post
			 */
			if ( ! \Contentsync\is_error_repaired( self::$error ) ) {
				$return .= \Contentsync\Utils\make_admin_icon_status_box( 'error', \Contentsync\get_error_message( self::$error ) );
				if ( Main_Helper::current_user_can_edit_synced_posts( $status ) ) {
					// repair button
					$return                  .= "<br><br><span class='button' onclick='contentsync.repairPost(this);' data-post_id='" . esc_attr( $post_id ) . "'>" . __( 'Repair', 'contentsync' ) . '</span>';
					self::$overlay_contents[] = 'contentsync_repair';

					// unlink
					$return .= "<div class='contentsync-gray-box'>
						<p>" . __( 'Edit this post?', 'contentsync' ) . "</p>
						<span class='button' onclick='contentsync.unimportPost(this);' data-post_id='" . esc_attr( $post_id ) . "'>
							" . __( 'Convert to local post', 'contentsync' ) . '
						</span>
					</div>';

					self::$overlay_contents[] = 'contentsync_unimport';
				}
			}
			/**
			 * Root post
			 * this post was exported from here
			 */
			elseif ( $status === 'root' ) {

				$connection_map = \Contentsync\get_post_connection_map( $post_id );
				// debug( $connection_map, true );

				// render status
				$return .= \Contentsync\Utils\make_admin_icon_status_box( $status, __( 'Root post', 'contentsync' ) );

				/**
				 * @since 1.7 'contentsync_export_options' can now be edited.
				 */
				if ( Main_Helper::current_user_can_edit_synced_posts( $status ) ) {
					$options          = \Contentsync\get_contentsync_meta_values( $post_id, 'contentsync_export_options' );
					$editable_options = self::get_contentsync_export_options_for_post( $post_id );
					// debug( $options );
					$return .= "<input type='checkbox' class='hidden _contentsync_export_options_toggle' id='_contentsync_export_options_toggle1'/>
					<label class='contentsync_export_options_toggle' for='_contentsync_export_options_toggle1'><span class='dashicons dashicons-admin-generic'></span></label>
					<div class='editable_contentsync_export_options contentsync-gray-box'>
						<p><b>" . __( 'Edit options:', 'contentsync' ) . '</b><br>';
					foreach ( $editable_options as $option ) {
						$checked = isset( $options[ $option['name'] ] ) && $options[ $option['name'] ] ? "checked='checked'" : '';
						$return .= "<input type='hidden' name='editable_contentsync_export_options[{$option['name']}]' value='off' />
								<label><input type='checkbox' name='editable_contentsync_export_options[{$option['name']}]' {$checked} />{$option['title']}</label><br>";
					}
					if ( is_array( $connection_map ) && count( $connection_map ) > 0 ) {
						$return .= \Contentsync\Utils\make_admin_info_box(
							array(
								'text'  => __( 'Subsequently changing the options has an impact on all imported content and can lead to unforeseen behavior, especially in combination with translations.', 'contentsync' ),
								'style' => 'warning',
							)
						);
					}

						/**
						 * @since 1.8 Add a canonical url
						 */
						$contentsync_canonical_url = esc_attr( get_post_meta( $post_id, 'contentsync_canonical_url', true ) );
					if ( empty( $contentsync_canonical_url ) ) {
						$contentsync_canonical_url = get_permalink( $post_id );
					}
						$return .= '<br><label>' . __( 'Global Canonical URL', 'contentsync' ) . "</label><br>
							<input type='text' name='contentsync_canonical_url' value='{$contentsync_canonical_url}' style='width:100%'/><br>";
						$return .= '</p>';
					$return     .= '</div>';
				}

				// subscribed posttype info
				if ( isset( $options['whole_posttype'] ) && $options['whole_posttype'] && get_post_type( $post_id ) === 'tp_posttypes' ) {
					$return .= \Contentsync\Utils\make_admin_info_box(
						array(
							'text'  => __( 'This post type including posts was provided for all connected sites. All posts are synchronized permanently.', 'contentsync' ),
							'style' => 'info',
						)
					);
				}

				// render connections
				if ( is_array( $connection_map ) && count( $connection_map ) > 0 ) {

					$count     = 0;
					$post_list = "<ul class='contentsync_box_list'>";
					foreach ( $connection_map as $_blog_id => $post_con ) {
						if ( is_numeric( $_blog_id ) ) {
							$post_list .= '<li>' . $post_con['nice'] . " (<a href='" . $post_con['edit'] . "' target='_blank'>" . __( 'To the post', 'contentsync' ) . '</a>)</li>';
							++$count;
						} elseif ( is_array( $post_con ) ) {
							foreach ( $post_con as $__blog_id => $_post_con ) {
								$post_list .= '<li>' . $_post_con['nice'] . " (<a href='" . $_post_con['edit'] . "' target='_blank'>" . __( 'To the post', 'contentsync' ) . '</a>)</li>';
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
					$post_list          .= "<ul class='contentsync_box_list'>";
					$cluster_has_reviews = false;
					foreach ( self::get_clusters_including_this_post( $post_id ) as $cluster ) {
						if ( $cluster->enable_reviews ) {
							$cluster_has_reviews = true;
						}

						$post_list .= '<li>';
						$post_list .= '<strong>' . $cluster->title . '</strong>:';
						$post_list .= "<ul style='margin:12px 0 0 4px;'>";

						foreach ( $cluster->destination_ids as $blog_id ) {

							if ( strpos( $blog_id, '|' ) == ! false ) {
								$tmp        = explode( '|', $blog_id );
								$connection = isset( $connection_map[ $tmp[1] ] ) ? $connection_map[ $tmp[1] ] : 'unknown';
								$blog       = isset( $connection[ intval( $tmp[0] ) ] ) ? $connection[ intval( $tmp[0] ) ] : 'unknown';

								if ( isset( $blog['blog'] ) ) {
									$post_list .= '<li>' . sprintf( __( 'Site %s', 'contentsync' ), "<a href='" . $blog['blog'] . "' target='_blank'>" . $blog['nice'] . '</a>' ) . '</li>';
								}
							} else {
								$post_list .= '<li>' . sprintf( __( 'Site %s', 'contentsync' ), "<a href='" . get_site_url( $blog_id ) . "'>" . get_blog_details( $blog_id )->blogname . '</a>' ) . '</li>';
							}
						}
						$post_list .= '</ul>';
						$post_list .= '</li>';
					}
					$post_list .= '</ul>';

					$return .= $post_list;

					// review history
					if ( $cluster_has_reviews ) {

						$return .= "<p style='margin-bottom:5px'><strong>" . __( 'Reviews', 'contentsync' ) . '</strong></p>';
						$return .= "<input type='checkbox' class='hidden _contentsync_export_options_toggle' id='_contentsync_export_options_toggle2'/>";
						$return .= "<label class='contentsync_export_options_toggle button' for='_contentsync_export_options_toggle2'>" . __( 'Toggle review history', 'contentsync' ) . '</label>';
						$return .= "<ul class='editable_contentsync_export_options contentsync_box_list'>";
						$reviews = get_all_post_reviews_by_post( $post_id, get_current_blog_id() );
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
										$inner .= "<br><em style='display: block; margin: 4px 0'>'" . $message->get_content( true ) . "'</em>";
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
											"<ul style='margin-top: 8px'>" . $details . '</ul>' .
										'</li>';
						}
						if ( count( $reviews ) == 0 ) {
							$return .= '<li><em>' . __( 'No reviews.', 'contentsync' ) . '</em></li>';
						}
						$return .= '</ul>';

					}
				}

				if ( Main_Helper::current_user_can_edit_synced_posts( $status ) ) {
					// unexport
					$return .= "<div class='contentsync-gray-box'>
						<p>" . __( 'No longer make this post available globally?', 'contentsync' ) . "</p>
						<span class='button button-ghost' onclick='contentsync.unexportPost(this);' data-post_id='" . esc_attr( $post_id ) . "' data-gid='" . esc_attr( $gid ) . "'>" .
							__( 'Unlink', 'contentsync' ) .
						'</span>
					</div>';

					self::$overlay_contents[] = 'contentsync_unexport';
				}
			}
			/**
			 * Linked post
			 * this post was imported to this site
			 */
			elseif ( $status === 'linked' ) {

				$post_links = \Contentsync\get_post_links_by_gid( $gid );

				// status
				$return .= \Contentsync\Utils\make_admin_icon_status_box( $status, __( 'Linked post', 'contentsync' ) );

				// options
				$options = \Contentsync\get_contentsync_meta_values( $post_id, 'contentsync_export_options' );
				if ( $options['whole_posttype'] && get_post_type( $post_id ) === 'tp_posttypes' ) {
					$return .= \Contentsync\Utils\make_admin_info_box(
						array(
							'text'  => __( 'This post type including posts was provided for all connected sites. All posts are synchronized permanently.', 'contentsync' ),
							'style' => 'info',
						)
					);
				}

				// import info
				$return                   .= '<p>' . sprintf(
					__( 'This post is synced from the site %s', 'contentsync' ),
					'<strong>' . $post_links['nice'] . '</strong>'
				) . '</p>';
				$contentsync_canonical_url = esc_attr( get_post_meta( $post_id, 'contentsync_canonical_url', true ) );
				if ( ! empty( $contentsync_canonical_url ) ) {
					$return .= '<p>' . sprintf(
						__( 'The canonical URL of this post was also set in the source post: %s', 'contentsync' ),
						"<code style='word-break: break-word;'>" . $contentsync_canonical_url . '</code>'
					) . '</p>';
				}
				if ( Main_Helper::current_user_can_edit_synced_posts( $status ) ) {
					$return .= "<a href='" . $post_links['edit'] . "' target='_blank'>" . __( 'Go to the original post', 'contentsync' ) . '</a>';

					// unlink
					$return .= "<div class='contentsync-gray-box'>
						<p>" . __( 'Edit this post?', 'contentsync' ) . "</p>
						<span class='button' onclick='contentsync.unimportPost(this);' data-post_id='" . esc_attr( $post_id ) . "'>
							" . __( 'Convert to local post', 'contentsync' ) . '
						</span>
					</div>';

					self::$overlay_contents[] = 'contentsync_unimport';
				}
			}

			// display fix info
			if ( self::$error && \Contentsync\is_error_repaired( self::$error ) ) {
				$return .= \Contentsync\Utils\make_admin_icon_status_box( 'info', \Contentsync\get_error_repaired_log( self::$error ) );
			}
		}

		return $return;
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
		elseif ( ! empty( $status ) && ! Main_Helper::current_user_can_edit_synced_posts( $status ) ) {
			$classes .= ' contentsync-locked';
		}

		return $classes;
	}

	/**
	 * Remove revisions meta box
	 *
	 * @param  string  $post_type
	 * @param  string  $context
	 * @param  WP_Post $post
	 */
	public function remove_revisions_meta_box( $post_type, $context, $post ) {
		if ( empty( $post ) ) {
			return;
		}

		$status = get_post_meta( $post->ID, 'synced_post_status', true );
		if ( $status === 'linked' ) {
			remove_meta_box( 'revisionsdiv', $post_type, $context );
		}
	}

	/**
	 * Holds the cluster objects including the current post.
	 *
	 * @var null|array  Null on init, Array with cluster objects otherwise.
	 */
	public static $clusters_including_this_post = null;

	public static function get_clusters_including_this_post( $post_id ) {
		if ( self::$clusters_including_this_post === null ) {
			self::$clusters_including_this_post = get_clusters_including_post( $post_id );
		}
		return self::$clusters_including_this_post;
	}


	/**
	 * =================================================================
	 *                          MODIFY EDIT PAGES
	 * =================================================================
	 */

	/**
	 * Filter row actions for synced posts:
	 * - Remove quick edit for linked posts
	 * - Remove trash for non-editors
	 *
	 * @param  array    $actions Array of current actions
	 * @param  \WP_Post $post Post object
	 *
	 * @return array
	 */
	public function filter_row_actions( $actions, $post ) {

		$status = get_post_meta( $post->ID, 'synced_post_status', true );

		if ( $status === 'linked' ) {
			unset( $actions['inline hide-if-no-js'] );
		}

		if ( ! empty( $status ) && ! Main_Helper::current_user_can_edit_synced_posts( $status ) ) {
			unset( $actions['trash'] );
		}

		return $actions;
	}

	/**
	 * Setup custom columns for all supported post types
	 */
	public function setup_columns() {
		$post_types = (array) \Contentsync\get_export_post_types();

		foreach ( $post_types as $post_type ) {
			add_filter( 'manage_' . $post_type . '_posts_columns', array( $this, 'add_column' ) );
			add_action( 'manage_' . $post_type . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );

			/**
			 * Add bulk action to make posts global
			 *
			 * @since 2.6.0 [version has to be edited again]
			 */
			add_filter( 'bulk_actions-edit-' . $post_type, array( $this, 'bulk_action_make_global' ) );
			add_filter( 'handle_bulk_actions-edit-' . $post_type, array( $this, 'handle_bulk_action_make_global' ), 10, 3 );

			/**
			 * Add custom column to attachment overview
			 *
			 * @since 1.2
			 */
			if ( $post_type === 'attachment' ) {
				add_filter( 'manage_media_columns', array( $this, 'add_column' ) );
				add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
			}
		}

		if ( defined( 'GREYD_THEME_CONFIG' ) ) {
			add_filter( 'contentsync_theme_manage_assets_columns', array( $this, 'add_column' ) );
			add_filter( 'contentsync_theme_manage_assets_column_default', array( $this, 'render_column' ), 10, 3 );
		}
	}

	/**
	 * Add the custom column
	 *
	 * @param  array $columns
	 * @return array
	 */
	public function add_column( $columns ) {

		// delete date column
		unset( $columns['date'] );

		// register custom column
		$columns['contentsync_status'] = \Contentsync\Utils\make_dashicon( 'admin-site-alt' );

		// re-insert date column after our column
		$columns['date'] = esc_html__( 'Date' );

		return $columns;
	}

	/**
	 * Render the custom column
	 *
	 * @param  string $column_name
	 * @param  int    $post_id
	 */
	public function render_column( $column_name, $post_id ) {
		if ( 'contentsync_status' === $column_name ) {
			$status = get_post_meta( $post_id, 'synced_post_status', true );

			$posttype = get_post_type( $post_id );

			$supported_post_types = \Contentsync\get_export_post_types();

			if ( ! in_array( $posttype, $supported_post_types ) ) {
				return;
			}

			if ( ! empty( $status ) ) {
				// don't check for errors on overview pages, as it costs performance with remote posts...
				// $status = \Contentsync\get_post_error( $post_id ) ? 'error' : $status;
				echo \Contentsync\Utils\make_admin_icon_status_box( $status );
			} elseif ( Main_Helper::current_user_can_edit_synced_posts( 'root' ) ) {
				// if the status is empty, the post is not a synced post and therefore we will render a button to make it global

				self::$overlay_contents = array_merge(
					self::$overlay_contents,
					array(
						'contentsync_export',
					)
				);

				printf(
					'<div class="contentsync_info_box contentsync_status" data-title="%1$s">' .
						'<button role="button" class="button large button-tertiary" onclick="%2$s" data-post_id="%3$s">' .
							'<span class="dashicons dashicons-plus-alt2"></span>' .
						'</button>' .
					'</div>',
					/* title    */ preg_replace( '/\s{1}/', '&nbsp;', __( 'Convert to global content', 'contentsync' ) ),
					/* onclick  */ 'contentsync.exportPost(this); return false;',
					/* post_id  */ esc_attr( $post_id ),
				);
			}
		}
	}

	public function bulk_action_make_global( $bulk_actions ) {
		if ( Main_Helper::current_user_can_edit_synced_posts( 'root' ) ) {
			$bulk_actions['contentsync_make_posts_global'] = __( 'Content Sync: Make posts global', 'contentsync' );
		}
		return $bulk_actions;
	}

	public function handle_bulk_action_make_global( $redirect_to, $doaction, $post_ids ) {

		if ( $doaction !== 'contentsync_make_posts_global' ) {
			return $redirect_to;
		}

		$export_args = \Contentsync\get_contentsync_default_export_options();
		foreach ( $post_ids as $post_id ) {
			$gid = \Contentsync\make_post_global( $post_id, $export_args );

		}

		return $redirect_to;
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
		$export_form = "<form id='contentsync_export_form' class='" . ( count( $export_options ) ? 'inner_content' : '' ) . "'>";
		foreach ( $export_options as $option ) {
			$name         = $option['name'];
			$checked      = isset( $option['checked'] ) && $option['checked'] ? "checked='checked'" : '';
			$export_form .= "<label for='$name'>
				<input type='checkbox' id='$name' name='$name' $checked />
				<span>" . $option['title'] . '</span>
				<small>' . $option['descr'] . '</small>
			</label>';
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
			$options .= "<option value='$name'>$value</option>";
		}
		$options = urlencode( $options );

		$import_form = "<form id='contentsync_export_form' class='inner_content'>
			<label for='handle_conflicts'>
				" . __( 'What should be done with ', 'contentsync' ) . "
			</label>
			<select id='handle_conflicts' name='handle_conflicts'>
				<option value='replace'>" . __( 'Replace', 'contentsync' ) . "</option>
				<option value='keep'>" . __( 'Keep both', 'contentsync' ) . '</option>
			</select>
		</form>';

		/**
		 * Get connected posts
		 */
		$connection_map = \Contentsync\get_post_connection_map( $post_id );
		$post_list      = '';

		if ( is_array( $connection_map ) && count( $connection_map ) > 0 ) {

			$count     = 0;
			$post_list = "<ul class='contentsync_box_list' style='margin:0px 0 10px'>";
			foreach ( $connection_map as $_blog_id => $post_con ) {
				if ( is_numeric( $_blog_id ) ) {
					$post_list .= '<li>' . $post_con['nice'] . " (<a href='" . $post_con['edit'] . "' target='_blank'>" . __( 'to the post', 'contentsync' ) . '</a>)</li>';
					++$count;
				} elseif ( is_array( $post_con ) ) {
					foreach ( $post_con as $__blog_id => $_post_con ) {
						$post_list .= '<li>' . $_post_con['nice'] . " (<a href='" . $_post_con['edit'] . "' target='_blank'>" . __( 'to the post', 'contentsync' ) . '</a>)</li>';
						++$count;
					}
				}
			}
			$post_list .= '</ul>';
		} elseif ( $post_id ) {

			// if the post is in a cluster, show the cluster names
			if ( ! empty( self::get_clusters_including_this_post( $post_id ) ) ) {
				$post_list = "<ul class='contentsync_box_list' style='margin:0px 0 10px'>";
				foreach ( self::get_clusters_including_this_post( $post_id ) as $cluster ) {
					$post_list .= '<li>';
					$post_list .= '<strong>' . sprintf( __( "Cluster '%s'", 'contentsync' ), $cluster->title ) . '</strong>:';
					$post_list .= "<ul style='margin:12px 0 0 4px;'>";
					foreach ( $cluster->destination_ids as $blog_id ) {
						// $post_list .= "<li>" . sprintf( __("Site %s", "contentsync"), "<a href='".get_site_url( $blog_id )."'>".get_blog_details( $blog_id )->blogname."</a>" ) . "</li>";
						if ( strpos( $blog_id, '|' ) == ! false ) {
							$tmp        = explode( '|', $blog_id );
							$connection = isset( $connection_map[ $tmp[1] ] ) ? $connection_map[ $tmp[1] ] : 'unknown';
							$blog       = isset( $connection[ intval( $tmp[0] ) ] ) ? $connection[ intval( $tmp[0] ) ] : 'unknown';

							if ( isset( $blog['blog'] ) ) {
								$post_list .= '<li>' . sprintf( __( 'Site %s', 'contentsync' ), "<a href='" . $blog['blog'] . "' target='_blank'>" . $blog['nice'] . '</a>' ) . '</li>';
							}
						} else {
							$post_list .= '<li>' . sprintf( __( 'Site %s', 'contentsync' ), "<a href='" . get_site_url( $blog_id ) . "'>" . get_blog_details( $blog_id )->blogname . '</a>' ) . '</li>';
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
		$reviewer_message_deny = '<form id="contentsync_review_message_form_deny" class="contentsync_review_message_forms">
			<label for="review_message_deny">' . __( 'Message to the editor (required)', 'contentsync' ) . '</label>
			<textarea id="review_message_deny" name="review_message_deny" placeholder="' . __( 'Please enter a message for the editor.', 'contentsync' ) . '" rows="4"></textarea></form>';

		/**
		 * Message Box to revert the review
		 */
		$reviewer_message_revert = '<form id="contentsync_review_message_form_revert" class="contentsync_review_message_forms">
			<label for="review_message_revert">' . __( 'Message to the editor (optional)', 'contentsync' ) . '</label>
			<textarea id="review_message_revert" name="review_message_revert" placeholder="' . __( 'Please enter a message for the editor.', 'contentsync' ) . '" rows="4"></textarea></form>';

		/**
		 * Add all the contents
		 */
		$overlay_contents = array(
			'contentsync_export'         => array(
				'confirm' => array(
					'title'   => __( 'Convert to global content', 'contentsync' ),
					'descr'   => sprintf( __( 'This will make the post "%s" available on all connected sites.', 'contentsync' ), '<strong class="replace"></strong>' ),
					'content' => $export_form,
					'button'  => __( 'Convert now', 'contentsync' ),
				),
				'loading' => array(
					'descr' => __( 'Converting post.', 'contentsync' ),
				),
				'reload'  => array(
					'title' => __( 'Successfully converted.', 'contentsync' ),
					'descr' => __( 'The post has been converted to global content.', 'contentsync' ),
				),
				'success' => array(
					'title' => __( 'Successfully converted.', 'contentsync' ),
					'descr' => __( 'The post has been converted to global content.', 'contentsync' ),
				),
				'fail'    => array(
					'title' => __( 'Convert failed.', 'contentsync' ),
					'descr' => __( 'The post could not be converted to global content.', 'contentsync' ),
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
					'content' => "<form id='contentsync_import_form'>
										<div class='conflicts'>
											<p>" . __( '<b>Attention:</b> Some content already seems to exist on this site. Choose what to do with it.', 'contentsync' ) . "</p>
											<div class='inner_content' data-multioption='" . __( 'Multiselect', 'contentsync' ) . "' data-options='$options'></div>
										</div>
										<div class='new'>
											<p>" . sprintf( __( 'No conflicts found. Do you want to make the post "%s" available on this site now?', 'contentsync' ), "<strong class='post_title'></strong>" ) . '</p>
										</div>
									</form>',
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
					'content' => "<form id='contentsync_import_bulk_form'>
										<div class='new'>
											<p>" . sprintf( __( 'No conflicts found. Do you want to make the posts available on this site now?', 'contentsync' ), "<strong class='post_title'></strong>" ) . "</p>
										</div>
										<div class='conflicts'>
											<p>" . __( '<b>Attention:</b> Some content already seems to exist on this site. Choose what to do with it.', 'contentsync' ) . "</p>
											<div class='inner_content' data-multioption='" . __( 'Multiselect', 'contentsync' ) . "' data-options='$options' data-unused='" . __( 'No conflicts', 'contentsync' ) . "' data-import='" . __( 'Already imported', 'contentsync' ) . "' data-success='" . __( 'Import successful', 'contentsync' ) . " ✅' data-fail='" . __( 'Import failed', 'contentsync' ) . " ❌'></div>
										</div>
									</form>",
					'button'  => __( 'import now', 'contentsync' ),
				),
				'loading'    => array(
					'content' => "<div class='import_bulk conflicts' style='margin-bottom: 20px;'><div class='inner_content' data-multioption='" . __( 'Multiselect', 'contentsync' ) . "' data-options='$options' data-unused='" . __( 'No conflicts', 'contentsync' ) . "' data-import='" . __( 'Already imported', 'contentsync' ) . "' data-success='" . __( 'Import successful', 'contentsync' ) . " ✅' data-fail='" . __( 'Import failed', 'contentsync' ) . " ❌'></div></div>",
				),
				'success'    => array(
					'title'   => __( 'Import successful.', 'contentsync' ),
					'content' => "<div class='import_bulk conflicts' style='margin-bottom: 20px;'><div class='inner_content' data-multioption='" . __( 'Multiselect', 'contentsync' ) . "' data-options='$options' data-unused='" . __( 'No conflicts', 'contentsync' ) . "' data-import='" . __( 'Already imported', 'contentsync' ) . "' data-success='" . __( 'Import successful', 'contentsync' ) . " ✅' data-fail='" . __( 'Import failed', 'contentsync' ) . " ❌'></div></div>",
				),
				'fail'       => array(
					'title'   => __( 'Import failed.', 'contentsync' ),
					'descr'   => '<strong class="replace">' . __( 'At least some posts failed to import, please check the log:', 'contentsync' ) . '</strong>',
					'content' => "<div class='import_bulk' style='margin-bottom: 20px;'></div>",
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
					'descr'  => sprintf( __( 'This post will be overwritten with the global content "%s".<br><br>The current post will be replaced with the synced post’s content, metadata, and taxonomy. The post will become a Linked Post (synced) and the editor will reload.', 'contentsync' ), '<strong class="replace"></strong>' ),
					'button' => __( 'Overwrite now', 'contentsync' ),
				),
				'loading' => array(
					'descr' => __( 'Overwriting post.', 'contentsync' ),
				),
				'reload'  => array(
					'title' => __( 'Successfully overwritten.', 'contentsync' ),
					'descr' => __( 'This post has been overwritten with the global content.', 'contentsync' ),
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
					'title'        => __( 'Permanently delete global content', 'contentsync' ),
					'descr'        => sprintf(
						__( 'The global content is permanently deleted on all pages. If you want to make the posts static instead, select %s.', 'contentsync' ),
						'<strong>' . __( 'Unlink', 'contentsync' ) . '</strong>'
					),
					'content'      => \Contentsync\Utils\make_admin_info_box(
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
					'title' => __( 'Successfully deleted global content.', 'contentsync' ),
					'descr' => __( 'The global content has been permanently deleted on all sites.', 'contentsync' ),
				),
				'fail'    => array(
					'title' => __( 'Deletion failed.', 'contentsync' ),
					'descr' => __( 'The global content could not be deleted on all sites.', 'contentsync' ),
				),
			),
			'contentsync_review_approve' => array(
				'confirm' => array(
					'title'   => __( 'Approve changes', 'contentsync' ),
					'descr'   => __( 'The current version of this post is going to be published on the following sites. Afterwards you can still revert the changes, if anything goes wrong.', 'contentsync' ),
					'content' => "<div class='inner_content'>" . $post_list . '</div>',
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
					'content' => "<div class='inner_content'>" . $reviewer_message_deny . '</div>',
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
					'content' => "<div class='inner_content'>" . $reviewer_message_revert . '</div>',
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
			'nested' => array(
				'name'    => 'append_nested',
				'title'   => __( 'Include nested content', 'contentsync' ),
				'descr'   => __( 'Templates, media, etc. are also made available globally, so that used images, backgrounds, etc. are displayed correctly on the target site.', 'contentsync' ),
				'checked' => true,
			),
			'menus'  => array(
				'name'  => 'resolve_menus',
				'title' => __( 'Resolve menus', 'contentsync' ),
				'descr' => __( 'All menus will be converted to static links.', 'contentsync' ),
			),
		);

		$post = get_post( $post_id_or_object );
		if ( $post ) {

			if ( $post->post_type === 'attachment' ) {
				unset( $contentsync_export_options['nested'], $contentsync_export_options['menus'] );
			}

			/**
			 * add option to include translations when translation tool is active
			 *
			 * @since 2.3.0 support wpml and polylang
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
