<?php
/**
 * Destination class.
 *
 * The `Destination` class forms the foundation for distribution targets in the global
 * content system. When you distribute content, you define a destination such as a blog,
 * a post or a remote network. This base class holds core properties that every destination
 * shares. It does not perform operations itself but provides a consistent structure that
 * other destination classes extend.
 *
 * Every `Destination` instance has an identifier stored in the `$ID` property. This could
 * be a post ID, a connection ID, or a blog ID depending on the context. The constructor
 * records the current time in the `$timestamp` property and sets the initial status to
 * `'init'`. It accepts an optional array of properties and assigns each key to the
 * corresponding property on the object. This flexibility allows derived classes to pass
 * custom settings without repeating assignment logic.
 *
 * The class also defines a `$status` property for tracking the progress of a distribution.
 * Typical values include `'init'`, `'started'`, `'success'` and `'failed'`. The `$error`
 * property can hold a `WP_Error` object if an error occurs. A `$url` property is available
 * for storing a destination URL when needed. While the class itself does not enforce any
 * constraints on these values, derived classes or the distribution process will update
 * them as appropriate. Remember to instantiate this class or its subclasses within a
 * defined `ABSPATH` context to prevent execution outside of WordPress.
 *
 * @since 2.17.0
 */
namespace Contentsync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

#[AllowDynamicProperties]
class Destination {

	/**
	 * ID.
	 * Could be the post ID, connection ID or blog ID.
	 *
	 * @var int
	 */
	public $ID;

	/**
	 * Timestamp.
	 */
	public $timestamp = 0;

	/**
	 * Overall status of the distribution.
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
	 * URL.
	 */
	public $url;

	public function __construct( $ID, $properties = array() ) {
		$this->ID        = $ID;
		$this->timestamp = time();

		if ( $properties ) {
			foreach ( $properties as $key => $value ) {
				$this->$key = $value;
			}
		}
	}
}
