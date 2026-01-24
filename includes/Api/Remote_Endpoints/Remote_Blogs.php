<?php
/**
 * Endpoint 'remote_blogs'
 *
 * @link {{your-domain}}/wp-json/contentsync/v1/remote_blogs
 */
namespace Contentsync\Api\Remote_Endpoints;

use Contentsync\Utils\Multisite_Manager;

defined( 'ABSPATH' ) || exit;

class Remote_Blogs extends Remote_Endpoint_Base {

	/**
	 * Class constructor
	 */
	public function __construct() {

		$this->rest_base = 'remote_blogs';

		parent::__construct();
	}

	/**
	 * Register REST API endpoints
	 */
	public function register_routes() {

		// base (get all blogs)
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => $this->method,
					'callback'            => array( $this, 'get_all_blogs' ),
					'permission_callback' => array( $this, 'permission_callback' ),
				),
			)
		);
	}

	/**
	 * Endpoint callbacks
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return Blog_Reference[] $all_blogs  Array of Blog_Reference objects keyed by blog_id
	 *      @property int blog_id
	 *      @property string db_prefix
	 *      @property string name
	 *      @property string domain
	 *      @property string protocol
	 *      @property string site_url
	 */
	public function get_all_blogs( $request ) {

		$blogs = Multisite_Manager::get_all_blogs();

		if ( empty( $blogs ) ) {
			return new \WP_Error( 'no_blogs', 'Blogs could not be found', array( 'status' => 404 ) );
		}

		return $this->respond( $blogs );
	}
}
