<?php
/**
 * Mail logic and sending functionality for reviews.
 *
 * This class provides static helper methods which handle the sending
 * of notification emails during the post review workflow. The
 * `send_review_mail` method determines the recipients based on review
 * status and role (reviewer or editor), composes the appropriate
 * message and triggers filters so developers can customise the mail
 * recipients. Extend the included mail templates to add new
 * notification types or modify the email content.
 */

namespace Contentsync\Reviews;

use Contentsync\Cluster\Cluster_Service;
use Contentsync\Reviews\Mails\Mail_Editor_Approved;
use Contentsync\Reviews\Mails\Mail_Editor_Denied;
use Contentsync\Reviews\Mails\Mail_Editor_Reverted;
use Contentsync\Reviews\Mails\Mail_Reviewer_New;
use Contentsync\Reviews\Mails\Mail_Reviewer_Updated;

defined( 'ABSPATH' ) || exit;

/**
 * Review mail helper class with static methods.
 */
class Review_Mail_Service {

	/**
	 * Send review mail.
	 *
	 * Determines recipients from status and role, picks the appropriate mail
	 * class, and delegates to it for composition and sending. All filters
	 * (contentsync_reviews_mail_to, contentsync_reviews_mail_subject,
	 * contentsync_reviews_mail_content, contentsync_reviews_mail_headers) are
	 * applied in the mail class's send() method.
	 *
	 * @param int    $review_id The ID of the review.
	 * @param string $status    The status. Can be 'new', 'in_review', 'denied', 'approved', 'reverted'.
	 * @param string $recipient To whom to send: 'reviewers' or 'editor'.
	 *
	 * @return bool|WP_Error True on success, false when no recipient after filtering, or WP_Error on failure.
	 */
	public static function send_review_mail( $review_id, $status, $recipient ) {

		$post_review = Post_Review_Service::get_post_review_by_id( $review_id );
		$post        = get_post( $post_review->post_id );
		if ( ! $post ) {
			$post = $post_review->previous_post;
		}

		$mail_to = '';
		if ( $recipient === 'reviewers' ) {
			$mail_to = self::get_post_reviewer_mails( $post_review->post_id );
		} elseif ( $recipient === 'editor' ) {
			$editor = get_user_by( 'id', $post_review->editor );
			if ( $editor ) {
				$mail_to = $editor->user_email;
			}
		}

		$mail_class = null;
		if ( $recipient === 'reviewers' && $status === 'new' ) {
			$mail_class = Mail_Reviewer_New::class;
		} elseif ( $recipient === 'reviewers' && $status === 'in_review' ) {
			$mail_class = Mail_Reviewer_Updated::class;
		} elseif ( $recipient === 'editor' && $status === 'approved' ) {
			$mail_class = Mail_Editor_Approved::class;
		} elseif ( $recipient === 'editor' && $status === 'denied' ) {
			$mail_class = Mail_Editor_Denied::class;
		} elseif ( $recipient === 'editor' && $status === 'reverted' ) {
			$mail_class = Mail_Editor_Reverted::class;
		}

		if ( ! $mail_class ) {
			return true;
		}

		$mail = new $mail_class( $mail_to, $post_review, $post, '' );
		return $mail->send();
	}

	/**
	 * Get all reviewer emails for a post.
	 *
	 * @param int $post_id  The post ID.
	 *
	 * @return string  Comma separated list of emails.
	 */
	public static function get_post_reviewer_mails( $post_id ) {

		$reviewer_ids = array();

		foreach ( Cluster_Service::get_clusters_including_post( $post_id ) as $cluster ) {
			if ( $cluster->enable_reviews ) {
				$reviewer_ids = array_merge( $reviewer_ids, $cluster->reviewer_ids );
			}
		}

		$mail_to = '';

		if ( ! empty( $reviewer_ids ) ) {
			foreach ( $reviewer_ids as $reviewer_id ) {
				$reviewer = get_user_by( 'id', $reviewer_id );
				if ( $reviewer ) {
					$mail_to .= $mail_to === '' ? $reviewer->user_email : ', ' . $reviewer->user_email;
				}
			}
		}

		if ( empty( $mail_to ) ) {
			$super_admins = get_super_admins();
			foreach ( $super_admins as $super_admin ) {
				$super_admin = get_user_by( 'login', $super_admin );
				if ( $super_admin ) {
					$mail_to .= $mail_to === '' ? $super_admin->user_email : ', ' . $super_admin->user_email;
				}
			}
		}

		return $mail_to;
	}
}
