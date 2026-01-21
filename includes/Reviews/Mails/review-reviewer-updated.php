<?php

namespace Contentsync\Reviews\Mails;

use Contentsync\Utils\Urls;

/**
 * Generate the subject and body for a review updated notification email.
 *
 * Called when an editor updates an existing review request. Builds a
 * subject line and HTML message to notify reviewers that the review
 * was updated and includes the post type, title and links to edit
 * the post or view all reviews. Accepts the review and post objects
 * and returns an associative array ready for `wp_mail()`.
 *
 * @param object  $review Review object representing the updated review.
 * @param WP_Post $post   WordPress post associated with the review.
 *
 * @return array Array with 'subject' and 'message' keys for use with wp_mail().
 */
function get_mail_content_for_reviews_reviewer_updated( $review, $post ) {
	$subject = __( 'A review request was updated', 'contentsync' );

	$mail_title = sprintf(
		__( 'On your WordPress site "%1$s" a review for "%2$s" was updated.', 'contentsync' ),
		get_bloginfo( 'name' ),
		get_the_title( $post->ID )
	) . '<br><br>';

	$mail_note = sprintf( __( 'Please review the %s and let the editor know if any changes need to be made.', 'contentsync' ) . '<br><br>', $post->post_type );

	$links  = "<a href='" . Urls::get_edit_post_link( $post->ID ) . "'>" . sprintf( __( 'View %s', 'contentsync' ), $post->post_type ) . '</a>';
	$links .= " | <a href='" . network_admin_url( 'admin.php?page=contentsync-post-reviews' ) . "'>" . __( 'View all reviews', 'contentsync' ) . '</a>';

	$message = $mail_title . $mail_note . $links;

	return array(
		'subject' => $subject,
		'message' => $message,
	);
}
