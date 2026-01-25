<?php
/**
 * Post Export Admin REST Endpoint
 *
 * Handles REST requests for exporting posts to ZIP files.
 *
 * @package Contentsync
 * @subpackage Api\Admin_Endpoints
 */

namespace Contentsync\Api\Admin_Endpoints;

use Contentsync\Post_Transfer\Post_Export;
use Contentsync\Utils\Files;

defined( 'ABSPATH' ) || exit;

/**
 * Post Export Endpoint Class
 */
class Post_Export_Endpoint extends Admin_Endpoint_Base {

	/**
	 * REST base for this endpoint.
	 *
	 * @var string
	 */
	protected $rest_base = 'post-export';

	/**
	 * Arguments used by this endpoint.
	 *
	 * @var array
	 */
	private static $route_param_names = array( 'post_id', 'append_nested', 'nested', 'resolve_menus', 'translations' );

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		$args = array_intersect_key(
			$this->get_endpoint_args(),
			array_flip( self::$route_param_names )
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => $this->method,
					'callback'            => array( $this, 'callback' ),
					'permission_callback' => array( $this, 'permission_callback' ),
					'args'                => $args,
				),
			)
		);
	}

	/**
	 * Endpoint callback: export post to ZIP and return URL path.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function callback( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );

		if ( ! $post_id ) {
			return $this->respond( false, __( 'No valid post ID could be found.', 'contentsync' ), 400 );
		}

		$append_nested = $request->get_param( 'append_nested' ) || $request->get_param( 'nested' );
		$args          = array(
			'append_nested' => (bool) $append_nested,
			'resolve_menus' => (bool) $request->get_param( 'resolve_menus' ),
			'translations'  => (bool) $request->get_param( 'translations' ),
		);

		$filepath = ( new Post_Export( $post_id, $args ) )->export_to_zip();

		if ( ! $filepath ) {
			return $this->respond( false, __( 'The export file could not be written.', 'contentsync' ), 400 );
		}

		$url_path = Files::convert_wp_content_dir_to_url( $filepath );

		return $this->respond( $url_path, '', true );
	}
}
