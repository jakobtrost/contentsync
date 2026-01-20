<?php

namespace Contentsync\Mails\Templates;

/**
 * Generate the subject and body for a new review notification email.
 *
 * Constructs a subject line and HTML message informing reviewers that
 * a new review has been requested on the given site. The message
 * includes the site name, post title and type and provides links to
 * edit the post and view all reviews. Accepts a review object and
 * post object and returns the subject and message for `wp_mail()`.
 *
 * @param object  $review Review object representing the new review.
 * @param WP_Post $post   WordPress post under review.
 *
 * @return array Array with 'subject' and 'message' keys for use with wp_mail().
 */
function get_mail_content_for_reviews_reviewer_new( $review, $post ) {
	$subject = __( 'A new review was requested', 'contentsync' );

	$mail_title = sprintf(
		__( 'On your WordPress site "%1$s" a new review for "%2$s" was requested.', 'contentsync' ),
		get_bloginfo( 'name' ),
		get_the_title( $post->ID )
	) . '<br><br>';

	$mail_note = sprintf( __( 'Please review the %s and make the necessary changes.', 'contentsync' ) . '<br><br>', $post->post_type );

	$links  = "<a href='" . \Contentsync\get_edit_post_link( $post->ID ) . "'>" . sprintf( __( 'View %s', 'contentsync' ), $post->post_type ) . '</a>';
	$links .= " | <a href='" . network_admin_url( 'admin.php?page=contentsync-post-reviews' ) . "'>" . __( 'View all reviews', 'contentsync' ) . '</a>';

	$message = $mail_title . $mail_note . $links;

	return array(
		'subject' => $subject,
		'message' => $message,
	);
}
