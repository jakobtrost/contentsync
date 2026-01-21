<?php
/**
 * Distribution item class.
 *
 * The `Distribution_Item` class represents a unit of work within the global content
 * distribution system. Each instance encapsulates the posts being distributed and the
 * destination to which they are sent. It also stores metadata about the origin and
 * status of the distribution. This class is used when saving and updating distribution
 * information in the database.
 *
 * When you construct a `Distribution_Item` you can pass either an array or an object
 * containing the properties to assign. The constructor converts an array into an object
 * and returns early if the provided value is not an object. It then iterates through
 * all properties using `get_object_vars` and assigns them to the current instance,
 * unserializing each value if necessary. This approach simplifies the initialization
 * from database results or user input.
 *
 * The class tracks several properties: an `$ID` for the database record, a `$status`
 * string to indicate progress, an `$error` that may hold a `WP_Error`, a `$time`
 * timestamp, and optional `$origin` and `$origin_id` fields used when the distribution
 * originates from another site. A `$destination` property stores a `Blog_Destination`
 * or `Remote_Destination` instance, and `$posts` holds an array of prepared post objects.
 * These fields allow the distribution system to coordinate complex operations across
 * multiple sites.
 *
 * Accessor and mutator methods `get` and `set` provide simple access to properties by
 * key. The `save` method sets the `$time` to the current time in SQL format and then
 * calls `save_distribution_item` to persist the item. If the save succeeds it stores
 * the returned ID. The `delete` method removes the record by calling
 * `delete_distribution_item`. The `update` method merges provided properties into the
 * current instance, updates the timestamp, and then saves the record. It includes a
 * mechanism to inform an origin site about updates using
 * `\Contentsync\Api\update_distribution_item`, although the comment notes that this
 * code path is currently unused. These methods abstract the persistence logic and let
 * higher-level code focus on distribution workflows.
 *
 */
namespace Contentsync\Distribution;

use Contentsync\Utils\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Distribution Item.
 *
 * This is a representation of a distribution item that is being processed and
 * saved in the database.
 *
 * It contains the posts that are being distributed and a single destination
 * to which the posts are being distributed.
 */
class Distribution_Item {

	/**
	 * DB ID.
	 *
	 * @var int
	 */
	public $ID;

	/**
	 * Status of the distribution.
	 *
	 * @var string 'init|started|success|failed'
	 */
	public $status = 'init';

	/**
	 * WP_Error.
	 *
	 * @var WP_Error
	 */
	public $error;

	/**
	 * Timestamp.
	 */
	public $time = 0;

	/**
	 * Origin URL.
	 * This is only used, when the distribution is started from a remote site.
	 * After the distribution is completed, the origin site will be informed
	 * and the related Distribution_Item on the origin site will be updated.
	 */
	public $origin;

	/**
	 * Origin Distribution_Item ID.
	 * This is only used, when the distribution is started from a remote site.
	 * After the distribution is completed, the origin site will be informed
	 * and the related Distribution_Item on the origin site will be updated.
	 */
	public $origin_id;

	/**
	 * Destination sites and their status.
	 *
	 * @var Blog_Destination|Remote_Destination
	 */
	public $destination

	/**
	 * Prepared_Post Objects
	 *
	 * @var Prepared_Post_Object[]
	 */
	public $posts;

	/**
	 * Constructor.
	 */
	public function __construct( $item ) {

		if ( is_array( $item ) ) {
			$item = (object) $item;
		}

		if ( ! $item || ! is_object( $item ) ) {
			return null;
		}

		// set object vars
		foreach ( get_object_vars( $item ) as $key => $value ) {
			$this->$key = maybe_unserialize( $value );
		}

		// // save if ID is empty
		// if ( empty( $this->ID ) ) {
		// $result = $this->save();
		// }

		// $this->time = time();

		return $this;
	}

	/**
	 * Get a property.
	 *
	 * @param string $key
	 *
	 * @return mixed
	 */
	public function get( $key ) {
		return isset( $this->$key ) ? $this->$key : null;
	}

	/**
	 * Set a property.
	 *
	 * @param string $key
	 * @param mixed  $value
	 */
	public function set( $key, $value ) {
		$this->$key = $value;
	}

	/**
	 * Save the item to the database.
	 */
	public function save() {

		// current time in sql format
		$this->time = current_time( 'mysql' );

		// attempt to save the item to the database
		$ID = save_distribution_item( $this );

		if ( is_wp_error( $ID ) ) {
			return $ID;
		}

		// set the ID
		$this->ID = $ID;

		return $ID;
	}

	public function delete() {
		return delete_distribution_item( $this->ID );
	}

	/**
	 * Update the item.
	 *
	 * @param array $properties
	 *
	 * @return bool|WP_Error
	 */
	public function update( $properties = array() ) {

		if ( ! empty( $properties ) ) {
			foreach ( $properties as $key => $value ) {
				$this->$key = $value;
			}
		}

		// current time in sql format
		$this->time = current_time( 'mysql' );

		$result = save_distribution_item( $this );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/**
		 * Directly let the origin site know about the update.
		 *
		 * Since background processing is not working inside rest requests,
		 * we do inform the origin site directly. So currently this code is
		 * unused, but we leave it here for future use.
		 *
		 * @see Distribution_Endpoint->handle_distribute_item_request()
		 * @see \Contentsync\Api\update_distribution_item()
		 */
		if ( ! empty( $this->origin ) && ! empty( $this->origin_id ) ) {
			$result = \Contentsync\Api\update_distribution_item(
				$this->origin,
				$this->origin_id,
				$this->status,
				$this->destination
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}
}
