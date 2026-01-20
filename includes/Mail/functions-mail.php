<?php
/**
 * Mail logic and sending functionality for reviews.
 *
 * This file loads email template files and provides functions
 * which handle the sending of notification emails during the post
 * review workflow. The `contentsync_mails_init` function registers
 * an action to catch `wp_mail_failed` events and store errors for
 * debugging. The `send_review_mail` function determines
 * the recipients based on review status and role (reviewer or editor),
 * composes the appropriate message and triggers filters so developers
 * can customise the mail recipients. Extend the included mail templates
 * to add new notification types or modify the email content.
 *
 * @since 2.17.0
 */

namespace Contentsync\Mails;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Send review mail.
 *
 * @todo Rewrite this to make it work for the review emails to both reviewers and editors
 *       The entire handling of the email will be in this function (or subfunctions), deciding what to send based on the status and recipient
 *
 * @param int    $review_id     The ID of the review.
 * @param string $status     The status of the review. Can be 'new', 'in_review', 'denied', 'approved', 'reverted'.
 * @param string $recipient  To whom the email should be sent, can be 'reviewers' or 'editor'.
 *
 * @return bool|string True on success, Mail error text on failure.
 */
function send_review_mail( $review_id, $status, $recipient ) {

	// Get the review post
	$post_review = get_post_review_by_id( $review_id );
	$post        = get_post( $post_review->post_id );
	if ( ! $post ) {
		$post = $post_review->previous_post;
	}

	// Get the recipients of the email
	$mail_to = '';
	if ( $recipient === 'reviewers' ) {
		$mail_to = get_post_reviewer_mails( $post_review->post_id );
	} elseif ( $recipient === 'editor' ) {
		$editor = get_user_by( 'id', $post_review->editor );
		if ( $editor ) {
			$mail_to = $editor->user_email;
		}
	}

	/**
	 * Filter to modify the email recipient address for review notifications.
	 *
	 * This filter allows developers to customize who receives review notification
	 * emails, enabling dynamic recipient management based on review context.
	 *
	 * @filter contentsync_reviews_mail_to
	 *
	 * @param string $mail_to   The email address(es) to send the notification to.
	 * @param int    $review_id The ID of the review.
	 *
	 * @return string $mail_to  The modified email recipient address(es).
	 */
	$mail_to = apply_filters( 'contentsync_reviews_mail_to', $mail_to, $review_id );

	if ( empty( $mail_to ) ) {
		return true;
	}

	$content = array(
		'subject' => 'Synced Post Review Notification',
		'message' => '',
	);

	// Get the email subject and content
	if ( $recipient === 'reviewers' ) {
		if ( $status === 'new' ) {
			require_once __DIR__ . '/templates/review-reviewer-new.php';
			$content = Templates\get_mail_content_for_reviews_reviewer_new( $post_review, $post );
		} elseif ( $status === 'in_review' ) {
			require_once __DIR__ . '/templates/review-reviewer-updated.php';
			$content = Templates\get_mail_content_for_reviews_reviewer_updated( $post_review, $post );
		}
	} elseif ( $recipient === 'editor' ) {
		if ( $status === 'approved' ) {
			require_once __DIR__ . '/templates/review-editor-approved.php';
			$content = Templates\get_mail_content_for_reviews_editor_approved( $post_review, $post );
		} elseif ( $status === 'denied' ) {
			require_once __DIR__ . '/templates/review-editor-denied.php';
			$content = Templates\get_mail_content_for_reviews_editor_denied( $post_review, $post );
		} elseif ( $status === 'reverted' ) {
			require_once __DIR__ . '/templates/review-editor-reverted.php';
			$content = Templates\get_mail_content_for_reviews_editor_reverted( $post_review, $post );
		}
	}

	/**
	 * Filter to modify the email message content for review notifications.
	 *
	 * This filter allows developers to customize the HTML content of review
	 * notification emails, enabling dynamic message formatting and content.
	 *
	 * @filter contentsync_reviews_mail_content
	 *
	 * @param string $content['message'] The HTML content of the email message.
	 * @param int    $review_id          The ID of the review.
	 *
	 * @return string $content['message'] The modified email message content.
	 */
	$content['message'] = apply_filters( 'contentsync_reviews_mail_content', $content['message'], $review_id );

	/**
	 * Filter to modify the email subject line for review notifications.
	 *
	 * This filter allows developers to customize the subject line of review
	 * notification emails, enabling dynamic subject formatting.
	 *
	 * @filter contentsync_reviews_mail_subject
	 *
	 * @param string $content['subject'] The email subject line.
	 * @param int    $review_id          The ID of the review.
	 *
	 * @return string $content['subject'] The modified email subject line.
	 */
	$content['subject'] = apply_filters( 'contentsync_reviews_mail_subject', $content['subject'], $review_id );

	// set headers
	$headers = '';

	/**
	 * Filter to modify the email headers for review notifications.
	 *
	 * This filter allows developers to customize the email headers for review
	 * notification emails, enabling custom headers like CC, BCC, or custom
	 * email headers.
	 *
	 * @filter contentsync_reviews_mail_headers
	 * @see https://developer.wordpress.org/reference/functions/wp_mail/
	 *
	 * @param string $headers   The email headers string or array.
	 * @param int    $review_id The ID of the review.
	 *
	 * @return string|array $headers The modified email headers.
	 */
	$headers = apply_filters( 'contentsync_reviews_mail_headers', $headers, $review_id );

	// send Mail
	$return = send_mail( $mail_to, $content['subject'], $content['message'], $headers );

	return $return;
}

/**
 * Sends an email, similar to PHP's mail function.
 *
 * @see https://developer.wordpress.org/reference/functions/wp_mail/
 *
 * @param string|string[] $to          Array or comma-separated list of email addresses to send message.
 * @param string          $subject     Email subject.
 * @param string          $message     Message contents.
 * @param string|string[] $headers     Optional. Additional headers.
 * @param string|string[] $attachments Optional. Paths to files to attach.
 *
 * @return bool|string True on success, Mail error text on failure.
 */
function send_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
	// Reset mail errors before sending
	$GLOBALS['contentsync_mail_errors'] = array();

	$content = create_mail_template( $subject, $message );

	// set filter
	add_filter( 'wp_mail_content_type', __NAMESPACE__ . '\\set_html_mail_content_type' );
	add_filter( 'wp_mail_charset', __NAMESPACE__ . '\\get_utf8_charset' );
	add_action( 'wp_mail_failed', __NAMESPACE__ . '\\on_mail_error' );

	// send Mail
	$return = wp_mail( $to, $subject, $content, $headers, $attachments );

	error_log( 'Mail sent: ' . $return );

	// Reset content-type to avoid conflicts -- https://core.trac.wordpress.org/ticket/23578
	remove_filter( 'wp_mail_content_type', __NAMESPACE__ . '\\set_html_mail_content_type' );
	remove_filter( 'wp_mail_charset', __NAMESPACE__ . '\\get_utf8_charset' );
	remove_action( 'wp_mail_failed', __NAMESPACE__ . '\\on_mail_error' );

	if ( isset( $GLOBALS['contentsync_mail_errors'] ) && count( $GLOBALS['contentsync_mail_errors'] ) ) {
		return $GLOBALS['contentsync_mail_errors'][0];
	}

	return $return;
}

/**
 * Set mail charset to UTF-8
 */
function get_utf8_charset() {
	return 'UTF-8';
}

/**
 * set mails to html
 *
 * @source (see comments): https://developer.wordpress.org/reference/functions/wp_mail/
 */
function set_html_mail_content_type() {
	return 'text/html';
}

/**
 * on wp_mail() error event
 */
function on_mail_error( $wp_error ) {
	if ( ! isset( $GLOBALS['contentsync_mail_errors'] ) ) {
		$GLOBALS['contentsync_mail_errors'] = array();
	}
	$GLOBALS['contentsync_mail_errors'][] = $wp_error;
}

/**
 * Get mail template and replace placeholders.
 *
 * @see https://github.com/leemunroe/responsive-html-email-template
 */
function create_mail_template( $title, $body, $footer = null ) {

	// default footer
	$sitetitle = get_bloginfo( 'name' );
	if ( ! $footer && ! empty( $sitetitle ) ) {
		$footer         = '<a href="' . get_home_url() . '" style="color: #999999; font-size: 12px; text-align: center; text-decoration: none;">' . $sitetitle . '</a>';
		$blogdecription = get_bloginfo( 'description' );
		if ( ! empty( $blogdecription ) ) {
			$footer .= '  |  ' . $blogdecription;
		}
	}

	require_once __DIR__ . '/templates/mail-template.php';
	return Templates\get_mail_template( $title, $body, $footer );
}

/**
 * Get all reviewer emails for a post.
 *
 * @param int $post_id  The post ID.
 *
 * @return string  Comma separated list of emails.
 */
function get_post_reviewer_mails( $post_id ) {

	$reviewer_ids = array();

	foreach ( get_clusters_including_post( $post_id ) as $cluster ) {
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
