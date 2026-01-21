<?php
/**
 * Blog destination class.
 *
 * The `Blog_Destination` class models a single blog within the distribution framework.
 * It extends the base `Destination` class and provides an interface to work with posts
 * belonging to that blog. You use this class when you need to distribute content to
 * specific posts in a blog.
 *
 * When you instantiate a `Blog_Destination` object you provide an identifier and an
 * optional array of properties. The constructor calls the parent `Destination` constructor,
 * sets the timestamp and other properties, and then parses an incoming `posts` property.
 * If the `posts` array is present, the constructor iterates through each entry and adds
 * them as `Post_Destination` objects using `add_post`. This design keeps the initialization
 * flexible and ensures the object always knows which posts it manages.
 *
 * The class offers methods to manage posts. The `add_post` method creates a new
 * `Post_Destination` instance and stores it in the `posts` array keyed by the root post ID.
 * The `set_post` method updates or creates a post entry depending on whether it already
 * exists. The `get_post` method returns the associated `Post_Destination` object or `null`
 * if none is set. There is also a `set_properties` method that iterates through a provided
 * array and assigns each key to a property on the current object. Finally,
 * `inherit_properties_to_posts` iterates through each stored post and applies the given
 * properties via `set_properties`. Use this method to propagate common configuration
 * options across all posts. You should ensure that the properties you inherit are
 * appropriate for every post to avoid unintended overrides.
 *
 */
namespace Contentsync\Distribution\Destinations;

use Contentsync\Utils\Logger;
use Contentsync\Distribution\Destinations\Post_Destination;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

#[AllowDynamicProperties]
class Blog_Destination extends Destination {

	/**
	 * Blog ID.
	 *
	 * @var int
	 */
	public $ID;

	/**
	 * Posts.
	 *
	 * @var Post_Destination[]
	 */
	public $posts = array();

	public function __construct( $ID, $properties = array() ) {
		parent::__construct( $ID, $properties );

		if ( isset( $properties['posts'] ) ) {
			$this->posts = array();
			foreach ( $properties['posts'] as $root_post_id => $properties ) {
				if ( ! isset( $properties['ID'] ) ) {
					continue;
				}
				$this->add_post( $root_post_id, $properties['ID'], $properties );
			}
		}
	}

	/**
	 * Add a post to the blog destination.
	 *
	 * Creates a new `Post_Destination` entry keyed by the given root post
	 * ID. The linked post ID identifies the corresponding post on the
	 * destination site and optional properties are passed to the
	 * `Post_Destination` constructor.
	 *
	 * @param int   $root_post_id   ID of the root post on the origin site.
	 * @param int   $linked_post_id ID of the linked post on the destination site.
	 * @param array $properties     Optional associative array of additional properties.
	 *
	 * @return void
	 */
	public function add_post( $root_post_id, $linked_post_id, $properties = array() ) {
		$this->posts[ $root_post_id ] = new Post_Destination( $linked_post_id, $properties );
	}

	/**
	 * Set or update a post in the blog destination.
	 *
	 * If a post for the given root post ID does not yet exist it is
	 * created via {@see add_post()}. Otherwise the existing
	 * `Post_Destination` is updated with the new linked post ID and
	 * properties. Returns the resulting `Post_Destination` object for
	 * chaining.
	 *
	 * @param int   $root_post_id   ID of the root post on the origin site.
	 * @param int   $linked_post_id ID of the linked post on the destination site.
	 * @param array $properties     Optional associative array of additional properties.
	 *
	 * @return Post_Destination The updated or newly created post destination.
	 */
	public function set_post( $root_post_id, $linked_post_id, $properties = array() ) {
		if ( ! isset( $this->posts[ $root_post_id ] ) ) {
			$this->add_post( $root_post_id, $linked_post_id, $properties );
		} else {
			$this->posts[ $root_post_id ]->ID = $linked_post_id;
			$this->posts[ $root_post_id ]->set_properties( $properties );
		}

		return $this->posts[ $root_post_id ];
	}

	/**
	 * Retrieve a post destination by root post ID.
	 *
	 * Returns the `Post_Destination` object for the specified root post
	 * ID if it exists, or `null` if no such post has been added.
	 *
	 * @param int $root_post_id Root post ID to lookup.
	 *
	 * @return Post_Destination|null The matching `Post_Destination` or null if none.
	 */
	public function get_post( $root_post_id ) {
		return isset( $this->posts[ $root_post_id ] ) ? $this->posts[ $root_post_id ] : null;
	}

	/**
	 * Set properties.
	 */
	public function set_properties( $properties ) {
		foreach ( $properties as $property => $value ) {
			$this->$property = $value;
		}
	}

	/**
	 * Inherit properties to all posts.
	 *
	 * @param array $properties
	 */
	public function inherit_properties_to_posts( $properties ) {
		foreach ( $this->posts as $post ) {
			$post->set_properties( $properties );
		}
	}
}
