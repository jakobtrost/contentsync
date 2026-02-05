<?php
/**
 * Abstract base class for review notification emails.
 *
 * Subclasses implement get_subject() and get_message() to provide
 * subject and message based on post_review and post. The base handles
 * HTML generation from the template, filters, and sending via wp_mail.
 */

namespace Contentsync\Reviews\Mails;

use WP_Error;
use Contentsync\Reviews\Post_Review;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract review mail base.
 */
abstract class Review_Mail_Base {

	/**
	 * Email address(es) to send to.
	 *
	 * @var string
	 */
	protected $mail_to;

	/**
	 * Post review object.
	 *
	 * @var Post_Review
	 */
	protected $post_review;

	/**
	 * WordPress post object.
	 *
	 * @var \WP_Post
	 */
	protected $post;

	/**
	 * Email headers (optional).
	 *
	 * @var string|string[] Array of strings or string.
	 */
	protected $headers;

	/**
	 * Email subject, set by get_subject().
	 *
	 * @var string
	 */
	protected $subject;

	/**
	 * Email message, set by get_message().
	 *
	 * @var string
	 */
	protected $message;

	/**
	 * Array of mail errors.
	 *
	 * @var array
	 */
	private $mail_errors = array();

	/**
	 * Constructor.
	 *
	 * @param string          $mail_to    Email address(es) to send to.
	 * @param Post_Review     $post_review Post review object.
	 * @param \WP_Post        $post       WordPress post.
	 * @param string|string[] $headers Optional. Email headers. Default ''.
	 */
	public function __construct( $mail_to, Post_Review $post_review, $post, $headers = '' ) {
		$this->mail_to     = $mail_to;
		$this->post_review = $post_review;
		$this->post        = $post;
		$this->headers     = $headers;

		$this->subject = $this->get_subject();
		$this->message = $this->get_message();
	}

	/**
	 * Build the email subject from post_review and post.
	 *
	 * @return string
	 */
	abstract protected function get_subject(): string;

	/**
	 * Build the email message from post_review and post.
	 *
	 * @return string
	 */
	abstract protected function get_message(): string;

	/**
	 * Default footer: site name as link, optionally blog description.
	 *
	 * @return string
	 */
	protected function get_default_footer(): string {
		$sitetitle = get_bloginfo( 'name' );
		if ( empty( $sitetitle ) ) {
			return '';
		}
		$footer          = '<a href="' . get_home_url() . '" style="color: #999999; font-size: 12px; text-align: center; text-decoration: none;">' . $sitetitle . '</a>';
		$blogdescription = get_bloginfo( 'description' );
		if ( ! empty( $blogdescription ) ) {
			$footer .= '  |  ' . $blogdescription;
		}
		return $footer;
	}

	/**
	 * Build full HTML from title, body and optional footer.
	 *
	 * @see https://github.com/leemunroe/responsive-html-email-template
	 *
	 * @param string      $title  Email title/heading.
	 * @param string      $body   Main email body content.
	 * @param string|null $footer Footer content. If null, uses get_default_footer().
	 * @return string Full HTML email.
	 */
	protected function build_html_from_parts( $title, $body, $footer = null ): string {
		if ( $footer === null ) {
			$footer = $this->get_default_footer();
		}
		ob_start();
		require __DIR__ . '/html-template.php';
		return ob_get_clean();
	}

	/**
	 * Get HTML for the current subject and message.
	 *
	 * @return string Full HTML email.
	 */
	public function getHTML(): string {
		return $this->build_html_from_parts( $this->subject, $this->message, null );
	}

	/**
	 * Send the email.
	 *
	 * Applies contentsync_reviews_mail_to, contentsync_reviews_mail_subject,
	 * contentsync_reviews_mail_content, and contentsync_reviews_mail_headers,
	 * then sends via wp_mail. Returns false if the recipient (mail_to) is
	 * empty after applying the mail_to filter.
	 *
	 * @return bool|WP_Error True on success, false when no recipient, or WP_Error on failure.
	 */
	public function send() {
		$review_id = $this->post_review->ID;

		/**
		 * Filter to modify the email recipient address for review notifications.
		 *
		 * This filter allows developers to customize who receives review notification
		 * emails, enabling dynamic recipient management based on review context.
		 * If the filtered value is empty, send() returns false to indicate the mail
		 * could not be sent.
		 *
		 * @filter contentsync_reviews_mail_to
		 * @param string $mail_to   The email address(es) to send the notification to.
		 * @param int    $review_id The ID of the review.
		 * @return string The modified email recipient address(es).
		 */
		$mail_to = apply_filters( 'contentsync_reviews_mail_to', $this->mail_to, $review_id );

		if ( empty( $mail_to ) ) {
			return new WP_Error( 'no_recipient', __( 'No recipient mail address found', 'contentsync' ) );
		}

		/**
		 * Filter to modify the email subject line for review notifications.
		 *
		 * This filter allows developers to customize the subject line of review
		 * notification emails, enabling dynamic subject formatting.
		 *
		 * @filter contentsync_reviews_mail_subject
		 * @param string $subject   The email subject line.
		 * @param int    $review_id The ID of the review.
		 * @return string The modified email subject line.
		 */
		$subject = apply_filters( 'contentsync_reviews_mail_subject', $this->subject, $review_id );

		/**
		 * Filter to modify the email message content for review notifications.
		 *
		 * This filter allows developers to customize the HTML content of review
		 * notification emails, enabling dynamic message formatting and content.
		 *
		 * @filter contentsync_reviews_mail_content
		 * @param string $message   The HTML content of the email message body.
		 * @param int    $review_id The ID of the review.
		 * @return string The modified email message content.
		 */
		$message = apply_filters( 'contentsync_reviews_mail_content', $this->message, $review_id );

		/**
		 * Filter to modify the email headers for review notifications.
		 *
		 * This filter allows developers to customize the email headers for review
		 * notification emails, enabling custom headers like CC, BCC, or other
		 * email headers supported by wp_mail().
		 *
		 * @filter contentsync_reviews_mail_headers
		 * @see https://developer.wordpress.org/reference/functions/wp_mail/
		 * @param string|string[] $headers   The email headers string or array.
		 * @param int             $review_id The ID of the review.
		 * @return string|string[] The modified email headers.
		 */
		$headers = apply_filters( 'contentsync_reviews_mail_headers', $this->headers, $review_id );

		$html = $this->build_html_from_parts( $subject, $message, null );

		$this->mail_errors = array(); // reset mail errors

		add_filter( 'wp_mail_content_type', array( $this, 'set_html_mail_content_type' ) );
		add_filter( 'wp_mail_charset', array( static::class, 'get_utf8_charset' ) );
		add_action( 'wp_mail_failed', array( static::class, 'preserve_mail_error' ) );

		$return = wp_mail( $mail_to, $subject, $html, $headers );

		remove_filter( 'wp_mail_content_type', array( $this, 'set_html_mail_content_type' ) );
		remove_filter( 'wp_mail_charset', array( static::class, 'get_utf8_charset' ) );
		remove_action( 'wp_mail_failed', array( static::class, 'preserve_mail_error' ) );

		// return the first mail error
		if ( ! empty( $this->mail_errors ) ) {
			return reset( $this->mail_errors );
		}

		return $return ? true : new WP_Error( 'mail_failed', __( 'Mail failed to send', 'contentsync' ) );
	}

	/**
	 * Set mail charset to UTF-8.
	 *
	 * @return string
	 */
	public static function get_utf8_charset(): string {
		return 'UTF-8';
	}

	/**
	 * Set mails to HTML.
	 *
	 * @see https://developer.wordpress.org/reference/functions/wp_mail/
	 * @return string
	 */
	public function set_html_mail_content_type(): string {
		return 'text/html';
	}

	/**
	 * On wp_mail() error event,
	 *
	 * @param \WP_Error $wp_error Error from wp_mail_failed.
	 */
	public static function preserve_mail_error( $wp_error ): void {
		$this->mail_errors[] = $wp_error;
	}
}
