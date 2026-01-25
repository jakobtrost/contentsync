<?php

namespace Contentsync\Admin\Views\Transfer;

use Contentsync\Translations\Translation_Manager;
use Contentsync\Post_Transfer\Post_Export;
use Contentsync\Post_Transfer\Post_Transfer_Service;
use Contentsync\Utils\Hooks_Base;
use Contentsync\Utils\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Hook provider class for admin bulk actions.
 */
class Bulk_Actions_Hooks extends Hooks_Base {

	/**
	 * Register admin-only hooks.
	 */
	public function register_admin() {
		add_action( 'admin_init', array( $this, 'add_bulk_actions' ) );
	}

	/**
	 * Add bulk action callbacks.
	 */
	public function add_bulk_actions() {

		// bulk actions for posttypes
		foreach ( Post_Transfer_Service::get_supported_post_types() as $posttype ) {
			add_filter( 'bulk_actions-edit-' . $posttype, array( $this, 'add_post_export_bulk_action' ) );
			add_filter( 'handle_bulk_actions-edit-' . $posttype, array( $this, 'handle_post_export_bulk_action' ), 10, 3 );
		}

		// bulk actions for media library
		add_filter( 'bulk_actions-upload', array( $this, 'add_post_export_bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_post_export_bulk_action' ), 10, 3 );
	}

	/**
	 * Add bulk action to the bulk action dropdown
	 *
	 * @param array $bulk_actions The bulk actions array.
	 * @return array Modified bulk actions array.
	 */
	public function add_post_export_bulk_action( $bulk_actions ) {

		$bulk_actions['contentsync_export'] = __( 'Export', 'contentsync' );

		if ( ! empty( Translation_Manager::get_translation_tool() ) ) {
			$bulk_actions['contentsync_export_multilanguage'] = __( 'Export including translations', 'contentsync' );
		}

		return $bulk_actions;
	}

	/**
	 * Handle bulk action
	 *
	 * set via 'add_bulk_action_callbacks'
	 *
	 * @param string $sendback The redirect URL.
	 * @param string $doaction The action being taken.
	 * @param array  $items    Array of IDs of posts.
	 * @return string The redirect URL.
	 */
	public function handle_post_export_bulk_action( $sendback, $doaction, $items ) {
		if ( count( $items ) === 0 ) {
			return $sendback;
		}
		if ( $doaction !== 'contentsync_export' && $doaction !== 'contentsync_export_multilanguage' ) {
			return $sendback;
		}

		$args = array(
			'append_nested' => true,
			'translations'  => $doaction === 'contentsync_export_multilanguage',
		);

		$filepath = ( new Post_Export( $items, $args ) )->export_to_zip();

		if ( $filepath ) {
			$href = Files::convert_wp_content_dir_to_url( $filepath );
			// $sendback = add_query_arg( 'download', $href, $sendback );
			$sendback = $href;
		} else {
			// set transient to display admin notice
			set_transient( 'contentsync_transient_notice', 'error::' . __( 'The export file could not be written.', 'contentsync' ) );
		}

		return $sendback;
	}
}
