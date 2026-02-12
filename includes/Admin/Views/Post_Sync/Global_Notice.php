<?php
/**
 * Global Notice for Synced Posts
 *
 * Builds notice content (text, type, icon, actions) from a post ID.
 * Used in both the Block/Site Editor (REST API) and Classic Editor contexts.
 *
 * @package Contentsync
 * @subpackage Admin\Views\Post_Sync
 */

namespace Contentsync\Admin\Views\Post_Sync;

use Contentsync\Cluster\Cluster_Service;
use Contentsync\Post_Sync\Post_Connection_Map;
use Contentsync\Post_Sync\Post_Error_Handler;
use Contentsync\Post_Sync\Synced_Post_Service;
use Contentsync\Reviews\Post_Review_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Global Notice Class
 *
 * Constructs notice properties from a post ID. Exposes output methods for
 * editor (REST/JS) and classic editor (PHP HTML) contexts.
 */
class Global_Notice {

	/**
	 * Post ID.
	 *
	 * @var int
	 */
	public $post_id;

	/**
	 * Global synced post ID (gid).
	 *
	 * @var string|null
	 */
	public $gid;

	/**
	 * Status of the synced post.
	 *
	 * @var string
	 */
	public $status;

	/**
	 * Error of the synced post.
	 *
	 * @var false|object False if no error found, error object if found.
	 */
	public $error = false;

	/**
	 * True when no notice should be shown.
	 *
	 * @var bool
	 */
	public $is_empty = true;

	/**
	 * HTML content of the notice.
	 *
	 * @var string
	 */
	public $text = '';

	/**
	 * Notice type
	 *
	 * @var string
	 *     @value 'info'    Info notice (maps to 'success' for editor)
	 *     @value 'error'   Error notice.
	 *     @value 'purple'  Purple notice.
	 */
	public $notice_type = 'info';

	/**
	 * Dashicon class.
	 *
	 * @var string
	 */
	public $icon = 'lock';

	/**
	 * List of actions.
	 *
	 * @var array List of actions.
	 *     @property string label     Label of the action.
	 *     @property string url       URL of the action.
	 *     @property string onClick   OnClick of the action.
	 *     @property string variant   Variant of the action.
	 *     @property string className Class name of the action.
	 */
	public $actions = array();

	/**
	 * Constructor. Builds notice properties from post ID.
	 *
	 * @param int $post_id Post ID.
	 */
	public function __construct( $post_id ) {

		if ( empty( $post_id ) ) {
			return;
		}

		$this->post_id = (int) $post_id;
		$this->gid     = get_post_meta( $this->post_id, 'synced_post_id', true );

		if ( empty( $this->gid ) ) {
			return;
		}

		$this->status = get_post_meta( $this->post_id, 'synced_post_status', true );
		if ( empty( $this->status ) ) {
			return;
		}

		$this->build_notice();
	}

	/**
	 * Get notice as array for createNotice(type, message, options).
	 * Used by Editor REST endpoint and block editor JS.
	 *
	 * @return array Empty array when no notice; otherwise @example:
	 *     array(
	 *         'success contentsync_components_notice',
	 *         '<p>This post is synced from the site <strong>https://example.com</strong></p>',
	 *         array(
	 *             'className' => 'contentsync-notice__element',
	 *             'isDismissible' => false,
	 *             '__unstableHTML' => true,
	 *             'icon' => 'lock',
	 *             'actions' => array(
	 *                 array(
	 *                     'label' => 'Edit the original post',
	 *                     'url' => 'https://example.com/edit',
	 *                     'variant' => 'primary',
	 *                     'className' => 'contentsync-notice__action',
	 *                 ),
	 *             ),
	 *         ),
	 */
	public function get_editor_notice_array() {
		if ( $this->is_empty || empty( $this->text ) ) {
			return array();
		}

		$type = ( $this->notice_type === 'info' ? 'success' : $this->notice_type ) . ' contentsync_components_notice';

		$actions = array_map(
			function ( $button ) {
				return array(
					'label'     => isset( $button['label'] ) ? $button['label'] : '',
					'url'       => isset( $button['url'] ) ? $button['url'] : null,
					'onClick'   => isset( $button['onClick'] ) ? $button['onClick'] : null,
					'variant'   => isset( $button['variant'] ) ? $button['variant'] : '',
					'className' => isset( $button['className'] ) ? $button['className'] . ' contentsync-notice__action' : 'contentsync-notice__action',
				);
			},
			$this->actions
		);

		return array(
			$type,
			$this->text,
			array(
				'className'      => 'contentsync-notice__element',
				'isDismissible'  => false,
				'__unstableHTML' => true,
				'icon'           => $this->icon,
				'actions'        => $actions,
			),
		);
	}

	/**
	 * Get ready-made HTML for classic editor admin notice.
	 *
	 * @return string Empty string when no notice; otherwise full HTML.
	 */
	public function get_classic_editor_html() {
		if ( $this->is_empty || empty( $this->text ) ) {
			return '';
		}

		$html = '<div class="notice notice-' . $this->notice_type . ' synced_post_notice">' .
			'<div>' .
			'<div><span class="dashicons dashicons-' . $this->icon . '"></span></div>' .
			'<div>' .
			'<p>' . $this->text . '</p>' .
			'</div>';

		if ( ! empty( $this->actions ) ) {
			$html .= '<div class="actions">';
			foreach ( $this->actions as $button ) {
				if ( isset( $button['url'] ) ) {
					$html .= '<a class="button ' . ( isset( $button['className'] ) ? $button['className'] : '' ) . '" href="' . $button['url'] . '">' . $button['label'] . '</a>';
				} elseif ( isset( $button['onClick'] ) ) {
					$html .= '<span class="button ' . ( isset( $button['className'] ) ? $button['className'] : '' ) . '" onclick="' . $button['onClick'] . '">' . $button['label'] . '</span>';
				}
			}
			$html .= '</div>';
		}

		$html .= '</div></div>';

		return $html;
	}

	/**
	 * =================================================================
	 *                          PRIVATE METHODS
	 * =================================================================
	 */

	/**
	 * Build all notice properties. Delegates to focused methods.
	 *
	 * @return void
	 */
	private function build_notice() {

		$this->error = Post_Error_Handler::get_post_error( $this->post_id );

		if ( $this->error ) {
			if ( ! Post_Error_Handler::is_error_repaired( $this->error ) ) {
				$this->build_error_notice( $this->error );
				return;
			}
		}

		if ( $this->status === 'linked' ) {
			$this->build_linked_notice();
		} elseif ( $this->status === 'root' ) {
			$this->build_root_notice();
		}
	}

	/**
	 * Set error state notice (post not repaired).
	 *
	 * @return void
	 */
	private function build_error_notice() {
		$this->is_empty    = false;
		$this->notice_type = 'error';
		$this->icon        = 'warning color_red';
		$this->text        = Post_Error_Handler::get_error_message( $this->error );

		if ( Synced_Post_Service::current_user_can_edit_synced_posts() ) {
			$this->actions = array(
				array(
					'label'   => __( 'Repair', 'contentsync' ),
					'variant' => 'primary',
					/** todo: to be replaced with contentSync.repairPost.openModal */
					'onClick' => 'contentSync.repairPost(this, ' . esc_attr( $this->post_id ) . ')',
				),
			);
		}
	}

	/**
	 * Set linked post notice.
	 *
	 * @return void
	 */
	private function build_linked_notice() {
		$post_links = Post_Connection_Map::get_links_by_gid( $this->gid );

		$this->is_empty    = false;
		$this->notice_type = 'info';
		$this->icon        = 'lock';
		$this->text        = sprintf(
			__( 'This post is synced from the site %s', 'contentsync' ),
			'<strong>' . $post_links['nice'] . '</strong>'
		);

		if ( Synced_Post_Service::current_user_can_edit_synced_posts( 'linked' ) ) {

			$post_data = array(
				'id'     => $this->post_id,
				'title'  => get_the_title( $this->post_id ),
				'gid'    => $this->gid,
				'status' => $this->status,
			);

			$this->actions = array(
				array(
					'label'   => __( 'Edit the original post', 'contentsync' ),
					'variant' => 'primary',
					'url'     => $post_links['edit'],
				),
				array(
					'label'     => __( 'Convert to local post', 'contentsync' ),
					'variant'   => 'tertiary',
					'className' => 'button-ghost',
					'onClick'   => 'contentSync.unlinkLinkedPost.openModal( ' . json_encode( $post_data ) . ' )',
				),
			);
		}
	}

	/**
	 * Set root post notice (global source post).
	 *
	 * @return void
	 */
	private function build_root_notice() {
		$connection_map = Post_Connection_Map::get( $this->post_id );
		$clusters       = Cluster_Service::get_clusters_including_post( $this->post_id );
		$review         = Post_Review_Service::get_post_review_by_post( $this->post_id, get_current_blog_id() );

		$review_status = $this->get_review_status( $connection_map, $clusters, $review );
		$status        = get_post_meta( $this->post_id, 'synced_post_status', true );

		$post_count_text  = $this->get_post_count_text( $connection_map, $clusters );
		$status_pill      = $this->get_status_pill_markup( $review_status, $clusters );
		$status_text      = $this->get_status_text_for_root( $review_status, $post_count_text, $clusters );
		$reviewer_message = $this->get_denied_reviewer_message( $review, $review_status );

		if ( $review_status === 'denied' ) {
			$status_text .= $reviewer_message . __( 'By saving the post, reviewers will be notified again and the changes will be reviewed again.', 'contentsync' );
		}

		$this->is_empty    = false;
		$this->notice_type = 'purple';
		$this->icon        = 'admin-site-alt';
		$this->text        = '<p class="heading"><strong>' . __( 'Global Source Post', 'contentsync' ) . '</strong>' . $status_pill . '</p>';
		$this->text       .= '<p>' . $status_text . '</p>';

		if ( ! Synced_Post_Service::current_user_can_edit_synced_posts( $status ) ) {
			$this->text .= '<p>' . __( 'You are not allowed to edit this synced post.', 'contentsync' ) . '</p>';
		}

		$this->actions = $this->get_reviewer_actions( $review, $review_status );
	}

	/**
	 * Resolve review status: new, live, in_review, or denied.
	 *
	 * @param array       $connection_map Post connection map.
	 * @param array       $clusters       Clusters including this post.
	 * @param object|null $review         Post review object.
	 * @return string
	 */
	private function get_review_status( $connection_map, $clusters, $review ) {
		$default = ( empty( $clusters ) && empty( $connection_map ) ) ? 'new' : 'live';
		return ( $review && $review->state ) ? $review->state : $default;
	}

	/**
	 * Get post/cluster count text for display.
	 *
	 * @param array $connection_map Post connection map.
	 * @param array $clusters       Clusters including this post.
	 * @return string
	 */
	private function get_post_count_text( $connection_map, $clusters ) {
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
			return sprintf(
				_n(
					'1 linked post',
					'%s linked posts',
					$count,
					'contentsync'
				),
				$count
			);
		}

		if ( ! empty( $clusters ) ) {
			return sprintf(
				_n(
					'1 active cluster',
					'%s active clusters',
					count( $clusters ),
					'contentsync'
				),
				count( $clusters )
			);
		}

		return __( 'No linked posts yet', 'contentsync' );
	}

	/**
	 * Get status pill HTML (e.g. New, Live, In Review).
	 *
	 * @param string $review_status Review status.
	 * @param array  $clusters      Clusters including this post.
	 * @return string
	 */
	private function get_status_pill_markup( $review_status, $clusters ) {
		if ( $review_status === 'new' ) {
			$class = ' status_pill_neutral';
			$title = empty( $clusters ) ? __( 'New', 'contentsync' ) : __( 'Not published', 'contentsync' );
		} elseif ( $review_status === 'in_review' ) {
			$class = ' status_pill_neutral';
			$title = __( 'In Review', 'contentsync' );
		} elseif ( $review_status === 'denied' ) {
			$class = ' status_pill_error';
			$title = __( 'Not approved', 'contentsync' );
		} else {
			$class = ' status_pill_success';
			$title = __( 'Live', 'contentsync' );
		}

		return '<span class="status_pill' . $class . '">' . $title . '</span>';
	}

	/**
	 * Get the main status text for root post based on review status and user role.
	 *
	 * @param string $review_status   Review status.
	 * @param string $post_count_text Post count text.
	 * @param array  $clusters       Clusters including this post.
	 * @return string
	 */
	private function get_status_text_for_root( $review_status, $post_count_text, $clusters ) {
		$current_user = $this->is_current_user_reviewer() ? 'reviewer' : 'editor';

		if ( $review_status === 'new' ) {
			if ( empty( $clusters ) ) {
				return __( 'This post has not been published to other sites yet.', 'contentsync' );
			}
			if ( $current_user === 'reviewer' ) {
				return __( 'Changes to this post have not been published to other sites. Please review the changes to this post.', 'contentsync' );
			}
			return __( 'Changes to this post have not been published to other sites. Reviewers will be notified automatically after you saved this post. You will be notified, as soon as the changes are approved or denied.', 'contentsync' );
		}

		if ( $review_status === 'live' ) {
			if ( empty( $clusters ) ) {
				return sprintf(
					__( 'Changes to this post will be published on this site and <strong>will be distributed</strong> to other sites (%s).', 'contentsync' ),
					$post_count_text
				);
			}

			$cluster_has_reviews = false;
			foreach ( $clusters as $cluster ) {
				if ( $cluster->enable_reviews ) {
					$cluster_has_reviews = true;
					break;
				}
			}

			if ( $cluster_has_reviews ) {
				return sprintf(
					__( 'Changes to this post will only be published on this site and <strong>will be reviewed before being published</strong> on other sites (%s). Reviewers will be notified automatically after you saved this post. You will be notified, as soon as the changes are approved or denied.', 'contentsync' ),
					$post_count_text
				);
			}

			return sprintf(
				__( 'Changes to this post will be published on this site and <strong>will immediately be distributed</strong> to other sites (%s).', 'contentsync' ),
				$post_count_text
			);
		}

		if ( $review_status === 'in_review' ) {
			if ( $current_user === 'reviewer' ) {
				return sprintf(
					__( 'Changes to this post have not been published to connected sites (%s). Please review the changes to this post.', 'contentsync' ),
					$post_count_text
				);
			}
			return sprintf(
				__( 'Changes to this post have not been published to connected sites (%s), because they have not been approved yet. If you save this post, all changes will be added to the reviewed version.', 'contentsync' ),
				$post_count_text
			);
		}

		if ( $review_status === 'denied' ) {
			return sprintf(
				__( 'Changes to this post cannot be published to connected sites (%s). You need to make changes, before it can be reviewed again.', 'contentsync' ),
				$post_count_text
			);
		}

		return sprintf(
			__( 'Each saved change is automatically applied to all linked posts (%s).', 'contentsync' ),
			$post_count_text
		);
	}

	/**
	 * Get reviewer message markup for denied status.
	 *
	 * @param object|null $review        Post review object.
	 * @param string      $review_status Review status.
	 * @return string
	 */
	private function get_denied_reviewer_message( $review, $review_status ) {
		if ( $review_status !== 'denied' || ! $review || ! $review->ID ) {
			return '';
		}

		$reviewer_message = Post_Review_Service::get_latest_message_by_post_review_id( $review->ID );
		if ( ! $reviewer_message || empty( $reviewer_message->content ) || $reviewer_message->action !== 'denied' ) {
			return '<br>';
		}

		$reviewer        = $reviewer_message->get_reviewer();
		$message_content = $reviewer_message->get_content( true );
		return '<br>' . sprintf( __( 'The reviewer (%s) left the following message:', 'contentsync' ), $reviewer ) . '<br><em>' . $message_content . '</em>';
	}

	/**
	 * Check if current user is a reviewer (super admin with manage_network).
	 *
	 * @return bool
	 */
	private function is_current_user_reviewer() {
		return in_array( wp_get_current_user()->user_login, get_super_admins(), true )
			&& current_user_can( 'manage_network' );
	}

	/**
	 * Get reviewer action actions (approve, deny, revert) when applicable.
	 *
	 * @param object|null $review        Post review object.
	 * @param string      $review_status Review status.
	 * @return array
	 */
	private function get_reviewer_actions( $review, $review_status ) {
		if (
			! $review
			|| ! $review->ID
			|| ! $this->is_current_user_reviewer()
			|| ! in_array( $review_status, array( 'in_review', 'new' ), true )
		) {
			return array();
		}

		return array(
			array(
				'label'   => __( 'Approve changes', 'contentsync' ),
				'variant' => 'primary',
				/** todo: to be replaced with contentSync.reviewApprove.openModal */
				'onClick' => 'contentSync.openReviewApprove(this, ' . esc_attr( $this->post_id ) . ', ' . esc_attr( $review->ID ) . ')',
			),
			array(
				'label'   => __( 'Request modification', 'contentsync' ),
				/** todo: to be replaced with contentSync.reviewDeny.openModal */
				'onClick' => 'contentSync.openReviewDeny(this, ' . esc_attr( $this->post_id ) . ', ' . esc_attr( $review->ID ) . ')',
			),
			array(
				'label'   => __( 'Revert changes', 'contentsync' ),
				/** todo: to be replaced with contentSync.reviewRevert.openModal */
				'onClick' => 'contentSync.openReviewRevert(this, ' . esc_attr( $this->post_id ) . ', ' . esc_attr( $review->ID ) . ')',
			),
		);
	}
}
