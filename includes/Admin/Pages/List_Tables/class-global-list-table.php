<?php

/**
 * Displays all synced posts as default WP admin list table
 *
 * @extends WP_List_Table ( wp-admin/includes/class-wp-list-table.php )
 */

namespace Contentsync\Contents;

use Contentsync\Translations\Translation_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// include the parent class
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Global_List_Table extends \WP_List_Table {

	/**
	 * Page Title
	 */
	private $title = 'Content Sync';

	/**
	 * Posts per page
	 *
	 * default is 20.
	 */
	public $posts_per_page = 20;

	/**
	 * Hold the prepared post items
	 */
	private $posts = array();

	/**
	 * Collect all GIDs to prevent duplicates
	 */
	private $all_gids = array();

	/**
	 * Hold the current post item info
	 */
	private $current_row = array();

	/**
	 * Hold the current post item info
	 */
	private $network_url = '';

	/**
	 * Hold the relationship of the current post item
	 */
	private $relationship = '';

	/**
	 * Class constructor
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'contentsync_table', // table class
				'plural'   => 'contentsync_table', // table class
				'ajax'     => false,
			)
		);
	}


	/**
	 * =================================================================
	 *                          GENERAL
	 * =================================================================
	 */

	/**
	 * Handles data query and filter, sorting, and pagination.
	 */
	public function prepare_items() {

		// process bulk action
		$this->process_bulk_action();

		// search term
		$query_args = array();
		if ( isset( $_REQUEST['s'] ) ) {
			$query_args['s'] = esc_attr( $_REQUEST['s'] );
		}
		if ( isset( $_GET['post_type'] ) ) {
			$query_args['post_type'] = esc_attr( $_GET['post_type'] );
		}

		// get posts
		$blog_id     = get_current_blog_id();
		$connections = is_network_admin() || ! is_multisite() ? \Contentsync\get_site_connections() : false;
		$rel         = isset( $_GET['rel'] ) && ! empty( $_GET['rel'] ) ? esc_attr( $_GET['rel'] ) : 'all';

		$this->posts['all'] = \Contentsync\get_all_synced_posts( $query_args );

		// get posts in relation to this blog
		if ( ! is_network_admin() ) {
			$this->posts['export'] = \Contentsync\get_synced_posts_of_blog( $blog_id, 'root', $query_args );
			$this->posts['import'] = \Contentsync\get_synced_posts_of_blog( $blog_id, 'linked', $query_args );
		}

		if ( $connections && count( $connections ) > 0 ) {
			if ( is_network_admin() ) {
				$this->posts['here'] = \Contentsync\get_all_synced_posts( $query_args, 'here' );
			}
			foreach ( $connections as $key => $connection ) {
				$this->posts[ $key ] = \Contentsync\get_all_synced_posts( $query_args, $key );
			}
		}

		// post error view
		if ( $rel === 'errors' ) {
			if ( is_network_admin() ) {
				$this->posts['errors'] = \Contentsync\get_network_synced_posts_with_errors( false, $query_args );
			} else {
				$this->posts['errors'] = \Contentsync\get_synced_posts_of_blog_with_errors( $blog_id, false, $query_args );
			}
		}

		// get items depending on relation
		$items = isset( $this->posts[ $rel ] ) ? $this->posts[ $rel ] : $this->posts['all'];

		// general
		$this->_column_headers = $this->get_column_info();
		$total_items           = count( $items );

		// sort
		$orderby  = isset( $_GET['orderby'] ) ? $_GET['orderby'] : 'post_date';
		$order    = isset( $_GET['order'] ) ? $_GET['order'] : 'desc';
		$class    = 'Contentsync\Contents\Global_List_Table';
		$callback = method_exists( $class, 'sortby_' . $orderby . '_' . $order ) ? array( $class, 'sortby_' . $orderby . '_' . $order ) : array( $class, 'sortby_post_date_desc' );
		usort( $items, $callback );

		// pagination
		$per_page     = $this->get_items_per_page( 'globals_per_page', $this->posts_per_page );
		$current_page = $this->get_pagenum();

		// slice the array
		$items = array_slice(
			/* subject */            $items,
			/* offset  */ ( $current_page - 1 ) * $per_page,
			/* length  */ $per_page
		);

		// set items
		$this->items = $items;

		// set pagination
		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);

		// set class vars
		$this->network_url = \Contentsync\get_network_url();

		/**
		 * display message if translation tool is not active on the main site
		 *
		 * @since 2.3.0 support wpml and polylang
		 */
		if ( is_multisite() && is_network_admin() ) {
			\Contentsync\switch_blog( get_main_site_id() );
			foreach ( $items as $post ) {
				if ( isset( $post->language ) && ! empty( $post->language ) ) {
					// get translation tool of post
					\Contentsync\switch_blog( $post->blog_id );
					$tool = Translation_Manager::get_translation_tool();
					\Contentsync\restore_blog();
					// compare to this
					if ( Translation_Manager::get_translation_tool() != $tool ) {
						switch ( $tool ) {
							case 'wpml':
								echo '<div class="notice notice-error">' .
									'<p>' .
										__( "We've noticed that you're using multilingual global content.", 'contentsync' ) .
										' <strong>' .
											__( 'WPML must be active on the main page of this multisite and every other connection for this to work.', 'contentsync' ) .
										'</strong> ' .
										__( 'In addition, WPML should be active on every page with synchronized multilingual content.', 'contentsync' ) .
									'</p><p>' .
										__( 'On the other hand, unexpected behavior and errors may occur when synchronizing posts and especially post types in different languages.', 'contentsync' ) .
									'</p>' .
								'</div>';
								break;
							case 'polylang':
								echo '<div class="notice notice-error">' .
									'<p>' .
										__( "We've noticed that you're using multilingual global content.", 'contentsync' ) .
										__( 'Polylang should be active on every page with synchronized multilingual content.', 'contentsync' ) .
									'</p><p>' .
										__( 'On the other hand, unexpected behavior and errors may occur when synchronizing posts and especially post types in different languages.', 'contentsync' ) .
									'</p>' .
								'</div>';
								break;
						}
					}
				}
				break;
			}
			\Contentsync\restore_blog();
		}
	}

	/**
	 * Render the page
	 */
	public function render_page( $title = '' ) {

		echo '<div class="wrap"><h1>' . __( ( empty( $title ) ? $this->title : $title ), 'contentsync' ) . '</h1><hr class="wp-header-end">';

		$this->render_table();

		echo '<br class="clear" /></div>';
	}

	/**
	 * Render the table
	 */
	public function render_table() {

		$this->prepare_items();

		$this->tabs();

		$this->views();

		echo '<form id="posts-filter" method="post">';

		$this->display();

		echo '</form>';
	}

	/**
	 * Render the tabs
	 */
	public function tabs() {

		$current = isset( $_GET['post_type'] ) ? esc_attr( $_GET['post_type'] ) : '';
		$builtin = array_flip( array( 'post', 'page', 'attachment' ) );
		$greyd   = array_flip( array( 'dynamic_template', 'tp_posttypes', 'contentsync_popup', 'tp_forms' ) );

		// re-sort supported post types
		$post_types = array_keys(
			array_merge(
				$builtin,
				$greyd,
				array_flip( \Contentsync\get_export_post_types() )
			)
		);

		echo "<div id='global_tabs' class='contentsync_tabs'>";
		printf(
			"<a href='%s' class='tab %s'>%s</a>",
			remove_query_arg( 'post_type' ),
			empty( $current ) ? 'active' : '',
			__( 'All', 'contentsync' )
		);
		foreach ( $post_types as $post_type ) {
			$post_type_obj = get_post_type_object( $post_type );
			if ( $post_type_obj ) {
				printf(
					"<a href='%s' class='tab %s %s'>%s</a>",
					add_query_arg(
						'post_type',
						$post_type,
						remove_query_arg( array( 'paged' ) )
					),
					isset( $builtin[ $post_type ] ) ? 'blue' : ( isset( $greyd[ $post_type ] ) ? 'greyd' : '' ),
					$current == $post_type ? 'active' : '',
					$post_type_obj->labels->name
				);
			}
		}
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

		$views       = array();
		$return      = array();
		$rel         = isset( $_GET['rel'] ) && ! empty( $_GET['rel'] ) ? esc_attr( $_GET['rel'] ) : 'all';
		$connections = is_network_admin() || ! is_multisite() ? \Contentsync\get_site_connections() : false;

		if ( is_network_admin() ) {
			if ( $connections && count( $connections ) > 0 ) {
				$views = array(
					'all'  => __( 'All', 'contentsync' ),
					'here' => __( 'This installation', 'contentsync' ),
				);
			}
		} else {
			$views = array(
				'all'    => __( 'All', 'contentsync' ),
				'export' => __( 'Exported from here', 'contentsync' ),
				'import' => __( 'Imported here', 'contentsync' ),
			);
		}

		if ( $connections && count( $connections ) > 0 ) {
			foreach ( $connections as $key => $connection ) {
				$views[ $key ] = $key;
			}
		}

		if ( $rel === 'errors' ) {
			if ( count( $this->posts['errors'] ) ) {
				$views['errors'] = '<span class="color_red"><span class="dashicons dashicons-warning"></span>&nbsp;' . __( 'Contents with errors', 'contentsync' ) . '</span>';
			} else {
				$views['errors'] = __( 'No errors found', 'contentsync' );
			}
		}

		foreach ( $views as $type => $title ) {
			$return[ $type ] = sprintf(
				'<a href="%s" class="%s">%s <span class="count">(%s)</span></a>',
				esc_attr( $type === 'all' ? remove_query_arg( array( 'rel', 'paged' ) ) : add_query_arg( 'rel', $type, remove_query_arg( array( 'paged' ) ) ) ),
				$rel === $type ? 'current' : '',
				$title,
				isset( $this->posts[ $type ] ) ? count( $this->posts[ $type ] ) : 0
			);
		}

		if ( $rel !== 'errors' ) {
			$return['errors'] = sprintf(
				'<span class="js_check_errors" data-mode="%s" data-blog_id="%s" data-post_type="%s">' .
					'<span class="loading">%s<span class="spinner is-active"></span></span>' .
					'<span class="no_errors hidden">%s</span>' .
					'<span class="errors_found hidden">' .
						'<a href="%s" class="%s">' .
							'<span class="color_red"><span class="dashicons dashicons-warning"></span>&nbsp;%s</span>' .
							'<span class="count">(?)</span>' .
						'</a>' .
					'</span>' .
				'</span>',
				is_network_admin() ? 'network' : 'site',
				get_current_blog_id(),
				isset( $_GET['post_type'] ) ? $_GET['post_type'] : '',
				__( 'Search for errors...', 'contentsync' ),
				__( 'No errors found', 'contentsync' ),
				add_query_arg( 'rel', 'errors', remove_query_arg( array( 'paged' ) ) ),
				$rel === 'errors' ? 'current' : '',
				__( 'Contents with errors', 'contentsync' )
			);
		}

		return $return;
	}

	/**
	 * Display text when no items found
	 */
	public function no_items() {

		$text = __( 'No global content found.', 'contentsync' );
		$rel  = isset( $_GET['rel'] ) && ! empty( $_GET['rel'] ) ? sanitize_key( $_GET['rel'] ) : 'all';
		if ( $rel === 'export' ) {
			$text = __( 'No global content exported from here was found.', 'contentsync' );
		} elseif ( $rel === 'import' ) {
			$text = __( 'No global content imported here was found.', 'contentsync' );
		} elseif ( $rel === 'errors' ) {
			$text = __( 'No faulty global content found.', 'contentsync' );
		}

		echo '<div style="margin: 4px 0;">' . $text . '</div>';
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
			$this->search_box( __( 'Search' ), 'search-box-id' );
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

	/**
	 * Generates content for a single row of the table.
	 *
	 * @since 3.1.0
	 *
	 * @param object $post The current item (still a post)
	 */
	public function single_row( $post ) {
		// debug( $post );

		$item = new_synced_post( $post );
		$gid  = \Contentsync\get_contentsync_meta_values( $item, 'synced_post_id' );

		// return if not synced post
		if ( ! isset( $item->meta ) || empty( $gid ) ) {
			// debug($item);
			return;
		}

		// init error parameter
		$item->error = null;
		if ( isset( $_GET['rel'] ) && $_GET['rel'] === 'errors' ) {
			$item->error = $post->error;
		} elseif ( isset( $this->all_gids[ $gid ] ) ) {
			$item->error = (object) array(
				'repaired' => false,
				'message'  => __( 'There is already a post that references the same global ID.', 'contentsync' ),
				'log'      => '',
			);
		}

		// save gid to prevent duplicates
		$this->all_gids[ $gid ] = $post->ID;

		list( $root_blog_id, $root_post_id, $root_net_url ) = \Contentsync\explode_gid( $gid );

		// prepare the item
		$item->gid            = $gid;
		$item->relationship   = $this->get_contentsync_relationship( $item );
		$item->local_post     = is_network_admin() ? false : \Contentsync\get_local_post_by_gid( $gid );
		$item->post_type_name = post_type_exists( $item->post_type ) ? get_post_type_object( $item->post_type )->labels->singular_name : $item->post_type;
		$item->blog_id        = ( $item->local_post || ! $post->blog_id ) ? get_current_blog_id() : $post->blog_id;

		// if relationship == 'unused' but local_post is set, this is an error
		if ( $item->relationship == 'unused' && $item->local_post ) {
			$item->relationship = 'error';
			$error              = \Contentsync\check_post_for_errors( $item );
			if ( $error ) {
				$item->error = $error;
			}
		}

		// get root site url
		if ( empty( $item->site_url ) ) {
			if ( empty( $root_net_url ) ) {
				$item->site_url = trailingslashit( get_site_url( $root_blog_id ) );
			} elseif ( isset( $this->posts[ $root_net_url ] ) ) {
					$_post = array_filter(
						$this->posts[ $root_net_url ],
						function ( $_post ) use ( $root_blog_id, $root_post_id ) {
							return $_post->ID === $root_post_id && $_post->blog_id === $root_blog_id;
						}
					);
				if ( $_post && is_array( $_post ) && count( $_post ) > 0 ) {
					$_post          = reset( $_post );
					$item->site_url = trailingslashit( esc_url( $_post->site_url ) );
				}
			} else {
				$synced_post = \Contentsync\get_synced_post( $gid );
				if ( $synced_post && isset( $synced_post->post_links ) && isset( $synced_post->post_links->edit ) ) {
					$item->site_url = trailingslashit( esc_url( $synced_post->post_links->blog ) );
				} else {
					$item->site_url = trailingslashit( esc_url( $root_net_url ) );
				}
			}
		} else {
			$item->site_url = trailingslashit( esc_url( $item->site_url ) );
		}

		// links
		$data = array(
			'gid'       => $gid,
			'post_id'   => $item->ID,
			'post_type' => $item->post_type,
			'blog_id'   => $item->blog_id,
		);
		if ( $item->local_post && isset( $item->local_post->ID ) ) {
			$data['post_id'] = $item->local_post->ID;
		}

		if ( $item->post_type == 'wp_template' || $item->post_type == 'wp_template_part' ) {
			// debug($item);
			$blog_theme = isset( $item->blog_theme ) ? $item->blog_theme : 'greyd-theme';

			// If local post is set, use the theme of the local post.
			// The blog theme on the root stage is not always the same as the theme of the local post,
			// because the theme will automatically be switched to the theme of the destination blog
			// during import.
			if ( $item->local_post ) {
				$blog_theme = \Contentsync\get_wp_template_theme( $item->local_post );
			}
			// debug( $item, true );
			$item->post_links = array(
				'root' => $item->site_url . "wp-admin/site-editor.php?postType={$item->post_type}&postId={$blog_theme}//{$item->post_name}&categoryId={$root_post_id}&canvas=edit",
				'edit' => $item->local_post ? admin_url( "site-editor.php?postType={$item->post_type}&postId={$blog_theme}//{$item->post_name}&categoryId={$item->local_post->ID}&canvas=edit" ) : '',
			);
		} elseif ( $item->post_type == 'wp_navigation' ) {
			$item->post_links = array(
				'root' => $item->site_url . "wp-admin/site-editor.php?postType={$item->post_type}&postId={$root_post_id}&canvas=edit",
				'edit' => $item->local_post ? admin_url( "site-editor.php?postType={$item->post_type}&postId={$item->local_post->ID}&canvas=edit" ) : '',
			);
		} elseif ( $item->post_type == 'wp_global_styles' ) {
			$item->post_links = array(
				'root' => $item->site_url . 'wp-admin/site-editor.php?p=/styles',
				'edit' => $item->local_post ? admin_url( 'site-editor.php?p=/styles' ) : '',
			);
		} else {
			$item->post_links = array(
				'root' => $item->site_url . "wp-admin/post.php?post={$root_post_id}&action=edit",
				'edit' => $item->local_post ? admin_url( "post.php?post={$item->local_post->ID}&action=edit" ) : '',
			);
		}

		if ( isset( $_GET['rel'] ) && $_GET['rel'] === 'errors' ) {
			$item->post_links['edit'] = get_site_url( $item->blog_id ) . "/wp-admin/post.php?post={$post->ID}&action=edit";
		}
		$item->actions = array(
			// edit the local post
			'edit'     => $item->local_post || $item->error ? "<a href='" . $item->post_links['edit'] . "'>" . __( 'Edit', 'contentsync' ) . '</a>' : '',
			// import by gid
			'import'   => $this->ajax_link( 'checkImport', __( 'Import', 'contentsync' ), $data ),
			// unexport if this is the root
			'unexport' => $item->relationship == 'export' ? $this->ajax_link( 'unexportPost', __( 'Unlink', 'contentsync' ), $data ) : '',
			// unimport if local post exists
			'unimport' => $item->local_post ? $this->ajax_link( 'unimportPost', __( 'Unlink', 'contentsync' ), $data ) : '',
			// trash the local post
			'trash'    => $item->local_post ? $this->ajax_link( 'trashPost', __( 'Trash', 'contentsync' ), $data ) : '',
			// edit the root
			'root'     => "<a href='" . $item->post_links['root'] . "'>" . __( 'Go to the original post', 'contentsync' ) . '</a>',
			// repair if error
			'repair'   => $this->ajax_link( 'repairPost', __( 'Repair', 'contentsync' ), $data ),
		);

		if ( is_network_admin() ) {
			// unexport by gid
			$item->actions['unexport'] = $this->ajax_link( 'unexportPost', __( 'Unlink', 'contentsync' ), $data );
			// delete all by gid
			$item->actions['delete'] = $this->ajax_link( 'deletePost', __( 'Delete everywhere', 'contentsync' ), $data );
		}

		if ( is_network_admin() ) {
			echo '<tr>';
		} elseif ( isset( $_GET['rel'] ) && $_GET['rel'] === 'errors' ) {
			echo '<tr>';
		} else {
			// add a custom class, depending on whether the post is used on this stage or not
			echo '<tr class="' . $this->relationship . '">';
		}

		// debug( $this->item );
		$this->single_row_columns( $item );

		echo '</tr>';
	}

	/**
	 * Build ajax link
	 */
	public function ajax_link( $action, $text, $data = array() ) {
		return "<a onclick='contentsync.{$action}(this);' " . implode(
			' ',
			array_map(
				function ( $key, $val ) {
					return "data-$key='$val'";
				},
				array_keys( $data ),
				$data
			)
		) . ">{$text}</a>";
	}


	/**
	 * =================================================================
	 *                          COLUMNS
	 * =================================================================
	 */

	/**
	 * Associative array of columns
	 *
	 * @return array
	 */
	function get_columns() {
		$columns = array(
			'cb'           => '<input type="checkbox" />',
			'post_title'   => __( 'Title', 'contentsync' ),
			'relationship' => __( 'Status', 'contentsync' ),
			'post_type'    => __( 'Post Type', 'contentsync' ),
			'site_url'     => __( 'Source', 'contentsync' ),
			'usage'        => __( 'Usage', 'contentsync' ),
			'language'     => __( 'Language', 'contentsync' ),
			'options'      => __( 'Options', 'contentsync' ),
			'post_date'    => __( 'Date', 'contentsync' ),
			'gid'          => 'GID',
		);

		if ( is_multisite() ) {
			if ( is_network_admin() ) {
				unset( $columns['relationship'] );
			} else {
				unset( $columns['usage'] );
			}
			if ( ! is_super_admin() ) {
				unset( $columns['debug'] );
			}
		} elseif ( ! current_user_can( 'manage_options' ) ) {
			unset( $columns['usage'] );
			unset( $columns['debug'] );
		}

		return $columns;
	}


	/**
	 * Columns to make sortable.
	 *
	 * @return array
	 */
	public function get_sortable_columns() {
		$sortable_columns = array(
			'post_title'   => array( 'post_title', true ),
			'relationship' => array( 'contentsync_status', true ),
			'post_type'    => array( 'post_type', true ),
			'site_url'     => array( 'site_url', true ),
			'post_date'    => array( 'post_date', true ),
			'language'     => array( 'language', true ),
		);

		return $sortable_columns;
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

		$render       = '';
		$gid          = $item->gid;
		$root_post_id = $item->ID;
		$blog_id      = get_current_blog_id();

		list( $root_blog_id, $_root_post_id, $root_net_url ) = \Contentsync\explode_gid( $gid );

		switch ( $column_name ) {

			case 'post_title':
				$actions = array();
				$title   = $item->local_post ? "<a class='row-title' href='{$item->post_links['edit']}'>{$item->post_title}</a>" : $item->post_title;

				if ( is_network_admin() ) {
					if ( empty( $root_net_url ) ) {
						$actions = array( 'root', 'unexport', 'delete' );
					} else {
						$actions = array( 'root' );
					}
				} elseif ( $item->relationship === 'export' ) {
					$actions = array( 'edit', 'unexport', 'trash' );
				} elseif ( $item->relationship === 'import' ) {
					$actions = array( 'root', 'edit', 'trash', 'unimport' );
				} elseif ( $item->relationship === 'unused' ) {
					$actions = array( 'import', 'root' );
				}

				// Highlight Posttypes
				$options = \Contentsync\get_contentsync_meta_values( $item, 'contentsync_export_options' );
				if ( isset( $options['whole_posttype'] ) && $options['whole_posttype'] ) {
					if ( $item->post_type == 'tp_posttypes' ) {
						$title .= '<strong> — ' . __( 'Dynamic Post Type incl. posts', 'contentsync' ) . '</strong>';
					} elseif ( ! isset( array_flip( array( 'page', 'post', 'attachment' ) )[ $item->post_type ] ) ) {
						$title .= ' — ' . __( 'Dynamic Post', 'contentsync' );
					}
				}

				if ( $item->error ) {
					$actions = array( 'edit', 'root' );
					$title  .= "<br><span class='color_red'>" . \Contentsync\get_error_message( $item->error ) . '</span>';
					if ( ! \Contentsync\is_error_repaired( $item->error ) ) {
						$actions[] = 'repair';
						$actions[] = 'trash';
					}
				}

				return $title . $this->row_actions( array_intersect_key( $item->actions, array_flip( $actions ) ) );
				break;

			case 'relationship':
				if ( $item->error ) {
					if ( \Contentsync\is_error_repaired( $item->error ) ) {
						return \Contentsync\Admin\make_admin_icon_status_box( 'info', __( 'Error fixed', 'contentsync' ) );
					} else {
						return \Contentsync\Admin\make_admin_icon_status_box( 'error', __( 'Error', 'contentsync' ) );
					}
				} elseif ( $item->relationship === 'unused' ) {
					return __( 'Not imported', 'contentsync' );
				} else {
					$text = $item->relationship == 'export' ? __( 'Exported', 'contentsync' ) : __( 'Imported', 'contentsync' );
					return \Contentsync\Admin\make_admin_icon_status_box( $item->relationship, $text );
				}
				break;

			case 'site_url':
				if ( ! is_network_admin() && $root_blog_id == $blog_id && empty( $root_net_url ) ) {
					return __( 'This site', 'contentsync' );
				}
				// highlight remote posts
				$nice_url   = strpos( $item->site_url, '://' ) !== false ? explode( '://', $item->site_url )[1] : $item->site_url;
				$global_url = $item->site_url . 'wp-admin/admin.php?page=global_contents';
				$class      = empty( $root_net_url ) ? '' : 'remote';
				return "<a href='{$global_url}' class='{$class}' target='_blank'>{$nice_url}</a>";
				break;

			case 'post_type':
				return $item->post_type_name;
				break;

			case 'post_name':
				return $post->post_name;
				break;

			case 'usage':
				// don't display for imported posts
				if ( $item->relationship == 'import' || $item->relationship == 'unused' ) {
					return '--';
				}

				if ( empty( $root_net_url ) ) {
					$connections = \Contentsync\get_contentsync_meta_values( $item, 'contentsync_connection_map' );
				} else {
					$connections = \Contentsync\get_network_remote_connection_map_by_gid( $gid );
				}

				// loop through connections
				// debug( $connections );
				if ( is_array( $connections ) && count( $connections ) > 0 ) {
					$count = 0;
					foreach ( $connections as $_blog_id => $_post_con ) {
						// local network posts
						if ( is_numeric( $_blog_id ) ) {
							$class   = empty( $root_net_url ) ? '' : 'remote';
							$render .= "<a href='{$_post_con["edit"]}' class='{$class}' target='_blank'>{$_post_con["nice"]}</a><br>";
							++$count;
						}
						// remote posts
						elseif ( is_array( $_post_con ) ) {
							foreach ( $_post_con as $__blog_id => $__post_con ) {
								$class   = $_blog_id == $this->network_url ? '' : 'remote';
								$render .= "<a href='{$__post_con["edit"]}' class='{$class}' target='_blank'>{$__post_con["nice"]}</a><br>";
								++$count;
							}
						}
					}
					$render = '<div class="overflow-vertical"><strong>' . $count . 'x</strong> ' . ( empty( $root_net_url ) ? __( 'Imported', 'contentsync' ) : __( 'Imported here', 'contentsync' ) ) . ':<br>' . $render . '</div>';
				}

				// render
				return $render;
				break;

			case 'language':
				if ( ! isset( $item->language ) ) {
					return $this->make_info_popup( __( 'The language of contents from remote sites can not be displayed in the overview.', 'contentsync' ) );
				} elseif ( empty( $item->language ) ) {
					return '--';
				} else {
					return $item->language;
				}
				break;

			case 'options':
				if ( empty( $root_net_url ) ) {
					$options      = \Contentsync\get_contentsync_meta_values( $item, 'contentsync_export_options' );
					$option_infos = array(
						'append_nested'  => array(
							'icon'     => 'networking',
							'active'   => __( 'Nested content in this post will also be imported.', 'contentsync' ),
							'inactive' => __( 'Nested content in this post will NOT be imported with it.', 'contentsync' ),
						),
						'translations'   => array(
							'icon'     => 'translation',
							'active'   => __( 'All translations of this post will be imported.', 'contentsync' ),
							'inactive' => __( 'The translations of this post will NOT be imported.', 'contentsync' ),
						),
						'resolve_menus'  => array(
							'icon'     => 'menu-alt',
							'active'   => __( 'Menus in the content of this post will be dissolved.', 'contentsync' ),
							'inactive' => __( 'Menus in the content of this post will NOT be dissolved.', 'contentsync' ),
						),
						'whole_posttype' => $item->post_type == 'tp_posttypes' ? array(
							'icon'     => 'rss',
							'active'   => __( 'All posts of this post type are automatically displayed on the same pages as this post.', 'contentsync' ),
							'inactive' => __( 'The posts of this post type will NOT be imported.', 'contentsync' ),
						) : array(
							'icon'     => 'rss',
							'active'   => __( 'This post was automatically made global and played on the same pages as the parent post type.', 'contentsync' ),
							'inactive' => __( 'This post was manually made global.', 'contentsync' ),
						),
					);
					foreach ( $option_infos as $name => $info ) {
						$active  = isset( $options[ $name ] ) && $options[ $name ] ? 'active' : 'inactive';
						$render .= $this->make_info_popup( $info[ $active ], $info['icon'], $active );
					}
				} else {
					$render = $this->make_info_popup( __( 'The options for content from external websites cannot be displayed in the overview.', 'contentsync' ) );
				}
				return $render;
				break;

			case 'post_date':
				return date_i18n( __( 'M j, Y @ H:i' ), strtotime( $item->post_date ) );
				break;

			case 'gid':
				return "<code style='font-size:smaller;'>" . $gid . '</code>';
				break;

			default:
				$item->post_content = esc_attr( $item->post_content );
				return debug( $item );
		}
	}

	/**
	 * Get the realtionship of a ppst to the current site
	 *
	 * @param Contentsync\Synced_Post $post
	 *
	 * @return string
	 */
	public function get_contentsync_relationship( $post ) {

		if ( is_network_admin() ) {
			return '';
		}

		$gid         = $post->meta['synced_post_id'];
		$connections = isset( $post->meta['contentsync_connection_map'] ) ? $post->meta['contentsync_connection_map'] : array();
		$blog_id     = get_current_blog_id();
		list( $root_blog_id, $root_post_id, $root_net_url ) = \Contentsync\explode_gid( $gid );

		$relationship = $blog_id == $root_blog_id && empty( $root_net_url ) ? 'export' : 'unused';

		// we're only watching imported posts anyway...
		if ( isset( $_GET['rel'] ) && $_GET['rel'] === 'import' ) {
			$relationship = 'import';
		}
		// root post from this network
		if ( empty( $root_net_url ) && isset( $connections[ $blog_id ] ) ) {
			$local_post_id = $connections[ $blog_id ]['post_id'];
		}
		// remote root post
		elseif ( ! empty( $root_net_url ) && isset( $connections[ $this->network_url ][ $blog_id ] ) ) {
			$local_post_id = $connections[ $this->network_url ][ $blog_id ]['post_id'];
		}

		// get the local post
		if ( isset( $local_post_id ) ) {
			$local_post = get_post( $local_post_id );
			if ( $local_post ) {
				$relationship = 'import';
			} else {
				$result = \Contentsync\remove_post_connection_from_connection_map( $gid, $blog_id, $local_post_id );
				if ( $result ) {
					$relationship = 'unused';
				} else {
					$relationship = 'error';
				}
			}
		}

		return $relationship;
	}

	public function make_info_popup( $text, $icon = 'info', $class = '' ) {
		return "<div class='contentsync_info_popup'><span class='dashicons dashicons-{$icon} {$class}'></span><span class='contentsync_info_text'>{$text}</span></div>";
	}


	/**
	 * =================================================================
	 *                          BULK ACTIONS
	 * =================================================================
	 */

	/**
	 * Render the bulk edit checkbox
	 */
	function column_cb( $post ) {

		if (
			! isset( $post->meta ) ||
			! isset( $post->meta['synced_post_id'] )
		) {
			return;
		}

		$option = 'gids[]';
		$gid    = $post->meta['synced_post_id'];

		// we fake a local gid for repair actions
		if ( isset( $_GET['rel'] ) && $_GET['rel'] === 'errors' ) {
			$option = 'post_ids[]';
			$gid    = $post->blog_id . '-' . $post->ID;
		}

		// debug($post);
		return '<input 
				type="checkbox" 
				name="' . $option . '" 
				data-pt="' . $post->post_type . '" 
				data-title="' . rawurlencode( $post->post_title ) . '" 
				data-rel="' . $this->get_contentsync_relationship( $post ) . '" 
				value="' . $gid . '" />';
	}

	/**
	 * Returns an associative array containing the bulk action
	 *
	 * @return array
	 */
	public function get_bulk_actions() {
		if ( is_network_admin() ) {
			$actions = array(
				'unexport' => __( 'Unlink', 'contentsync' ),
				'delete'   => __( 'Delete everywhere', 'contentsync' ),
			);
		} else {
			$actions = array(
				'unlink' => __( 'Unlink', 'contentsync' ),
				'import' => __( 'Import', 'contentsync' ),
				'trash'  => __( 'Trash', 'contentsync' ),
			);

			if ( ! is_multisite() ) {
				$actions['delete'] = __( 'Delete everywhere', 'contentsync' );
			}
		}

		if ( isset( $_GET['rel'] ) && $_GET['rel'] === 'errors' ) {
			$actions['repair'] = __( 'Repair', 'contentsync' );
		}

		return $actions;
	}

	/**
	 * Process the bulk actions
	 * Called via prepare_items()
	 */
	public function process_bulk_action() {

		// verify the nonce
		if (
			! isset( $_REQUEST['_wpnonce'] ) ||
			! wp_verify_nonce( $_REQUEST['_wpnonce'], 'bulk-' . $this->_args['plural'] )
		) {
			return false;
		}

		// get the data
		$bulk_action = isset( $_POST['action'] ) ? $_POST['action'] : ( isset( $_POST['action'] ) ? $_POST['action2'] : null );
		if ( empty( $bulk_action ) ) {
			return;
		}

		$gids = isset( $_POST['gids'] ) ? $_POST['gids'] : array();

		// normally the values are set as an array of inputs (checkboxes)
		if ( is_array( $gids ) ) {
			$gids = esc_sql( $gids );
		}
		// but if the user confirmed the action (eg. on 'delete'),
		// the values are set as an encoded string
		elseif ( is_string( $gids ) ) {
			$gids = esc_sql( json_decode( stripslashes( $gids ), true ) );
		}

		// support the error page, where 'post_ids' are set
		$gids_are_post_ids = false;
		if ( ! $gids || empty( $gids ) || ! is_array( $gids ) ) {

			$post_ids = isset( $_POST['post_ids'] ) ? $_POST['post_ids'] : array();
			if ( is_array( $post_ids ) ) {
				$post_ids = esc_sql( $post_ids );
			}

			if ( $post_ids && ! empty( $post_ids ) && is_array( $post_ids ) ) {
				$gids_are_post_ids = true;
				$gids              = $post_ids;
			} else {
				return;
			}
		}

		// vars used to display the admin notice
		$result         = false;
		$post_titles    = array();
		$notice_content = ''; // additional content rendered inside the admin notice
		$notice_class   = 'success'; // success admin notice type

		foreach ( $gids as $gid ) {

			list( $root_blog_id, $root_post_id, $root_net_url ) = \Contentsync\explode_gid( $gid );

			// unlink (site)
			if ( $bulk_action === 'unlink' ) {

				// unlink error posts
				if ( $gids_are_post_ids ) {
					$result        = \Contentsync\unlink_synced_post( $root_post_id );
					$post_titles[] = get_post( $root_post_id )->post_title;
				}

				// unexport root posts from this stage
				elseif ( $root_blog_id === get_current_blog_id() && empty( $root_net_url ) ) {
					$status = get_post_meta( $root_post_id, 'synced_post_status', true );
					if ( $status === 'root' ) {
						$result        = \Contentsync\unlink_synced_root_post( $gid );
						$post_titles[] = get_post( $root_post_id )->post_title;
					}
				}

				// unimport linked posts from this stage
				elseif ( $post = \Contentsync\get_local_post_by_gid( $gid ) ) {
					$result        = \Contentsync\unlink_synced_post( $post->ID );
					$post_titles[] = $post->post_title;
				}
			}

			// unexport (network)
			elseif ( $bulk_action === 'unexport' ) {
				if ( ! is_network_admin() ) {
					return false;
				}

				$result = \Contentsync\unlink_synced_root_post( $gid );
			}

			// import
			elseif ( $bulk_action === 'import' ) {

				/**
				 * @todo not in use right now though...
				 */

				// // comes from this site
				// if ( $root_blog_id === get_current_blog_id() && empty( $cur_net_url ) ) {
				// continue;
				// }

				// // already imported
				// if ( $post = \Contentsync\get_local_post_by_gid( $gid ) ) {
				// continue;
				// }

				// $result = \Contentsync\import_synced_post( $gid );
				// if ( $result === true && $post = get_post( $result ) ) {
				// $post_titles[] = $post->post_title;
				// }
			}

			// trash
			elseif ( $bulk_action === 'trash' ) {

				if ( $gids_are_post_ids ) {
					if ( is_network_admin() && $root_blog_id ) {
						\Contentsync\switch_blog( $root_blog_id );
					}
					$result = (bool) wp_trash_post( $root_post_id );
					if ( $result ) {
						$post_titles[] = get_post( $root_post_id )->post_title;
					}
					if ( is_network_admin() && $root_blog_id ) {
						\Contentsync\restore_blog();
					}
				} else {
					$post = \Contentsync\get_local_post_by_gid( $gid );
					if ( $post ) {
						$result = (bool) wp_trash_post( $post->ID );
						if ( $result ) {
							$post_titles[] = $post->post_title;
						}
					}
				}
			}

			// delete (to be confirmed)
			elseif ( $bulk_action === 'delete' ) {

				// only local network posts
				if ( ! empty( $root_net_url ) ) {
					continue;
				}

				$post = \Contentsync\get_synced_post( $gid );
				if ( $post ) {
					$post_titles[] = $post->post_title;
				}
				$result       = true;
				$notice_class = 'error';
				ob_start();
				echo "<form method='post'>";
				wp_nonce_field( 'bulk-' . $this->_args['plural'] );
				echo "<input type='hidden' name='gids' value='" . json_encode( $gids ) . "'>
					<input type='hidden' name='action' value='delete_confirmed'>
					<button type='submit' class='button button-primary' style='margin-bottom: 1em;'>
						" . __( 'Delete post irrevocably now', 'contentsync' ) . '
					</button>
				</form></div>';
				$notice_content = ob_get_contents();
				ob_end_clean();
			}

			// delete confirmed
			elseif ( $bulk_action === 'delete_confirmed' ) {

				$post = \Contentsync\get_synced_post( $gid );
				if ( $post ) {
					$post_title = $post->post_title;
					$result     = (bool) \Contentsync\delete_synced_post( $gid );
					if ( $result ) {
						$post_titles[] = $post_title;
					}
				}
			}

			// repair
			elseif ( $bulk_action === 'repair' ) {

				$error = \Contentsync\repair_post( $root_post_id, $root_blog_id, true );
				if ( \Contentsync\is_error_repaired( $error ) ) {
					if ( ! is_string( $result ) ) {
						$result = true;
					}
				} elseif ( ! is_string( $result ) ) {
						$result = \Contentsync\get_error_repaired_log( $error );
				} else {
					$result .= ' ' . \Contentsync\get_error_repaired_log( $error );
				}
			}
		}

		$notices = array(
			'unlink'           => array(
				'success' => __( 'The link between the posts %s has been successfully resolved.', 'contentsync' ),
				'fail'    => __( 'There were errors in resolving the links.', 'contentsync' ),
			),
			'delete'           => array(
				'success' => __( 'The link between the posts %s has been successfully resolved.', 'contentsync' ),
				'fail'    => __( 'There were errors in resolving the links.', 'contentsync' ),
			),
			'import'           => array(
				'success' => __( 'The posts %s were successfully imported.', 'contentsync' ),
				'fail'    => __( 'There were errors when importing the posts.', 'contentsync' ),
			),
			'trash'            => array(
				'success' => __( 'The posts %s were successfully moved to the trash.', 'contentsync' ),
				'fail'    => __( 'There were errors when moving the posts to the trash.', 'contentsync' ),
			),
			'delete'           => array(
				'success' => __( 'Are you sure you want to delete the global content %s everywhere? This action cannot be undone.', 'contentsync' ),
				'fail'    => __( 'There were errors when deleting the content.', 'contentsync' ),
			),
			'delete_confirmed' => array(
				'success' => __( 'The global content %s has been permanently deleted on all sites.', 'contentsync' ),
				'fail'    => __( 'There were errors when deleting the content.', 'contentsync' ),
			),
			'repair'           => array(
				'success' => __( 'The global content has been successfully repaired. In this overview, the old error messages are still displayed. When you refresh this page, you should no longer see the errors.', 'contentsync' ),
				'fail'    => __( 'Errors occurred while repairing the contents. All error messages are still displayed in this overview. If you refresh this page, you will see the bugs that have not yet been fixed.', 'contentsync' ),
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
		\Contentsync\Admin\render_admin_notice( $content . $notice_content, $notice_class );
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
	public function sortby_post_date_asc( $a, $b ) {
		return strtotime( $a->post_date ) - strtotime( $b->post_date );
	}
	public function sortby_post_date_desc( $a, $b ) {
		return -1 * $this->sortby_post_date_asc( $a, $b );
	}
	// post_title
	public function sortby_post_title_asc( $a, $b ) {
		return $this->sort_alphabet( $a->post_title, $b->post_title );
	}
	public function sortby_post_title_desc( $a, $b ) {
		return -1 * $this->sortby_post_title_asc( $a, $b );
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
	// site_url
	public function sortby_site_url_asc( $a, $b ) {

		// get a
		if ( isset( $a->meta ) ) {
			list( $a_blog_id, $a_post_id, $a_net_url ) = \Contentsync\explode_gid( $a->meta['synced_post_id'] );
		} else {
			$a_blog_id = $a->blog_id;
			$a_net_url = $a->network_url;
		}
		// get b
		if ( isset( $b->meta ) ) {
			list( $b_blog_id, $b_post_id, $b_net_url ) = \Contentsync\explode_gid( $b->meta['synced_post_id'] );
		} else {
			$b_blog_id = $b->blog_id;
			$b_net_url = $b->network_url;
		}

		// both are remote posts
		if ( ! empty( $a_net_url ) && ! empty( $b_net_url ) ) {
			// they come from the same network
			if ( $a_net_url == $b_net_url ) {
				return intval( $b_blog_id ) - intval( $a_blog_id );
			}
			// they come from different networks
			else {
				return $this->sort_alphabet( $a_net_url, $b_net_url );
			}
		}
		// only the first one is a remote post
		elseif ( ! empty( $a_net_url ) ) {
			return -1; // a negative value means, the two items change postion
		}
		// only the second one is a remote post
		elseif ( ! empty( $b_net_url ) ) {
			return 1; // a positive value means, the position does not change
		}
		// both posts come from here
		else {
			return intval( $b_blog_id ) - intval( $a_blog_id );
		}
	}
	public function sortby_site_url_desc( $a, $b ) {
		return -1 * $this->sortby_site_url_asc( $a, $b );
	}
	// contentsync_status
	public function sortby_contentsync_status_asc( $a, $b ) {
		$ints  = array(
			'export' => 1,
			'import' => 2,
			'unused' => 3,
		);
		$a_rel = $ints[ $this->get_contentsync_relationship( $a ) ];
		$b_rel = $ints[ $this->get_contentsync_relationship( $b ) ];
		return $b_rel - $a_rel;
	}
	public function sortby_contentsync_status_desc( $a, $b ) {
		return -1 * $this->sortby_contentsync_status_asc( $a, $b );
	}
	// language
	public function sortby_language_asc( $a, $b ) {
		$a_lang = isset( $a->language ) ? ( empty( $a->language ) ? 'xx' : $a->language ) : 'xxx';
		$b_lang = isset( $b->language ) ? ( empty( $b->language ) ? 'xx' : $b->language ) : 'xxx';
		return $this->sort_alphabet( $a_lang, $b_lang );
	}
	public function sortby_language_desc( $a, $b ) {
		return -1 * $this->sortby_language_asc( $a, $b );
	}
}
