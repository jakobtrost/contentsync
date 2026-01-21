<?php

/**
 * Endpoints for 'distribution'.
 *
 * @link {{your-domain}}/wp-json/contentsync/v1/distribution
 *
 * This class has the following endpoints in it:
 *
 * /distribution/distribute-item/ (POST)
 * This endpoint is called from distribute_item_to_remote_site() It will
 * respond once it has received the item and added it to the queue, so the
 * request desn't time out.
 * It then distributes the post to all local sites. Once it is done, it will
 * call update_distribution_item() to send a request to the origin site which
 * updates the related Distribution_Item there.
 *
 * /distribution/update-item/ (POST)
 * This endpoint is called via update_distribution_item() and then updates
 * the related Distribution_Item with the status 'completed' for the included
 * Remote_Destination.
 *
 * Related functions:
 * @see distribute_item_to_remote_site()
 * @see update_distribution_item()
 *
 * Related files:
 * @see inc/distribution/classes/class-distribution-queue.php
 * @see inc/distribution/classes/class-distribution-item.php
 * @see includes/Distribution/Destinations/Remote_Destination.php
 * @see includes/Distribution/Destinations/Blog_Destination.php
 */
namespace Contentsync\Api\Endpoints;

use Contentsync\Api\Endpoint;
use Contentsync\Distribution\Distributor;
use Contentsync\Distribution\Distributor_Item_Service;
use Contentsync\Utils\Logger;
use Contentsync\Distribution\Distributor_Item;
use Contentsync\Distribution\Destinations\Remote_Destination;
use Contentsync\Distribution\Destinations\Blog_Destination;
use Contentsync\Utils\Urls;

defined( 'ABSPATH' ) || exit;

new Distribution_Endpoint();
class Distribution_Endpoint extends Endpoint {

	/**
	 * Store the current request origin in the class
	 */
	public $origin;

	/**
	 * Class constructor
	 */
	public function __construct() {

		$this->rest_base = 'distribution';

		parent::__construct();
	}

	/**
	 * Register REST API endpoints
	 */
	public function register_routes() {

		// distribute Remote_Destination
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/distribute-item/',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_distribute_item_request' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $this->get_endpoint_args(),
				),
			)
		);

		// update Distribution_Item
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/update-item/',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_update_item_request' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $this->get_endpoint_args(),
				),
			)
		);
	}

	/**
	 * Handle distribute item request.
	 *
	 * @see distribute_item_to_remote_site()
	 *
	 * This endpoint receives a call from the Distribution_Queue including the
	 * Remote_Destination object and the related Distribution_Item ID.
	 *
	 * In an ideal scenario, this endpoint will respond once it has received
	 * the posts and added them to the queue, so the request desn't time out.
	 * But unfortunately, the background processing is not working inside a rest
	 * request, so we have to emulate it by creating the task directly.
	 *
	 * We therefore send the updated Distribution_Item back to the origin site
	 * directly after the distribution to the local sites is done.
	 *
	 * @param WP_REST_Request $request
	 *   @property int $distribution_item_id
	 *   @property array|object $destination
	 *   @property string $origin
	 *
	 * @return WP_REST_Response
	 */
	public function handle_distribute_item_request( $request ) {

		// Logger::clear_log_file();

		$response = false;

		$distribution_item = $request->get_param( 'distribution_item' );
		$origin            = $request->get_param( 'origin' ) ?? $request->get_header( 'Origin' );

		// something is missing
		if ( empty( $origin ) || empty( $distribution_item ) ) {
			return $this->respond( $response, 'The request arguments were set incorrectly (origin, distribution_item_id, destination).' );
		}

		// store the origin in the class to use it inside the filter functions
		$this->origin = $origin;

		$distribution_item = json_decode( $distribution_item );

		if ( ! is_array( $distribution_item ) && ! is_object( $distribution_item ) ) {
			return $this->respond( $response, 'The destination is neither an array nor an object.' );
		}

		$item = $this->sanitize_distribution_item( $distribution_item );
		// Logger::add( 'sanitized item', $item );

		if ( ! $item ) {
			return $this->respond( $response, 'The destination could not be sanitized.' );
		}

		$blogs                = isset( $item->destination->blogs ) ? $item->destination->blogs : array();
		$prepared_posts       = $item->posts;
		$distribution_item_id = $item->ID;

		if ( empty( $blogs ) || ! is_array( $blogs ) ) {
			return $this->respond( $response, 'The destination has no blogs.' );
		}

		foreach ( $blogs as $key => $blog_destination ) {

			$distribution_item_properties = array(
				'posts'       => $prepared_posts,
				'destination' => $blog_destination,
				'origin'      => $origin,
				'origin_id'   => $distribution_item_id,
			);

			$distribution_item = Distributor::schedule_distribution_item( $distribution_item_properties );

			Logger::add( 'Distribution to destination scheduled: ' . $distribution_item->destination->ID );
		}

		return $this->respond( true, 'The request was successful.' );
	}

	/**
	 * Possible arguments
	 */
	public function get_endpoint_args() {
		return array(
			'origin'               => array(
				'validate_callback' => array( $this, 'is_string' ),
			),
			'distribution_item'    => array(
				'validate_callback' => array( $this, 'is_array_or_object' ),
			),
			'distribution_item_id' => array(
				'validate_callback' => array( $this, 'is_numeric' ),
			),
			'destination'          => array(
				'validate_callback' => array( $this, 'is_array_or_object' ),
			),
		);
	}

	/**
	 * Validate callback to confirm a value is a string.
	 *
	 * Used by REST endpoint parameter validation to ensure that incoming
	 * values intended to be strings actually are. Returns true if the
	 * provided value is of type string and false otherwise.
	 *
	 * @param mixed $value The value to test.
	 * @return bool True when the value is a string, false otherwise.
	 */
	public function is_string( $value ) {
		return is_string( $value );
	}

	/**
	 * Validate callback to confirm a value is numeric.
	 *
	 * REST endpoints use this to validate integer parameters such as IDs.
	 * Returns true if the given value is numeric.
	 *
	 * @param mixed $value The value to test.
	 * @return bool True when the value is numeric, false otherwise.
	 */
	public function is_numeric( $value ) {
		return is_numeric( $value );
	}

	/**
	 * Validate callback to ensure a value is an array or object.
	 *
	 * Used by REST endpoints to validate complex body parameters. If the
	 * provided value is a JSON encoded string it will be decoded to an
	 * object. Returns true for objects and arrays, and logs an error
	 * for any other type.
	 *
	 * @param mixed $value The value to validate.
	 * @return bool True if the value is an array or object, false otherwise.
	 */
	public function is_array_or_object( $value ) {
		if ( is_string( $value ) ) {
			$value = json_decode( $value );
		}
		if ( is_object( $value ) ) {
			return true;
		}
		if ( is_array( $value ) ) {
			return true;
		}
		error_log( 'is_array_or_object: The value is neither an array nor an object.' );
		var_error_log( $value );
		return false;
	}

	/**
	 * Sanitize the distribution item.
	 *
	 * @param array|object $distribution_item  The distribution item from the request, usually an array.
	 *
	 * @return Distribution_Item|bool          The sanitized distribution item or false.
	 */
	public function sanitize_distribution_item( $distribution_item ) {

		if ( is_object( $distribution_item ) ) {
			$distribution_item = json_decode( json_encode( $distribution_item ), true );
		}

		if ( ! is_array( $distribution_item ) ) {
			return false;
		}

		foreach ( $distribution_item as $key => $value ) {

			switch ( $key ) {
				case 'ID':
					$distribution_item[ $key ] = intval( $value );
					break;
				case 'time':
					$distribution_item[ $key ] = current_time( 'mysql' );
					break;
				case 'destination':
					$result = $this->sanitize_remote_destination( $value );
					if ( $result ) {
						$distribution_item[ $key ] = $result;
					}
					break;
				case 'posts':
					$result = $this->sanitize_posts( $value );
					if ( $result ) {
						$distribution_item[ $key ] = $result;
					}
					break;
				default:
					$distribution_item[ $key ] = strval( $value );
					break;
			}
		}

		return new Distribution_Item( $distribution_item );
	}

	/**
	 * Sanitize the remote destination object.
	 *
	 * @param array|object $destination  The remote destination object from the request, usually an array.
	 *
	 * @return Remote_Destination|bool   The sanitized remote destination object or false.
	 */
	public function sanitize_remote_destination( $destination ) {

		if ( is_object( $destination ) ) {
			$destination = json_decode( json_encode( $destination ), true );
		}

		if ( ! is_array( $destination ) ) {
			return false;
		}

		$blogs = isset( $destination['blogs'] ) ? $destination['blogs'] : array();
		unset( $destination['blogs'] );

		$remote_destination = new Remote_Destination( $destination );

		if ( empty( $blogs ) ) {
			Logger::add( 'sanitize_remote_destination', 'The destination has no blogs.' );
			return $remote_destination;
		}

		foreach ( $blogs as $blog ) {

			if ( ! is_array( $blog ) ) {
				if ( is_object( $blog ) ) {
					Logger::add( 'sanitize_remote_destination', 'The blog is an object and is being converted to an array.' );
					$blog = json_decode( json_encode( $blog ), true );
				} else {
					Logger::add( 'sanitize_remote_destination', 'The blog is not an array or object.' );
					continue;
				}
			}

			if ( ! is_array( $blog ) || ! isset( $blog['ID'] ) ) {
				Logger::add( 'sanitize_remote_destination', 'The blog does not have an ID.' );
				continue;
			}

			$posts = array();
			if ( isset( $blog['posts'] ) ) {
				$posts = $blog['posts'];
				unset( $blog['posts'] );
			}

			$remote_destination->add_blog( $blog['ID'], $blog );

			foreach ( $posts as $root_post_id => $post ) {
				$remote_destination->add_post_to_blog( $blog['ID'], $root_post_id, $post['ID'], $post );
			}
		}

		return $remote_destination;
	}

	/**
	 * Sanitize the posts array.
	 *
	 * @param array $posts  The posts array from the request.
	 *
	 * @return object[]|bool   The sanitized posts array of objects or false.
	 */
	public function sanitize_posts( $posts ) {

		if ( ! is_array( $posts ) ) {
			return false;
		}

		$sanitized_posts = array();

		foreach ( $posts as $post_id => $post ) {
			$result = $this->sanitize_post( $post );
			if ( $result ) {
				$sanitized_posts[ $post_id ] = $result;
			}
		}

		return $sanitized_posts;
	}

	/**
	 * Sanitize the Post object.
	 *
	 * @param array|object $post  The post object from the request, usually an array.
	 *
	 * @return object|bool        The sanitized post object or false.
	 */
	public function sanitize_post( $post ) {

		if ( is_object( $post ) ) {
			$post = json_decode( json_encode( $post ), true );
		}

		if ( ! is_array( $post ) ) {
			return false;
		}

		foreach ( $post as $key => $value ) {

			switch ( $key ) {
				case 'ID':
					$post[ $key ] = intval( $value );
					break;
				case 'is_root_post':
					$post[ $key ] = $value == '1' ? true : false;
					break;
				case 'meta':
					if ( is_array( $value ) ) {
						foreach ( $value as $meta_key => $meta_value ) {
							if ( $meta_key == 'synced_post_id' ) {
								$meta_value[0] = $this->match_gid_before_import( $meta_value[0] );
							}
							$post[ $key ][ $meta_key ] = $meta_value;
						}
					}
					break;
				default:
					$post[ $key ] = $value;
			}
		}

		return (object) $post;
	}

	/**
	 * Filter the gid before import to match conflict actions
	 *
	 * @param string  $gid
	 * @param int     $post_id
	 * @param WP_Post $post
	 *
	 * @return string $gid
	 */
	public function match_gid_before_import( $gid ) {

		$origin  = $this->origin;
		$current = Urls::get_network_url();

		list( $blog_id, $post_id, $net_url ) = \Contentsync\Posts\Sync\Synced_Post_Utils::explode_gid( $gid );

		if ( ! empty( $post_id ) && ! empty( $origin ) ) {

			/**
			 * Matching gids between networks, there are basically 3 scenarios:
			 *
			 * (1) The request comes from the same network:
			 *     The meta-value for the gid in the request currently is:
			 *         '4-20'
			 *     On this network we need to keep it this way:
			 *         '4-20'
			 *     This happends
			 *
			 * (2) The post comes from the request origin network:
			 *     The meta-value for the gid in the request currently is:
			 *         '1-42'
			 *     On this network we need to append the origin network-url to it:
			 *         '1-42-origin.multisite.com'
			 *
			 * (3) The post comes from the current network:
			 *     The meta-value for the gid in the request currently is:
			 *         '4-20-this.multisite.com'
			 *     On this network we need to remove the current network-url from it:
			 *         '4-20'
			 *
			 * (4) The post comes from another network:
			 *     The meta-value for the gid in the request currently is:
			 *         '1-69-third.multisite.com'
			 *     This is fine, it just stays this way:
			 *         '1-69-third.multisite.com'
			 */

			// (1)
			if ( $origin == $current ) {
				$gid = $blog_id . '-' . $post_id;
			}
			// (2)
			elseif ( empty( $net_url ) ) {
				$gid = $blog_id . '-' . $post_id . '-' . $origin;
			}
			// (3)
			elseif ( $net_url == $current ) {
				$gid = $blog_id . '-' . $post_id;
			}
			// (4)
			else {
				// Think about it: Someone coded stackoverflow without stackoverflow.
			}
		}

		return $gid;
	}

	/**
	 * Handle update item request.
	 *
	 * @see update_distribution_item()
	 *
	 * This endpoint is called via update_distribution_item() and then updates
	 * the related Distribution_Item with the status 'completed' for the included
	 * Remote_Destination.
	 *
	 * @param WP_REST_Request $request
	 *   @property int $distribution_item_id
	 *   @property array|object $destination
	 *   @property string $status
	 *
	 * @return WP_REST_Response
	 */
	public function handle_update_item_request( $request ) {

		Logger::add( 'update_item_request' );

		$response = false;

		$distribution_item_id = $request->get_param( 'distribution_item_id' );
		$destination          = $request->get_param( 'destination' );
		$status               = $request->get_param( 'status' );

		// something is missing
		if ( empty( $status ) || empty( $distribution_item_id ) || empty( $destination ) ) {
			return $this->respond( $response, 'The request arguments were set incorrectly (status, distribution_item_id, destination).' );
		}

		$remote_blog_destination = isset( $destination['ID'] ) ? new Blog_Destination( $destination['ID'], $destination ) : false;

		if ( ! $remote_blog_destination ) {
			return $this->respond( $response, 'The remote blog destination does not have an ID.' );
		}

		$distribution_item = Distribution_Item_Service::get( $distribution_item_id );

		if ( ! $distribution_item ) {
			return $this->respond( $response, 'The distribution item could not be found.' );
		}

		// Logger::add( 'remote_blog_destination', $remote_blog_destination );
		// Logger::add( 'local_distribution_item', $distribution_item );

		if ( isset( $distribution_item->destination->blogs[ $remote_blog_destination->ID ] ) ) {
			$distribution_item->destination->blogs[ $remote_blog_destination->ID ] = $remote_blog_destination;
		}

		$new_distribution_status = '';

		// loop through all blogs and get the status
		foreach ( $distribution_item->destination->blogs as $blog_id => $blog_destination ) {

			if ( $blog_destination->status === 'success' ) {
				// update to success only if no other status is set
				$new_distribution_status = empty( $new_distribution_status ) ? 'success' : $new_distribution_status;
			} elseif ( $blog_destination->status === 'failed' ) {
				// update to failed if only one blog failed
				$new_distribution_status = 'failed';
				break;
			} else {
				// init or started
				$new_distribution_status = $blog_destination->status;
			}
		}

		// update the status
		$distribution_item->destination->status = $new_distribution_status;
		$distribution_item->status              = $new_distribution_status;

		// update the distribution item
		$response = $distribution_item->save();

		if ( ! $response ) {
			return $this->respond( $response, 'The request was unsuccessful.' );
		}

		return $this->respond( true, 'The request was successful.' );
	}
}
