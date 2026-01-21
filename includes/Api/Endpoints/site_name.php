<?php
/**
 * Endpoint 'site_name'
 *
 * @link {{your-domain}}/wp-json/contentsync/v1/site_name
 */
namespace Contentsync\Api\Endpoints;

defined( 'ABSPATH' ) || exit;

new Site_Name();
class Site_Name extends Endpoint_Base {

	/**
	 * Class constructor
	 */
	public function __construct() {

		$this->rest_base = 'site_name';

		parent::__construct();
	}

	/**
	 * Endpoint callback
	 *
	 * @param WP_REST_Request $request
	 */
	public function callback( $request ) {
		$site_name = null;
		if ( is_multisite() ) {
			$network = get_network();
			if ( $network && isset( $network->site_name ) ) {
				$site_name = $network->site_name;
			}
		} else {
			$site_name = get_bloginfo( 'name' );
		}

		if ( empty( $site_name ) ) {
			return new \WP_Error( 'no_site_name', 'Site name could not be found', array( 'status' => 404 ) );
		}

		return $this->respond( $site_name );
	}

	/**
	 * Make this a public endpoint
	 */
	public function permission_callback( $request ) {
		return true;
	}
}
