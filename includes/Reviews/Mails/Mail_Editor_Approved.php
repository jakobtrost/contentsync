<?php
/**
 * Review approved email for the editor.
 *
 * Notifies the editor that the reviewer has approved the requested change.
 */

namespace Contentsync\Reviews\Mails;

use Contentsync\Reviews\Post_Review_Service;
use Contentsync\Utils\Urls;

defined( 'ABSPATH' ) || exit;

/**
 * Review editor approved mail.
 */
class Mail_Editor_Approved extends Review_Mail_Base {

	/**
	 * Build the email subject for editor-approved notification.
	 *
	 * @return string
	 */
	protected function get_subject(): string {
		return __( 'The reviewer approved your request', 'contentsync' );
	}

	/**
	 * Build the email message for editor-approved notification.
	 *
	 * @return string
	 */
	protected function get_message(): string {
		$post = $this->post;

		$reviewer_message = Post_Review_Service::get_latest_message_by_post_review_id( $this->post_review->ID );
		if ( ! $reviewer_message ) {
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

		return $mail_title . $mail_note . $links;
	}
}
