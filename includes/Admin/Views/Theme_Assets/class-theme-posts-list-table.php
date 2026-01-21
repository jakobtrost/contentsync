<?php

/**
 * Displays all synced posts as default WP admin list table
 *
 * @extends WP_List_Table ( wp-admin/includes/class-wp-list-table.php )
 */

namespace Contentsync\Admin\Pages\List_Tables;

use Contentsync\Admin\Utils\Admin_Posts;
use Contentsync\Admin\Utils\Admin_Render;
use Contentsync\Posts\Theme_Assets;
use Contentsync\Utils\Urls;

defined( 'ABSPATH' ) || exit;

// include the parent class
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Theme_Assets_List_Table extends \WP_List_Table {

	/**
	 * Posts per page
	 *
	 * default is 20.
	 */
	public $posts_per_page = 20;

	/**
	 * Displayed post type.
	 */
	private $post_type;

	/**
	 * Class constructor
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'theme-post-table',
				'plural'   => 'theme-post-table',
				'ajax'     => false,
			)
		);

		// remove the first char '_' from argument
		$posttype = isset( $_GET['post_type'] ) ? substr( $_GET['post_type'], 1 ) : '';

		if ( in_array( $posttype, Theme_Assets::get_theme_post_types() ) ) {
			$this->post_type = $posttype;
		} else {
			$this->post_type = Theme_Assets::get_theme_post_types();
		}
	}

	/**
	 * Handles data query and filter, sorting, and pagination.
	 */
	function prepare_items() {

		// process bulk action
		$this->process_bulk_action();

		// Define the columns and data for the list table
		$columns               = $this->get_columns();
		$hidden                = array();
		$sortable              = $this->get_sortable_columns();
		$this->_column_headers = array( $columns, $hidden, $sortable );

		// get items
		$args = array(
			'post_type' => $this->post_type,
		);
		if ( isset( $_REQUEST['s'] ) ) {
			$args['s'] = esc_attr( $_REQUEST['s'] );
		}
		// Only show trashed items if Trash tab is active
		if ( isset( $_GET['post_status'] ) && $_GET['post_status'] === 'trash' ) {
			$args['post_status'] = 'trash';
		} else {
			$args['post_status'] = array( 'publish', 'future', 'draft', 'pending', 'private' );
		}

		// Filter by current theme if current theme view is active
		if ( isset( $_GET['view'] ) && ( $_GET['view'] === 'current_theme' || $_GET['view'] === 'inactive' ) ) {

			$current_post_type = (array) $this->post_type;
			if (
				in_array( 'wp_template', $current_post_type )
				|| in_array( 'wp_template_part', $current_post_type )
				|| in_array( 'wp_global_styles', $current_post_type )
			) {
				$current_theme     = get_option( 'stylesheet' );
				$args['tax_query'] = array(
					array(
						'taxonomy' => 'wp_theme',
						'field'    => 'slug',
						'terms'    => $current_theme,
						'operator' => ( $_GET['view'] === 'inactive' ? 'NOT IN' : 'IN' ),
					),
				);
				$args['post_type'] = array_filter(
					$current_post_type,
					function ( $post_type ) {
						return $post_type == 'wp_template' || $post_type == 'wp_template_part' || $post_type == 'wp_global_styles';
					}
				);
			}
		}

		$items = Theme_Assets::get_theme_posts( $args );

		// sort
		$orderby  = isset( $_GET['orderby'] ) ? $_GET['orderby'] : 'post_date';
		$order    = isset( $_GET['order'] ) ? $_GET['order'] : 'desc';
		$callback = method_exists( __CLASS__, 'sortby_' . $orderby . '_' . $order ) ? array( __CLASS__, 'sortby_' . $orderby . '_' . $order ) : array( __CLASS__, 'sortby_date_desc' );
		usort( $items, $callback );

		// pagination
		$per_page     = $this->get_items_per_page( 'theme_posts_per_page', $this->posts_per_page );
		$current_page = $this->get_pagenum();
		$total_items  = count( $items );
		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);

		// debug( $items );

		// debug( \Contentsync\Admin\Export\Post_Export_Manager::handle_post_export_bulk_action( 'localhost', 'contentsync_export', array_map( function( $item ) {
		// return $item->ID;
		// }, $items ) ) );

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
		echo '<h1 class="wp-heading-inline">' . esc_html( get_admin_page_title() ) . '</h1>';
		printf(
			'<a href="%s" class="page-title-action">%s</a>',
			admin_url( 'site-editor.php' ),
			__( 'Site Editor', 'contentsync_hub' )
		);
		echo '<hr class="wp-header-end">';

		echo '<p>' . __( 'Here you can manage all the customized content of your theme. This content is often not directly visible, but is used by your theme. For example, you have the option of exporting templates, styles, fonts, blocks and navigations and importing them into another theme.', 'contentsync_hub' ) . '</p>';

		$this->tabs();
		$this->views();

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
		return apply_filters(
			'contentsync_theme_manage_assets_columns',
			array(
				'cb'        => '<input type="checkbox" />',
				'title'     => __( 'Post Title', 'contentsync_hub' ),
				'post_name' => __( 'Slug', 'contentsync_hub' ),
				'post_type' => __( 'Post Type', 'contentsync_hub' ),
				'author'    => __( 'Author', 'contentsync_hub' ),
				'date'      => __( 'Date', 'contentsync_hub' ),
			)
		);
	}

	public function get_sortable_columns() {
		return array(
			'title'     => array( 'title', false ),
			'post_name' => array( 'post_name', false ),
			'post_type' => array( 'post_type', false ),
			'author'    => array( 'author', false ),
			'date'      => array( 'date', false ),
		);
	}

	/**
	 * Render the tabs
	 */
	public function tabs() {

		$current   = is_string( $this->post_type ) ? $this->post_type : '';
		$trashed   = isset( $_GET['post_status'] ) && $_GET['post_status'] === 'trash';
		$all_url   = remove_query_arg( array( 'post_type', 'post_status', 'paged' ) );
		$trash_url = add_query_arg( array( 'post_status' => 'trash' ), $all_url );

		echo "<div class='contentsync_tabs'>";
		printf(
			"<a href='%s' class='tab %s'>%s</a>",
			$all_url,
			( ! $trashed && empty( $current ) ) ? 'active' : '',
			__( 'All', 'contentsync_hub' )
		);
		foreach ( Theme_Assets::get_theme_post_types() as $post_type ) {
			$post_type_obj = get_post_type_object( $post_type );
			printf(
				"<a href='%s' class='tab blue %s'>%s</a>",
				add_query_arg(
					array( 'post_type' => '_' . $post_type ),
					$all_url
				),
				( ! $trashed && $current == $post_type ) ? 'active' : '',
				$post_type_obj ? $post_type_obj->labels->singular_name : $post_type
			);
		}
		echo '</div>';
	}

	/**
	 * Renders WP-style views (status filters) above the table.
	 */
	protected function get_views() {
		$trashed   = ( isset( $_GET['post_status'] ) && $_GET['post_status'] === 'trash' );
		$all_url   = remove_query_arg( array( 'post_status', 'paged', 'view' ) );
		$trash_url = add_query_arg( array( 'post_status' => 'trash' ), $all_url );

		$current_theme_view = ( isset( $_GET['view'] ) && $_GET['view'] === 'current_theme' );
		$current_theme_url  = add_query_arg( array( 'view' => 'current_theme' ), $all_url );

		$inactive_view = ( isset( $_GET['view'] ) && $_GET['view'] === 'inactive' );
		$inactive_url  = add_query_arg( array( 'view' => 'inactive' ), $all_url );

		// Determine which post types are currently in scope for counting
		$scoped_post_types = is_string( $this->post_type ) ? array( $this->post_type ) : Theme_Assets::get_theme_post_types();
		$theme_post_types  = array_filter(
			$scoped_post_types,
			function ( $post_type ) {
				return $post_type == 'wp_template' || $post_type == 'wp_template_part' || $post_type == 'wp_global_styles';
			}
		);

		$all_count           = 0;
		$trash_count         = 0;
		$current_theme_count = 0;
		$inactive_count      = 0;
		$status_keys         = array( 'publish', 'future', 'draft', 'pending', 'private' );

		foreach ( $scoped_post_types as $type ) {
			$counts = wp_count_posts( $type );
			if ( ! $counts ) {
				continue;
			}
			$trash_count += isset( $counts->trash ) ? (int) $counts->trash : 0;
			foreach ( $status_keys as $key ) {
				$all_count += isset( $counts->{$key} ) ? (int) $counts->{$key} : 0;
			}
		}

		// Count current theme posts
		$current_theme       = get_option( 'stylesheet' );
		$current_theme_args  = array(
			// only wp-template & wp-template-part
			'post_type'      => $theme_post_types,
			'post_status'    => $status_keys,
			'posts_per_page' => -1,
			'tax_query'      => array(
				array(
					'taxonomy' => 'wp_theme',
					'field'    => 'slug',
					'terms'    => $current_theme,
				),
			),
		);
		$current_theme_posts = Theme_Assets::get_theme_posts( $current_theme_args );
		$current_theme_count = count( $current_theme_posts );

		$current_theme_args['tax_query'][0]['operator'] = 'NOT IN';
		$inactive_theme_posts                           = Theme_Assets::get_theme_posts( $current_theme_args );
		$inactive_theme_count                           = count( $inactive_theme_posts );

		$views        = array();
		$views['all'] = sprintf(
			'<a href="%s" class="%s">%s <span class="count">(<span class="all-count">%d</span>)</span></a>',
			esc_url( $all_url ),
			( ! $trashed && ! $current_theme_view ) ? 'current' : '',
			esc_html__( 'All', 'contentsync_hub' ),
			$all_count
		);

		$current_post_type = (array) $this->post_type;

		if (
			in_array( 'wp_template', $current_post_type )
			|| in_array( 'wp_template_part', $current_post_type )
			|| in_array( 'wp_global_styles', $current_post_type )
		) {
			$views['current_theme'] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(<span class="current-theme-count">%d</span>)</span></a>',
				esc_url( $current_theme_url ),
				$current_theme_view ? 'current' : '',
				esc_html__( 'Current Theme', 'contentsync_hub' ),
				$current_theme_count
			);
			$views['inactive']      = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(<span class="nactive-count">%d</span>)</span></a>',
				esc_url( $inactive_url ),
				$inactive_view ? 'current' : '',
				esc_html__( 'Not Active', 'contentsync_hub' ),
				$inactive_theme_count
			);
		}
		$views['trash'] = sprintf(
			'<a href="%s" class="%s">%s <span class="count">(<span class="trash-count">%d</span>)</span></a>',
			esc_url( $trash_url ),
			$trashed ? 'current' : '',
			esc_html__( 'Trash', 'contentsync_hub' ),
			$trash_count
		);

		return $views;
	}

	/**
	 * Display text when no items found
	 */
	public function no_items() {

		$text = __( 'No posts found.', 'contentsync_hub' );

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

		$edit_post_link  = Urls::get_edit_post_link( $post );
		$trash_post_link = Admin_Posts::get_delete_post_link( $post );

		$is_trash    = ( isset( $_GET['post_status'] ) && $_GET['post_status'] === 'trash' );
		$row_actions = array();
		if ( $is_trash ) {
			// Restore
			$restore_link           = Admin_Posts::get_untrash_post_link( $post );
			$row_actions['restore'] = sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				$restore_link,
				esc_attr( sprintf( __( 'Restore &#8220;%s&#8221;' ), $post->post_title ) ),
				__( 'Restore', 'contentsync_hub' )
			);
			// Delete Permanently
			$delete_link           = Admin_Posts::get_permanent_delete_post_link( $post );
			$row_actions['delete'] = sprintf(
				'<a href="%s" aria-label="%s" onclick="return confirm(\'Are you sure you want to delete this item permanently?\');">%s</a>',
				$delete_link,
				esc_attr( sprintf( __( 'Delete &#8220;%s&#8221; permanently', 'contentsync_hub' ), $post->post_title ) ),
				__( 'Delete Permanently', 'contentsync_hub' )
			);
			// Export
			$row_actions['contentsync_export'] = sprintf(
				'<a style="cursor:pointer;" onclick="contentsync.postExport.openExport(this);" data-post_id="%s">%s</a>',
				$post->ID,
				__( 'Export', 'contentsync_hub' )
			);
		} else {
			$row_actions['edit'] = sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				$edit_post_link,
				/* translators: %s: Post title. */
				esc_attr( sprintf( __( 'Edit &#8220;%s&#8221;' ), $post->post_title ) ),
				__( 'Edit', 'contentsync_hub' )
			);
			$row_actions['trash'] = sprintf(
				'<a href="%s" aria-label="%s" onclick="return confirm(\'Are you sure you want to move this item to the trash?\');">%s</a>',
				$trash_post_link,
				/* translators: %s: Post title. */
				esc_attr( sprintf( __( 'Move &#8220;%s&#8221; to the trash' ), $post->post_title ) ),
				__( 'Trash', 'contentsync_hub' )
			);
			$row_actions['contentsync_export'] = sprintf(
				'<a style="cursor:pointer;" onclick="contentsync.postExport.openExport(this);" data-post_id="%s">%s</a>',
				$post->ID,
				__( 'Export', 'contentsync_hub' )
			);
			$row_actions['rename_template']    = sprintf(
				'<a style="cursor:pointer;" onclick="contentsync.postExport.openRenameTemplate(this);" data-post_id="%s" data-post_title="%s" data-post_name="%s">%s</a>',
				$post->ID,
				$post->post_title,
				$post->post_name,
				__( 'Rename', 'contentsync_hub' )
			);
		}

		if ( empty( $edit_post_link ) ) {
			unset( $row_actions['edit'] );
		}
		if ( $post->post_type == 'wp_global_styles' ) {
			unset( $row_actions['rename_template'] );
		}

		$error       = '';
		$post_status = '';

		$template_theme = Theme_Assets::get_wp_template_theme( $post );
		if ( $template_theme ) {

			$template_theme_name = '';
			$theme_object        = wp_get_theme( $template_theme );

			if ( $theme_object instanceof \WP_Theme ) {
				$template_theme_name = $theme_object->get( 'Name' );
			}

			// check if the template is from the current theme
			if ( get_option( 'stylesheet' ) != $template_theme ) {

				$error = sprintf(
					__( 'This asset was created with a different theme (%1$s) and is not available for the current theme (%2$s).', 'contentsync_hub' ),
					$template_theme_name,
					get_option( 'stylesheet' )
				);

				// add the switch theme action
				if ( $post->post_type == 'wp_template' || $post->post_type == 'wp_template_part' ) {
					$row_actions['switch_template_theme'] = sprintf(
						'<a style="cursor:pointer;" onclick="contentsync.postExport.openSwitchTemplateTheme(this);" data-post_id="%s">%s</a>',
						$post->ID,
						__( 'Assign to current theme', 'contentsync_hub' )
					);
				} elseif ( $post->post_type == 'wp_global_styles' ) {
					$row_actions['switch_global_styles'] = sprintf(
						'<a style="cursor:pointer;" onclick="contentsync.postExport.openSwitchGlobalStyles(this, \'' . $template_theme . '\');" data-post_id="%s">%s</a>',
						$post->ID,
						__( 'Assign to current theme', 'contentsync_hub' )
					);
				}
			}
		}

		// add the post status to the title
		if ( ! empty( $post_status ) ) {
			$post_status = ' - <span class="post-state">' . $post_status . '</span>';
		}

		// render the post title
		// In Trash view, the title should not be linked.
		if ( empty( $error ) && ! empty( $edit_post_link ) && ! ( isset( $_GET['post_status'] ) && $_GET['post_status'] === 'trash' ) ) {
			$content = sprintf(
				'<strong><a class="row-title" href="%s">%s</a>%s</strong>',
				$edit_post_link,
				$post->post_title,
				$post_status
			);
		} else {
			$content = sprintf(
				'<strong><span class="row-title">%s</span>%s&nbsp;%s</strong>',
				$post->post_title,
				$post_status,
				Admin_Render::make_admin_info_popup( $error, 'right' )
			);
		}

		// row actions
		$content .= $this->row_actions( $row_actions );

		return $content;
	}

	/**
	 * Handles the post_name column output.
	 *
	 * @param WP_Post $post The current WP_Post object.
	 */
	public function column_post_name( $post ) {

		return $post->post_name;
	}

	/**
	 * Handles the post_type column output.
	 *
	 * @param WP_Post $post The current WP_Post object.
	 */
	public function column_post_type( $post ) {

		$post_type_obj = get_post_type_object( $post->post_type );

		if ( $post_type_obj ) {
			return $post_type_obj->labels->singular_name;
		} else {
			return '<i>' . $post->post_type . '</i>';
		}
	}

	/**
	 * Handles the author column output.
	 *
	 * @param WP_Post $post The current WP_Post object.
	 */
	public function column_author( $post ) {
		$author = get_userdata( $post->post_author );
		return $author ? $author->display_name : 'N/A';
	}

	/**
	 * Handles the post date column output.
	 *
	 * @param WP_Post $post The current WP_Post object.
	 */
	public function column_date( $post ) {
		global $mode;

		if ( '0000-00-00 00:00:00' === $post->post_date ) {
			$t_time    = __( 'Unpublished' );
			$time_diff = 0;
		} else {
			$t_time = sprintf(
				/* translators: 1: Post date, 2: Post time. */
				__( '%1$s at %2$s' ),
				/* translators: Post date format. See https://www.php.net/manual/datetime.format.php */
				get_the_time( __( 'Y/m/d' ), $post ),
				/* translators: Post time format. See https://www.php.net/manual/datetime.format.php */
				get_the_time( __( 'g:i a' ), $post )
			);

			$time      = get_post_timestamp( $post );
			$time_diff = time() - $time;
		}

		if ( 'publish' === $post->post_status ) {
			$status = __( 'Published' );
		} elseif ( 'future' === $post->post_status ) {
			if ( $time_diff > 0 ) {
				$status = '<strong class="error-message">' . __( 'Missed schedule' ) . '</strong>';
			} else {
				$status = __( 'Scheduled' );
			}
		} else {
			$status = __( 'Last modified' );
		}

		/**
		 * Filters the status text of the post.
		 *
		 * @param string  $status      The status text.
		 * @param WP_Post $post        Post object.
		 * @param string  $column_name The column name.
		 * @param string  $mode        The list display mode ('excerpt' or 'list').
		 */
		$status = apply_filters( 'post_date_column_status', $status, $post, 'date', $mode );

		if ( $status ) {
			echo $status . '<br />';
		}

		/**
		 * Filters the published, scheduled, or unpublished time of the post.
		 *
		 *              The published time and date are both displayed now,
		 *              which is equivalent to the previous 'excerpt' mode.
		 *
		 * @param string  $t_time      The published time.
		 * @param WP_Post $post        Post object.
		 * @param string  $column_name The column name.
		 * @param string  $mode        The list display mode ('excerpt' or 'list').
		 */
		echo apply_filters( 'post_date_column_time', $t_time, $post, 'date', $mode );
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

	public function column_default( $item, $column_name ) {
		$post_id = isset( $item->ID ) ? $item->ID : 0;
		return apply_filters( 'contentsync_theme_manage_assets_column_default', $column_name, $post_id );
	}

	/**
	 * Returns an associative array containing the bulk action
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		$is_trash = ( isset( $_GET['post_status'] ) && $_GET['post_status'] === 'trash' );
		if ( $is_trash ) {
			$actions = array(
				'untrash' => __( 'Restore', 'contentsync_hub' ),
				'delete'  => __( 'Delete Permanently', 'contentsync_hub' ),
			);
		} else {
			$actions = array(
				'trash'        => __( 'Move to the trash', 'contentsync_hub' ),
				'export'       => __( 'Export', 'contentsync_hub' ),
				'switch_theme' => __( 'Assign to current theme', 'contentsync_hub' ),
			);
		}
		return $actions;
	}

	/**
	 * Process the bulk actions
	 * Called via prepare_items()
	 */
	public function process_bulk_action() {

		// Check for single post actions first (from row action links)
		if ( isset( $_GET['action'] ) && isset( $_GET['post'] ) && isset( $_GET['post_status'] ) && $_GET['post_status'] === 'trash' ) {
			$single_action = sanitize_text_field( $_GET['action'] );
			$post_id       = intval( $_GET['post'] );

			if ( $single_action === 'delete' && current_user_can( 'delete_post', $post_id ) ) {
				$post = get_post( $post_id );
				if ( $post ) {
					$result = wp_delete_post( $post_id, true );
					if ( $result ) {
						Admin_Render::render_admin_notice(
							sprintf( __( 'The post "%s" has been permanently deleted.', 'contentsync_hub' ), $post->post_title ),
							'success'
						);
					} else {
						Admin_Render::render_admin_notice( __( 'Error occurred when deleting the post permanently.', 'contentsync_hub' ), 'error' );
					}
				}
				return;
			}
		}

		// verify the nonce for bulk actions
		if (
			! isset( $_REQUEST['_wpnonce'] ) ||
			! wp_verify_nonce( $_REQUEST['_wpnonce'], 'bulk-' . $this->_args['plural'] )
		) {
			return false;
		}

		// get the action
		$bulk_action = isset( $_POST['action'] ) && $_POST['action'] !== '-1' ? $_POST['action'] : ( isset( $_POST['action2'] ) ? $_POST['action2'] : null );
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

			case 'untrash':
				foreach ( $post_ids as $post_id ) {
					$post = get_post( $post_id );
					if ( $post ) {
						$post_title = $post->post_title;
						$result     = (bool) wp_untrash_post( $post_id );
						if ( $result ) {
							$post_titles[] = $post_title;
						} else {
							__debug( $result, true );
						}
					}
				}
				break;

			case 'delete':
				foreach ( $post_ids as $post_id ) {
					$post = get_post( $post_id );
					if ( $post ) {
						$post_title = $post->post_title;
						$result     = (bool) wp_delete_post( $post_id, true );
						if ( $result ) {
							$post_titles[] = $post_title;
						} else {
							__debug( $result, true );
						}
					}
				}
				break;

			case 'trash':
				foreach ( $post_ids as $post_id ) {
					$post = get_post( $post_id );
					if ( $post ) {
						$post_title = $post->post_title;
						$result     = (bool) wp_trash_post( $post_id );
						if ( $result ) {
							$post_titles[] = $post_title;
						} else {
							__debug( $result, true );
						}
					}
				}

				break;

			case 'switch_theme':
				foreach ( $post_ids as $post_id ) {
					$post = get_post( $post_id );
					if ( $post ) {
						$post_title = $post->post_title;

						if ( $post->post_type == 'wp_template' || $post->post_type == 'wp_template_part' ) {
							$result = Theme_Assets::set_wp_template_theme( $post, true );
						} elseif ( $post->post_type == 'wp_global_styles' ) {
							$result = Theme_Assets::set_wp_global_styles_theme( $post );
						} else {
							$result = false;
						}

						if ( $result ) {
							$post_titles[] = $post_title;
						} else {
							__debug( $result, true );
						}
					}
				}

				break;

			case 'export':
				$zip_uri = \Contentsync\Admin\Export\Post_Export_Manager::handle_post_export_bulk_action( '', 'contentsync_export', $post_ids );

				if ( empty( $zip_uri ) ) {
					$result = false;
					break;
				} else {
					$result = true;
				}

				$unique_id = uniqid( 'contentsync_export_download_' );
				echo '<a id="' . $unique_id . '" href="' . $zip_uri . '" download style="display:none;"></a>';
				echo '<script>document.getElementById("' . $unique_id . '").click();</script>';

				break;

			default:
				break;
		}

		// set the admin notice content
		$notices = array(
			'edit'         => array(
				'success' => __( 'The posts %s have been successfully edited.', 'contentsync_hub' ),
				'fail'    => __( 'There were errors when editing the posts.', 'contentsync_hub' ),
			),
			'trash'        => array(
				'success' => __( 'The posts %s have been successfully moved to the trash.', 'contentsync_hub' ),
				'fail'    => __( 'Errors occurred when moving posts to the trash.', 'contentsync_hub' ),
			),
			'untrash'      => array(
				'success' => __( 'The posts %s have been successfully restored.', 'contentsync_hub' ),
				'fail'    => __( 'Errors occurred when restoring the posts.', 'contentsync_hub' ),
			),
			'delete'       => array(
				'success' => __( 'The posts %s have been permanently deleted.', 'contentsync_hub' ),
				'fail'    => __( 'Errors occurred when deleting the posts permanently.', 'contentsync_hub' ),
			),
			'switch_theme' => array(
				'success' => __( 'The posts %s have been successfully assigned to the current theme', 'contentsync_hub' ),
				'fail'    => __( 'Errors occurred when assigning posts to the current theme.', 'contentsync_hub' ),
			),
			'export'       => array(
				'success' => __( 'The posts %s were exported successfully.', 'contentsync_hub' ),
				'fail'    => __( 'Errors occurred when exporting the posts.', 'contentsync_hub' ),
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
				$content .= ' ' . __( 'Error message:', 'contentsync_hub' ) . ' ' . $result;
			}
		}

		// display the admin notice
		Admin_Render::render_admin_notice( $content . $notice_content, $notice_class );
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
	// post_name
	public function sortby_post_name_asc( $a, $b ) {
		return $this->sort_alphabet( $a->post_name, $b->post_name );
	}
	public function sortby_post_name_desc( $a, $b ) {
		return -1 * $this->sortby_post_name_asc( $a, $b );
	}
	// post_type
	public function sortby_post_type_asc( $a, $b ) {
		$a_post_type = post_type_exists( $a->post_type ) ? get_post_type_object( $a->post_type )->labels->singular_name : $a->post_type;
		$b_post_type = post_type_exists( $b->post_type ) ? get_post_type_object( $b->post_type )->labels->singular_name : $b->post_type;
		return $this->sort_alphabet( $a_post_type, $b_post_type );
	}
	public function sortby_post_type_desc( $a, $b ) {
		return -1 * $this->sortby_post_type_asc( $a, $b );
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
