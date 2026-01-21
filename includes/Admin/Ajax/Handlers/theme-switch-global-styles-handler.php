<?php
/**
 * Theme Switch Global Styles AJAX Handler
 *
 * Handles AJAX requests for switching global styles themes.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax;

use Contentsync\Posts\Theme_Assets;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Theme Switch Global Styles Handler Class
 */
class Theme_Switch_Global_Styles_Handler extends Contentsync_Ajax_Handler {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'switch_global_styles' );
	}

	/**
	 * Handle the AJAX request
	 *
	 * @param array $data Request data containing post_id.
	 */
	protected function handle( $data ) {
		$post_id = isset( $data['post_id'] ) ? intval( $data['post_id'] ) : 0;

		if ( empty( $post_id ) ) {
			$this->send_fail( __( 'No valid post ID found.', 'contentsync_hub' ) );
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			$this->send_fail( __( 'Post not found.', 'contentsync_hub' ) );
			return;
		}

		$result = Theme_Assets::set_wp_global_styles_theme( $post );

		if ( is_wp_error( $result ) ) {
			$this->send_fail( $result->get_error_message() );
			return;
		}

		if ( ! $result ) {
			$this->send_fail( __( 'Styles could not be assigned to the current theme.', 'contentsync_hub' ) );
			return;
		}

		$this->send_success( __( 'Styles were assigned to the current theme.', 'contentsync_hub' ) );
	}
}

// Instantiate the handler
new Theme_Switch_Global_Styles_Handler();
