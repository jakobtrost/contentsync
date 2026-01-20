<?php

namespace Contentsync\Reviews\Mails\Templates;

/**
 * Generate the subject and body for a review reverted email.
 *
 * Called when a reviewer reverts a previously approved change back to the
 * original state. Builds a subject line and HTML message indicating
 * whether a reviewer message exists and including any reviewer notes.
 * The message contains the post type, title, site name and link to
 * edit the post. Returns an associative array for use with
 * `wp_mail()`.
 *
 * @param object  $review Review object representing the reverted review.
 * @param WP_Post $post   WordPress post that was reverted.
 *
 * @return array Array with 'subject' and 'message' keys for use with wp_mail().
 */
function get_mail_content_for_reviews_editor_reverted( $review, $post ) {
	$subject = __( 'Your review has been reverted', 'contentsync' );

	$reviewer_message = \Contentsync\Reviews\get_latest_message_by_post_review_id( $review->ID );

	if ( ! $reviewer_message ) {
		// If no reviewer message is found, use a default message
		$mail_title = sprintf(
			__( 'Your modifications of the %1$s "%2$s" on the "%3$s" site have been reverted.', 'contentsync' ),
			$post->post_type,
			get_the_title( $post->ID ),
			get_bloginfo( 'name' ),
		);
		$mail_note  = '';
	} else {
		$reviewer_message_content = $reviewer_message->get_content();
		$reviewer                 = $reviewer_message->get_reviewer();

		$mail_title = sprintf(
			__( 'The reviewer (%1$s) has reverted your modifications of the %2$s "%3$s" on the "%4$s" site.', 'contentsync' ),
			$reviewer,
			$post->post_type,
			get_the_title( $post->ID ),
			get_bloginfo( 'name' ),
		);

		$mail_note = '';
		if ( ! empty( $reviewer_message_content ) ) {
			$mail_note = '<br><br>' . sprintf( __( 'The reviewer (%s) left the following message:', 'contentsync' ), $reviewer ) . '<br><br><em>' . $reviewer_message_content . '</em><br><br>';
		}
	}

	$links = '<a href="' . \Contentsync\Utils\get_edit_post_link( $post->ID ) . '">' . sprintf( __( 'View %s', 'contentsync' ), $post->post_type ) . '</a>';

	$message = $mail_title . $mail_note . $links;

	return array(
		'subject' => $subject,
		'message' => $message,
	);
}
