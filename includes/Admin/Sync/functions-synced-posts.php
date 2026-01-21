<?php

namespace Contentsync\Admin\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Function to check if current user is allowed to edit global contents.
 * Permission is based on 'edit_posts' capability and can be overridden
 * with the filter 'contentsync_user_can_edit'.
 *
 * @param string $status 'root' or 'linked'
 *
 * @return bool
 */
function current_user_can_edit_synced_posts( $status = '' ) {

	$can_edit = function_exists( 'current_user_can' ) ? current_user_can( 'edit_posts' ) : true;

	if ( $status === 'root' ) {

		/**
		 * Filter to allow editing of root posts.
		 *
		 * @param bool $can_edit
		 *
		 * @return bool
		 */
		$can_edit = apply_filters( 'contentsync_user_can_edit_root_posts', $can_edit );
	} elseif ( $status === 'linked' ) {

		/**
		 * Filter to allow editing of linked posts.
		 *
		 * @param bool $can_edit
		 *
		 * @return bool
		 */
		$can_edit = apply_filters( 'contentsync_user_can_edit_linked_posts', $can_edit );
	}

	/**
	 * Filter to allow editing of all synced posts, no matter the status.
	 *
	 * @param bool $can_edit
	 *
	 * @return bool
	 */
	return apply_filters( 'contentsync_user_can_edit_synced_posts', $can_edit, $status );
}
