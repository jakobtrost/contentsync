<?php

namespace Contentsync\Reviews\Mails\Templates;

use Contentsync\Utils\Urls;

/**
 * Generate the subject and body for a review approved email.
 *
 * Builds a translated subject line and HTML message notifying the editor
 * that the reviewer has approved the requested change. The message
 * includes the reviewer’s name (if available), the post type and title,
 * the site name and a call to action link. It accepts the review and
 * post objects and returns an associative array with the email
 * components.
 *
 * @param object  $review Review object containing metadata about the review.
 * @param WP_Post $post   WordPress post being reviewed.
 *
 * @return array Array with 'subject' and 'message' keys for use with wp_mail().
 */
function get_mail_content_for_reviews_editor_approved( $review, $post ) {
	$subject = __( 'The reviewer approved your request', 'contentsync' );

	$reviewer_message = \Contentsync\Reviews\get_latest_message_by_post_review_id( $review->ID );
	if ( ! $reviewer_message ) {
		// If no reviewer message is found, use a default message
		$mail_title = sprintf(
			__( 'Your requested change on the %1$s "%2$s" on the "%3$s" site has been approved:', 'contentsync' ),
			$post->post_type,
			get_the_title( $post->ID ),
			get_bloginfo( 'name' ),
		) . '<br><br>';
	} else {
		$reviewer   = $reviewer_message->get_reviewer();
		$mail_title = sprintf(
			__( 'The reviewer (%1$s) has approved your requested change on the %2$s "%3$s" on the "%4$s" site:', 'contentsync' ),
			$reviewer,
			$post->post_type,
			get_the_title( $post->ID ),
			get_bloginfo( 'name' ),
		) . '<br><br>';
	}

	$mail_note = __( 'No further action is needed.', 'contentsync' ) . '<br><br>';

	$links = '<a href="' . Urls::get_edit_post_link( $post->ID ) . '">' . sprintf( __( 'View %s', 'contentsync' ), $post->post_type ) . '</a>';

	$message = $mail_title . $mail_note . $links;

	return array(
		'subject' => $subject,
		'message' => $message,
	);
}
