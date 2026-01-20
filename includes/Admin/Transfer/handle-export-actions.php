<?php

namespace Contentsync\Admin\Transfer;

use Contentsync\Translations\Translation_Manager;
use Contentsync\Posts\Transfer\Post_Export;
use Contentsync\Posts\Transfer\File_Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add action callbacks.
 */
add_action( 'admin_init', __NAMESPACE__ . '\add_action_callbacks' );

/**
 * Add export bulk action callbacks.
 */
function add_action_callbacks() {

	// ajax action for post export
	add_action( 'contentsync_ajax_mode_post_export', __NAMESPACE__ . '\handle_admin_ajax_post_export' );

	// bulk actions for posttypes
	foreach ( \Contentsync\get_export_post_types() as $posttype ) {
		add_filter( 'bulk_actions-edit-' . $posttype, __NAMESPACE__ . '\add_export_bulk_action' );
		add_filter( 'handle_bulk_actions-edit-' . $posttype, __NAMESPACE__ . '\handle_admin_ajax_post_export_bulk_action', 10, 3 );
	}

	// bulk actions for media library
	add_filter( 'bulk_actions-upload', __NAMESPACE__ . '\add_export_bulk_action' );
	add_filter( 'handle_bulk_actions-upload', __NAMESPACE__ . '\handle_admin_ajax_post_export_bulk_action', 10, 3 );
}

/**
 * Handle the ajax export action
 *
 * @action 'contentsync_ajax_mode_post_export'
 *
 * @param array $data   holds the $_POST['data']
 */
function handle_admin_ajax_post_export( $data ) {

	Logger::add( '========= HANDLE EXPORT =========', $data );

	$post_id = isset( $data['post_id'] ) ? $data['post_id'] : '';
	$args    = array(
		'append_nested'  => isset( $data['nested'] ) ? true : false,
		'whole_posttype' => isset( $data['whole_posttype'] ) ? true : false,
		'all_terms'      => isset( $data['all_terms'] ) ? true : false,
		'resolve_menus'  => isset( $data['resolve_menus'] ) ? true : false,
		'translations'   => isset( $data['translations'] ) ? true : false,
	);
	if ( ! empty( $post_id ) ) {

		// export post
		$filepath = ( new Post_Export( $post_id, $args ) )->export_to_zip();

		if ( ! $filepath ) {
			\Contentsync\admin_ajax_return_error( __( 'The export file could not be written.', 'contentsync_hub' ) );
		}

		admin_ajax_return_success( convert_wp_content_dir_to_path( $filepath ) );
	}

	\Contentsync\admin_ajax_return_error( __( 'No valid post ID could found.', 'contentsync_hub' ) );
}

/**
 * Add export to the bulk action dropdown
 */
function add_export_bulk_action( $bulk_actions ) {
	$bulk_actions['contentsync_export'] = __( 'Export', 'contentsync_hub' );

	if ( ! empty( Translation_Manager::get_translation_tool() ) ) {
		$bulk_actions['contentsync_export_multilanguage'] = __( 'Export including translations', 'contentsync_hub' );
	}
	return $bulk_actions;
}

/**
 * Handle export bulk action
 *
 * set via 'add_bulk_action_callbacks'
 *
 * @param string $sendback The redirect URL.
 * @param string $doaction The action being taken.
 * @param array  $items    Array of IDs of posts.
 */
function handle_admin_ajax_post_export_bulk_action( $sendback, $doaction, $items ) {
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
		$href = convert_wp_content_dir_to_path( $filepath );
		// $sendback = add_query_arg( 'download', $href, $sendback );
		$sendback = $href;
	} else {
		// set transient to display admin notice
		set_transient( 'contentsync_transient_notice', 'error::' . __( 'The export file could not be written.', 'contentsync_hub' ) );
	}

	return $sendback;
}
