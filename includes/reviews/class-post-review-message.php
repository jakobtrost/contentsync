<?php
/**
 * Synced post review message object
 *
 * This file defines the `Post_Review_Message` class, representing
 * a single message associated with a post review. Each message records
 * a timestamp, the reviewer’s user ID, the content of the message,
 * the action taken (e.g. comment, decision) and the related post
 * review ID. The class provides helper methods to format the date and
 * retrieve the reviewer’s display name. Use this class when storing
 * or presenting comments and notes in the post review workflow.
 *
 * @since 2.17.0
 */
namespace Contentsync\Reviews;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Post_Review_Message {


	/**
	 * Timestamp
	 *
	 * @var string
	 */
	public $timestamp;

	/**
	 * Reviewer (User ID)
	 *
	 * @var int
	 */
	public $reviewer;

	/**
	 * Content
	 *
	 * @var string
	 */
	public $content;

	/**
	 * Action
	 *
	 * @var string
	 */
	public $action;

	/**
	 * Post Review ID
	 *
	 * @var int
	 */
	public $post_review_id;



	/**
	 * Constructor.
	 *
	 * @since 3.5.0
	 *
	 * @param int   $post_review_id The post review ID
	 * @param array $args              The message arguments
	 */
	public function __construct( $post_review_id, $args ) {
		if ( ! is_array( $args ) ) {
			$args = array();
		}

		$this->timestamp      = isset( $args['timestamp'] ) ? $args['timestamp'] : '';
		$this->reviewer       = isset( $args['reviewer'] ) ? $args['reviewer'] : 0;
		$this->content        = isset( $args['content'] ) ? $args['content'] : '';
		$this->action         = isset( $args['action'] ) ? $args['action'] : '';
		$this->post_review_id = $post_review_id;
	}

	public function get_date() {
		return date_i18n( __( 'M j, Y @ H:i' ), $this->timestamp );
	}

	public function get_reviewer() {
		$user = get_userdata( $this->reviewer );
		return $user ? $user->display_name : 'N/A';
	}

	public function get_content( $escape = false ) {
		$content = nl2br( $this->content );
		if ( $escape ) {
			return preg_replace( '/[^\w\<>.,;-_?()!&%#+* ]/u', '', str_replace( '<br />', '<br>', nl2br( $content ) ) );
		}
		return $content;
	}

	public function save() {
		// save to db
		global $wpdb;

		$post_review_id = (int) $this->post_review_id;
		if ( ! $post_review_id ) {
			return false;
		}

		// get messages
		$messages = get_messages_by_post_review_id( $post_review_id );
		if ( ! is_array( $messages ) ) {
			$messages = array();
		}
		$new_message = array(
			'timestamp' => $this->timestamp,
			'reviewer'  => $this->reviewer,
			'content'   => $this->content,
			'action'    => $this->action,
		);

		// add to old messages
		$messages[] = $new_message;

		$wpdb->update(
			$wpdb->base_prefix . 'synced_post_reviews',
			array(
				'messages' => serialize( $messages ),
			),
			array( 'ID' => $post_review_id )
		);

		return $post_review_id;
	}
}
