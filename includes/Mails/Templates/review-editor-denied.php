<?php

namespace Contentsync\Mails\Templates;

/**
 * Generate the subject and body for a review denied email.
 *
 * When a reviewer denies a change request they may leave a message
 * explaining what needs to be modified. This helper constructs a
 * translated subject and HTML message including the reviewer’s name,
 * their message if provided, the post type and title and a link to
 * view the post. The function accepts the review and post objects and
 * returns an associative array with the subject and message ready to
 * pass to `wp_mail()`.
 *
 * @param object  $review Review object describing the requested review.
 * @param WP_Post $post   WordPress post that was reviewed.
 *
 * @return array Array with 'subject' and 'message' keys for use with wp_mail().
 */
function get_mail_content_for_reviews_editor_denied( $review, $post ) {
	$subject = __( 'The reviewer requested modifications', 'contentsync' );

	$reviewer_message         = \Contentsync\Reviews\get_latest_message_by_post_review_id( $review->ID );
	$reviewer_message_content = $reviewer_message->get_content();
	$reviewer                 = $reviewer_message->get_reviewer();

	$mail_title = sprintf(
		__( 'The reviewer (%1$s) has requested modifications of your %2$s "%3$s" on the "%4$s" site.', 'contentsync' ),
		$reviewer,
		$post->post_type,
		get_the_title( $post->ID ),
		get_bloginfo( 'name' ),
	);

	$mail_note = '';
	if ( ! empty( $reviewer_message ) ) {
		$mail_note = '<br><br>' . sprintf( __( 'The reviewer (%s) left the following message:', 'contentsync' ), $reviewer ) . '<br><br><em>' . $reviewer_message_content . '</em><br><br>';
	}

	$mail_note .= __( 'Please review the requested modifications and make the necessary changes.', 'contentsync' ) . '<br><br>';

	$links = '<a href="' . \Contentsync\Utils\get_edit_post_link( $post->ID ) . '">' . sprintf( __( 'View %s', 'contentsync' ), $post->post_type ) . '</a>';

	$message = $mail_title . $mail_note . $links;

	return array(
		'subject' => $subject,
		'message' => $message,
	);
}
