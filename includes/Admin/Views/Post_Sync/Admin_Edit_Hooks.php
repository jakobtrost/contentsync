<?php

namespace Contentsync\Admin\Views\Post_Sync;

use Contentsync\Utils\Hooks_Base;
use Contentsync\Admin\Utils\Admin_Render;
use Contentsync\Post_Transfer\Post_Transfer_Service;
use Contentsync\Post_Sync\Post_Meta;
use Contentsync\Post_Sync\Synced_Post_Service;

defined( 'ABSPATH' ) || exit;

class Admin_Edit_Hooks extends Hooks_Base {

	public function register_admin() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_edit_assets' ) );

		add_action( 'admin_init', array( $this, 'add_edit_column' ) );
		add_action( 'admin_init', array( $this, 'add_bulk_actions' ) );

		add_filter( 'post_row_actions', array( $this, 'remove_row_actions_for_synced_posts' ), 10, 2 );
	}


	/**
	 * =================================================================
	 *                          ENQUEUE ASSETS
	 * =================================================================
	 */

	/**
	 * Enqueue admin edit assets
	 */
	public function enqueue_admin_edit_assets() {

		if ( ! Admin_Render::is_current_edit_screen_supported() ) {
			return;
		}

		// CSS
		wp_register_style(
			'contentsync-post-sync-admin-edit',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Views/Post_Sync/assets/css/admin-edit.css',
			array(),
			CONTENTSYNC_VERSION,
			'all'
		);
		wp_enqueue_style( 'contentsync-post-sync-admin-edit' );

		// JS
		wp_register_script(
			'contentSync-makeRoot',
			CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Views/Post_Sync/assets/js/contentSync.makeRoot.js',
			array( 'contentSync-tools', 'contentSync-Modal', 'contentSync-RestHandler', 'contentSync-SnackBar' ),
			CONTENTSYNC_VERSION,
			true
		);
		wp_enqueue_script( 'contentSync-makeRoot' );
	}


	/**
	 * =================================================================
	 *                          COLUMN
	 * =================================================================
	 */

	/**
	 * Add column to all supported post types edit.php screens
	 */
	public function add_edit_column() {
		foreach ( Post_Transfer_Service::get_supported_post_types() as $post_type ) {

			if ( $post_type === 'attachment' ) {
				add_filter( 'manage_media_columns', array( $this, 'add_synced_state_column' ) );
				add_action( 'manage_media_custom_column', array( $this, 'render_synced_state_column' ), 10, 2 );
			} else {
				add_filter( 'manage_' . $post_type . '_posts_columns', array( $this, 'add_synced_state_column' ) );
				add_action( 'manage_' . $post_type . '_posts_custom_column', array( $this, 'render_synced_state_column' ), 10, 2 );
			}
		}

		/**
		 * Add column to theme posts overview
		 */
		add_filter( 'contentsync_theme_posts_columns', array( $this, 'add_synced_state_column' ) );
		add_filter( 'contentsync_theme_posts_column_default', array( $this, 'render_synced_state_column' ), 10, 3 );
	}

	/**
	 * Add the synced state column to allow users to see the synced state of
	 * a post and convert it into a synced post.
	 *
	 * @param  array $columns
	 * @return array
	 */
	public function add_synced_state_column( $columns ) {

		// delete date column
		unset( $columns['date'] );

		// register custom column
		$columns['contentsync_status'] = Admin_Render::make_dashicon( 'admin-site-alt' );

		// re-insert date column after our column
		$columns['date'] = esc_html__( 'Date' );

		return $columns;
	}

	/**
	 * Render the synced state column to allow users to see the synced state of
	 * a post and convert it into a synced post.
	 *
	 * @param  string $column_name
	 * @param  int    $post_id
	 */
	public function render_synced_state_column( $column_name, $post_id ) {
		if ( 'contentsync_status' !== $column_name ) {
			return;
		}

		$status = get_post_meta( $post_id, 'synced_post_status', true );

		$posttype = get_post_type( $post_id );

		$supported_post_types = Post_Transfer_Service::get_supported_post_types();

		if ( ! in_array( $posttype, $supported_post_types ) ) {
			return;
		}

		// if the post is a synced post, render the status box
		if ( ! empty( $status ) ) {
			echo Admin_Render::make_admin_icon_status_box( $status );
			return;
		}

		// if the status is empty, the post is not a synced post
		// render a button to make it global
		if ( Synced_Post_Service::current_user_can_edit_synced_posts( 'root' ) ) {

			printf(
				'<button role="button" class="button button-tertiary contentsync-make-global-button" onclick="%2$s" data-title="%1$s">' .
					'<span class="dashicons dashicons-plus-alt2"></span>' .
				'</button>',
				__( 'Convert to synced post', 'contentsync' ),
				sprintf(
					'contentSync.makeRoot.openModal( %s, %s, this ); return false;',
					esc_attr( $post_id ),
					esc_html( get_the_title( $post_id ) )
				)
			);
		}
	}


	/**
	 * =================================================================
	 *                          BULK ACTIONS
	 * =================================================================
	 */

	/**
	 * Add bulk action to make posts global to the edit.php screen
	 */
	public function add_bulk_actions() {
		foreach ( Post_Transfer_Service::get_supported_post_types() as $post_type ) {
			add_filter( 'bulk_actions-edit-' . $post_type, array( $this, 'bulk_action_make_global' ) );
			add_filter( 'handle_bulk_actions-edit-' . $post_type, array( $this, 'handle_bulk_action_make_global' ), 10, 3 );
		}
	}

	public function bulk_action_make_global( $bulk_actions ) {
		if ( Synced_Post_Service::current_user_can_edit_synced_posts( 'root' ) ) {
			$bulk_actions['contentsync_make_posts_global'] = __( 'Content Sync: Make posts global', 'contentsync' );
		}
		return $bulk_actions;
	}

	public function handle_bulk_action_make_global( $redirect_to, $doaction, $post_ids ) {

		if ( $doaction !== 'contentsync_make_posts_global' ) {
			return $redirect_to;
		}

		$export_args = Post_Meta::get_default_export_options();
		foreach ( $post_ids as $post_id ) {
			$gid = Synced_Post_Service::make_root_post( $post_id, $export_args );

		}

		return $redirect_to;
	}


	/**
	 * =================================================================
	 *                          MISC
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
	public function remove_row_actions_for_synced_posts( $actions, $post ) {

		$status = get_post_meta( $post->ID, 'synced_post_status', true );

		if ( $status === 'linked' ) {
			unset( $actions['inline hide-if-no-js'] );
		}

		if ( ! empty( $status ) && ! Synced_Post_Service::current_user_can_edit_synced_posts( $status ) ) {
			unset( $actions['trash'] );
		}

		return $actions;
	}
}
