<?php
/**
 * New review notification email for reviewers.
 *
 * Informs reviewers that a new review has been requested.
 */

namespace Contentsync\Reviews\Mails;

use Contentsync\Utils\Urls;

defined( 'ABSPATH' ) || exit;

/**
 * Review reviewer new mail.
 */
class Mail_Reviewer_New extends Review_Mail_Base {

	/**
	 * Build the email subject for reviewer-new notification.
	 *
	 * @return string
	 */
	protected function get_subject(): string {
		return __( 'A new review was requested', 'contentsync' );
	}

	/**
	 * Build the email message for reviewer-new notification.
	 *
	 * @return string
	 */
	protected function get_message(): string {
		$post = $this->post;

		$mail_title = sprintf(
			__( 'On your WordPress site "%1$s" a new review for "%2$s" was requested.', 'contentsync' ),
			get_bloginfo( 'name' ),
			get_the_title( $post->ID )
		) . '<br><br>';

		$mail_note = sprintf( __( 'Please review the %s and make the necessary changes.', 'contentsync' ) . '<br><br>', $post->post_type );

		$links  = "<a href='" . Urls::get_edit_post_link( $post->ID ) . "'>" . sprintf( __( 'View %s', 'contentsync' ), $post->post_type ) . '</a>';
		$links .= " | <a href='" . network_admin_url( 'admin.php?page=contentsync-post-reviews' ) . "'>" . __( 'View all reviews', 'contentsync' ) . '</a>';

		return $mail_title . $mail_note . $links;
	}
}
