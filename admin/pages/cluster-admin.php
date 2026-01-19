<?php
/**
 * Admin screen extensions for clusters and reviews.
 *
 * This file defines the `Cluster_Admin` class, which adds custom admin
 * screens, popups, meta boxes and overlay interfaces to support the
 * cluster and post review workflow. It registers menu pages for
 * clusters and post reviews, sets up screen options, implements AJAX
 * handlers for creating and deleting clusters, and enqueues
 * cluster‑specific scripts and styles. When extending this class, you
 * can add new UI components for cluster management or customise the
 * existing ones.
 *
 * @since 2.17.0
 */
namespace Contentsync\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Contentsync\Main_Helper;

new Cluster_Admin();

class Cluster_Admin {

	/**
	 * The Cluster_List_Table instance.
	 */
	public $Cluster_List_Table = null;

	/**
	 * The Post_Review_List_Table instance.
	 */
	public $Post_Review_List_Table = null;

	/**
	 * Constructor.
	 */
	public function __construct() {

		$admin_menu_hook = is_multisite() ? 'network_admin_menu' : 'admin_menu';

		add_action( $admin_menu_hook, array( $this, 'add_cluster_admin_page' ), 4 );
		add_filter( 'set-screen-option', array( $this, 'cluster_save_screen_options' ), 10, 3 );

		add_action( $admin_menu_hook, array( $this, 'add_post_reviews_admin_page' ), 4 );
		add_filter( 'set-screen-option', array( $this, 'post_reviews_save_screen_options' ), 10, 3 );

		// // add wizard
		add_action( 'admin_footer', array( $this, 'add_wizard' ), 999 );
		// ajax callback
		add_action( 'wp_ajax_contentsync_create_cluster', array( $this, 'create_cluster' ), 10, 1 );

		add_action( 'admin_init', array( $this, 'add_cluster_boxes' ) );

		add_filter( 'contentsync_overlay_contents', array( $this, 'add_overlay_contents' ) );

		add_action( 'wp_ajax_contentsync_delete_cluster', array( $this, 'delete_cluster' ) );

		// Add enqueues for cluster-specific assets
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ), 98 );
	}

	/**
	 * Enqueue cluster-specific admin scripts and styles
	 */
	public function admin_enqueue_scripts() {

		// Only enqueue on cluster admin pages
		if ( ! $this->is_global_content_screen() ) {
			return;
		}

		// Styles
		wp_register_style(
			'contentsync_admin_clusters',
			CONTENTSYNC_PLUGIN_URL . '/inc/cluster/assets/css/admin-clusters.css',
			null,
			CONTENTSYNC_VERSION,
			'all'
		);
		wp_enqueue_style( 'contentsync_admin_clusters' );

		// Scripts
		wp_register_script(
			'contentsync_admin_clusters',
			CONTENTSYNC_PLUGIN_URL . '/inc/cluster/assets/js/admin-clusters.js',
			array( 'wp-i18n', 'jquery', 'jquery-ui-sortable' ),
			CONTENTSYNC_VERSION,
			true
		);
		wp_enqueue_script( 'contentsync_admin_clusters' );

		// Localize the script with admin URL base
		wp_localize_script(
			'contentsync_admin_clusters',
			'contentsync_admin',
			array(
				'admin_url_base' => is_multisite() ? 'wp-admin/network/admin.php' : 'wp-admin/admin.php',
			)
		);
	}

	/**
	 * ***************************************************************
	 * Cluster Admin Page
	 * ***************************************************************
	 */

	/**
	 * Add a menu item to the WordPress admin menu
	 */
	function add_cluster_admin_page() {

		$hook = add_submenu_page(
			'global_contents',
			( isset( $_GET['cluster_id'] ) && is_numeric( $_GET['cluster_id'] ) ) ? __( 'Edit Cluster', 'contentsync' ) : __( 'Clusters', 'contentsync' ), // page title
			__( 'Clusters', 'contentsync' ), // menu title
			'manage_options',
			'contentsync_clusters',
			array( $this, 'render_clusters_admin_page' )
		);

		add_action( "load-$hook", array( $this, 'clusters_add_screen_options' ) );
	}

	/**
	 * Set screen options for the admin pages
	 */
	public function clusters_add_screen_options() {
		$args = array(
			'label'   => __( 'Clusters per page:', 'contentsync' ),
			'default' => 20,
			'option'  => 'clusters_per_page',
		);

		add_screen_option( 'per_page', $args );

		if ( ! class_exists( 'Cluster_List_Table' ) ) {
			require_once __DIR__ . '/class-cluster-list-table.php';
		}

		$this->Cluster_List_Table = new Cluster_List_Table();
	}

	/**
	 * Save the admin screen option
	 */
	public function cluster_save_screen_options( $status, $option, $value ) {

		if ( 'clusters_per_page' == $option ) {
			return $value;
		}

		return $status;
	}

	/**
	 * Display the custom admin list page
	 */
	function render_clusters_admin_page() {

		// check if set and is numeric
		if ( isset( $_GET['cluster_id'] ) && is_numeric( $_GET['cluster_id'] ) ) {
			$this->render_cluster_edit_page();
		} else {
			if ( ! class_exists( 'Cluster_List_Table' ) ) {
				require_once __DIR__ . '/class-cluster-list-table.php';
			}

			if ( ! $this->Cluster_List_Table ) {
				$this->Cluster_List_Table = new Cluster_List_Table();
			}

			$this->Cluster_List_Table->prepare_items();
			$this->Cluster_List_Table->render();
		}
	}

	/**
	 * ***************************************************************
	 * Cluster Edit page
	 * ***************************************************************
	 */
	public function render_cluster_edit_page() {

		// if ( ! is_super_admin() ) {
		// return;
		// }

		$this->save_cluster_settings();

		$cluster_id = $_GET['cluster_id'];

		if ( ! $cluster_id ) {
			echo 'Cluster not found';
			return;
		}
		$cluster = get_cluster_by_id( $cluster_id );

		// $cluster = get_cluster($cluster_id);
		$asset_url    = CONTENTSYNC_PLUGIN_URL . '/assets/icon';
		$nonce_action = 'contentsync_clusters';
		?>

		<div class="wrap">
			<h1><?php _e( 'Edit Cluster', 'contentsync' ); ?></h1>

			<form method="post">
				<?php wp_nonce_field( $nonce_action ); ?>
				<input type="hidden" name="cluster_id" value="<?php echo $cluster_id; ?>">
				<div id='poststuff'>
					<div id="titlediv" style="margin-bottom:30px;">
						<div id="titlewrap">
							<label class="screen-reader-text" id="title-prompt-text" for="title">Add title</label>
							<input type="text" name="post_title" size="30" value="<?php echo $cluster->title; ?>" id="title" spellcheck="true" autocomplete="off" placeholder="Enter title here">
						</div>
					</div>
					<?php do_meta_boxes( 'admin_page_contentsync_cluster', 'normal', $cluster ); ?>
					<div>
						<div>
							<div>
								<p class="submit"><input type="submit" name="save" id="submit" class="button button-primary huge" value="<?php _e( 'Save Cluster and Sync Contents', 'contentsync' ); ?>"></p>
								<?php
								echo \Contentsync\Utils\make_admin_info_box(
									array(
										'text'  => __( 'After saving changes to a cluster, all affected content will be synchronized inside this cluster. Depending on the changes, posts will be added or removed from all destination blogs. If destinations have been removed, all cluster contents will be removed there.', 'contentsync' ),
										'style' => 'orange',
									)
								);
								?>
							</div>
						</div>
					</div>
				</div>
			</form>
		</div>

		<?php
	}

	public function add_cluster_boxes() {

		// add settings box
		add_meta_box(
			'contentsync_cluster_settings', // ID
			__( 'Cluster Settings', 'contentsync' ), // Title
			array( $this, 'render_cluster_settings_box' ), // Callback
			'admin_page_contentsync_cluster',
			'normal', // advanced = at bottom of page
			'high', // Priority,
		);

		add_meta_box(
			'contentsync_cluster_conditions', // ID
			__( 'Select Contents', 'contentsync' ), // Title
			array( $this, 'render_cluster_screen_conditions_box' ), // Callback
			'admin_page_contentsync_cluster',
			'normal', // advanced = at bottom of page
			'high', // Priority,
		);
	}

	public function save_cluster_settings() {

		if ( empty( $_POST ) ) {
			return;
		}

		// debug($_POST);
		$result = false;

		// verify the nonce
		$nonce_action = 'contentsync_clusters';
		$nonce        = isset( $_POST['_wpnonce'] ) ? $_POST['_wpnonce'] : null;
		$cluster_id   = isset( $_POST['cluster_id'] ) ? $_POST['cluster_id'] : null;

		if ( isset( $_POST['save'] ) && $nonce && wp_verify_nonce( $nonce, $nonce_action ) && $cluster_id ) {

			// get all posts and destination ids before
			$cluster_posts_before   = get_cluster_posts_per_blog( $cluster_id );
			$cluster_before         = get_cluster_by_id( $cluster_id );
			$destination_ids_before = $cluster_before->get( 'destination_ids' );

			// make new cluster
			$cluster               = array();
			$content_condition_ids = array();
			$cluster['ID']         = $cluster_id;

			$has_date_condition = false;

			if ( isset( $_POST['post_title'] ) ) {
				$cluster['title'] = sanitize_text_field( $_POST['post_title'] );
			}

			if ( isset( $_POST['destination_ids'] ) ) {
				$cluster['destination_ids'] = sanitize_text_field( $_POST['destination_ids'] );
			}

			if ( isset( $_POST['enable_reviews'] ) ) {
				$cluster['enable_reviews'] = $_POST['enable_reviews'] == 'true' ? 1 : 0;
			} else {
				$cluster['enable_reviews'] = 0;
			}

			if ( isset( $_POST['reviewer_ids'] ) ) {
				$cluster['reviewer_ids'] = sanitize_text_field( $_POST['reviewer_ids'] );
			}

			if ( isset( $_POST['conditions'] ) ) {
				$content_conditions = $_POST['conditions'];

				// check if there are existing conditions
				$existing_conditions = get_cluster_content_conditions_by_cluster_id( $cluster_id );

				// loop through the conditions
				foreach ( $content_conditions as $index => $condition ) {
					$condition = wp_parse_args(
						$condition,
						array(
							'ID'               => 'new',
							'blog_id'          => '',
							'post_type'        => '',
							'taxonomy'         => '',
							'terms'            => '',
							'filter'           => '',
							'make_posts_global_automatically' => false,
							'export_arguments' => array(),
						)
					);

					if ( ! isset( $condition['ID'] ) || $condition['ID'] == 'hidden' ) {
						continue;
					}

					$condition_id = $condition['ID'];

					// if the condition is new
					if ( $condition_id == 'new' ) {
						$condition['contentsync_cluster_id'] = $cluster_id;
						$condition_id                        = insert_cluster_content_condition( $condition );
					} else {
						$condition['ID']  = $condition_id;
						$condition_before = get_cluster_content_condition_by_id( $condition_id );
						// if (
						// (bool) $condition_before->make_posts_global_automatically === true
						// && (bool) $condition_before->make_posts_global_automatically !== (bool)$condition['make_posts_global_automatically']
						// ) {
						// 'make_posts_global_automatically' changed to false: remove posts from sync
						// $conditon_posts = get_posts_by_cluster_content_condition( $condition_before, true );
						// foreach ( $conditon_posts as $blog_id_and_post_id ) {
						// if ( ( $key = array_search( $blog_id_and_post_id, $cluster_posts_before ) ) !== false ) {
						// unset( $cluster_posts_before[ $key ] );
						// }
						// }
						// }
						$res = update_cluster_content_condition( $condition );
					}

					// check if condition has 'date_mode'
					if ( ! empty( $condition['filter'] ) &&
						is_array( $condition['filter'] ) &&
						count( $condition['filter'] ) > 0
					) {
						foreach ( $condition['filter'] as $filter ) {
							if ( isset( $filter['date_mode'] ) && $filter['date_mode'] !== '' ) {
								$has_date_condition = true;
							}
						}
					}

					$content_condition_ids[] = $condition_id;
				}

				if ( $existing_conditions && is_array( $existing_conditions ) ) {
					// delete conditions that are not in the new list
					foreach ( $existing_conditions as $existing_condition ) {
						$existing_condition = (array) $existing_condition;
						if ( ! in_array( $existing_condition['ID'], $content_condition_ids ) ) {
							delete_cluster_content_condition( $existing_condition['ID'] );
						}
					}
				}

				$cluster['content_conditions'] = serialize( $content_condition_ids );
			}

			$result = update_cluster( $cluster );

			if ( $result ) {

				// \Contentsync\Logger::clear_log_file();

				// if destination_ids are set or changed, distribute posts
				if (
					( isset( $cluster['destination_ids'] ) && ! empty( $cluster['destination_ids'] ) )
					|| ( ! empty( $destination_ids_before ) )
				) {

					// distribute posts
					$distribute_result = \Contentsync\distribute_cluster_posts(
						new \Contentsync\Cluster( (object) $cluster ),
						array(
							'posts'           => $cluster_posts_before,
							'destination_ids' => $destination_ids_before,
						)
					);

					// start scheduler (only if condition has 'date_mode')
					if ( $has_date_condition ) {
						schedule_cluster_date_check();
					}
				}
			}
		}

		if ( $result ) {
			\Contentsync\Utils\render_admin_notice(
				sprintf(
					__( 'Settings saved. Contents will be distributed to all destinations. You can see the progress of the distribution on the page %s.', 'contentsync' ),
					' <a href="' . (
					is_multisite() ? network_admin_url( 'admin.php?page=contentsync_queue' ) : admin_url( 'admin.php?page=contentsync_queue' )
					) . '">' . __( 'Queue', 'contentsync' ) . '</a>',
				),
				'success'
			);
		} else {
			\Contentsync\Utils\render_admin_notice(
				__( 'Settings could not be saved and contents have not been synched.', 'contentsync' ),
				'error'
			);
		}

		if ( isset( $distribute_result ) && is_wp_error( $distribute_result ) ) {
			\Contentsync\Utils\render_admin_notice(
				sprintf(
					__( 'Failed to distribute posts to destinations: %s', 'contentsync' ),
					'<br>- ' . $distribute_result->get_error_message()
				),
				'error'
			);
		}
	}

	public static function log( $log, $action = '' ) {
		if ( $action == 'start' ) {
			ob_start();
		}
		echo str_pad( $log, 4096 );
		ob_flush();
		flush();
		if ( $action == 'end' ) {
			ob_end_flush();
		}
	}

	public function render_cluster_settings_box( $cluster ) {

		$cluster = (array) $cluster;

		// get blogs for the select
		$all_blogs   = \Contentsync\Connections\Connections_Helper::get_all_networks();
		$local_blogs = $all_blogs['local'];
		$remote      = isset( $all_blogs['remote'] ) ? $all_blogs['remote'] : array();

		// get the sites options
		$single = __( 'On this stage', 'contentsync' );
		$values = array( $single => array() );

		foreach ( $local_blogs as $blog ) {
			if ( $blog['blog_id'] < 1 ) {
				continue;
			}
			if ( isset( $blog['attributes']['staging']['is_stage'] ) && $blog['attributes']['staging']['is_stage'] ) {
				$title                                       = urlencode( $blog['name'] ) . ' – ' . $blog['domain'] . ' [STAGING]';
				$values[ $single ][ (int) $blog['blog_id'] ] = $title;
			} else {
				$title                                       = urlencode( $blog['name'] ) . ' – ' . $blog['domain'];
				$values[ $single ][ (int) $blog['blog_id'] ] = $title;
			}
		}

		// flatten remote array
		foreach ( $remote as $network_url => $network ) {
			$single = $network_url;
			foreach ( $network as $blog ) {
				// debug( $blog );
				if ( ! isset( $blog['blog_id'] ) || $blog['blog_id'] == 0 ) {
					continue;
				}
				$title = urlencode( $blog['name'] ) . ' – ' . $blog['domain'];
				$values[ $single ][ $blog['blog_id'] . '|' . $blog['network'] ] = $title;
			}
		}

		?>
		<table class='contentsync_table vertical cluster_edit_table'>
			<tbody>
		<?php

		$rows[] = array(
			'id'      => 'settings_destinations',
			'title'   => __( 'Select Destinations', 'contentsync' ),
			'desc'    => __( 'Select the destination blogs for the cluster', 'contentsync' ),
			'content' => '<div class="flex input_section">
						<div class="settings_input" style="position: relative; width: 100%;">' .
							$this->render_autotag_select(
								array(
									'name'     => 'destination_ids',
									'selected' => isset( $cluster['destination_ids'] ) ? $cluster['destination_ids'] : '',
									'values'   => $values,
									'default'  => '',
								)
							)
						. '</div>
				</div>',
		);

		$enable_reviews = isset( $cluster['enable_reviews'] ) && $cluster['enable_reviews'] == 'true' ? 'checked' : '';

		// get super admins
		if ( is_multisite() ) {
			$user_ids = get_users(
				array(
					'login__in' => get_super_admins(),
					'fields'    => 'ID',
				)
			);
		} else {
			$user_ids = get_users(
				array(
					'fields' => 'ID',
				)
			);
		}

		$reviewer_options = '';

		$values = array( $single => array() );

		foreach ( $user_ids as $user_id ) {
			$user                          = get_userdata( $user_id );
			$values[ $single ][ $user_id ] = $user->display_name;
		}

		$single = __( 'Authors', 'contentsync' );

		$rows[] = array(
			'id'      => 'settings_enable_reviews',
			'title'   => __( 'Enable reviews?', 'contentsync' ),
			'desc'    => __( 'Should post reviews for this cluster be enabled?', 'contentsync' ),
			'content' => "<div class='flex input_section'>
					<div class='settings_input' style='position: relative; width: 100%;'>
						<label for='enable_reviews' style='white-space:nowrap;display:flex;margin-bottom:0.5em'><input type='checkbox' name='enable_reviews' id='enable_reviews' value='true' {$enable_reviews} autocomplete='off'><span>" . __( 'Enable reviews?', 'contentsync' ) . "</span></label>
						<div id='enable_reviews_author_select'>
						<label>" . __( 'Select Reviewers', 'contentsync' ) . '</label><br>'
						. $this->render_autotag_select(
							array(
								'name'     => 'reviewer_ids',
								'selected' => isset( $cluster['reviewer_ids'] ) ? $cluster['reviewer_ids'] : '',
								'values'   => $values,
								'default'  => '',
							)
						) .
					'</div>
				</div>',
		);

		foreach ( $rows as $row ) {
			self::render_table_row( $row );
		}
		?>
			</tbody>
		</table>
		<?php
	}

	public function render_cluster_screen_conditions_box( $cluster ) {

		$cluster = (array) $cluster;

		$conditions = $cluster['content_conditions']; // get_cluster_content_conditions_by_cluster_id( $cluster['ID'] );

		if ( ! is_array( $conditions ) ) {
			$conditions = array();
		}

		$hidden_condition = array(
			array(
				'name'   => '',
				// "title"         => __("empty", 'contentsync'),
				'hidden' => 'true',
			),
		);

		$conditions = array_merge( $conditions, $hidden_condition );

		?>
		
		<div class='contentsync_table vertical cluster_edit_table conditions_table'>
			<div class="thead">
				<?php
				echo '<ul class="tr">
					<li class="th">
					</li>
					<!--<li class="th">
						' . __( 'Condition', 'contentsync' ) . '
					</li>-->
					<li class="th">
						' . __( 'Stage', 'contentsync' ) . '
					</li>
					<li class="th">
						' . __( 'Post Type', 'contentsync' ) . '
					</li>
					<li class="th">
						' . __( 'Actions', 'contentsync' ) . '
					</li>
				</ul>';
				?>
			</div>
			<div class='tbody' id="contentsync_sortable" data-empty="<?php echo __( 'No conditions added yet', 'contentsync' ); ?>">
				<?php
				if ( ! empty( $conditions ) && is_array( $conditions ) ) {
					$index = 0;
					foreach ( $conditions as $condition ) {
						$this->render_condition_row( $condition, $index );
						// debug($condition);
						++$index;
					}
				}
				?>
			</div>
			<div class="tfoot">
				<ul class="tr">
					<li class="td" colspan="4">
						<span class="button button-primary add_condition"><span class="dashicons dashicons-plus"></span>&nbsp;&nbsp; <?php echo __( 'Add condition', 'contentsync' ); ?></span>
					</li>
				</ul>
			</div>
		</div>
		<?php
	}

	public static function render_table_row( $atts = array(), $echo = true ) {

		$title   = isset( $atts['title'] ) ? '<h3>' . $atts['title'] . '</h3>' : '';
		$desc    = isset( $atts['desc'] ) ? $atts['desc'] : '';
		$content = isset( $atts['content'] ) ? $atts['content'] : '';
		$id      = isset( $atts['id'] ) ? ' id="' . $atts['id'] . '"' : '';
		$classes = isset( $atts['classes'] ) ? ' class="' . ( is_array( $atts['classes'] ) ? implode( ' ', $atts['classes'] ) : $atts['classes'] ) . '"' : '';

		ob_start();
		echo '<tr' . $id . $classes . '>
				<th>
					' . $title . '
					' . $desc . '
				</th>
				<td>
					' . $content . '
				</td>
			</tr>';
		$return = ob_get_contents();
		ob_end_clean();
		if ( $echo ) {
			echo $return;
		} else {
			return $return;
		}
	}

	public function render_condition_row( $condition, $index ) {

		$condition = (array) $condition;

		$hidden = isset( $condition['hidden'] ) && $condition['hidden'] == 'true' ? 'hidden' : '';

		$title              = isset( $condition['title'] ) ? $condition['title'] : __( 'Title', 'contentsync' );
		$selected_blog      = isset( $condition['blog_id'] ) ? $condition['blog_id'] : null;
		$selected_post_type = isset( $condition['post_type'] ) ? $condition['post_type'] : null;
		$selected_taxonomy  = isset( $condition['taxonomy'] ) ? $condition['taxonomy'] : null;
		$selected_terms     = isset( $condition['terms'] ) ? $condition['terms'] : null;

		$blog_title = '–';
		if ( $selected_blog ) {
			$blog_title = is_multisite() ? get_blog_details( $selected_blog )->blogname : get_bloginfo( 'name' );
		}

		$condition_id = isset( $condition['ID'] ) ? $condition['ID'] : 'new';

		if ( $hidden ) {
			$condition_id = 'hidden';
		}

		echo "<div class='row_container " . $hidden . "'>";

			echo "<ul class='tr'>
			<li class='td sortable_handle'>
				<span class='dashicons dashicons-menu'></span>
			</li>
			<!--<li class='td'>
				<a href='javascript:void(0)' class='edit_field field_label'><span class='dyn_field_label'>{$title}</span><span class='required-star'>&nbsp;*</span></a>
			</li>-->
			<li class='td'>
				<span class='dyn_field_type'>" . $blog_title . "</span>
			</li>
			<li class='td'>
				<span class='dyn_field_type'>" . ( isset( $selected_post_type ) ? $selected_post_type : '–' ) . "</span>
			</li>
			<li class='td'>
				<span class='button button-secondary edit_condition'><span class='dashicons dashicons-edit resiz'></span>&nbsp;" . __( 'edit', 'contentsync' ) . '</span>
				<span class="button button-danger delete_condition" title="' . __( 'delete', 'contentsync' ) . '"><span class="dashicons dashicons-trash resize"></span></span>
			</li>
			</ul>';

		// sub container
		$inner_rows = array();

		// /**
		// * TITLE ROW
		// */
		// $inner_rows[] = [
		// "title" => __("Title", 'contentsync'),
		// "content" =>
		// '<div class="flex input_section">
		// <div>
		// <label class="required">'.__('Title', 'contentsync').'<span class="required-star">&nbsp;*</span></label><br>
		// <input type="text" name="conditions['.$index.'][title]" value="'.$condition["title"].'">
		// </div>
		// </div>'
		// ];

		/**
		 * MANAGE ALL THE DATA
		 * TODO: maybe move this to a helper function
		 */

		// get blogs for the select
		$blog_options = '';
		$blogs        = \Contentsync\Connections\Connections_Helper::get_basic_blogs();
		foreach ( $blogs as $blog ) {

			// build blogoptions
			$selected = $blog['blog_id'] == $selected_blog ? 'selected' : '';
			if ( isset( $blog['attributes']['staging']['is_stage'] ) && $blog['attributes']['staging']['is_stage'] ) {
				$blog_options .= '<option value="' . $blog['blog_id'] . '" ' . $selected . '>' . $blog['name'] . ' [STAGING]</option>';
			} else {
				$blog_options .= '<option value="' . $blog['blog_id'] . '" ' . $selected . '>' . $blog['name'] . '</option>';
			}
		}
		$data = $this->get_stage_posttype_data();

		/**
		 * BLOG ROW
		 */
		$inner_rows[] = array(
			'title'   => __( 'Source site', 'contentsync' ),
			// "desc"    => __("From which site should the posts be included?", 'contentsync'),
			'content' => '<div class="flex input_section blog_select_wrapper" data-all-blogdata="' . esc_attr( json_encode( $data ) ) . '">
					<div>
						<label class="required">' . __( 'Select Site', 'contentsync' ) . '<span class="required-star">&nbsp;*</span></label><br>
						<select class="cluster_blog_select" name="conditions[' . $index . '][blog_id]" value="' . $selected_blog . '">
							' . $blog_options . '
						</select>
					</div>
				</div>',
		);

		/**
		 * POST TYPE ROW
		 */

		$post_type_options  = '';
		$post_type_messages = array(
			'not_global'   => sprintf( __( 'Attention: Post Type "%s" needs to be set as global!', 'contentsync' ), '__pt__' ),
			'no_condition' => sprintf( __( 'Add condition with source site "%s" and post type "Dynamic Posttypes".', 'contentsync' ), '__blog__' ),
			'not_all'      => sprintf( __( 'In the condition with "Dynamic Posttypes", select "Make posts global automatically" or set it global manually on the source site "%s".', 'contentsync' ), '__blog__' ),
		);

		// select correct data source
		$post_type_source = isset( $data[ $selected_blog ] ) ? $data[ $selected_blog ]['post_types'] : array_shift( $data )['post_types'];

		foreach ( $post_type_source as $post_type => $post_type_data ) {
			// check if post type is selected
			$selected = $post_type == $selected_post_type ? 'selected' : '';
			// Create select options
			$post_type_options .= '<option value="' . $post_type . '" ' . $selected . '>' . $post_type_data['title'] . '</option>';
		}

		$inner_rows[] = array(
			'title'   => __( 'Post Type', 'contentsync' ),
			'content' => '<div class="flex input_section posttype_select_wrapper">
					<div>
						<label class="required">' . __( 'Select Post Type', 'contentsync' ) . '<span class="required-star">&nbsp;*</span></label><br>
						<select class="cluster_post_type_select" name="conditions[' . $index . '][post_type]" value="' . $selected_post_type . '">
							' . $post_type_options . '
						</select>
						<p class="notice notice-warning cluster_post_type_not_global" style="margin:15px 0 0; padding: 6px 12px; display: none;" data-messages="' . esc_attr( json_encode( $post_type_messages ) ) . '"></p>
					</div>
				</div>',
		);

		/**
		 * MAKE POSTS GLOBAL AUTOMATICALLY
		 */
		$make_posts_globally_checked = isset( $condition['make_posts_global_automatically'] ) && $condition['make_posts_global_automatically'] == 'true' ? 'checked' : '';

		$inner_rows[] = array(
			'title'   => __( 'Behaviour', 'contentsync' ),
			'content' => "<div class='flex input_section'>" .
					"<div data-hide='type=hr,space'>" .
						"<label for='conditions[" . $index . "][make_posts_global_automatically]' style='white-space:nowrap;display:flex;margin-bottom:0.5em'>" .
							"<input type='checkbox' {$make_posts_globally_checked} name='conditions[" . $index . "][make_posts_global_automatically]' id='conditions[" . $index . "][make_posts_global_automatically]' class='make_posts_global_automatically' value='true' autocomplete='off'>" .
							'<div>' .
								'<strong>' . __( 'Make posts global automatically?', 'contentsync' ) . '</strong>' .
							'</div>' .
						'</label>
					</div>
				</div>' .
				'<p>' . __( 'When selected, all posts that are included in this cluster will be made global automatically as soon as they are published.', 'contentsync' ) .
				__( 'If not, only posts that are made global manually will be included in this cluster.', 'contentsync' ) . '</p>',
		);

		/**
		 * TAXONOMY & TERMS ROW
		 */
		$taxonomies_options = '<option value="">' . __( 'Select Taxonomy', 'contentsync' ) . '</option>';
		if ( isset( $data[ $selected_blog ]['post_types'][ $selected_post_type ]['taxonomies'] ) ) {
			foreach ( $data[ $selected_blog ]['post_types'][ $selected_post_type ]['taxonomies'] as $tax_name => $taxonomy ) {
				$taxonomies_options .= '<option value="' . $tax_name . '" ' . ( $tax_name == $selected_taxonomy ? 'selected' : '' ) . '>' . $tax_name . '</option>';
			}
		}

		$single = __( 'Terms', 'contentsync' );
		$terms  = array();

		// get terms depending on the selected taxonomy in data array
		$terms = array();
		if ( isset( $data[ $selected_blog ]['post_types'][ $selected_post_type ]['taxonomies'][ $selected_taxonomy ]['terms'] ) ) {
			$terms[ $single ] = $data[ $selected_blog ]['post_types'][ $selected_post_type ]['taxonomies'][ $selected_taxonomy ]['terms'];
		}

		$inner_rows[] = array(
			'title'   => __( 'Filter by taxonomy', 'contentsync' ),
			'content' => '<div>
					<div class="flex input_section taxonomy_select_wrapper">' .
						'<div>' .
							'<label>' . __( 'Taxonomy', 'contentsync' ) . '</label><br>
							<select class="taxonomy_select" name="conditions[' . $index . '][taxonomy]" value="' . $selected_taxonomy . '">
								' . $taxonomies_options . '
							</select>' .
						'</div>
					</div>
					<div class="flex input_section settings_input terms_input_wrapper" style="position: relative; width: 100%;">' .
						$this->render_autotag_select(
							array(
								'name'     => 'conditions[' . $index . '][terms]',
								'selected' => $selected_terms,
								'values'   => $terms,
								'default'  => '',
							)
						) . '
					</div>' .
				'</div>',
		);

		/**
		 * FILTER: COUNT
		 */
		$count = isset( $condition['filter'][0]['count'] ) ? $condition['filter'][0]['count'] : '';

		$inner_rows[] = array(
			'title'   => __( 'Filter number of posts', 'contentsync' ),
			'content' => "<div class='flex input_section'>
					<div>
						<label class='required'>" . __( 'How many posts should be made global?', 'contentsync' ) . "</label><br>
						<input type='number' name='conditions[" . $index . "][filter][0][count]' value={$count}>" .
					'</div>
				</div>',
		);

		$filter = isset( $condition['filter'] ) ? $condition['filter'] : array();

		/**
		 * FILTER: DATE
		 */

		// define it as filter so it becomes scalable if we want to add multiple filters
		// with for example logical conditions without changing the database structure

		$date_mode        = isset( $filter[1]['date_mode'] ) ? $filter[1]['date_mode'] : '';
		$date_since       = isset( $filter[1]['date_since'] ) ? $filter[1]['date_since'] : '';
		$date_value       = isset( $filter[1]['date_value'] ) ? $filter[1]['date_value'] : '';
		$date_value_from  = isset( $filter[1]['date_value_from'] ) ? $filter[1]['date_value_from'] : '';
		$date_value_to    = isset( $filter[1]['date_value_to'] ) ? $filter[1]['date_value_to'] : '';
		$date_since_value = isset( $filter[1]['date_since_value'] ) ? $filter[1]['date_since_value'] : '';

		$date_mode_static_checked       = $date_mode == 'static' ? 'checked' : '';
		$date_mode_static_range_checked = $date_mode == 'static_range' ? 'checked' : '';
		$date_mode_dynamic_checked      = $date_mode == 'dynamic' ? 'checked' : '';

		$date_since_options  = '';
		$date_since_options .= "<label><input name='conditions[" . $index . "][filter][1][date_since]' value='day' type='radio' " . ( $date_since == 'day' ? 'checked' : '' ) . '>' . __( 'Day(s)', 'contentsync' ) . '&nbsp;</label>';
		$date_since_options .= "<label><input name='conditions[" . $index . "][filter][1][date_since]' value='week' type='radio' " . ( $date_since == 'week' ? 'checked' : '' ) . '>' . __( 'Week(s)', 'contentsync' ) . '&nbsp;</label>';
		$date_since_options .= "<label><input name='conditions[" . $index . "][filter][1][date_since]' value='month' type='radio' " . ( $date_since == 'month' ? 'checked' : '' ) . '>' . __( 'Month(s)', 'contentsync' ) . '&nbsp;</label>';

		// default to date mode static if not set
		$date_mode_off = '';
		if ( empty( $date_mode ) || $date_mode != 'dynamic' ) {
			$date_mode_off = 'checked';
		}

		$inner_rows[] = array(
			'title'   => __( 'Filter by date', 'contentsync' ),
			'content' => "<form><div class='filter_section' data-mode='date'>
							<div class='flex input_section'>
								<fieldset name='conditions[" . $index . "][filter][1][date_mode]' class='date_mode'>
									<label><input name='conditions[" . $index . "][filter][1][date_mode]' value='' type='radio' " . $date_mode_off . '>' . __( 'Do not filter by date', 'contentsync' ) . "</label>
									<br><label><input name='conditions[" . $index . "][filter][1][date_mode]' value='static' type='radio' " . $date_mode_static_checked . '>' . __( 'Since a single date', 'contentsync' ) . "</label>
									<br><label><input name='conditions[" . $index . "][filter][1][date_mode]' value='static_range' type='radio' " . $date_mode_static_range_checked . '>' . __( 'Between two dates', 'contentsync' ) . "</label>
									<br><label><input name='conditions[" . $index . "][filter][1][date_mode]' value='dynamic' type='radio' " . $date_mode_dynamic_checked . '>' . __( 'From today backwards', 'contentsync' ) . "</label>
								</fieldset>
							</div>
						<div class='flex input_section hidden' data-date-mode='static'>
							<label>" . __( 'All posts after the following date:', 'contentsync' ) . "
								<input type='date' name='conditions[" . $index . "][filter][1][date_value]' placeholder='' value='{$date_value}'>
							</label>
						</div>
						<div class='flex input_section hidden' data-date-mode='static_range'>
							<label>" . __( 'All posts between the following dates:', 'contentsync' ) . "
								<input type='date' name='conditions[" . $index . "][filter][1][date_value_from]' placeholder='' value='{$date_value_from}'>
								<input type='date' name='conditions[" . $index . "][filter][1][date_value_to]' placeholder='' value='{$date_value_to}'>
							</label>
						</div>
						<div class='flex flex-center input_section hidden' data-date-mode='dynamic'>" .
							__( 'Posts of the last', 'contentsync' ) . '&nbsp;&nbsp;' .
							"<input type='number' name='conditions[" . $index . "][filter][1][date_since_value]' placeholder='X' value={$date_since_value}>" .
							"<fieldset name='conditions[" . $index . "][filter][1][date_since]' class='date_since'>
								" . $date_since_options . '
							</fieldset>' .
						'</div>' .
					'</div></form>',
		);

		/**
		 * Another row with checkboxes for "export_arguments"
		 *
		 * @var array
		 *    @property bool  append_nested   Append nested posts to the export.
		 *    @property bool  whole_posttype  Export the whole post type.
		 *    @property bool  all_terms       Export all terms of the post.
		 *    @property bool  resolve_menus   Resolve navigation links to custom links.
		 *    @property bool  translations    Include translations of the post.
		 *    @property array query_args      Additional query arguments.
		 */
		$export_arguments = isset( $condition['export_arguments'] ) ? $condition['export_arguments'] : array(
			'append_nested'  => true,
			'whole_posttype' => false,
			'all_terms'      => true,
			'resolve_menus'  => true,
			'translations'   => true,
		);
		$export_arguments = wp_parse_args(
			$export_arguments,
			array(
				'append_nested'  => false,
				'whole_posttype' => false,
				'all_terms'      => false,
				'resolve_menus'  => false,
				'translations'   => false,
			)
		);
		$inner_rows[]     = array(
			'title'   => __( 'Export Details', 'contentsync' ),
			'content' => "<div class='input_section'>
					<div>
						<label><input type='checkbox' name='conditions[" . $index . "][export_arguments][append_nested]' " . ( isset( $export_arguments['append_nested'] ) && $export_arguments['append_nested'] ? 'checked' : '' ) . '>' . __( 'Append nested posts to the export', 'contentsync' ) . "</label>
					</div>
					<div>
						<label><input type='checkbox' name='conditions[" . $index . "][export_arguments][whole_posttype]' " . ( isset( $export_arguments['whole_posttype'] ) && $export_arguments['whole_posttype'] ? 'checked' : '' ) . '>' . __( 'Export the whole post type', 'contentsync' ) . "</label>
					</div>
					<div>
						<label><input type='checkbox' name='conditions[" . $index . "][export_arguments][all_terms]' " . ( isset( $export_arguments['all_terms'] ) && $export_arguments['all_terms'] ? 'checked' : '' ) . '>' . __( 'Export all terms of the post', 'contentsync' ) . "</label>
					</div>
					<div>
						<label><input type='checkbox' name='conditions[" . $index . "][export_arguments][resolve_menus]' " . ( isset( $export_arguments['resolve_menus'] ) && $export_arguments['resolve_menus'] ? 'checked' : '' ) . '>' . __( 'Resolve navigation links to custom links', 'contentsync' ) . "</label>
					</div>
					<div>
						<label><input type='checkbox' name='conditions[" . $index . "][export_arguments][translations]' " . ( isset( $export_arguments['translations'] ) && $export_arguments['translations'] ? 'checked' : '' ) . '>' . __( 'Include translations of the post', 'contentsync' ) . '</label>
					</div>
				</div>',
		);

		/**
		 * FILTER: Render the rows
		 */
		echo ' <div class="sub" data-num="' . $index . '">
		<table class="contentsync_table cluster_edit_table inner_table vertical">';
			echo "<input type='hidden' name='conditions[" . $index . "][ID]' value='" . $condition_id . "'>";
		foreach ( $inner_rows as $row ) {
			self::render_table_row( $row );
		}
		echo '</table>
		</div>';

		echo '</div>';
	}

	public function render_autotag_select( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'name'     => '',
				'selected' => '',
				'values'   => array(),
				'default'  => '',
			)
		);

		$selected = isset( $args['selected'] ) ? $args['selected'] : $args['default'];

		if ( is_array( $selected ) ) {
			$selected = implode( ',', $selected );
		}

		$options  = "<div class='settings_input_option autotags'>";
		$options .= "<input type='hidden' name='" . $args['name'] . "' class='settings_input_option_value' value='" . $selected . "' data-tags='" . json_encode( $args['values'] ) . "'  autocomplete='off'>";
		$options .= '</div>';

		return $options;
	}

	/**
	 * ***************************************************************
	 * Overlay for deleting clusters
	 * ***************************************************************
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

		// check if the screen is cluster table screen
		if ( ! $this->is_global_content_screen() ) {
			return $contents;
		}

		$delete_options_form = "<form id='contentsync_cluster_deletion_form'>
			<input type='hidden' name='_wpnonce' value='" . wp_create_nonce( 'contentsync_delete_cluster' ) . "'>
			<fieldset>
				<label for='retain_post_connections'>
					<input type='radio' name='contentsync_cluster_deletion_mode' id='retain_post_connections' value='retain_post_connections' checked>
					" . __( 'Retain post connections', 'contentsync' ) . '</br>
					<small>' . __( 'All content is left as it is and the posts remain linked.', 'contentsync' ) . "</small>
				</label>   
			

				<label for='make_posts_static'>
					<input type='radio' name='contentsync_cluster_deletion_mode' id='make_posts_static' value='make_posts_static' >
					" . __( 'Make posts static', 'contentsync' ) . '</br>
					<small>' . __( 'All post connections are deleted, but the posts themselves are retained on all instances.', 'contentsync' ) . "</small>
				</label>   
	

				<label for='delete_connected_posts'>
					<input type='radio' name='contentsync_cluster_deletion_mode' id='delete_connected_posts' value='delete_connected_posts' >
					" . __( 'Delete connected posts', 'contentsync' ) . '</br>
					<small>' . __( 'The contents of this cluster are deleted on all instances. Only the root posts remain available.', 'contentsync' ) . '</small>
				</label>   

			</fieldset>
		</form>';

		// add the contents
		$contents['contentsync_delete_cluster'] = array(
			'confirm' => array(
				'title'        => __( 'Delete Cluster', 'contentsync' ),
				'descr'        => sprintf( __( 'Do you want to delete the cluster "%s"?', 'contentsync' ), "<b class='replace'></b>" ),
				'content'      => $delete_options_form,
				'button'       => __( 'delete', 'contentsync' ),
				'button_class' => 'danger',
			),
			'loading' => array(
				'descr' => __( 'Deleting Cluster', 'contentsync' ),
			),
			'reload'  => array(
				'title' => __( 'Deletion of cluster was successful', 'contentsync' ),
				'descr' => __( 'Cluster has been deleted.', 'contentsync' ),
			),
			'fail'    => array(
				'title' => __( 'Deletion of cluster has failed', 'contentsync' ),
				'descr' => '<span class="replace">' . __( 'The cluster could not be deleted.', 'contentsync' ) . '</span>',
			),
		);

		return $contents;
	}

	public function delete_cluster() {

		// if ( ! is_super_admin() ) {
		// return;
		// }

		// json decode data
		$data       = json_decode( stripslashes( $_POST['data'] ), true );
		$cluster_id = isset( $data['id'] ) ? $data['id'] : null;
		$mode       = isset( $data['mode'] ) ? $data['mode'] : null;

		if ( ! $cluster_id ) {
			wp_send_json_error( array( 'message' => 'No cluster ID provided' ), 400 );
		}

		if ( ! $mode ) {
			wp_send_json_error( array( 'message' => 'No mode provided' ), 400 );
		}

		// delete cluster
		switch ( $mode ) {
			case 'retain_post_connections':
				$cluster = get_cluster_by_id( $cluster_id );

				// delete all conditions
				foreach ( $cluster->content_conditions as $condition ) {
					delete_cluster_content_condition( $condition->ID );
				}

				$result = delete_cluster( $cluster_id );

				if ( ! $result ) {
					wp_send_json_error( array( 'message' => 'Could not delete cluster' ), 400 );
				} else {
					wp_send_json_success( array(), 200 );
				}
				break;
			case 'make_posts_static':
				$cluster       = get_cluster_by_id( $cluster_id );
				$cluster_posts = get_cluster_posts_per_blog( $cluster );
				foreach ( $cluster_posts as $blog_id => $posts ) {
					\Contentsync\switch_blog( $blog_id );
					foreach ( $posts as $post ) {
						// make post static
						$gid = Main_Helper::get_gid( $post->ID );
						if ( $gid ) {
							$result = \Contentsync\unlink_synced_root_post( $gid );
						}
					}
					\Contentsync\restore_blog();
				}

				// delete all conditions
				foreach ( $cluster->content_conditions as $condition ) {
					delete_cluster_content_condition( $condition->ID );
				}

				$result = delete_cluster( $cluster_id );
				if ( ! $result ) {
					wp_send_json_error( array( 'message' => 'Could not delete cluster' ), 400 );
				}

				wp_send_json_success( array(), 200 );

				break;
			case 'delete_connected_posts':
				// delete all connections and posts

				$cluster = get_cluster_by_id( $cluster_id );

				// format destinations
				$destination_arrays = array();
				foreach ( $cluster->destination_ids as $destination_id ) {
					if ( empty( $destination_id ) ) {
						continue;
					}
					$destination_arrays[ $destination_id ] = array(
						'import_action' => 'delete',
					);
				}

				$cluster_posts = get_cluster_posts_per_blog( $cluster );

				/**
				 * Distribute posts to all destinations, step by step per blog.
				 */
				$result = \Contentsync\distribute_posts_per_blog( $cluster_posts, $destination_arrays );

				// delete all conditions
				foreach ( $cluster->content_conditions as $condition ) {
					delete_cluster_content_condition( $condition->ID );
				}

				$result = delete_cluster( $cluster_id );

				if ( ! $result ) {
					wp_send_json_error( array( 'message' => 'Could not delete cluster' ), 400 );
				}

				wp_send_json_success( $result, 200 );
		}
	}

	/**
	 * ***************************************************************
	 * Wizard for creating a new cluster (could maybe be moved to a separate file)
	 * ***************************************************************
	 */
	public function render_wizard() {

		$asset_url = CONTENTSYNC_PLUGIN_URL . '/assets/icon';

		echo '
		<div id="greyd-wizard" class="wizard cluster_wizard">
			<div class="wizard_box">
				<div class="wizard_head">
					<img class="wizard_icon" src="' . $asset_url . '/logo_light.svg">
					<span class="close_wizard dashicons dashicons-no-alt"></span>
				</div>
				<div class="wizard_content">
					<div data-slide="0-error">
						<h2>' . __( 'Ooooops!', 'contentsync' ) . '</h2>
						<div class="contentsync_info_box orange" style="width: 100%; box-sizing: border-box;">
							<span class="dashicons dashicons-warning"></span>
							<div>
								<p>' . __( 'There was a problem:', 'contentsync' ) . '</p><br>
								<p class="error_msg"></p>
							</div>
						</div>
					</div>

					<div data-slide="1">

						<!--<h2>' . __( 'What do you want to do?', 'contentsync' ) . '</h2>-->
						<br><br>

						<div class="">
							<h3>' . __( 'Create a new cluster.', 'contentsync' ) . '</h3>
							<div class="row flex">
								<div class="element grow">
									<div class="label">' . __( 'Name', 'contentsync' ) . '</div>
									<input id="create_cluster" name="create_cluster" type="text" value="" placeholder="' . __( 'enter here', 'contentsync' ) . '" autocomplete="off" >
								</div>
							</div>
						</div>

					</div>

					<div data-slide="11">
						<h2>' . __( 'Congratulations!', 'contentsync' ) . '</h2>
						<div class="install_txt">' . __( 'You can now edit the cluster', 'contentsync' ) . '</div>
						<div class="success_mark"><div class="checkmark"></div></div>
					</div>
				</div>


				<div class="wizard_foot">
					<div data-slide="0-error">
						<div class="flex">
							<span class="close_wizard reload button button-primary">' . __( 'close', 'contentsync' ) . '</span>
						</div>
					</div>
					<div data-slide="1">
						<div class="flex">
							<span class="create button button-primary">' . sprintf( __( 'Create %s', 'contentsync' ), __( 'Cluster', 'contentsync' ) ) . '</span>
						</div>
					</div>
					<div data-slide="11">
						<div class="flex">
							<span class="close_wizard reload button button-secondary">' . __( 'close', 'contentsync' ) . '</span>
							<a class="finish_wizard button button-primary" href="">' . __( 'open', 'contentsync' ) . '</a>
						</div>
					</div>
				</div>
			</div>
		</div>';
	}

	public function create_cluster() {

		$json = str_replace( '\"', '"', $_POST['data'] );
		$data = json_decode( preg_replace( '/[\x00-\x1F\x80-\xFF]/', '', $json ), true );

		if ( json_last_error() ) {
			wp_send_json_error( array( 'message' => 'Invalid JSON provided' ), 400 );
		}

		if ( ! isset( $data['title'] ) || empty( $data['title'] ) ) {
			wp_send_json_error( array( 'message' => 'Please enter a title' ), 400 );
		}
		// $result = false;
		$cluster_id = insert_cluster(
			array(
				'title' => $data['title'],
			)
		);

		$result = array(
			'cluster_id' => $cluster_id,
			'message'    => 'Cluster created!',
		);

		if ( ! $cluster_id ) {
			wp_send_json_error( array( 'message' => 'Could not create cluster' ), 400 );
		} else {
			wp_send_json_success( $result, 200 );
		}
	}

	public function is_global_content_screen() {

		$screen = get_current_screen();
		return $screen->base == 'global-content_page_contentsync_clusters-network' || $screen->base == 'global-content_page_contentsync_clusters';
	}

	public function add_wizard() {

		if ( ! $this->is_global_content_screen() ) {
			return;
		}

		// render the wizard
		$this->render_wizard();
	}


	/**
	 * ***************************************************************
	 * Post Reviews Admin Page
	 * ***************************************************************
	 */

	/**
	 * Add a menu item to the WordPress admin menu
	 */
	function add_post_reviews_admin_page() {

		$hook = add_submenu_page(
			'global_contents',
			__( 'Post Reviews', 'contentsync' ), // page title
			__( 'Post Reviews', 'contentsync' ), // menu title
			'manage_options',
			'contentsync-post-reviews',
			array( $this, 'render_post_reviews_admin_page' )
		);

		add_action( "load-$hook", array( $this, 'post_reviews_add_screen_options' ) );
	}

	/**
	 * Set screen options for the admin pages
	 */
	public function post_reviews_add_screen_options() {

		$args = array(
			'label'   => __( 'Post Reviews per page:', 'contentsync' ),
			'default' => 20,
			'option'  => 'post_reviews_per_page',
		);

		add_screen_option( 'per_page', $args );

		if ( ! class_exists( 'Post_Review_List_Table' ) ) {
			require_once __DIR__ . '/class-post-review-list-table.php';
		}

		$this->Post_Review_List_Table = new Post_Review_List_Table();
	}

	/**
	 * Save the admin screen option
	 */
	public function post_reviews_save_screen_options( $status, $option, $value ) {

		if ( 'post_reviews_per_page' == $option ) {
			return $value;
		}

		return $status;
	}

	/**
	 * Display the custom admin list page
	 */
	public function render_post_reviews_admin_page() {

		if ( ! class_exists( 'Post_Review_List_Table' ) ) {
			require_once __DIR__ . '/class-post-review-list-table.php';
		}

		if ( ! $this->Post_Review_List_Table ) {
			$this->Post_Review_List_Table = new Post_Review_List_Table();
		}

		$this->Post_Review_List_Table->prepare_items();
		$this->Post_Review_List_Table->render();
	}

	/**
	 * Get the stage posttype data
	 */
	public function get_stage_posttype_data() {

		// get cache
		// if ( get_transient('contentsync_stage_posttype_data') ) {
		// return get_transient('contentsync_stage_posttype_data');
		// }

		$data = array();

		// core posttypes - they are the same on each blog
		$core_posttypes = array(
			'page'             => __( 'Pages', 'contentsync' ),
			'post'             => __( 'Posts', 'contentsync' ),
			'attachment'       => __( 'Attachments', 'contentsync' ),
			'wp_template'      => __( 'WP Templates', 'contentsync' ),
			'wp_template_part' => __( 'Template Parts', 'contentsync' ),
			'wp_block'         => __( 'Blocks', 'contentsync' ),
			'wp_navigation'    => __( 'WP Navigations', 'contentsync' ),
		);

		$blogs = \Contentsync\Connections\Connections_Helper::get_basic_blogs();

		foreach ( $blogs as $blog ) {
			$blog_id = $blog['blog_id'];

			\Contentsync\switch_blog( $blog_id );

			// merge core posttypes with dynamic and greyd posttypes
			$posttypes = array_merge( $core_posttypes );

			/**
			 * Check if polylang is active on the blog, but function pll_get_language_code is not available.
			 * this happens when the plugin is not active on the main blog, but on a sub-blog.
			 *
			 * @since 2.18.0
			 */
			$translation_tool = Translation_Manager::get_translation_tool();
			if ( $translation_tool == 'polylang' && ! function_exists( 'pll_get_language_code' ) ) {
				$polylang_options     = get_option( 'polylang', array() );
				$translated_posttypes = array_values(
					wp_parse_args(
						isset( $polylang_options['post_types'] ) ? $polylang_options['post_types'] : array(),
						array( 'post', 'page', 'wp_block' ) // default polylang posttypes
					)
				);
			}

			// add merged posttype array
			$data[ $blog_id ]['post_types'] = array();

			foreach ( $posttypes as $post_type => $post_type_title ) {
				if ( ! is_string( $post_type ) ) {
					continue;
				}

				$data[ $blog_id ]['post_types'][ $post_type ]['slug']  = $post_type;
				$data[ $blog_id ]['post_types'][ $post_type ]['title'] = $post_type_title;

				$data[ $blog_id ]['post_types'][ $post_type ]['slug']  = $post_type;
				$data[ $blog_id ]['post_types'][ $post_type ]['title'] = $post_type_title;

				/**
				 * Taxonomies
				 */
				$taxonomies = array();

				$object_taxonomies = get_object_taxonomies( $post_type, 'objects' );
				if ( $object_taxonomies && is_array( $object_taxonomies ) ) {
					foreach ( $object_taxonomies as $taxonomy_slug => $taxonomy ) {
						$taxonomies[ $taxonomy_slug ] = $taxonomy->name;
					}
				}

				// try to register language taxonomy from polylang
				if ( isset( $translated_posttypes ) && in_array( $post_type, $translated_posttypes ) ) {
					register_taxonomy( 'language', $post_type );
					$taxonomies['language'] = 'language';
				}

				/**
				 * Terms
				 */
				foreach ( $taxonomies as $taxonomy_slug ) {
					$terms = get_terms(
						array(
							'taxonomy'         => $taxonomy_slug,
							'hide_empty'       => false,
							'suppress_filters' => true,
							'lang'             => '',
						)
					);
					// debug($terms);
					if ( is_wp_error( $terms ) ) {
						continue;
					}
					$new_terms = array();
					foreach ( $terms as $term ) {
						$new_terms[ $term->slug ] = $term->name;
					}

					$data[ $blog_id ]['post_types'][ $post_type ]['taxonomies'][ $taxonomy_slug ]['terms'] = $new_terms;
				}
			}

			\Contentsync\restore_blog();
		}

		// debug($data);

		// set cache (3 minutes
		delete_transient( 'contentsync_stage_posttype_data' );
		// set_transient('contentsync_stage_posttype_data', $data, 180);

		return $data;
	}
}
