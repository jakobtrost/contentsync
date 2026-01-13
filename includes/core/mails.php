<?php
/**
 * Mail logic and sending functionality for reviews.
 *
 * This file loads email template files and declares the `Mail` class,
 * which handles the sending of notification emails during the post
 * review workflow. The constructor registers an action to catch
 * `wp_mail_failed` events and store errors for debugging. The static
 * `send_review_mail` method determines the recipients based on review
 * status and role (reviewer or editor), composes the appropriate
 * message and triggers filters so developers can customise the mail
 * recipients. Extend this class or the included mail templates to add
 * new notification types or modify the email content.
 *
 * @since 2.17.0
 */

namespace Contentsync\Cluster;

require_once __DIR__.'/mails/review-reviewer-new.php';
require_once __DIR__.'/mails/review-reviewer-updated.php';
require_once __DIR__.'/mails/review-editor-approved.php';
require_once __DIR__.'/mails/review-editor-denied.php';
require_once __DIR__.'/mails/review-editor-reverted.php';

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

new Mail();

class Mail {

	/**
	 * Holds all mail errors
	 */
	public static $mail_errors = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		// show wp_mail() errors
		add_action( 'wp_mail_failed', array( 'Contentsync\Cluster\Mail', 'on_mail_error' ), 10, 1 );
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
	public static function send_review_mail( $review_id, $status, $recipient ) {

		// Get the review post
		$synced_post_review = get_synced_post_review_by_id( $review_id );
		$post           = get_post( $synced_post_review->post_id );
		if ( !$post ) {
			$post = $synced_post_review->previous_post;
		}

		// Get the recipients of the email
		$mail_to = '';
		if ( $recipient === 'reviewers' ) {
			$mail_to = self::get_post_reviewer_mails( $synced_post_review->post_id );
		} else if ( $recipient === 'editor' ) {
			$editor                 = get_user_by( 'id', $synced_post_review->editor );
			if ( $editor ) $mail_to = $editor->user_email;
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
			'subject' => 'Global Post Review Notification',
			'message' => '',
		);

		// Get the email subject and content
		if ( $recipient === 'reviewers' ) {
			if ( $status === 'new' ) {
				$content = contentsync_reviews_reviewer_new( $synced_post_review, $post );
			} else if ( $status === 'in_review' ) {
				$content = contentsync_reviews_reviewer_updated( $synced_post_review, $post );
			}
		} else if ( $recipient === 'editor' ) {
			if ( $status === 'approved' ) {
				$content = contentsync_reviews_editor_approved( $synced_post_review, $post );
			} else if ( $status === 'denied' ) {
				$content = contentsync_reviews_editor_denied( $synced_post_review, $post );
			} else if ( $status === 'reverted' ) {
				$content = contentsync_reviews_editor_reverted( $synced_post_review, $post );
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
		$return = self::send_mail( $mail_to, $content['subject'], $content['message'], $headers );

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
	public static function send_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {

		$content = self::create_mail_template( $subject, $message );

		// set filter
		add_filter( 'wp_mail_content_type', array( 'Contentsync\Cluster\Mail', 'set_html_mail_content_type' ) );
		add_filter( 'wp_mail_charset', array( 'Contentsync\Cluster\Mail', 'utf8' ) );
		add_action( 'wp_mail_failed', array( 'Contentsync\Cluster\Mail', 'on_mail_error' ) );

		// send Mail
		$return = wp_mail( $to, $subject, $content, $headers, $attachments );

		error_log( 'Mail sent: '.$return );

		// Reset content-type to avoid conflicts -- https://core.trac.wordpress.org/ticket/23578
		remove_filter( 'wp_mail_content_type', array( 'Contentsync\Cluster\Mail', 'set_html_mail_content_type' ) );
		remove_filter( 'wp_mail_charset', array( 'Contentsync\Cluster\Mail', 'utf8' ) );
		add_action( 'wp_mail_failed', array( 'Contentsync\Cluster\Mail', 'on_mail_error' ) );

		if ( count( self::$mail_errors ) ) {
			return self::$mail_errors[0];
		}

		return $return;
	}

	/**
	 * Set mail charset to UTF-8
	 */
	public static function utf8() {
		return 'UTF-8';
	}

	/**
	 * set mails to html
	 *
	 * @source (see comments): https://developer.wordpress.org/reference/functions/wp_mail/
	 */
	public static function set_html_mail_content_type() {
		return 'text/html';
	}

	/**
	 * on wp_mail() error event
	 */
	public static function on_mail_error( $wp_error ) {
		self::$mail_errors[] = $wp_error;
	}

	/**
	 * Get mail template and replace placeholders.
	 *
	 * @see https://github.com/leemunroe/responsive-html-email-template
	 */
	public static function create_mail_template( $title, $body, $footer = null ) {

		// default footer
		$sitetitle = get_bloginfo( 'name' );
		if ( !$footer && !empty( $sitetitle ) ) {
			$footer         = '<a href="'.get_home_url().'" style="color: #999999; font-size: 12px; text-align: center; text-decoration: none;">'.$sitetitle.'</a>';
			$blogdecription = get_bloginfo( 'description' );
			if ( !empty( $blogdecription ) ) {
				$footer .= '  |  '.$blogdecription;
			}
		}

		return '<!doctype html>
		<html>
			<head>
				<meta name="viewport" content="width=device-width, initial-scale=1.0">
				<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
				<title>WordPress Content Sync</title>
				<style>
				@media only screen and (max-width: 620px) {
					table.body h1 {
						font-size: 28px !important;
						margin-bottom: 10px !important;
					}
				
					table.body p,
					table.body ul,
					table.body ol,
					table.body td,
					table.body span,
					table.body a {
						font-size: 16px !important;
					}
				
					table.body .wrapper,
					table.body .article {
						padding: 10px !important;
					}
				
					table.body .content {
						padding: 0 !important;
					}
				
					table.body .container {
						padding: 0 !important;
						width: 100% !important;
					}
				
					table.body .main {
						border-left-width: 0 !important;
						border-radius: 0 !important;
						border-right-width: 0 !important;
					}
				
					table.body .btn table {
						width: 100% !important;
					}
				
					table.body .btn a {
						width: 100% !important;
					}
				
					table.body .img-responsive {
						height: auto !important;
						max-width: 100% !important;
						width: auto !important;
					}
				}
				
				@media all {
					.ExternalClass {
						width: 100%;
					}
				
					.ExternalClass,
					.ExternalClass p,
					.ExternalClass span,
					.ExternalClass font,
					.ExternalClass td,
					.ExternalClass div {
						line-height: 100%;
					}
				
					.apple-link a {
						color: inherit !important;
						font-family: inherit !important;
						font-size: inherit !important;
						font-weight: inherit !important;
						line-height: inherit !important;
						text-decoration: none !important;
					}
				
					#MessageViewBody a {
						color: inherit;
						text-decoration: none;
						font-size: inherit;
						font-family: inherit;
						font-weight: inherit;
						line-height: inherit;
					}
				
					.btn-primary table td:hover {
						background-color: #34495e !important;
					}
				
					.btn-primary a:hover {
						background-color: #34495e !important;
						border-color: #34495e !important;
					}
				}
				</style>
			</head>
			<body style="background-color: #f6f6f6; font-family: DM Sans, Inter, Helvetica, -apple-system, BlinkMacSystemFont, Roboto, Arial, sans-serif; -webkit-font-smoothing: antialiased; font-size: 16px; line-height: 1.4; margin: 0; padding: 0; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%;">
				<table role="presentation" border="0" cellpadding="0" cellspacing="0" class="body" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; background-color: #f6f6f6; width: 100%;" width="100%" bcontentsyncolor="#f6f6f6">
				<tr>
					<td style="font-family: DM Sans, Inter, Helvetica, -apple-system, BlinkMacSystemFont, Roboto, Arial, sans-serif; font-size: 16px; vertical-align: top;" valign="top">&nbsp;</td>
					<td class="container" style="font-family: DM Sans, Inter, Helvetica, -apple-system, BlinkMacSystemFont, Roboto, Arial, sans-serif; font-size: 16px; vertical-align: top; display: block; max-width: 580px; padding: 10px; width: 580px; margin: 0 auto;" width="580" valign="top">
						<div class="content" style="box-sizing: border-box; display: block; margin: 0 auto; max-width: 580px; padding: 24px;">
				
							<!-- START TITLE -->
							<div class="title" style="clear: both; margin-top: 10px; text-align: center; width: 100%;">
								<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;" width="100%">
									<tr>
										<td class="content-block" style="font-family: DM Sans, Inter, Helvetica, -apple-system, BlinkMacSystemFont, Roboto, Arial, sans-serif; vertical-align: top; padding-bottom: 10px; padding-top: 10px; color: #1E1E1E; font-size: 32px; text-align: center;" valign="top" align="center"><h1 style="font-family: DM Sans, Inter, Helvetica, -apple-system, BlinkMacSystemFont, Roboto, Arial, sans-serif; color: #1E1E1E; font-size: 32px; text-align: center;">'.$title.'</h1></td>
									</tr>
								</table>
							</div>
							<!-- END TITLE -->
				
							<!-- START CENTERED WHITE CONTAINER -->
							<table role="presentation" class="main" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; background: #ffffff; border-radius: 3px; color: #1E1E1E; width: 100%;" width="100%">
				
								<!-- START MAIN CONTENT AREA -->
								<tr>
									<td class="wrapper" style="font-family: DM Sans, Inter, Helvetica, -apple-system, BlinkMacSystemFont, Roboto, Arial, sans-serif; font-size: 16px; color: #1E1E1E; vertical-align: top; box-sizing: border-box; padding: 20px;" valign="top">
									<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;" width="100%">
										<tr>
										<td style="font-family: DM Sans, Inter, Helvetica, -apple-system, BlinkMacSystemFont, Roboto, Arial, sans-serif; font-size: 16px; color: #1E1E1E; vertical-align: top;" valign="top">'.$body.'</td>
										</tr>
									</table>
									</td>
								</tr>
								<!-- END MAIN CONTENT AREA -->

							</table>
							<!-- END CENTERED WHITE CONTAINER -->
				
							<!-- START FOOTER -->
							<div class="footer" style="clear: both; margin-top: 10px; text-align: center; width: 100%;">
								<table role="presentation" border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;" width="100%">
									<tr>
										<td class="content-block powered-by" style="font-family: DM Sans, Inter, Helvetica, -apple-system, BlinkMacSystemFont, Roboto, Arial, sans-serif; vertical-align: top; padding-bottom: 10px; padding-top: 10px; color: #999999; font-size: 12px; text-align: center;" valign="top" align="center">'.$footer.'</td>
									</tr>
								</table>
							</div>
							<!-- END FOOTER -->
				
						</div>
					</td>
					<td style="font-family: DM Sans, Inter, Helvetica, -apple-system, BlinkMacSystemFont, Roboto, Arial, sans-serif; font-size: 14px; vertical-align: top;" valign="top">&nbsp;</td>
				</tr>
				</table>
			</body>
		</html>';
	}

	/**
	 * Send an admin email because an error ocurred
	 *
	 * @note This function is not used in the plugin right now.
	 *
	 * @param int $post_id  The form post ID.
	 * @param int $message  Content of the email.
	 */
	public static function send_admin_error_mail( $post, $message ) {

		$mail_to = get_post_meta( $post->ID, 'mail_to', true );
		if ( empty( $mail_to ) ) {
			$mail_to = get_bloginfo( 'admin_email' );
		}

		if ( empty( $mail_to ) ) {
			return true;
		}

		$subject = __( 'There were errors on doing a review.', 'contentsync' );

		// add headline
		$mail_title = sprintf(
			__( 'On your WordPress site "%1$s" there were errors while doing a review "%2$s".', 'contentsync' ),
			get_bloginfo( 'name' ),
			get_the_title( $post->ID )
		);

		// links to WordPress
		$links  = " | <a href='".\Contentsync\Main_Helper::get_edit_post_link( $post->ID )."'>".__( 'View post', 'contentsync' ).'</a>';
		$links .= "<br><br><a href='".wp_login_url()."'>".__( 'Login to WordPress', 'contentsync' ).'</a>';

		// mail content
		$mail_content = $mail_title.'<br><br>'.$message.$links;

		// set filter
		add_filter( 'wp_mail_content_type', array( 'Contentsync\Cluster\Mail', 'wpdocs_set_html_mail_content_type' ) );
		add_filter( 'wp_mail_charset', array( 'Contentsync\Cluster\Mail', 'utf8' ) );
		add_action( 'wp_mail_failed', array( 'Contentsync\Cluster\Mail', 'on_mail_error' ) );

		// send Mail
		$return = self::send_mail( $mail_to, $subject, $mail_content );

		// Reset content-type to avoid conflicts -- https://core.trac.wordpress.org/ticket/23578
		remove_filter( 'wp_mail_content_type', array( 'Contentsync\Cluster\Mail', 'wpdocs_set_html_mail_content_type' ) );
		remove_filter( 'wp_mail_charset', array( 'Contentsync\Cluster\Mail', 'utf8' ) );
		remove_filter( 'wp_mail_failed', array( 'Contentsync\Cluster\Mail', 'on_mail_error' ) );

		return $return;
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

		foreach ( get_clusters_including_post( $post_id ) as $cluster ) {
			if ( $cluster->enable_reviews ) {
				$reviewer_ids = array_merge( $reviewer_ids, $cluster->reviewer_ids );
			}
		}

		$mail_to = '';

		if ( !empty( $reviewer_ids ) ) {
			foreach ( $reviewer_ids as $reviewer_id ) {
				$reviewer                  = get_user_by( 'id', $reviewer_id );
				if ( $reviewer ) $mail_to .= $mail_to === '' ? $reviewer->user_email : ', '.$reviewer->user_email;
			}
		}

		if ( empty( $mail_to ) ) {
			$super_admins = get_super_admins();
			foreach ( $super_admins as $super_admin ) {
				$super_admin                  = get_user_by( 'login', $super_admin );
				if ( $super_admin ) $mail_to .= $mail_to === '' ? $super_admin->user_email : ', '.$super_admin->user_email;
			}
		}

		return $mail_to;
	}
}
