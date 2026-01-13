<?php
/**
 *
 */
namespace Contentsync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

new Theme_Posts_Admin();

class Theme_Posts_Admin {

	/**
	 * The Theme_Posts_List_Table instance.
	 */
	public $Theme_Posts_List_Table = null;

	/**
	 * Constructor.
	 */
	public function __construct() {

		add_action( 'admin_menu', array( $this, 'add_theme_export_admin_page' ) );
		add_filter( 'set-screen-option', array( $this, 'save_screen_options' ), 10, 3 );

		add_filter( 'synced_post_export_is_current_screen_supported', array( $this, 'support_post_export_on_theme_export_admin_page' ), 10, 2 );

		// add overlay contents
		add_filter( 'contentsync_overlay_contents', array( $this, 'add_overlay_contents' ) );

		// ajax
		add_action( 'contentsync_ajax_mode_switch_template_theme', array( $this, 'handle_switch_template_theme' ) );
		add_action( 'contentsync_ajax_mode_switch_global_styles', array( $this, 'handle_switch_global_styles' ) );
		add_action( 'contentsync_ajax_mode_rename_template', array( $this, 'handle_rename_template' ) );
	}

	/**
	 * Add a menu item to the WordPress admin menu
	 */
	function add_theme_export_admin_page() {

		// Only add the menu item if the current theme supports blocks
		if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return;
		}

		$hook = add_theme_page(
			__( 'Theme Assets', 'contentsync_hub' ), // page title
			__( 'Theme Assets', 'contentsync_hub' ), // menu title
			'manage_options',
			'theme-posts',
			array( $this, 'render_theme_export_admin_page' )
		);

		add_action( "load-$hook", array( $this, 'add_screen_options' ) );
	}

	/**
	 * Set screen options for the admin pages
	 */
	public function add_screen_options() {
		$args = array(
			'label'   => __( 'Posts per page:', 'contentsync_hub' ),
			'default' => 20,
			'option'  => 'theme_posts_per_page',
		);

		add_screen_option( 'per_page', $args );

		if ( ! class_exists( 'Theme_Posts_List_Table' ) ) {
			require_once __DIR__ . '/class-theme-posts-list-table.php';
		}

		$this->Theme_Posts_List_Table = new Theme_Posts_List_Table();
	}

	/**
	 * Save the admin screen option
	 */
	public function save_screen_options( $status, $option, $value ) {

		if ( 'theme_posts_per_page' == $option ) {
			return $value;
		}

		return $status;
	}

	/**
	 * Display the custom admin list page
	 */
	function render_theme_export_admin_page() {

		if ( ! class_exists( 'Theme_Posts_List_Table' ) ) {
			require_once __DIR__ . '/class-theme-posts-list-table.php';
		}

		if ( ! $this->Theme_Posts_List_Table ) {
			$this->Theme_Posts_List_Table = new Theme_Posts_List_Table();
		}

		$this->Theme_Posts_List_Table->prepare_items();
		$this->Theme_Posts_List_Table->render();
	}

	/**
	 * Add a filter to allow the post export to be run on the custom admin page
	 */
	function support_post_export_on_theme_export_admin_page( $is_supported, $screen ) {
		if ( $screen && is_object( $screen ) && isset( $screen->id ) && $screen->id === 'appearance_page_theme-posts' ) {
			$is_supported = true;
		}
		return $is_supported;
	}

	/**
	 * Add overlay contents
	 *
	 * @filter 'contentsync_overlay_contents'
	 *
	 * @param array $contents
	 * @return array $contents
	 */
	public function add_overlay_contents( $contents ) {

		// return if not on theme export admin page
		if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'theme-posts' ) {
			return $contents;
		}

		$contents['switch_template_theme'] = array(
			'confirm' => array(
				'title'   => __( 'Assign template to current theme', 'contentsync_hub' ),
				'descr'   => __( 'This will switch the template to the current theme. Be careful, this could lead to errors, because the template might not be designed for your theme or does already exist.', 'contentsync_hub' ),
				'content' => '<form id="switch_theme_form" class="inner_content">' .
									'<label for="switch_references_in_content">' .
										'<input type="checkbox" name="switch_references_in_content" id="switch_references_in_content" checked="checked" />' .
										'<span>' . __( 'Switch references in content', 'contentsync_hub' ) . '</span>' .
										'<small>' . __( 'All references to templates and template parts inside this post will be switched to your current theme. This way it uses the current templates, like your header or footer.', 'contentsync_hub' ) . '</small>' .
									'</label>' .
								'</form>',
				'button'  => __( 'Assign now', 'contentsync_hub' ),
			),
			'loading' => array(
				'descr' => __( 'Template is assigned to the current theme.', 'contentsync_hub' ),
			),
			'reload'  => array(
				'title' => __( 'Switch successful', 'contentsync_hub' ),
				'descr' => __( 'Template was assigned to the current theme.', 'contentsync_hub' ),
			),
			'fail'    => array(
				'title' => __( 'Switch failed.', 'contentsync_hub' ),
				'descr' => __( 'Template could not be assigned to the current theme.', 'contentsync_hub' ),
			),
		);

		$contents['switch_global_styles'] = array(
			'confirm' => array(
				'title'   => __( 'Assign styles to current theme', 'contentsync_hub' ),
				'descr'   => sprintf(
					__( 'This will assign the styles from the theme %s to your current theme. Be careful, this could lead to errors, because the styles might not be compatible with your theme.', 'contentsync_hub' ),
					'<strong class="replace"></strong>'
				),
				'content' => Helper::render_info_box(
					array(
						'text'  => __( 'You are overwriting the global styles of your current theme. This cannot be made undone. Make sure to make a backup of the current styles beforehand.', 'contentsync_hub' ),
						'style' => 'warning',
					)
				),
				'button'  => __( 'Assign now', 'contentsync_hub' ),
			),
			'loading' => array(
				'descr' => __( 'Styles are assigned to the current theme.', 'contentsync_hub' ),
			),
			'reload'  => array(
				'title' => __( 'Switch successful', 'contentsync_hub' ),
				'descr' => __( 'Styles were assigned to the current theme.', 'contentsync_hub' ),
			),
			'fail'    => array(
				'title' => __( 'Switch failed.', 'contentsync_hub' ),
				'descr' => __( 'Styles could not be assigned to the current theme.', 'contentsync_hub' ),
			),
		);

		$contents['rename_template'] = array(
			'confirm' => array(
				'title'   => __( 'Rename template', 'contentsync_hub' ),
				'descr'   => __( 'This might lead to errors in your theme, because the template name is used to identify the template and assign it to a page, post or taxonomy.', 'contentsync_hub' ),
				'content' => '<form id="rename_template_form" class="inner_content">' .
									'<div style="margin-bottom:4px">' . __( 'New title', 'contentsync_hub' ) . '</div>' .
									'<input style="margin-bottom:14px;width:100%" type="text" name="new_post_title" id="new_post_title" value="" placeholder="' . __( 'New title', 'contentsync_hub' ) . '" />' .
									'<div style="margin-bottom:4px">' . __( 'New slug', 'contentsync_hub' ) . '</div>' .
									'<input style="margin-bottom:14px;width:100%" type="text" name="new_post_name" id="new_post_name" value="" placeholder="' . __( 'New slug', 'contentsync_hub' ) . '" />' .
								'</form>',
				'button'  => __( 'Confirm', 'contentsync_hub' ),
			),
			'loading' => array(
				'descr' => __( 'Template is renamed.', 'contentsync_hub' ),
			),
			'reload'  => array(
				'title' => __( 'Rename successful', 'contentsync_hub' ),
				'descr' => __( 'Template was renamed.', 'contentsync_hub' ),
			),
			'fail'    => array(
				'title' => __( 'Rename failed.', 'contentsync_hub' ),
				'descr' => __( 'Template could not be renamed.', 'contentsync_hub' ),
			),
		);

		return $contents;
	}

	/**
	 * Handle the ajax request to switch the theme of a template or template part
	 *
	 * @action 'contentsync_ajax_mode_switch_template_theme'
	 *
	 * @param array $data   holds the $_POST['data']
	 */
	public function handle_switch_template_theme( $data ) {

		\Contentsync\post_export_enable_logs();

		do_action( 'post_export_log', "\r\n\r\n" . 'HANDLE SWITCH TEMPLATE THEME' . "\r\n", $data );

		$post_id                      = isset( $data['post_id'] ) ? $data['post_id'] : '';
		$switch_references_in_content = isset( $data['switch_references_in_content'] ) ? $data['switch_references_in_content'] : '';

		if ( ! empty( $post_id ) ) {

			$post = get_post( $post_id );

			$result = \Contentsync\set_wp_template_theme( $post, $switch_references_in_content );

			if ( is_wp_error( $result ) ) {
				\Contentsync\post_export_return_error( $result->get_error_message() );
				return;
			}

			if ( $result ) {
				post_export_return_success( __( 'Template was assigned to the current theme.', 'contentsync_hub' ) );
				return;
			}

			\Contentsync\post_export_return_error( __( 'Template could not be assigned to the current theme.', 'contentsync_hub' ) );
		}
		\Contentsync\post_export_return_error( __( 'No valid post ID found.', 'contentsync_hub' ) );
	}

	/**
	 * Handle the ajax request to overwrite the global styles of the current theme
	 *
	 * @action 'contentsync_ajax_mode_switch_global_styles'
	 *
	 * @param array $data   holds the $_POST['data']
	 */
	public function handle_switch_global_styles( $data ) {

		\Contentsync\post_export_enable_logs();

		do_action( 'post_export_log', "\r\n\r\n" . 'HANDLE SWITCH GLOBAL STYLES' . "\r\n", $data );

		$post_id = isset( $data['post_id'] ) ? $data['post_id'] : '';

		if ( ! empty( $post_id ) ) {

			$post = get_post( $post_id );

			$result = \Contentsync\set_wp_global_styles_theme( $post );

			if ( is_wp_error( $result ) ) {
				\Contentsync\post_export_return_error( $result->get_error_message() );
				return;
			}

			if ( $result ) {
				post_export_return_success( __( 'Styles were assigned to the current theme.', 'contentsync_hub' ) );
				return;
			}

			\Contentsync\post_export_return_error( __( 'Styles could not be assigned to the current theme.', 'contentsync_hub' ) );
		}
		\Contentsync\post_export_return_error( __( 'No valid post ID found.', 'contentsync_hub' ) );
	}

	/**
	 * Handle the ajax request to rename a template or template part
	 *
	 * @action 'contentsync_ajax_mode_rename_template'
	 *
	 * @param array $data   holds the $_POST['data']
	 */
	public function handle_rename_template( $data ) {

		\Contentsync\post_export_enable_logs();

		do_action( 'post_export_log', "\r\n\r\n" . 'HANDLE RENAME TEMPLATE' . "\r\n", $data );

		$post_id    = isset( $data['post_id'] ) ? $data['post_id'] : '';
		$post_title = isset( $data['post_title'] ) ? $data['post_title'] : '';
		$post_name  = isset( $data['post_name'] ) ? $data['post_name'] : '';

		if ( ! empty( $post_id ) ) {

			$post = get_post( $post_id );

			$post->post_title = $post_title;
			$post->post_name  = $post_name;

			debug( $post );

			$result = wp_update_post( $post );

			if ( is_wp_error( $result ) ) {
				\Contentsync\post_export_return_error( $result->get_error_message() );
				return;
			}

			if ( $result ) {
				post_export_return_success( __( 'Template was renamed.', 'contentsync_hub' ) );
				return;
			}

			\Contentsync\post_export_return_error( __( 'Template could not be renamed.', 'contentsync_hub' ) );
		}
		\Contentsync\post_export_return_error( __( 'No valid post ID found.', 'contentsync_hub' ) );
	}
}
