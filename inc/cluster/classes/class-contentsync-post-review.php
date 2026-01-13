<?php
/**
 * Global post review object
 *
 * This file defines the `Synced_Post_Review` class, which models a review
 * for a global post as part of the cluster review workflow. A post
 * review records the IDs of the blog, post and editor involved, the
 * time of the review, the review state (e.g. new, in_review, denied,
 * approved, reverted) and a snapshot of the previous post state. The
 * static `get_instance` method loads a post review from the database,
 * while the constructor assigns the retrieved properties to the
 * instance. Helper methods allow you to set and get properties and to
 * format the review date. Use this class to inspect or modify post
 * reviews in your own logic.
 *
 * @since 2.17.0
 */

if ( !defined( 'ABSPATH' ) ) {
	exit;
}

final class Synced_Post_Review {


	/**
	 * Post Review ID
	 *
	 * @var int
	 */
	public $ID;

	/**
	 * Blog ID
	 *
	 * @var int
	 */
	public $blog_id;

	/**
	 * Post ID
	 *
	 * @var int
	 */
	public $post_id;

	/**
	 * Editor (User ID)
	 *
	 * @var int
	 */
	public $editor;

	/**
	 * Date of review
	 *
	 * @var string
	 */
	public $date;

	/**
	 * State
	 *
	 * @var string (new, in_review, denied, approved, reverted)
	 */
	public $state;

	/**
	 * Previous Post (object)
	 *
	 * @var object
	 */
	public $previous_post;

	/**
	 * @deprecated
	 */
	public $messages;


	public static function get_instance( $post_review_id ) {
		global $wpdb;

		$post_review_id = (int)$post_review_id;
		if ( !$post_review_id ) {
			return false;
		}
		$table_name   = $wpdb->base_prefix.'synced_post_reviews';
		$_post_review = $wpdb->get_row( "SELECT * FROM $table_name WHERE ID = $post_review_id" );

		if ( !$_post_review ) {
			return false;
		}

		return new Synced_Post_Review( $_post_review );
	}

	/**
	 * Constructor.
	 *
	 * @since 3.5.0
	 *
	 * @param Synced_Post_Review|object $post_review Post Review object.
	 */
	public function __construct( $post_review ) {
		foreach ( get_object_vars( $post_review ) as $key => $value ) {
			if ( 'previous_post' === $key ) {
				$this->$key = unserialize( $value );
				continue;
			}
			if ( 'id' === $key ) {
				$this->ID = (int)$value;
				continue;
			}

			$this->$key = $value;
		}
	}

	public function set( $key, $value ) {
		$this->$key = $value;
	}

	public function get( $key ) {
		return $this->$key;
	}

	public function get_date() {
		return date_i18n( __( 'M j, Y @ H:i' ), strtotime( $this->date ) );
	}

	public function get_editor() {
		$user = get_userdata( $this->editor );
		return $user ? $user->display_name : 'N/A';
	}

	public function get_messages() {
		$messages = !empty( $this->messages ) ? unserialize( $this->messages ) : array();
		if ( !empty( $messages ) ) {
			$review_id = $this->ID;
			$messages  = array_map(
				function ( $message ) use ( $review_id ) {
					return new Synced_Post_Review_Message( $review_id, $message );
				},
				$messages
			);
			// Create an array of 'timestamp' values for the array_multisort() function
			$dates = array();
			foreach ($messages as $key => $row) {
				$dates[$key] = $row->timestamp;
			}
			// Sort the data with timestamp descending, which means newest first
			array_multisort($dates, SORT_DESC, $messages);
		}
		return $messages;
	}

	public function insert() {
		global $wpdb;

		$wpdb->insert(
			$wpdb->base_prefix.'synced_post_reviews',
			array(
				'blog_id'       => $this->blog_id,
				'post_id'       => $this->post_id,
				'editor'        => $this->editor,
				'date'          => $this->date,
				'state'         => $this->state,
				'previous_post' => serialize( $this->previous_post ),
			)
		);

		return $wpdb->insert_id;
	}
}
