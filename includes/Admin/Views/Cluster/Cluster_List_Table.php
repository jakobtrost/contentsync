<?php
/**
 * Displays all clusters in a table.
 *
 * @extends WP_List_Table ( wp-admin/includes/class-wp-list-table.php )
 */

namespace Contentsync\Admin\Views\Cluster;

use Contentsync\Admin\Utils\Admin_Posts;
use Contentsync\Admin\Utils\Admin_Render;
use Contentsync\Cluster\Cluster_Service;
use Contentsync\Api\Site_Connection;

defined( 'ABSPATH' ) || exit;

class Cluster_List_Table extends \WP_List_Table {

	/**
	 * All destinations
	 */
	public $all_destination_blogs = array();

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
				'singular' => 'cluster-table',
				'plural'   => 'cluster-table',
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
		$items                 = Cluster_Service::get_clusters();

		// sort
		$orderby  = isset( $_GET['orderby'] ) ? $_GET['orderby'] : 'post_date';
		$order    = isset( $_GET['order'] ) ? $_GET['order'] : 'desc';
		$callback = method_exists( __CLASS__, 'sortby_' . $orderby . '_' . $order ) ? array( __CLASS__, 'sortby_' . $orderby . '_' . $order ) : array( __CLASS__, 'sortby_date_desc' );
		usort( $items, $callback );

		// pagination
		$per_page     = $this->get_items_per_page( 'cluster_per_page', $this->posts_per_page );
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

		$this->all_destination_blogs = Site_Connection::get_all_local_and_remote_blogs();
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

	/**
	 * =================================================================
	 *                          RENDER
	 * =================================================================
	 */
	public function render() {
		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline" style="margin-right: 8px">' . esc_html( get_admin_page_title() ) . '</h1>';
		// echo sprintf(
		// '<a href="%s" class="page-title-action">%s</a>',
		// admin_url( 'site-editor.php' ),
		// __( 'Site Editor', 'contentsync' )
		// );
		echo '<hr class="wp-header-end">';

		echo '<p>' . __( 'Here you can manage all synced posts clusters.', 'contentsync' ) . '</p>';

		echo '<form id="posts-filter" method="post">';

		$this->display();

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Generates the table navigation above or below the table
	 *
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
			'cb'                 => '<input type="checkbox" />',
			'title'              => __( 'Title', 'contentsync' ),
			'destination_ids'    => __( 'Destinations', 'contentsync' ),
			'content_conditions' => __( 'Contents', 'contentsync' ),
			'reviews'            => __( 'Post Reviews', 'contentsync' ),
		);
	}

	public function get_sortable_columns() {
		return array(
			'title' => array( 'title', false ),
		);
	}

	/**
	 * Display text when no items found
	 */
	public function no_items() {

		$text = __( 'No clusters found.', 'contentsync' );

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
	 * @param WP_Post $post The current WP_Post object.
	 */
	public function column_title( $post ) {

		$edit_post_link = network_admin_url( 'admin.php?page=contentsync_clusters&cluster_id=' . $post->ID );
		// $trash_post_link = Admin_Posts::get_delete_post_link( $post );
		// $delete_post_link = wp_nonce_url( admin_url( 'admin-post.php?action=contentsync_delete_cluster&cluster_id=' . $post->ID ), 'contentsync_delete_cluster' )

		$row_actions = array(
			'edit'   => sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				$edit_post_link,
				/* translators: %s: Post title. */
				esc_attr( sprintf( __( 'Edit &#8220;%s&#8221;' ), $post->title ) ),
				__( 'Edit', 'contentsync' )
			),
			'delete' => sprintf(
				'<a data-cluster-id="%s" aria-label="%s">%s</a>',
				$post->ID,
				/* translators: %s: Post title. */
				esc_attr( sprintf( __( 'Move &#8220;%s&#8221; to the Trash' ), $post->title ) ),
				__( 'Delete', 'contentsync' )
			),
		);

		$error       = '';
		$post_status = '';

		// render the post title
		if ( empty( $error ) && ! empty( $edit_post_link ) ) {
			$content = sprintf(
				'<strong><a class="row-title" href="%s">%s</a>%s</strong>',
				$edit_post_link,
				$post->title,
				$post_status
			);
		} else {
			$content = sprintf(
				'<strong><span class="row-title">%s</span>%s&nbsp;%s</strong>',
				$post->title,
				$post_status,
				Admin_Render::make_admin_tooltip_popup( $error, 'right' )
			);
		}

		// row actions
		$content .= $this->row_actions( $row_actions );

		return $content;
	}

	/**
	 * Handles the destinations column output.
	 *
	 * @param WP_Post $post The current WP_Post object.
	 */
	public function column_destination_ids( $post ) {

		$destinations         = $post->destination_ids;
		$display_destinations = array();

		foreach ( $destinations as $destination_id ) {

			if ( empty( $destination_id ) ) {
				break;
			}

			$destination_label = null;
			$destination_url   = null;
			$destination_slug  = null;

			$blog = $this->get_blog_by_destination_id( $destination_id );

			if ( $blog ) {
				$destination_label = $blog['name'];
				$destination_slug  = $blog['domain'];
				$destination_url   = untrailingslashit( $blog['http'] . '://' . $blog['domain'] );
			} else {
				$destination_label = get_blogaddress_by_id( $destination_id );
			}

			if ( ! empty( $destination_label ) ) {
				$display_destinations[] = (
					empty( $destination_url )
					? $destination_label
					: "<a href='{$destination_url}' target='_blank'>{$destination_label}</a>" . (
						! empty( $destination_slug )
						? ' – ' . $destination_slug
						: ''
					)
				);
			}
		}

		return $display_destinations ? implode( '<br>', $display_destinations ) : '—';
	}

	/**
	 * Handles the reviewers column output.
	 *
	 * @param WP_Post $post The current WP_Post object.
	 */
	public function column_reviews( $post ) {

		if ( ! $post->enable_reviews ) {
			return Admin_Render::make_admin_icon_status_box( 'info', __( 'No reviews', 'contentsync' ), false );
		}

		foreach ( $post->reviewer_ids as $reviewer_id ) {
			$reviewer = get_user_by( 'ID', $reviewer_id );
			if ( $reviewer ) {
				$display_reviewers[] = $reviewer->display_name;
			}
		}

		return Admin_Render::make_admin_icon_status_box( 'info', __( 'Reviews active', 'contentsync' ), false );
	}

	/**
	 * Handles the content_conditions column output.
	 *
	 * @todo: Implement this.
	 *
	 * @param WP_Post $post The current WP_Post object.
	 */
	public function column_content_conditions( $post ) {

		$contents = array();

		foreach ( $post->content_conditions as $condition ) {

			// debug( $condition );

			// post type
			$post_type = $condition->post_type;
			$post_type = str_replace(
				array(
					'tp_',
					'wp_',
					'-',
					'_',
				),
				array(
					'',
					'WP ',
					' ',
					' ',
				),
				$post_type
			);

			/* translators: %s is the post type */
			$content_description = sprintf( __( '%s(s)', 'contentsync' ), '<strong>' . ucwords( $post_type ) . '</strong>' );

			// source blog
			$blog = $this->get_blog_by_destination_id( $condition->blog_id );
			if ( $blog ) {
				$destination_label = $blog['name'];
				$destination_slug  = $blog['domain'];
				$destination_url   = untrailingslashit( $blog['http'] . '://' . $blog['domain'] );
				$source            = "<a href='{$destination_url}' target='_blank'>{$destination_label}</a>";
			} else {
				$source = get_blogaddress_by_id( $destination_id );
			}

			/* translators: %s is the source blog name & url */
			$content_description .= ' ' . sprintf( __( 'from %s', 'contentsync' ), $source );

			// terms
			if ( ! empty( $condition->terms ) && ! empty( $condition->taxonomy ) ) {

				/* translators: %1$s is the terms, %2$s is the taxonomy */
				$content_description .= ' ' . sprintf( __( 'assigned to %1$s (%2$s)', 'contentsync' ), '<u>' . $condition->terms . '</u>', $condition->taxonomy );
			}

			// filter
			$has_filter = false;
			if ( ! empty( $condition->filter ) ) {
				foreach ( $condition->filter as $filter ) {

					if ( isset( $filter['count'] ) && ! empty( $filter['count'] ) ) {
						$content_description = $filter['count'] . ' ' . $content_description;
						$has_filter          = true;
					}

					if ( isset( $filter['date_mode'] ) ) {
						/*
						[date_mode] => static_range
						[date_value] => 2025-02-01
						[date_value_from] => 2025-02-01
						[date_value_to] => 2025-02-28
						[date_since] => day|week|month|year
						[date_since_value] => 3
						*/
						if ( $filter['date_mode'] == 'static_range' ) {
							if ( isset( $filter['date_value_from'] ) && isset( $filter['date_value_to'] ) ) {

								/* translators: %1$s is the start date, %2$s is the end date, eg. 2025-02-01 to 2025-02-28 */
								$content_description .= ' ' . sprintf( __( 'from %1$s to %2$s', 'contentsync' ), $filter['date_value_from'], $filter['date_value_to'] );
								$has_filter           = true;
							}
						} elseif ( $filter['date_mode'] == 'static' ) {
							if ( isset( $filter['date_value'] ) ) {

								/* translators: %s is the date, eg. 2025-02-01 */
								$content_description .= ' ' . sprintf( __( 'since %s', 'contentsync' ), $filter['date_value'] );
								$has_filter           = true;
							}
						} elseif ( $filter['date_mode'] == 'dynamic' ) {
							if ( isset( $filter['date_since'] ) && isset( $filter['date_since_value'] ) ) {

								/* translators: %1$s is the number, %2$s is the time unit, eg. 42 days or 1 week */
								$content_description .= ' ' . sprintf( __( 'from the last %1$s %2$s(s)', 'contentsync' ), $filter['date_since_value'], $filter['date_since'] );
								$has_filter           = true;
							}
						}
					}
				}
			}

			// make_posts_global_automatically
					// debug( $condition );
			if ( $condition->make_posts_global_automatically ) {
				if ( $has_filter ) {
					$content_description .= ', ' . __( 'automatically', 'contentsync' );
				} else {
					$content_description = __( 'All', 'contentsync' ) . ' ' . $content_description;
				}
			} elseif ( $has_filter ) {
					$content_description .= ', ' . __( 'manually', 'contentsync' );
			} else {
				$content_description = __( 'All global', 'contentsync' ) . ' ' . $content_description;
			}

			$contents[] = $content_description;
		}

		return implode( '<br>', $contents );
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
			'trash' => __( 'Move to Trash', 'contentsync' ),
		);
		return $actions;
	}

	/**
	 * Process the bulk actions
	 * Called via prepare_items()
	 */
	public function process_bulk_action() {

		// verify the nonce
		if ( ! isset( $_REQUEST['_wpnonce'] ) ||
			! wp_verify_nonce( $_REQUEST['_wpnonce'], 'bulk-' . $this->_args['plural'] )
		) {
			return false;
		}

		// get the action
		$bulk_action = isset( $_POST['action'] ) ? $_POST['action'] : ( isset( $_POST['action'] ) ? $_POST['action2'] : null );
		if ( empty( $bulk_action ) ) {
			return;
		}

		// get the data
		$post_ids = isset( $_POST['post'] ) ? $_POST['post'] : array();

		// normally the values are set as an array of inputs (checkboxes)
		if ( is_array( $post_ids ) ) {
			$post_ids = esc_sql( $post_ids );
		}
		// but if the user confirmed the action (eg. on 'delete'),
		// the values are set as an encoded string
		elseif ( is_string( $post_ids ) ) {
			$post_ids = esc_sql( json_decode( stripslashes( $post_ids ), true ) );
		}

		// vars used to display the admin notice
		$result         = false;
		$post_titles    = array();
		$notice_content = ''; // additional content rendered inside the admin notice
		$notice_class   = 'success'; // success admin notice type

		// perform the action...
		switch ( $bulk_action ) {
			case 'trash':
				foreach ( $post_ids as $post_id ) {
					$post = get_post( $post_id );
					if ( $post ) {
						$post_title = $post->post_title;
						$result     = (bool) wp_trash_post( $post_id );
						if ( $result ) {
							$post_titles[] = $post_title;
						}
					}
				}

				break;

			default:
				break;
		}

		// set the admin notice content
		$notices = array(
			'edit'  => array(
				'success' => __( 'The posts %s have been successfully edited.', 'contentsync' ),
				'fail'    => __( 'There were errors when editing the posts.', 'contentsync' ),
			),
			'trash' => array(
				'success' => __( 'The posts %s have been successfully moved to the trash.', 'contentsync' ),
				'fail'    => __( 'Errors occurred when moving posts to the trash.', 'contentsync' ),
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
			$content      = $notices[ $bulk_action ]['fail'];
			if ( is_string( $result ) && ! empty( $result ) ) {
				$content .= ' ' . __( 'Error message:', 'contentsync' ) . ' ' . $result;
			}
		}

		// display the admin notice
		Admin_Render::render_admin_notice( $content . $notice_content, $notice_class );
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

	public function get_blog_by_destination_id( $destination_id ) {
		if ( strpos( $destination_id, '|' ) === false ) {
			return $this->get_local_blog( $destination_id );
		}

		list( $blog_id, $connection_slug ) = explode( '|', $destination_id );

		if ( isset( $this->all_destination_blogs['remote'] ) && isset( $this->all_destination_blogs['remote'][ $connection_slug ] ) ) {

			$network_blogs = $this->all_destination_blogs['remote'][ $connection_slug ];

			foreach ( $network_blogs as $blog ) {
				if ( $blog['blog_id'] == $blog_id ) {
					return $blog;
				}
			}
		}
	}

	public function get_local_blog( $blog_id ) {
		foreach ( $this->all_destination_blogs['local'] as $blog ) {
			if ( $blog['blog_id'] == $blog_id ) {
				return $blog;
			}
		}
		return null;
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
}
