<?php
/**
 * Abstract AJAX Handler
 *
 * Base class for all Content Sync AJAX handlers. Provides common functionality
 * for nonce checking, referrer validation, data loading, and response handling.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax\Handler;

use Contentsync\Utils\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract AJAX Handler Class
 *
 * All AJAX handlers should extend this class and implement the handle() method.
 * The constructor automatically registers the handler with WordPress.
 */
abstract class Ajax_Base {

	/**
	 * Action name (e.g., 'post_export', 'contentsync_export')
	 *
	 * @var string
	 */
	protected $action;

	/**
	 * Request data
	 *
	 * @var array
	 */
	protected $data = array();

	/**
	 * Constructor
	 *
	 * @param string $action Action name (e.g., 'post_export' or 'contentsync_export').
	 */
	public function __construct( $action ) {
		$this->action = $action;
		add_action( 'wp_ajax_nopriv_' . $action, array( $this, 'maybe_handle' ), 10, 1 );
		add_action( 'wp_ajax_' . $action, array( $this, 'maybe_handle' ), 10, 1 );
	}

	/**
	 * Maybe handle the request
	 *
	 * Validates nonce and referrer, then calls the handle() method.
	 *
	 * @param array $data Request data.
	 */
	public function maybe_handle( $data ) {

		error_log( 'maybe_handle' );
		wp_send_json_error( 'maybe_handle' );

		// Check nonce
		if ( ! isset( $_REQUEST['_nonce'] ) || ! wp_verify_nonce( $_REQUEST['_nonce'], 'contentsync_ajax' ) ) {
			$this->send_fail( __( 'Security check failed. Please refresh the page and try again.', 'contentsync' ) );
			return;
		}

		// Check referrer (AJAX referrer)
		check_ajax_referer( 'contentsync_ajax', '_nonce' );

		// Log the request
		Logger::add( sprintf( '========= HANDLE AJAX: %s =========', $this->action ), $data );

		// Store data
		$this->data = $data;

		// Call the handler
		$this->handle( $data );
	}

	/**
	 * Handle the AJAX request
	 *
	 * This method must be implemented by subclasses.
	 *
	 * @param array $data Request data.
	 */
	abstract protected function handle( $data );

	/**
	 * Get request data
	 *
	 * Returns sanitized data from $_POST['data'] or $_FILES['data'].
	 *
	 * @return array Request data.
	 */
	protected function get_data() {
		if ( ! empty( $_POST['data'] ) ) {
			return $this->sanitize_data( $_POST['data'] );
		}

		if ( ! empty( $_FILES['data'] ) ) {
			return $_FILES['data'];
		}

		return $this->data;
	}

	/**
	 * Sanitize data array
	 *
	 * @param array $data Raw data.
	 * @return array Sanitized data.
	 */
	protected function sanitize_data( $data ) {
		if ( ! is_array( $data ) ) {
			return sanitize_text_field( $data );
		}

		$sanitized = array();
		foreach ( $data as $key => $value ) {
			$key = sanitize_key( $key );
			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_data( $value );
			} else {
				$sanitized[ $key ] = sanitize_text_field( $value );
			}
		}

		return $sanitized;
	}

	/**
	 * Send success response
	 *
	 * @param string $message Success message.
	 */
	protected function send_success( $message = '' ) {
		Logger::add( sprintf( '  - SUCCESS: %s', $message ) );
		Logger::echo_logs_to_console();
		wp_die( 'success::' . $message );
	}

	/**
	 * Send error response
	 *
	 * @param string $message Error message.
	 */
	protected function send_fail( $message = '' ) {
		Logger::add( sprintf( '  - ERROR: %s', $message ) );
		Logger::echo_logs_to_console();
		wp_die( 'error::' . $message );
	}

	/**
	 * Get action name
	 *
	 * @return string Action name.
	 */
	public function get_action() {
		return $this->action;
	}
}
