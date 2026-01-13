<?php
/**
 * Remote destination class.
 * 
 * The `Remote_Destination` class represents a remote network in the content distribution 
 * system. It extends the base `Destination` class and manages a collection of 
 * `Blog_Destination` objects that belong to that network. Use this class when you 
 * distribute content across multiple sites within a remote network.
 * 
 * When you create a `Remote_Destination` you can provide either a string representing 
 * the remote network URL or an array of properties. If an array is passed, the constructor 
 * assigns the properties and sets the timestamp. Otherwise it calls the parent 
 * `Destination` constructor with the provided URL. This flexibility accommodates 
 * different initialization patterns.
 * 
 * The `$blogs` property stores an associative array of `Blog_Destination` objects 
 * keyed by blog ID. The `add_blog` method instantiates a new `Blog_Destination` with 
 * the given blog ID and optional properties and stores it in the array. The `set_blog` 
 * method updates an existing blog's properties or creates a new blog if none exists.  
 * The `get_blog` method retrieves a blog by ID or returns `null` if it has not been 
 * added. The `add_post_to_blog` method delegates to `set_blog` to ensure the blog 
 * exists and then calls `set_post` on the blog to add a post with the provided 
 * identifiers and properties. These methods simplify managing multiple blogs under a 
 * single remote destination.
 * 
 * Two additional methods help you manage properties across the network. The 
 * `set_properties` method assigns the values of a provided array to properties on 
 * the object. The `inherit_properties_to_posts` method iterates through each blog 
 * and calls `inherit_properties_to_posts` to propagate settings to all posts. This 
 * is useful when you want consistent export or import behaviour across all blogs in 
 * a network. Always verify that the inherited properties are appropriate for all 
 * posts to avoid unexpected results.
 * 
 * @since 2.17.0
 */

namespace Contentsync\Distribution;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

#[AllowDynamicProperties]
class Remote_Destination extends Destination {

	/**
	 * Remote network URL.
	 * 
	 * @var int
	 */
	public $ID;
	
	/**
	 * Blogs.
	 * 
	 * @var Blog_Destination[]
	 */
	public $blogs = array();

	public function __construct( $remote_network_url_or_properties ) {

		if ( is_array( $remote_network_url_or_properties ) ) {
			$this->set_properties( $remote_network_url_or_properties );
			$this->timestamp = time();
		}
		else {
			parent::__construct( $remote_network_url_or_properties );
		}
	}

	/**
	 * Add a blog destination to this remote network.
	 *
	 * Instantiates a new `Blog_Destination` with the provided blog ID and
	 * optional properties and stores it in the `$blogs` array keyed by
	 * blog ID. Returns the newly created destination.
	 *
	 * @param int   $blog_id    Identifier of the blog within the remote network.
	 * @param array $properties Optional associative array of blog properties.
	 *
	 * @return Blog_Destination The newly created blog destination object.
	 */
	public function add_blog( $blog_id, $properties=array() ) {
		$this->blogs[ $blog_id ] = new Blog_Destination( $blog_id, $properties );
		return $this->blogs[ $blog_id ];
	}

	/**
	 * Set or update a blog destination on this remote network.
	 *
	 * Creates a new `Blog_Destination` if one for the given ID does not
	 * exist, otherwise updates the existing destination’s properties.
	 * Returns the resulting `Blog_Destination` instance.
	 *
	 * @param int   $blog_id    Identifier of the blog within the remote network.
	 * @param array $properties Optional associative array of blog properties.
	 *
	 * @return Blog_Destination The updated or newly created blog destination.
	 */
	public function set_blog( $blog_id, $properties=array() ) {
		if ( ! isset( $this->blogs[ $blog_id ] ) ) {
			$this->add_blog( $blog_id, $properties );
		}
		else {
			$this->blogs[ $blog_id ]->set_properties( $properties );
		}

		return $this->blogs[ $blog_id ];
	}

	/**
	 * Retrieve a blog destination by its ID.
	 *
	 * Looks up the blog destination keyed by the provided blog ID and
	 * returns it if found or `null` otherwise.
	 *
	 * @param int $blog_id Identifier of the blog to look up.
	 *
	 * @return Blog_Destination|null The blog destination or null if none exists.
	 */
	public function get_blog( $blog_id ) {
		return isset( $this->blogs[ $blog_id ] ) ? $this->blogs[ $blog_id ] : null;
	}

	/**
	 * Add a post entry to a blog destination.
	 *
	 * Ensures that a blog destination exists for the given blog ID and
	 * delegates to its {@see Blog_Destination::set_post()} method to
	 * add or update a post entry. Does not return a value.
	 *
	 * @param int   $blog_id      Identifier of the blog within the remote network.
	 * @param int   $root_post_id ID of the root post on the origin site.
	 * @param int   $post_id      ID of the linked post on the destination site.
	 * @param array $properties   Optional associative array of additional properties.
	 *
	 * @return void
	 */
	public function add_post_to_blog( $blog_id, $root_post_id, $post_id, $properties=array() ) {
		if ( ! isset( $this->blogs[ $blog_id ] ) ) {
			$this->set_blog( $blog_id );
		}

		$this->blogs[ $blog_id ]->set_post( $root_post_id, $post_id, $properties );
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
		foreach ( $this->blogs as $blog ) {
			$blog->inherit_properties_to_posts( $properties );
		}
	}
}