<?php
/**
 * Displays all queue items in a table.
 *
 * @extends WP_List_Table ( wp-admin/includes/class-wp-list-table.php )
 *
 * @since 2.17.0
 */

namespace Contentsync\Admin\Pages\List_Tables;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// include the parent class
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Queue_List_Table extends \WP_List_Table {

	/**
	 * Posts per page
	 *
	 * default is 20.
	 */
	public $posts_per_page = 20;

	public $all_destination_blogs = array();

	/**
	 * Class constructor
	 */
	public function __construct() {

		parent::__construct(
			array(
				'singular' => 'queue-table',
				'plural'   => 'queue-table',
				'ajax'     => false,
			)
		);

		$this->screen = get_current_screen();
	}

	/**
	 * Handles data query and filter, sorting, and pagination.
	 */
	function prepare_items() {

		// Process row actions
		if ( isset( $_GET['page'] ) && $_GET['page'] === 'contentsync_queue' ) {
			$this->process_row_action();
		}

		// process bulk action
		$this->process_bulk_action();

		// Define the columns and data for the list table
		$columns               = $this->get_columns();
		$hidden                = $this->get_hidden_columns();
		$sortable              = $this->get_sortable_columns();
		$this->_column_headers = array( $columns, $hidden, $sortable );

		/**
		 * Get the columns to select.
		 */
		$visible_columns = array_diff( array_keys( $columns ), $hidden );
		$sql_select      = array( 'ID', 'status' );
		if ( in_array( 'destination', $visible_columns ) || in_array( 'import_action', $visible_columns ) ) {
			$sql_select[] = 'destination';
		}
		if ( in_array( 'references', $visible_columns ) ) {
			$sql_select[] = 'posts';
			$sql_select[] = 'destination';
		}
		if ( in_array( 'time', $visible_columns ) ) {
			$sql_select[] = 'time';
		}
		if ( in_array( 'raw', $visible_columns ) ) {
			$sql_select = array( '*' );
		}
		$sql_select = implode( ', ', array_unique( $sql_select ) );

		// sort
		$orderby = isset( $_GET['orderby'] ) ? $_GET['orderby'] : 'ID';
		$order   = isset( $_GET['order'] ) ? strtoupper( $_GET['order'] ) : 'DESC';
		$view    = isset( $_GET['view'] ) && ! empty( $_GET['view'] ) ? esc_attr( $_GET['view'] ) : '';

		$items = \Contentsync\Distribution\get_distribution_items(
			array(
				'orderby' => $orderby,
				'order'   => $order,
				'status'  => $view,
			),
			$sql_select
		);

		// pagination
		$per_page     = $this->get_items_per_page( 'queue_per_page', $this->posts_per_page );
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

		$this->all_destination_blogs = \Contentsync\Connections\Connections_Helper::get_all_networks();
	}


	/**
	 * =================================================================
	 *                          RENDER
	 * =================================================================
	 */
	public function render() {

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline" style="margin-right: 8px">' . esc_html( get_admin_page_title() ) . '</h1>';

		if ( isset( $_GET['debug'] ) ) {
			printf(
				'<a href="%s" class="page-title-action">%s</a>',
				wp_nonce_url( network_admin_url( 'admin.php?page=contentsync_queue&delete_all_queues=1' ), 'contentsync_delete_all_queues' ),
				__( 'Delete All', 'contentsync' )
			);
		}
		echo '<hr class="wp-header-end">';

		$this->views();

		echo '<form id="posts-filter" method="post">';

		$this->display();

		echo '</form>';
		echo '</div>';
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

		$columns = array(
			'cb'            => '<input type="checkbox" />',
			'destination'   => __( 'Destination', 'contentsync' ),
			'import_action' => __( 'Action', 'contentsync' ),
			'references'    => __( 'Contents', 'contentsync' ),
			'status'        => __( 'Status', 'contentsync' ),
			'raw'           => __( 'Code', 'contentsync' ),
			'time'          => __( 'Time', 'contentsync' ),
		);

		return $columns;
	}

	public function get_sortable_columns() {
		return array(
			'status' => array( 'status', false ),
			'time'   => array( 'time', false ),
		);
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
		$view   = isset( $_GET['view'] ) && ! empty( $_GET['view'] ) ? esc_attr( $_GET['view'] ) : '';

		$views = array(
			''        => __( 'All', 'contentsync' ),
			'init'    => __( 'Scheduled', 'contentsync' ),
			'started' => __( 'Started', 'contentsync' ),
			'success' => __( 'Completed', 'contentsync' ),
			'failed'  => __( 'Failed', 'contentsync' ),
		);

		foreach ( $views as $type => $title ) {

			$items = \Contentsync\Distribution\get_distribution_items( array( 'status' => $type ), 'ID' );

			$return[ $type ] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%s)</span></a>',
				esc_attr( $type === 'all' ? remove_query_arg( array( 'view', 'paged' ) ) : add_query_arg( 'view', $type, remove_query_arg( array( 'paged' ) ) ) ),
				$view === $type ? 'current' : '',
				$title,
				is_countable( $items ) ? count( $items ) : 0
			);
		}

		return $return;
	}

	/**
	 * Display text when no items found
	 */
	public function no_items() {

		$text = __( 'No queues open.', 'contentsync' );

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
	 * @param Distribution_Item $item The current Distribution_Item object.
	 */
	public function column_destination( $item ) {

		$start_item_link = network_admin_url( 'admin.php?page=contentsync_queue&start_queue=' . $item->ID );
		$destination_url = '';
		$title           = '';
		$connection      = null;

		if ( is_a( $item->destination, 'Contentsync\Distribution\Destinations\Blog_Destination' ) ) {
			$title           = get_blog_option( $item->destination->ID, 'blogname' );
			$destination_url = get_blog_option( $item->destination->ID, 'siteurl' );
		} elseif ( is_a( $item->destination, 'Contentsync\Distribution\Destinations\Remote_Destination' ) ) {
			$connection = \Contentsync\Posts\Sync\get_site_connection( $item->destination->ID );
			if ( $connection ) {
				$title           = $connection['site_name'];
				$destination_url = $connection['site_url'];
			} else {
				$title           = $item->destination->ID;
				$destination_url = $item->destination->ID;
			}
		}

		$row_actions = array(
			'link'       => sprintf(
				'<a href="%s" aria-label="%s" target="_blank">%s</a>',
				$destination_url,
				esc_attr( __( 'Go to Site', 'contentsync' ) ),
				__( 'Go to Site', 'contentsync' )
			),
			'run_now'    => sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				wp_nonce_url( network_admin_url( 'admin.php?page=contentsync_queue&run_now=' . $item->ID ), 'contentsync_run_now' ),
				/* translators: %s: Post title. */
				esc_attr( sprintf( __( 'Run now &#8220;%s&#8221;' ), $title ) ),
				__( 'Run now', 'contentsync' )
			),
			'reschedule' => sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				wp_nonce_url( network_admin_url( 'admin.php?page=contentsync_queue&reschedule=' . $item->ID ), 'contentsync_reschedule' ),
				/* translators: %s: Post title. */
				esc_attr( sprintf( __( 'Reschedule &#8220;%s&#8221;' ), $title ) ),
				__( 'Reschedule', 'contentsync' )
			),
			'delete'     => sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				wp_nonce_url( network_admin_url( 'admin.php?page=contentsync_queue&delete_queue=' . $item->ID ), 'contentsync_delete_queue' ),
				/* translators: %s: Post title. */
				esc_attr( sprintf( __( 'Delete &#8220;%s&#8221;' ), $title ) ),
				__( 'Delete', 'contentsync' )
			),

		);

		$post_state = '';
		if ( isset( $connection ) ) {
			if ( isset( $item->destination->blogs ) && ! empty( $item->destination->blogs ) ) {
				$post_state = ' — <span class="post-state">' . __( 'Remote Network', 'contentsync' ) . '</span>';
			} else {
				$post_state = ' — <span class="post-state">' . __( 'Remote', 'contentsync' ) . '</span>';
			}
		}

		// render the post title
		$content = sprintf(
			'<strong><a class="row-title" href="%s">%s</a>%s</strong>',
			$destination_url,
			! empty( $title ) ? $title : __( 'Unknown', 'contentsync' ),
			$post_state
		);

		// row actions
		$content .= $this->row_actions( $row_actions );

		// display blogs
		if ( $connection && isset( $item->destination->blogs ) && ! empty( $item->destination->blogs ) ) {
			$content .= '<div class="remote-blogs-list">' . __( 'Sites:', 'contentsync' );
			$content .= '<ul>';
			foreach ( $item->destination->blogs as $blog ) {
				$blog_array = $this->get_remote_blog( $item->destination->ID, $blog->ID );
				$blog_url   = untrailingslashit( $blog_array['http'] . '://' . $blog_array['domain'] );
				$content   .= '<li><strong>' . $blog_array['name'] . '</strong> – <a href="' . $blog_url . '" target="_blank">' . $blog_array['domain'] . '</a></li>';
			}
			$content .= '</ul></div>';
		}

		return $content;
	}

	/**
	 * Handles the posts object column output.
	 */
	public function column_references( $item ) {
		$posts = isset( $item->posts ) ? $item->posts : array();

		if ( empty( $posts ) ) {
			echo '<span>' . __( 'No posts', 'contentsync' ) . '</span>';
			return;
		}

		echo '<ul style="margin:0">';
		foreach ( $posts as $post ) {

			$post_label      = $post->post_title;
			$post_url        = null;
			$status_icon     = null;
			$post_references = array();

			if ( isset( $item->destination->import_action ) && $item->destination->import_action === 'delete' ) {
				$post_label = '<s>' . $post_label . '</s>';
				if ( $item->status === 'success' ) {
					$status_icon = '<span style="color:rgb(235, 87, 87);white-space:nowrap"><span class="dashicons dashicons-yes"></span>' . __( 'Deleted', 'contentsync' ) . '</span>';
				}
			} elseif ( isset( $item->destination->blogs ) ) {
				foreach ( $item->destination->blogs as $blog ) {
					if ( isset( $blog->posts[ $post->ID ] ) ) {

						$post_destination = $blog->posts[ $post->ID ];
						$dashicon         = '';
						if ( $post_destination->status === 'failed' ) {
							$dashicon = 'dashicons-no-alt';
						} elseif ( $post_destination->status === 'success' ) {
							$dashicon = 'dashicons-yes';
						} else {
							$dashicon = 'dashicons-update';
						}

						$post_references[] = '<a href="' . $post_destination->url . '" target="_blank">' . $post_label . '</a><span class="dashicons ' . $dashicon . '" style="opacity:.65"></span>';
					}
				}
			} elseif ( isset( $item->destination->posts ) ) {
				if ( isset( $item->destination->posts[ $post->ID ] ) ) {

					$post_destination = $item->destination->posts[ $post->ID ];
					$post_url         = $post_destination->url;
					$dashicon         = '';

					if ( $post_destination->status === 'failed' ) {
						$dashicon = 'dashicons-no-alt';
					} elseif ( $post_destination->status === 'success' ) {
						$dashicon = 'dashicons-yes';
					} else {
						$dashicon = 'dashicons-update';
					}

					$status_icon = '<span class="dashicons ' . $dashicon . '" style="opacity:.65"></span>';
				}
			}

			if ( empty( $post_references ) ) {
				if ( isset( $post->import_action ) && $post->import_action === 'delete' ) {
					$post_label = '<s>' . $post_label . '</s>';
					if ( $item->status === 'success' ) {
						$dashicon    = 'dashicons-yes';
						$status_icon = '<span style="color:rgb(235, 87, 87);white-space:nowrap"><span class="dashicons ' . $dashicon . '"></span>' . __( 'Deleted', 'contentsync' ) . '</span>';
					}
				}
			} else {
				$post_label = implode( ', ', $post_references );
			}

			if ( ! empty( $post_url ) ) {
				$post_label = '<a href="' . $post_url . '" target="_blank">' . $post_label . '</a>';
			}

			echo '<li>' . $post_label . ' – ' . $post->post_type . '' . ( empty( $status_icon ) ? '' : '&nbsp;' . $status_icon ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * Render a column when no column specific method exist.
	 *
	 * @param array  $item
	 * @param string $column_name
	 *
	 * @return mixed
	 */
	public function column_default( $item, $column_name ) {

		switch ( $column_name ) {

			case 'status':
				echo \Contentsync\Admin\Utils\make_admin_icon_status_box( $item->status );

				if ( $item->status === 'failed' ) {

					$error = isset( $item->error ) && ! empty( $item->error ) ? $item->error : null;

					if ( ! $error ) {
						$error = isset( $item->destination->error ) && ! empty( $item->destination->error ) ? $item->destination->error : null;
					}

					if ( $error ) {
						$message = '';
						if ( is_wp_error( $error ) ) {
							$message = sprintf(
								__( 'Error message: %1$s (%2$s)', 'contentsync' ),
								'<i>' . $error->get_error_message() . '</i>',
								'<code>' . $error->get_error_code() . '</code>'
							);
						} elseif ( is_string( $error ) ) {
							$message = $error;
						}
						if ( ! empty( $message ) ) {
							echo '&nbsp;' . \Contentsync\Admin\Utils\make_admin_info_popup( $message );
						}
					}
				}

				break;

			case 'import_action':
				$color  = null;
				$text   = __( 'Unknown', 'contentsync' );
				$action = isset( $item->destination->import_action ) ? $item->destination->import_action : 'insert'; // 'insert|draft|trash|delete'
				if ( $action === 'insert' ) {
					$color = 'blue';
					$text  = __( 'Insert', 'contentsync' );
				} elseif ( $action === 'draft' ) {
					$color = 'yellow';
					$text  = __( 'Draft', 'contentsync' );
				} elseif ( $action === 'trash' ) {
					$color = 'red';
					$text  = __( 'Trash', 'contentsync' );
				} elseif ( $action === 'delete' ) {
					$color = 'red';
					$text  = __( 'Delete', 'contentsync' );
				}
				echo \Contentsync\Admin\Utils\make_admin_icon_status_box( $color, $text, false );
				break;

			case 'references':
				$items = $item->posts;
				echo '<ul style="margin:0">';
				foreach ( $items as $item ) {
					$edit_post_link = network_admin_url( 'admin.php?page=contentsync_queues&queue_id=' . $item->ID );
					$edit_post_link = $edit_post_link ? $edit_post_link : '#';
					echo '<li><a href="' . $edit_post_link . '">' . $item->post_title . '</a></li>';
				}
				echo '</ul>';
				break;

			case 'raw':
				$post_with_escaped_content = $item;
				if ( isset( $post_with_escaped_content->posts ) && is_array( $post_with_escaped_content->posts ) ) {
					foreach ( $post_with_escaped_content->posts as $idx => $post ) {
						$post_with_escaped_content->posts[ $idx ]->post_content = esc_html( $post->post_content );
					}
				}
				echo \Contentsync\Admin\Utils\make_admin_info_dialog( '<pre>' . print_r( $post_with_escaped_content, true ) . '</pre>' );
				break;

			case 'time':
				echo date_i18n( __( 'M j, Y @ H:i' ), strtotime( $item->time ) );
				break;

			default:
				if ( isset( $item->$column_name ) ) {
					debug( $item->$column_name );
				}
				break;
		}
	}

	/**
	 * Process row actions
	 */
	public function process_row_action() {
		// Run now action
		if ( isset( $_GET['run_now'] ) && check_admin_referer( 'contentsync_run_now' ) ) {
			$item_id = intval( $_GET['run_now'] );
			$result  = \Contentsync\Distribution\distribute_item( $item_id );
			if ( $result !== false ) {
				\Contentsync\Admin\Utils\render_admin_notice( __( 'Distribution started successfully.', 'contentsync' ), 'success' );
			} else {
				\Contentsync\Admin\Utils\render_admin_notice( __( 'Failed to start distribution.', 'contentsync' ), 'error' );
			}
		}

		// Reschedule action
		if ( isset( $_GET['reschedule'] ) && check_admin_referer( 'contentsync_reschedule' ) ) {
			$item_id = intval( $_GET['reschedule'] );
			$result  = \Contentsync\Distribution\schedule_distribution_item_by_id( $item_id );
			if ( ! is_wp_error( $result ) ) {
				\Contentsync\Admin\Utils\render_admin_notice( __( 'Distribution rescheduled successfully.', 'contentsync' ), 'success' );
			} else {
				\Contentsync\Admin\Utils\render_admin_notice( sprintf( __( 'Failed to reschedule distribution: %s', 'contentsync' ), $result->get_error_message() ), 'error' );
			}
		}

		// Delete action
		if ( isset( $_GET['delete_queue'] ) && check_admin_referer( 'contentsync_delete_queue' ) ) {
			$item_id = intval( $_GET['delete_queue'] );
			$item    = \Contentsync\Distribution\get_distribution_item( $item_id );
			if ( $item && $item->delete() ) {
				\Contentsync\Admin\Utils\render_admin_notice( __( 'Distribution deleted successfully.', 'contentsync' ), 'success' );
			} else {
				\Contentsync\Admin\Utils\render_admin_notice( __( 'Failed to delete distribution.', 'contentsync' ), 'error' );
			}
		}

		// Delete all queues action
		if ( isset( $_GET['delete_all_queues'] ) && check_admin_referer( 'contentsync_delete_all_queues' ) ) {
			$all_items     = \Contentsync\Distribution\get_distribution_items( array(), 'ID' );
			$deleted_count = 0;
			$failed_count  = 0;

			foreach ( $all_items as $item ) {
				if ( $item->delete() ) {
					++$deleted_count;
				} else {
					++$failed_count;
				}
			}

			// Set message as transient
			if ( $failed_count === 0 ) {
				$message      = sprintf( __( 'Successfully deleted %d queue items.', 'contentsync' ), $deleted_count );
				$message_type = 'success';
			} else {
				$message      = sprintf( __( 'Deleted %1$d queue items. Failed to delete %2$d items.', 'contentsync' ), $deleted_count, $failed_count );
				$message_type = 'warning';
			}
			\Contentsync\Admin\Utils\render_admin_notice( $message, $message_type );

		}
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
			'run_now'    => __( 'Run now', 'contentsync' ),
			'reschedule' => __( 'Reschedule', 'contentsync' ),
			'delete'     => __( 'Delete', 'contentsync' ),
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

		Logger::add( 'bulk_action', $bulk_action );

		// get the data
		$item_ids = isset( $_POST['post'] ) ? $_POST['post'] : array();

		// normally the values are set as an array of inputs (checkboxes)
		if ( is_array( $item_ids ) ) {
			$item_ids = esc_sql( $item_ids );
		}
		// but if the user confirmed the action (eg. on 'delete'),
		// the values are set as an encoded string
		elseif ( is_string( $item_ids ) ) {
			$item_ids = esc_sql( json_decode( stripslashes( $item_ids ), true ) );
		}

		Logger::add( 'item_ids', $item_ids );

		// vars used to display the admin notice
		$result         = false;
		$item_titles    = array();
		$failed_items   = array();
		$notice_content = ''; // additional content rendered inside the admin notice
		$notice_class   = 'success'; // success admin notice type

		// perform the action...
		switch ( $bulk_action ) {
			case 'run_now':
				foreach ( $item_ids as $item_id ) {
					$item = \Contentsync\Distribution\get_distribution_item( $item_id );
					if ( $item ) {
						$result = \Contentsync\Distribution\distribute_item( $item_id );
						if ( $result !== false ) {
							$item_titles[] = $item_id;
						} else {
							$failed_items[] = array(
								'id'    => $item->destination->ID,
								'error' => __( 'Failed to run distribution', 'contentsync' ),
							);
						}
					}
				}
				break;

			case 'reschedule':
				foreach ( $item_ids as $item_id ) {
					$item = \Contentsync\Distribution\get_distribution_item( $item_id );
					if ( $item ) {
						$result = \Contentsync\Distribution\schedule_distribution_item_by_id( $item_id );
						if ( ! is_wp_error( $result ) ) {
							$item_titles[] = $item_id;
						} else {
							$failed_items[] = array(
								'id'    => $item->destination->ID,
								'error' => $result->get_error_message(),
							);
						}
					}
				}
				break;

			case 'delete':
				foreach ( $item_ids as $item_id ) {
					$item = \Contentsync\Distribution\get_distribution_item( $item_id );
					if ( $item ) {
						$result = $item->delete();
						if ( $result ) {
							$item_titles[] = $item_id;
						} else {
							$failed_items[] = array(
								'id'    => $item->destination->ID,
								'error' => __( 'Failed to delete item', 'contentsync' ),
							);
						}
					}
				}
				break;

			default:
				break;
		}

		// set the admin notice content
		$notices = array(
			'run_now'    => array(
				'success' => __( 'The distribution of the assets %s has been successfully started.', 'contentsync' ),
				'fail'    => __( 'There were errors when starting the distribution of the following assets:', 'contentsync' ),
			),
			'reschedule' => array(
				'success' => __( 'The distribution of %s has been successfully rescheduled.', 'contentsync' ),
				'fail'    => __( 'There were errors when rescheduling the distribution:', 'contentsync' ),
			),
			'delete'     => array(
				'success' => __( 'The distribution order for %s has been successfully deleted.', 'contentsync' ),
				'fail'    => __( 'There were errors when deleting the distribution:', 'contentsync' ),
			),
		);

		// display the admin notice
		if ( ! empty( $item_titles ) ) {
			if ( count( $item_titles ) > 1 ) {
				$last        = array_pop( $item_titles );
				$item_titles = implode( ', ', $item_titles ) . ' & ' . $last;
			} else {
				$item_titles = implode( ', ', $item_titles );
			}
			$content = sprintf( $notices[ $bulk_action ]['success'], "<strong>$item_titles</strong>" );
		}

		// Add failed items to the notice if any
		if ( ! empty( $failed_items ) ) {
			$notice_class = 'error';
			$content      = $notices[ $bulk_action ]['fail'];
			$content     .= '<ul style="margin-left: 20px;">';
			foreach ( $failed_items as $failed ) {
				$content .= sprintf(
					'<li>%s: %s</li>',
					'<strong>' . esc_html( $failed['id'] ) . '</strong>',
					esc_html( $failed['error'] )
				);
			}
			$content .= '</ul>';
		}

		// display the admin notice
		\Contentsync\Admin\Utils\render_admin_notice( $content . $notice_content, $notice_class );
	}

	public function get_remote_blog( $connection_slug, $blog_id ) {

		if ( isset( $this->all_destination_blogs['remote'] ) && isset( $this->all_destination_blogs['remote'][ $connection_slug ] ) ) {

			$network_blogs = $this->all_destination_blogs['remote'][ $connection_slug ];

			foreach ( $network_blogs as $blog ) {
				if ( $blog['blog_id'] == $blog_id ) {
					return $blog;
				}
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
