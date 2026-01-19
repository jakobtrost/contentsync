<?php

/**
 * Global Post Object
 *
 * The `Synced_Post` class wraps the native WordPress `WP_Post` object and augments it
 * with additional metadata for the global content system. It enables you to access
 * both standard post properties and custom global properties in a unified way. The
 * class ensures that only valid global posts are instantiated and throws exceptions
 * when a post cannot be found, when it is in the trash or when it lacks the required
 * global metadata.
 *
 * When you construct a `Synced_Post` object you may pass either a post ID or an existing
 * post object. The constructor converts an ID into a post object using `get_post`,
 * checks for existence and throws if the post is missing or trashed. It then copies
 * all properties from the underlying post into the new object and loads the meta
 * information via `get_meta`. If the meta data does not indicate a global post the
 * constructor throws an exception. For local posts the constructor sets the language
 * if it is not provided; for remote posts the language is resolved differently.
 *
 * The `get_meta` method gathers metadata associated with the post. It starts with
 * default values provided by `get_contentsync_meta_default_values` and then merges any
 * existing metadata, converting objects to arrays when necessary. For remote posts
 * it calculates additional values such as the global ID and a connection map based
 * on network and blog identifiers. The method returns the complete meta array,
 * ensuring that all required keys are present. A helper `obj2arr` method converts
 * nested objects into arrays using JSON encoding and decoding. When you need to
 * retrieve a `Synced_Post` instance by ID, the static `get_instance` method returns a
 * new object or `false` if the post does not exist. The `new_synced_post` function
 * wraps the class construction in a try/catch block and returns `false` on failure.
 * Together these utilities provide a reliable interface for working with global posts.
 *
 * @since 2.17.0
 */
namespace Contentsync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class used to implement the Synced_Post object.
 *
 * You can create this object by using the function new_synced_post();
 * See end of document for details.
 *
 * @see     WP_Post
 * @source  /wp-includes/class-wp-post.php
 */
#[AllowDynamicProperties]
class Synced_Post {

	/**
	 * =================================================================
	 *                          Default WP_Post args
	 * =================================================================
	 */

	/**
	 * Post ID.
	 *
	 * @var int
	 */
	public $ID;

	/**
	 * The post's slug.
	 *
	 * @var string
	 */
	public $post_name = '';

	/**
	 * The post's title.
	 *
	 * @var string
	 */
	public $post_title = '';

	/**
	 * The post's content.
	 *
	 * @var string
	 */
	public $post_content = '';

	/**
	 * The post's excerpt.
	 *
	 * @var string
	 */
	public $post_excerpt = '';

	/**
	 * The post's local publication time.
	 *
	 * @var string
	 */
	public $post_date = '0000-00-00 00:00:00';

	/**
	 * The post's status.
	 *
	 * @var string
	 */
	public $post_status = 'publish';

	/**
	 * ID of post author.
	 *
	 * @var string
	 */
	public $post_author = 0;

	/**
	 * The post's type, like post or page.
	 *
	 * @since 1.2.0
	 * @var string
	 */
	public $post_type = 'any';


	/**
	 * =================================================================
	 *                          Custom Synced_Post args
	 * =================================================================
	 */

	/**
	 * Global meta info of the post
	 *
	 * @var array
	 */
	public $meta = array();

	/**
	 * The post's blog id
	 *
	 * @var int
	 */
	public $blog_id = 0;

	/**
	 * The post's root site url (remote only)
	 *
	 * @var string|null
	 */
	public $site_url = null;

	/**
	 * The post's root network url (remote only)
	 *
	 * @var string|null
	 */
	public $network_url = null;

	/**
	 * The post's urls (remote only)
	 *
	 * @var array array(edit, blog, nice)
	 */
	public $post_links = array();

	/**
	 * The post's language code
	 *
	 * @var string|null
	 */
	public $language = null;


	/**
	 * =================================================================
	 *                          Functions
	 * =================================================================
	 */

	/**
	 * Constructor.
	 *
	 * @param WP_Post|object|int $post  Post object or Post ID.
	 */
	public function __construct( $post ) {

		// support post_id as input
		if ( ! is_object( $post ) ) {
			$post = get_post( $post );
		}

		// 404
		if ( ! $post || ! is_object( $post ) ) {
			throw new Exception( 'Post could not be found.' );
			return;
		}

		// trash
		if ( $post->post_status == 'trash' ) {
			throw new Exception( 'Post status is trashed.' );
		}

		// set object vars
		foreach ( get_object_vars( $post ) as $key => $value ) {
			$this->$key = $value;
		}

		// set the meta infos
		$this->meta = $this->get_meta();

		// not a global post
		if ( empty( $this->meta['synced_post_status'] ) || empty( $this->meta['synced_post_id'] ) ) {
			throw new Exception( 'Post is not a global post.' );
		}

		// set language
		if ( $this->language === null && ! $this->network_url ) {
			$this->language = \Contentsync\Main_Helper::get_post_language_code( $post );
		}
	}

	/**
	 * Retrieve global content meta for the current post.
	 *
	 * This helper method assembles all meta data that describes a global
	 * post. It begins with the default values provided by the
	 * `get_contentsync_meta_default_values()` method and then merges in any
	 * existing post meta stored in the database. For remote posts it
	 * calculates additional values such as the global ID and connection
	 * map. When called on a local post with pre‑existing `$this->meta`
	 * values, those values are normalized to arrays and merged into the
	 * defaults. When called on a remote post the method constructs the
	 * necessary meta values based on the `blog_id` and `network_url`
	 * properties. The resulting associative array includes keys such as
	 * `synced_post_status`, `synced_post_id` and `contentsync_connection_map` and is
	 * guaranteed to contain every required meta key.
	 *
	 * @return array Associative array of meta values for this Synced_Post.
	 */
	public function get_meta() {

		// get default meta
		$meta = \Contentsync\get_contentsync_meta_default_values();

		// if the post meta does already exist we make sure that all contentsync_meta infos are set
		if ( ! empty( $this->meta ) ) {

			$this->meta = $this->obj2arr( $this->meta );

			foreach ( $meta as $meta_key => $default ) {
				if ( isset( $this->meta[ $meta_key ] ) ) {

					$meta_value = $this->obj2arr( $this->meta[ $meta_key ] );

					if ( ! function_exists( 'array_key_first' ) ) {
						function array_key_first( array $arr ) {
							foreach ( $arr as $key => $unused ) {
								return $key;
							}
							return null;
						}
					}

					// the meta value was saved as a zero-indexed array
					// this usually happens via the post-export process
					if ( is_array( $meta_value ) && array_key_first( $meta_value ) === 0 ) {
						$this->meta[ $meta_key ] = $meta_value[0];
					}
				} else {
					// get the post meta
					$meta_value = get_post_meta( $this->ID, $meta_key, true );
					if ( $meta_value ) {
						$this->meta[ $meta_key ] = $meta_value;
					}
				}
			}
			return $this->meta;
		}

		// normal posts
		if ( ! $this->network_url ) {
			foreach ( $meta as $meta_key => $default ) {
				$meta_value = get_post_meta( $this->ID, $meta_key, true );
				if ( $meta_value ) {
					$meta[ $meta_key ] = $meta_value;
				}
			}

			// set the blog id the post is on.
			if ( $this->blog_id == 0 ) {
				$this->blog_id = get_current_blog_id();
			}
		}
		// remote posts
		else {
			/**
			 * Remote posts set some attributes, that normal posts don't:
			 *
			 * @var string synced_post_status
			 * @var string blog_id
			 * @var string network_url
			 *
			 * We use them to calculate some required meta infos, like the global ID.
			 *
			 * @see \Contentsync\Api\Posts->get_global_posts_for_endpoint() for details
			 */

			// synced_post_status
			$meta['synced_post_status'] = 'root';

			// synced_post_id
			if ( ! empty( $this->blog_id ) ) {
				$gid = $this->blog_id . '-' . $this->ID;

				if ( ! empty( $this->network_url ) ) {
					$gid .= '-' . \Contentsync\Main_Helper::get_nice_url( $this->network_url );
				}

				$meta['synced_post_id'] = $gid;
			}

			// contentsync_connection_map (add connection only for current site)
			if ( isset( $gid ) && empty( $this->meta['contentsync_connection_map'] ) ) {

				$imported_post = \Contentsync\Main_Helper::get_local_post_by_gid( $gid );
				if ( $imported_post && isset( $imported_post->ID ) ) {
					$blog_id                            = get_current_blog_id();
					$net_url                            = \Contentsync\Main_Helper::get_network_url();
					$meta['contentsync_connection_map'] = array(
						$net_url => array(
							$blog_id => \Contentsync\Main_Helper::create_post_connection_map_array( $blog_id, $imported_post->ID ),
						),
					);
				}
			}
		}

		return $meta;
	}

	/**
	 * Convert nested objects or arrays into pure arrays.
	 *
	 * This utility method recursively converts any objects contained
	 * within the provided value into associative arrays. It uses JSON
	 * encoding/decoding to perform the conversion. If the input is
	 * neither an array nor an object the original value is returned
	 * unchanged.
	 *
	 * @param mixed $object Object or array to convert.
	 * @return mixed Converted array or original value when not array/object.
	 */
	public function obj2arr( $object ) {
		if ( is_object( $object ) || is_array( $object ) ) {
			return json_decode( json_encode( $object ), true );
		}
		return $object;
	}

	/**
	 * Retrieve a `Synced_Post` instance by WordPress post ID.
	 *
	 * This static helper safely constructs a `Synced_Post` wrapper around
	 * an existing WordPress post. It casts the provided ID to an
	 * integer, fetches the underlying `WP_Post` object and returns
	 * `false` if no such post exists. On success a new `Synced_Post`
	 * instance is returned.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return Synced_Post|false A `Synced_Post` object on success, false if the post cannot be found.
	 */
	public static function get_instance( $post_id ) {

		$post_id = (int) $post_id;
		if ( ! $post_id ) {
			return false;
		}

		$_post = get_post( $post_id );

		if ( ! $_post ) {
			return false;
		}

		return new Synced_Post( $_post );
	}
}

/**
 * Create and return a new `Synced_Post` wrapper for a WordPress post.
 *
 * This convenience function instantiates the `Synced_Post` class using
 * either a `WP_Post` object or a post ID. Any exceptions thrown
 * during instantiation (for example if the post does not exist or
 * does not qualify as a global post) are caught and the function
 * returns `false`. On success the new `Synced_Post` instance is
 * returned to the caller.
 *
 * @param WP_Post|object|int $post Post object or post ID to wrap.
 *
 * @return Synced_Post|false A `Synced_Post` on success, or `false` if instantiation fails.
 */
function new_synced_post( $post ) {
	try {
		$synced_post = new Synced_Post( $post );
	} catch ( Exception $e ) {
		return false;
	}
	return $synced_post;
}
