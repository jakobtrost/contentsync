<?php
/**
 * Review reverted email for the editor.
 *
 * Notifies the editor that the reviewer reverted their modifications.
 */

namespace Contentsync\Reviews\Mails;

use Contentsync\Reviews\Post_Review_Service;
use Contentsync\Utils\Urls;

defined( 'ABSPATH' ) || exit;

/**
 * Review editor reverted mail.
 */
class Mail_Editor_Reverted extends Review_Mail_Base {

	/**
	 * Build the email subject for editor-reverted notification.
	 *
	 * @return string
	 */
	protected function get_subject(): string {
		return __( 'Your review has been reverted', 'contentsync' );
	}

	/**
	 * Build the email message for editor-reverted notification.
	 *
	 * @return string
	 */
	protected function get_message(): string {
		$post = $this->post;

		$reviewer_message = Post_Review_Service::get_latest_message_by_post_review_id( $this->post_review->ID );

		if ( ! $reviewer_message ) {
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

		$links = '<a href="' . Urls::get_edit_post_link( $post->ID ) . '">' . sprintf( __( 'View %s', 'contentsync' ), $post->post_type ) . '</a>';

		return $mail_title . $mail_note . $links;
	}
}
