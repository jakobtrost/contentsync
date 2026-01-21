<?php

namespace Contentsync\Admin;

use Contentsync\Translations\Translation_Manager;
use Contentsync\Posts\Transfer\Post_Export;
use Contentsync\Posts\Transfer\Post_Transfer_Service;
use Contentsync\Posts\Transfer\File_Handler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_init', __NAMESPACE__ . '\add_bulk_actions' );

/**
 * Add bulk action callbacks.
 */
function add_bulk_actions() {

	// bulk actions for posttypes
	foreach ( Post_Transfer_Service::get_supported_post_types() as $posttype ) {
		add_filter( 'bulk_actions-edit-' . $posttype, __NAMESPACE__ . '\add_post_export_bulk_action' );
		add_filter( 'handle_bulk_actions-edit-' . $posttype, __NAMESPACE__ . '\handle_post_export_bulk_action', 10, 3 );
	}

	// bulk actions for media library
	add_filter( 'bulk_actions-upload', __NAMESPACE__ . '\add_post_export_bulk_action' );
	add_filter( 'handle_bulk_actions-upload', __NAMESPACE__ . '\handle_post_export_bulk_action', 10, 3 );
}

/**
 * Add bulk action to the bulk action dropdown
 */
function add_post_export_bulk_action( $bulk_actions ) {

	$bulk_actions['contentsync_export'] = __( 'Export', 'contentsync_hub' );

	if ( ! empty( Translation_Manager::get_translation_tool() ) ) {
		$bulk_actions['contentsync_export_multilanguage'] = __( 'Export including translations', 'contentsync_hub' );
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
 */
function handle_post_export_bulk_action( $sendback, $doaction, $items ) {
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
