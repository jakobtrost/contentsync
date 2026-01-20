<?php
/**
 * Displays all cluster post reviews in a table.
 *
 * @extends WP_List_Table ( wp-admin/includes/class-wp-list-table.php )
 *
 * @since 2.17.0
 */

namespace Contentsync\Cluster;

use Contentsync\Utils\Multisite_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// include the parent class
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Post_Review_List_Table extends \WP_List_Table {

	/**
	 * Posts per page
	 *
	 * default is 20.
	 */
	public $posts_per_page = 20;

	/**
	 * Class constructor
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'post-reviews-table',
				'plural'   => 'post-reviews-table',
				'ajax'     => false,
			)
		);

		$this->screen = get_current_screen();
	}

	/**
	 * Handles data query and filter, sorting, and pagination.
	 */
	function prepare_items() {

		// process bulk action
		$this->process_bulk_action();

		// Define the columns and data for the list table
		$columns               = $this->get_columns();
		$hidden                = $this->get_hidden_columns();
		$sortable              = $this->get_sortable_columns();
		$this->_column_headers = array( $columns, $hidden, $sortable );

		$rel   = isset( $_GET['rel'] ) && ! empty( $_GET['rel'] ) ? esc_attr( $_GET['rel'] ) : 'open';
		$items = get_post_reviews( $rel == 'open' ? null : $rel );
		// debug($items);

		// sort
		$orderby  = isset( $_GET['orderby'] ) ? $_GET['orderby'] : 'post_date';
		$order    = isset( $_GET['order'] ) ? $_GET['order'] : 'desc';
		$callback = method_exists( __CLASS__, 'sortby_' . $orderby . '_' . $order ) ? array( __CLASS__, 'sortby_' . $orderby . '_' . $order ) : array( __CLASS__, 'sortby_date_desc' );
		usort( $items, $callback );

		// pagination
		$per_page     = $this->get_items_per_page( 'post_reviews_per_page', $this->posts_per_page );
		$current_page = $this->get_pagenum();
		$total_items  = count( $items );
		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);

		// set items
		$this->items = array_slice( $items, ( $current_page - 1 ) * $per_page, $per_page );
	}


	/**
	 * =================================================================
	 *                          RENDER
	 * =================================================================
	 */
	public function render() {
		echo '<div class="wrap">';
		echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';
		// echo sprintf(
		// '<a href="%s" class="page-title-action">%s</a>',
		// admin_url( 'site-editor.php' ),
		// __( 'Site Editor', 'contentsync' )
		// );
		echo '<hr class="wp-header-end">';

		echo '<p>' . __( 'Here you can manage all pending post reviews.', 'contentsync' ) . '</p>';

		$this->views();

		echo '<form id="posts-filter" method="post">';

		$this->display();

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Gets the list of views available on this table.
	 *
	 * The format is an associative array:
	 * - `'id' => 'link'`
	 *
	 * @since 3.1.0
	 *
	 * @return array
	 */
	protected function get_views() {

		$views  = array();
		$return = array();
		$rel    = isset( $_GET['rel'] ) && ! empty( $_GET['rel'] ) ? esc_attr( $_GET['rel'] ) : 'open';

		$views = array(
			'open'     => __( 'Open', 'contentsync' ),
			// 'new' => __( "New", 'contentsync' ),
			// 'in_review' => __( "In Review", 'contentsync' ),
			// 'denied' => __( "Denied", 'contentsync' ),
			'approved' => __( 'Approved', 'contentsync' ),
			'reverted' => __( 'Reverted', 'contentsync' ),
		);

		foreach ( $views as $type => $title ) {
			if ( $type == 'open' ) {
				$items_count = 0;
				foreach ( array( 'new', 'in_review', 'denied' ) as $state ) {
					$items_count += count( get_post_reviews( $state ) );
				}
			} else {
				$items_count = count( get_post_reviews( $type ) );
			}
			$query = remove_query_arg( array( 'rel', 'paged', 'action', 'post', '_wpnonce' ) );

			$return[ $type ] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%s)</span></a>',
				esc_attr( $type === 'open' ? $query : add_query_arg( 'rel', $type, $query ) ),
				$rel === $type ? 'current' : '',
				$title,
				$items_count
			);
		}

		return $return;
	}

	/**
	 * Generates the table navigation above or below the table
	 *
	 * @since 3.1.0
	 * @param string $which
	 */
	protected function display_tablenav( $which ) {
		if ( 'top' === $which ) {
			wp_nonce_field( 'bulk-' . $this->_args['plural'] );
			// $this->search_box( __( 'Search' ), 'search-box-id' );
		}
		?>
		<div class="tablenav <?php echo esc_attr( $which ); ?>">

			<?php if ( $this->has_items() ) : ?>
			<div class="alignleft actions bulkactions">
				<?php $this->bulk_actions( $which ); ?>
			</div>
				<?php
			endif;
			$this->extra_tablenav( $which );
			$this->pagination( $which );
			?>

			<br class="clear" />
		</div>
		<?php
	}

	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'post_title' => __( 'Post Title', 'contentsync' ),
			'state'      => __( 'State', 'contentsync' ),
			'editor'     => __( 'Editor', 'contentsync' ),
			'date'       => __( 'Date', 'contentsync' ),
			'reviewer'   => __( 'Reviewer', 'contentsync' ),
			'root_stage' => __( 'Root Stage', 'contentsync' ),
			// 'destinations'   => __( 'Destinations', 'contentsync' ),
		);
	}

	public function get_sortable_columns() {
		return array(
			'post_title' => array( 'post_title', false ),
			'editor'     => array( 'editor', false ),
			'state'      => array( 'state', false ),
			'date'       => array( 'date', false ),
			'reviewer'   => array( 'reviewer', false ),
		);
	}

	/**
	 * Display text when no items found
	 */
	public function no_items() {

		$text = __( 'No reviews active.', 'contentsync' );

		echo '<div style="margin: 4px 0;">' . $text . '</div>';
	}


	/**
	 * =================================================================
	 *                          COLUMNS
	 * =================================================================
	 */

	/**
	 * Handles the post title & actions column output.
	 *
	 * @param \Contentsync\Reviews\Post_Review $post The current \Contentsync\Reviews\Post_Review object.
	 */
	public function column_post_title( $post ) {

		$root_post = is_multisite() ? get_blog_post( $post->blog_id, $post->post_id ) : get_post( $post->post_id );
		if ( ! $root_post ) {
			$root_post = $post->previous_post;
		}
		$title       = $root_post ? $root_post->post_title : __( 'N/A', 'contentsync' );
		$edit_link   = $this->get_root_post_edit_link( $post->blog_id, $post->post_id );
		$action_link = function ( $action, $post ) {
			return sprintf(
				'?page=%s&action=%s&post=%s&_wpnonce=%s',
				$_REQUEST['page'],
				$action,
				$post->ID,
				wp_create_nonce( $action . '_review' )
			);
		};

		// debug($root_post);
		// debug($post);

		$row_actions = array();

		if ( ! empty( $edit_link ) ) {
			$row_actions['edit'] = sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				$edit_link,
				/* translators: %s: Post title. */
				esc_attr( sprintf( __( 'Edit &#8220;%s&#8221;' ), $title ) ),
				__( 'Edit on root page', 'contentsync' )
			);
		}

		if ( $post->state != 'approved' && $post->state != 'denied' && $post->state != 'reverted' ) {
			$row_actions['approve-review'] = sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				$action_link( 'approve', $post ),
				/* translators: %s: Post title. */
				esc_attr( sprintf( __( 'Approve &#8220;%s&#8221;' ), $title ) ),
				__( 'Approve', 'contentsync' )
			);
		}

		// deny
		if ( $post->state != 'denied' && $post->state != 'approved' && $post->state != 'reverted' ) {
			$row_actions['deny-review'] = sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				$action_link( 'deny', $post ),
				esc_attr( sprintf( __( 'Deny &#8220;%s&#8221;' ), $title ) ),
				__( 'Deny', 'contentsync' )
			);
		}

		if ( $post->state == 'approved' || $post->state == 'denied' ) {
			$row_actions['revert-review'] = sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				$action_link( 'revert', $post ),
				/* translators: %s: Post title. */
				esc_attr( sprintf( __( 'Revert &#8220;%s&#8221;' ), $title ) ),
				__( 'Revert', 'contentsync' )
			);
		}

		$row_actions['delete'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			$action_link( 'delete', $post ),
			/* translators: %s: Post title. */
			esc_attr( sprintf( __( 'Delete &#8220;%s&#8221;' ), $title ) ),
			__( 'Delete', 'contentsync' )
		);

		// render the post title
		if ( ! empty( $edit_link ) && $root_post->post_status != 'trash' ) {
			$content = sprintf(
				'<strong><a class="row-title" href="%s">%s</a></strong>',
				$edit_link,
				$title,
			);
		} else {
			$msg = '';
			if ( $root_post->post_status == 'trash' ) {
				$msg = __( 'Post is trashed', 'contentsync' );
			} else {
				$msg = __( 'Post is deleted', 'contentsync' );
			}
			$content = sprintf(
				'<strong><span class="row-title">%s</span> %s</strong>',
				$title,
				\Contentsync\Admin\make_admin_info_popup( $msg, 'right' )
			);
		}

		// row actions
		$content .= $this->row_actions( $row_actions );

		return $content;
	}

	/**
	 * Handles the editor column output.
	 *
	 * @param \Contentsync\Reviews\Post_Review $post The current \Contentsync\Reviews\Post_Review object.
	 */
	public function column_editor( $post ) {
		return $post->get_editor();
	}

	/**
	 * Handles the reviewer column output.
	 *
	 * @param \Contentsync\Reviews\Post_Review $post The current \Contentsync\Reviews\Post_Review object.
	 */
	public function column_reviewer( $post ) {
		if ( in_array( $post->state, array( 'approved', 'denied', 'reverted' ) ) ) {
			$reviewer         = 'N/A';
			$info             = ' ';
			$reviewer_message = get_latest_message_by_post_review_id( $post->ID );
			if ( $reviewer_message && $reviewer_message->action === $post->state ) {
				$reviewer = $reviewer_message->get_reviewer();
				if ( $post->state != 'approved' ) {
					$reviewer_message_content = $reviewer_message->get_content( true );
					if ( empty( $reviewer_message_content ) ) {
						$info .= \Contentsync\Admin\make_admin_info_popup(
							"<div class='log_title'>" . sprintf( __( 'The reviewer (%s) left no message.', 'contentsync' ), $reviewer ) . '</div>'
						);
					} else {
						$info .= \Contentsync\Admin\make_admin_info_popup(
							"<div class='log_title'>" . sprintf( __( 'The reviewer (%s) left the following message:', 'contentsync' ), $reviewer ) . '</div>' .
							"<div class='log_items'><b>" . $reviewer_message_content . '</b></div>'
						);
					}
				}
			}
			return $reviewer . $info;
		}
		return '';
	}

	/**
	 * Handles the post date column output.
	 *
	 * @param \Contentsync\Reviews\Post_Review $post The current \Contentsync\Reviews\Post_Review object.
	 */
	public function column_date( $post ) {

		$date = sprintf(
			/* translators: 1: Post date, 2: Post time. */
			__( '%1$s at %2$s' ),
			/* translators: Post date format. See https://www.php.net/manual/datetime.format.php */
			date( __( 'Y/m/d' ), strtotime( $post->date ) ),
			/* translators: Post time format. See https://www.php.net/manual/datetime.format.php */
			date( __( 'g:i a' ), strtotime( $post->date ) )
		);

		return $date;
	}

	/**
	 * Handles the state column output.
	 *
	 * @param \Contentsync\Reviews\Post_Review $post The current \Contentsync\Reviews\Post_Review object.
	 */
	public function column_state( $post ) {
		// return $post->state;

		$color  = 'blue';
		$action = $post->state; // new, in_review, denied, approved, reverted
		if ( $action === 'new' ) {
			$color = 'blue';
			$text  = __( 'New', 'contentsync' );
		} elseif ( $action === 'in_review' ) {
			$text = __( 'In Review', 'contentsync' );
		} elseif ( $action === 'denied' ) {
			$color = 'red';
			$text  = __( 'Denied', 'contentsync' );
		} elseif ( $action === 'approved' ) {
			$color = 'green';
			$text  = __( 'Approved', 'contentsync' );
		} elseif ( $action === 'reverted' ) {
			$color = 'red';
			$text  = __( 'Reverted', 'contentsync' );
		}
		return \Contentsync\Admin\make_admin_icon_status_box( $color, $text, false );
	}

	/**
	 * Handles the root_stage column output.
	 *
	 * @param \Contentsync\Reviews\Post_Review $post The current \Contentsync\Reviews\Post_Review object.
	 */
	public function column_root_stage( $post ) {
		return $this->get_root_blog_url( $post->blog_id );
	}

	/**
	 * Handles the destinations column output.
	 *
	 * @param \Contentsync\Reviews\Post_Review $post The current \Contentsync\Reviews\Post_Review object.
	 */
	public function column_destinations( $post ) {
		return '';
	}


	/**
	 * =================================================================
	 *                          BULK ACTIONS
	 * =================================================================
	 */
	public function column_cb( $item ) {
		return sprintf(
			'<input type="checkbox" name="post[]" value="%s" />',
			$item->ID
		);
	}

	/**
	 * Returns an associative array containing the bulk action
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		$actions = array(
			'approve' => __( 'Approve', 'contentsync' ),
			'deny'    => __( 'Deny', 'contentsync' ),
			'revert'  => __( 'Revert', 'contentsync' ),
			'delete'  => __( 'Delete', 'contentsync' ),
		);
		return $actions;
	}

	/**
	 * Process the bulk actions
	 * Called via prepare_items()
	 */
	public function process_bulk_action() {

		// verify the nonce and action
		if ( ! isset( $_REQUEST['_wpnonce'] ) ||
			! isset( $_REQUEST['action'] ) || empty( $_REQUEST['action'] ) ||
			! in_array( $_REQUEST['action'], array( 'approve', 'deny', 'revert', 'delete' ) ) ||
			! (
				wp_verify_nonce( $_REQUEST['_wpnonce'], 'bulk-' . $this->_args['plural'] ) ||
				( wp_verify_nonce( $_REQUEST['_wpnonce'], 'approve_review' ) && $_REQUEST['action'] == 'approve' ) ||
				( wp_verify_nonce( $_REQUEST['_wpnonce'], 'revert_review' ) && $_REQUEST['action'] == 'revert' ) ||
				( wp_verify_nonce( $_REQUEST['_wpnonce'], 'delete_review' ) && $_REQUEST['action'] == 'delete' )
			)
		) {
			// debug($_REQUEST);
			return false;
		}

		// get the action
		$bulk_action = $_REQUEST['action'];

		// get the data
		$post_ids = isset( $_REQUEST['post'] ) ? $_REQUEST['post'] : array();

		// normally the values are set as an array of inputs (checkboxes)
		if ( is_array( $post_ids ) ) {
			$post_ids = esc_sql( $post_ids );
		}
		// but if the user confirmed the action (eg. on 'delete'),
		// the values are set as an encoded string
		elseif ( is_string( $post_ids ) ) {
			$post_ids = esc_sql( json_decode( stripslashes( $post_ids ), true ) );
		}

		// single action
		if ( ! is_array( $post_ids ) && intval( $post_ids ) == $post_ids ) {
			// debug($post_ids);
			$post_ids = array( $post_ids );
		}

		// vars used to display the admin notice
		$result         = false;
		$post_titles    = array();
		$notice_content = ''; // additional content rendered inside the admin notice
		$notice_class   = 'success'; // success admin notice type

		if ( count( $post_ids ) > 0 ) {
			foreach ( $post_ids as $post_id ) {
				$post = get_post_review_by_id( $post_id );
				if ( $post ) {
					// get root post
					$root_post = is_multisite() ? get_blog_post( $post->blog_id, $post->post_id ) : get_post( $post->post_id );
					if ( ! $root_post ) {
						$root_post = $post->previous_post;
					}
					// perform the action...
					$result = false;
					switch ( $bulk_action ) {
						case 'approve':
							Multisite_Manager::switch_blog( $post->blog_id );
							$result = (bool) \Contentsync\approve_post_review( $post_id );
							Multisite_Manager::restore_blog();
							break;
						case 'deny':
							Multisite_Manager::switch_blog( $post->blog_id );
							$result = (bool) \Contentsync\deny_post_review( $post_id );
							Multisite_Manager::restore_blog();
							break;
						case 'revert':
							Multisite_Manager::switch_blog( $post->blog_id );
							$result = (bool) \Contentsync\revert_post_review( $post_id, $root_post->ID );
							Multisite_Manager::restore_blog();
							break;
						case 'delete':
							$result = (bool) delete_post_review( $post_id );
							break;
						default:
							break;
					}
					// log
					if ( $result ) {
						$post_title    = $root_post ? $root_post->post_title : __( 'N/A', 'contentsync' );
						$post_titles[] = $post_title;
					}
				}
			}
		}

		// set the admin notice content
		$notices = array(
			'approve' => array(
				'success'  => count( $post_ids ) == 1 ? __( 'The post review %s has been approved.', 'contentsync' ) : __( 'The post reviews %s have been approved.', 'contentsync' ),
				'fail'     => count( $post_ids ) == 1 ? __( 'There was an error approving the post review.', 'contentsync' ) : __( 'There were errors when approving the post reviews.', 'contentsync' ),
				'no_posts' => __( 'There were no post reviews to approve.', 'contentsync' ),
			),
			'revert'  => array(
				'success'  => count( $post_ids ) == 1 ? __( 'The post review %s has been reverted.', 'contentsync' ) : __( 'The post reviews %s have been reverted.', 'contentsync' ),
				'fail'     => count( $post_ids ) == 1 ? __( 'There was an error reverting the post review.', 'contentsync' ) : __( 'There were errors when reverting the post reviews.', 'contentsync' ),
				'no_posts' => __( 'There were no post reviews to revert.', 'contentsync' ),
			),
			'delete'  => array(
				'success'  => count( $post_ids ) == 1 ? __( 'The post review %s has been successfully deleted.', 'contentsync' ) : __( 'The post reviews %s have been successfully deleted.', 'contentsync' ),
				'fail'     => count( $post_ids ) == 1 ? __( 'There was an error deleting the post review.', 'contentsync' ) : __( 'There were errors when deleting the post reviews.', 'contentsync' ),
				'no_posts' => __( 'There were no post reviews to delete.', 'contentsync' ),
			),
		);

		// display the admin notice
		if ( $result === true ) {
			if ( count( $post_titles ) > 1 ) {
				$last        = array_pop( $post_titles );
				$post_titles = implode( ', ', $post_titles ) . ' & ' . $last;
			} else {
				$post_titles = implode( ', ', $post_titles );
			}
			$content = sprintf( $notices[ $bulk_action ]['success'], "<strong>$post_titles</strong>" );
		} else {
			$notice_class = 'error';
			$error        = count( $post_ids ) == 0 ? 'no_posts' : 'fail';
			$content      = $notices[ $bulk_action ][ $error ];
			if ( is_string( $result ) && ! empty( $result ) ) {
				$content .= ' ' . __( 'Error message:', 'contentsync' ) . ' ' . $result;
			}
		}

		// clean url when single action is performed
		if ( isset( $_GET['_wpnonce'] ) ) {
			$notice_content .= "<script>    
				if ( typeof window.history.pushState == 'function' ) {
					window.history.pushState({}, 'Hide', '" . $_SERVER['HTTP_REFERER'] . "');
				}
			</script>";
		}

		// display the admin notice
		$this->render_admin_notice( $content . $notice_content, $notice_class );
	}

	/**
	 * =================================================================
	 *                          HELPER FUNCTIONS
	 * =================================================================
	 */

	/**
	 * Get the edit link for the root post.
	 *
	 * @param int $blog_id The blog id.
	 * @param int $post_id The post id.
	 */
	public function get_root_post_edit_link( $blog_id, $post_id ) {
		Multisite_Manager::switch_blog( $blog_id );
		$edit_post_link = \Contentsync\get_edit_post_link( $post_id );
		Multisite_Manager::restore_blog();
		return $edit_post_link;
	}

	/**
	 * Get the blog URL of the root post.
	 *
	 * @param int $blog_id The blog id.
	 */
	public function get_root_blog_url( $blog_id ) {
		Multisite_Manager::switch_blog( $blog_id );
		$root_post_url = get_bloginfo( 'url' );
		Multisite_Manager::restore_blog();
		return $root_post_url;
	}

	/**
	 * Show WordPress style notice in top of page.
	 *
	 * @param string $msg   The message to show.
	 * @param string $mode  Style of the notice (error, warning, success, info).
	 * @param bool   $list    Add to hub msg list (default: false).
	 */
	public static function render_admin_notice( $msg, $mode = 'info', $list = false ) {
		if ( empty( $msg ) ) {
			return;
		}
		if ( $list ) {
			echo "<p class='hub_msg msg_list {$mode}'>{$msg}</p>";
		} else {
			echo "<div class='notice notice-{$mode} is-dismissible'><p>{$msg}</p></div>";
		}
	}


	/**
	 * =================================================================
	 *                          SORT CALLBACKS
	 * =================================================================
	 */
	public function sort_alphabet( $a, $b ) {
		return strcasecmp( $b, $a );
	}
	// post_date
	public function sortby_date_asc( $a, $b ) {
		return strtotime( $a->post_date ) - strtotime( $b->post_date );
	}
	public function sortby_date_desc( $a, $b ) {
		return -1 * $this->sortby_post_date_asc( $a, $b );
	}
	// post_title
	public function sortby_title_asc( $a, $b ) {
		return $this->sort_alphabet( $a->post_title, $b->post_title );
	}
	public function sortby_title_desc( $a, $b ) {
		return -1 * $this->sortby_post_title_asc( $a, $b );
	}
	// author
	public function sortby_author_asc( $a, $b ) {
		// compare author IDs numerically
		$a_author_id = $a->post_author;
		$b_author_id = $b->post_author;
		if ( $a_author_id != $b_author_id ) {
			return $a_author_id - $b_author_id;
		}
	}
	public function sortby_author_desc( $a, $b ) {
		return -1 * $this->sortby_author_asc( $a, $b );
	}

	/**
	 * Get the list of hidden columns.
	 *
	 * @return array
	 */
	public function get_hidden_columns() {
		$hidden = get_user_option( 'manage' . $this->screen->id . 'columnshidden' );
		if ( ! $hidden ) {
			$hidden = array();
		}
		return $hidden;
	}
}
