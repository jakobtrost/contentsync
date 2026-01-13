<?php

/**
 * Endpoint 'posts/meta'
 * 
 * @link {{your-domain}}/wp-json/contentsync/v1/posts
 *
 * This class has the following endpoints in it:
 *
 * /posts/{{gid}}/meta
 */

namespace Contentsync\Connections\Endpoints;

use \Contentsync\Connections\Endpoint;
use \Contentsync\Main_Helper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

new Posts_Meta();
class Posts_Meta extends Endpoint {

	/**
	 * Class constructor
	 */
	public function __construct() {

		$this->rest_base = 'posts/' . $this->gid_regex . '/meta';

		parent::__construct();
	}

	/**
	 * Register REST API endpoints
	 */
	public function register_routes() {

		// meta
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?J)' . $this->gid_regex . '/meta',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_post_meta' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $this->get_endpoint_args(),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_post_meta' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $this->get_endpoint_args(),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_post_meta' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $this->get_endpoint_args(),
				),
			)
		);
	}


	/**
	 * =================================================================
	 *                          Endpoint callbacks
	 * =================================================================
	 */

	/**
	 * Get contentsync_meta of a global post
	 */
	public function get_post_meta( $request ) {

		$result  = false;
		$message = "The global ID was set incorrectly (input: {$request['gid']}).";

		list( $blog_id, $post_id, $net_url ) = Main_Helper::explode_gid( $request['gid'] );
		if ( $post_id !== null ) {

			$meta_key = isset( $request['meta_key'] ) ? esc_attr( urldecode( $request['meta_key'] ) ) : null;

			Main_Helper::switch_to_blog( $blog_id );
			// if no meta key set, we get all contentsync_meta
			if ( empty( $meta_key ) ) {
				$post_meta = array();
				$meta_keys = Main_Helper::contentsync_meta();
				foreach ( $meta_keys as $meta_key ) {
					$post_meta[ $meta_key ] = Main_Helper::get_contentsync_meta( $post_id, $meta_key );
				}
			} else {
				$post_meta = Main_Helper::get_contentsync_meta( $post_id, $meta_key );
			}
			Main_Helper::restore_blog();

			if ( ! empty( $post_meta ) ) {
				$result  = $post_meta;
				$message = "Post meta for the post '$gid' found [$net_url]";
			} else {
				$message = "Post meta for the post '$gid' could not be found [$net_url]";
			}
		}

		return $this->respond( $post_meta );
	}

	/**
	 * Update certain contentsync_meta of a global post
	 */
	public function update_post_meta( $request ) {

		$gid      = $request['gid'];
		$meta_key = isset( $request['meta_key'] ) ? esc_attr( urldecode( $request['meta_key'] ) ) : null;
		$meta_val = isset( $request['meta_value'] ) ? esc_attr( urldecode( $request['meta_value'] ) ) : null;

		if ( empty( $meta_key ) || $meta_val === null ) {
			return $this->respond( false );
		}

		$blog_id = explode( '-', $gid )[0];
		$post_id = explode( '-', $gid )[1];

		Main_Helper::switch_to_blog( $blog_id );
		update_post_meta( $post_id, $meta_key, $meta_val );
		Main_Helper::restore_blog();

		return $this->respond( $meta_val );
	}

	/**
	 * Delete contentsync_meta of a global post
	 */
	public function delete_post_meta( $request ) {

		$gid      = $request['gid'];
		$blog_id  = explode( '-', $gid )[0];
		$post_id  = explode( '-', $gid )[1];
		$meta_key = isset( $request['meta_key'] ) ? esc_attr( urldecode( $request['meta_key'] ) ) : null;

		Main_Helper::switch_to_blog( $blog_id );

		// if no meta key set, we delete all contentsync_meta
		if ( empty( $meta_key ) ) {
			Main_Helper::delete_contentsync_meta( $post_id );
		} else {
			delete_post_meta( $post_id, $meta_key );
		}

		Main_Helper::restore_blog();

		return $this->respond( true );
	}


	/**
	 * =================================================================
	 *                          Helper
	 * =================================================================
	 */

	/**
	 * Possible arguments
	 */
	public function get_endpoint_args() {
		return array(
			'gid'      => array(
				'validate_callback' => array( $this, 'is_gid' ),
			),
			'meta_key' => array(
				'validate_callback' => array( $this, 'is_contentsync_meta' ),
			),
		);
	}

    /**
     * Validate callback to confirm a string is a well‑formed global ID.
     *
     * The global ID (GID) format is `{blog_id}-{post_id}` with an optional
     * third segment representing the site URL. This helper uses a
     * regular expression to verify the format. It returns 1 for a match
     * and 0 for no match, mirroring `preg_match()` semantics.
     *
     * @param string $value The value to test.
     * @return int 1 if the value matches the GID pattern, 0 otherwise.
     */
    public function is_gid( $value ) {
        $regex = "(?P<blog_id>\d+)-(?P<post_id>\d+)(-(?P<site_url>((http|https)\:\/\/)?[a-zA-Z0-9\.\/\?\:@\-_=#]+\.([a-zA-Z0-9\&\.\/\?\:@\-_=#%])*))?";
        return preg_match( '/^' . $regex . '$/', $value );
    }

    /**
     * Validate callback to ensure a meta key belongs to the Contentsync meta list.
     *
     * Looks up the provided value against the array of valid global
     * content meta keys. Returns true when the meta key is defined for
     * global content and false otherwise.
     *
     * @param string $value Meta key to test.
     * @return bool True if the key is part of the Contentsync meta set, false otherwise.
     */
    public function is_contentsync_meta( $value ) {
        $meta_keys = Main_Helper::contentsync_meta();
        return isset( array_flip( $meta_keys )[ $value ] );
    }
	/**
	 * Debug permission callback
	 */
	// public function permission_callback($request) {
	// return true;
	// }
}
