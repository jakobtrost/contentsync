<?php

/**
 * Queue admin page hooks.
 *
 * This class registers hooks for the distribution queue admin page. It
 * registers a submenu under the Content Sync menu, initialises the queue
 * list table used to display pending distribution items, and provides
 * actions and filters for setting screen options, enqueueing scripts,
 * handling notices and processing AJAX requests.
 */

namespace Contentsync\Admin\Views\Distribution;

use Contentsync\Admin\Views\Distribution\Queue_List_Table;
use Contentsync\Distribution\Distributor;
use Contentsync\Distribution\Distribution_Item_Service;
use Contentsync\Utils\Hooks_Base;

defined( 'ABSPATH' ) || exit;

class Queue_Admin_Page_Hooks extends Hooks_Base {

	const QUEUE_PAGE_POSITION = 40;

	/**
	 * Holds instance of the class 'List_Table'
	 */
	public $List_Table = null;

	/**
	 * Holds instance of the class 'Queue_List_Table'
	 */
	public $Queue_List_Table = null;

	public function register_admin() {

		// add the menu items & pages
		add_action( 'admin_menu', array( $this, 'add_submenu_item' ) );
		add_action( 'network_admin_menu', array( $this, 'add_submenu_item' ) );

		add_filter( 'set-screen-option', array( $this, 'queue_save_screen_options' ), 10, 3 );

		// Enqueue scripts for the queue page
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ), 98 );

		// Check for stuck items and show notice
		$admin_notices_hook = is_multisite() ? 'network_admin_notices' : 'admin_notices';
		add_action( $admin_notices_hook, array( $this, 'check_stuck_items' ) );

		// Add AJAX handlers
		add_action( 'wp_ajax_contentsync_run_distribution_item', array( $this, 'ajax_run_distribution_item' ) );
	}

	/**
	 * Add a menu item to the WordPress admin menu
	 */
	public function add_submenu_item() {

		if ( is_multisite() && ! is_super_admin() ) {
			return;
		}

		$hook = add_submenu_page(
			'contentsync',
			__( 'Queue', 'contentsync' ), // page title
			__( 'Queue', 'contentsync' ), // menu title
			'manage_options',
			'contentsync_queue',
			array( $this, 'render_queue_admin_page' ),
			self::QUEUE_PAGE_POSITION // position
		);

		add_action( "load-$hook", array( $this, 'queue_add_screen_options' ) );
	}

	/**
	 * Set screen options for the admin pages
	 */
	public function queue_add_screen_options() {
		$args = array(
			'label'   => __( 'Queue Items per page:', 'contentsync' ),
			'default' => 20,
			'option'  => 'queue_per_page',
		);

		add_screen_option( 'per_page', $args );

		$this->Queue_List_Table = new Queue_List_Table();
	}

	/**
	 * Save the admin screen option
	 */
	public function queue_save_screen_options( $status, $option, $value ) {

		if ( 'queue_per_page' == $option ) {
			return $value;
		}

		return $status;
	}

	/**
	 * Display the custom admin list page
	 */
	public function render_queue_admin_page() {

		if ( ! $this->List_Table ) {
			$this->List_Table = new Queue_List_Table();
		}

		$this->List_Table->prepare_items();
		$this->List_Table->render();
	}

	/**
	 * Enqueue scripts and styles for the queue page
	 */
	public function enqueue_scripts( $hook ) {

		// Only enqueue on the queue page
		if ( $hook !== 'global-content_page_contentsync_queue' && $hook !== 'global-content_page_contentsync_queue-network' ) {
			return;
		}

		// Enqueue CSS
		wp_enqueue_style(
			'contentsync-admin-distribution',
			plugins_url( 'assets/css/admin-distribution.css', __FILE__ ),
			array(),
			filemtime( plugin_dir_path( __FILE__ ) . 'assets/css/admin-distribution.css' )
		);

		wp_enqueue_script(
			'greyd-admin-distribution',
			plugins_url( 'assets/js/admin-distribution.js', __FILE__ ),
			array( 'jquery' ), // or your dependencies
			CONTENTSYNC_VERSION,
			true
		);

		if ( $this->has_stuck_items() ) {
			// Get stuck items data
			$stuck_items = $this->get_stuck_items();

			$stuck_items_data = array(
				'count' => count( $stuck_items ),
				'items' => array(),
			);
			foreach ( $stuck_items as $item ) {
				$stuck_items_data['items'][] = array(
					'id'   => $item->ID,
					'time' => $item->time,
				);
			}
		} else {
			$stuck_items_data = array(
				'count' => 0,
				'items' => array(),
			);
		}

		wp_localize_script(
			'greyd-admin-distribution',
			'distributionData',
			array(
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'contentsync_run_all_stuck' ),
				'stuck_items' => $stuck_items_data,
				'i18n'        => array(
					'progressLabel'       => __( 'Processed %1$s out of %2$s items', 'contentsync' ),
					'resume'              => __( 'Resume', 'contentsync' ),
					'pause'               => __( 'Pause', 'contentsync' ),
					'summaryAllSuccess'   => __( 'All %s items were processed successfully.', 'contentsync' ),
					'summarySomeFailed'   => __( '%1$s of %2$s items were processed successfully. %3$s items failed.', 'contentsync' ),
					'failedToProcess'     => __( 'Failed to process', 'contentsync' ),
					'errorProcessingItem' => __( 'Error processing item', 'contentsync' ),
					'completed'           => __( 'Completed', 'contentsync' ),
					'failed'              => __( 'Failed', 'contentsync' ),
				),
			)
		);
	}

	/**
	 * Check if there are stuck items (not completed and older than 5 minutes).
	 *
	 * @return bool
	 */
	public function has_stuck_items() {
		return count( $this->get_stuck_items() ) > 0;
	}

	/**
	 * Get all stuck items (not completed and older than 5 minutes).
	 *
	 * @return array
	 */
	public function get_stuck_items() {

		// get transient first
		$stuck_items = wp_cache_get( 'contentsync_stuck_items', 'greyd' );
		if ( $stuck_items || is_array( $stuck_items ) ) {
			return $stuck_items;
		}

		// get items that are not completed and older than 5 minutes
		$stuck_items = Distribution_Item_Service::get_items(
			array(
				'status'  => array( 'init', 'started' ),
				'time'    => array(
					'before' => current_time( 'timestamp' ) - 5 * MINUTE_IN_SECONDS,
				),
				'orderby' => 'time',
				'order'   => 'DESC',
			),
			'ID, status, time'
		);

		// set transient
		wp_cache_set( 'contentsync_stuck_items', $stuck_items, 'greyd' );

		return $stuck_items;
	}

	/**
	 * Checks for stuck distribution items and displays an admin notice if found.
	 * An item is considered stuck if it's:
	 * - Scheduled (status = 'init')
	 * - Not completed
	 */
	public function check_stuck_items() {

		$screen = get_current_screen();
		if ( strpos( $screen->id, 'global-content' ) === false && strpos( $screen->id, 'contentsync' ) === false ) {
			return;
		}

		if ( $this->has_stuck_items() ) {

			// If we're on the queue page, show the button
			if ( isset( $_GET['page'] ) && $_GET['page'] === 'contentsync_queue' ) {

				$stuck_items       = $this->get_stuck_items();
				$stuck_items_count = count( $stuck_items );

				$message = sprintf(
					'<div class="contentsync-distribution-notice">
						<p>%s</p>
						<div class="contentsync-distribution-actions">
							<button type="button" id="contentsync-run-all-stuck" class="button button-primary">' . __( 'Run Items Now', 'contentsync' ) . '</button>
							<button type="button" id="contentsync-pause-stuck" class="button" style="display: none;">' . __( 'Pause', 'contentsync' ) . '</button>
							<button type="button" id="contentsync-stop-stuck" class="button" style="display: none;">' . __( 'Stop', 'contentsync' ) . '</button>
						</div>
						<div class="contentsync-distribution-progress" style="display: none;">
							<div class="contentsync-progress-bar-wrapper">
								<div class="contentsync-progress-bar"></div>
							</div>
							<div class="contentsync-progress-footer">
								<div class="contentsync-progress-count"></div>
								<div class="contentsync-progress-actions">
									<button type="button" id="contentsync-pause-stuck" class="button" style="display: none;">' . __( 'Pause', 'contentsync' ) . '</button>
									<button type="button" id="contentsync-stop-stuck" class="button" style="display: none;">' . __( 'Stop', 'contentsync' ) . '</button>
								</div>
							</div>
						</div>
						<div class="contentsync-distribution-summary" style="display: none;">
							<p class="contentsync-summary-text"></p>
						</div>
						<details class="contentsync-progress-log-details" style="margin-top: 15px;">
							<summary>' . __( 'See details', 'contentsync' ) . '</summary>
							<div class="contentsync-progress-log"></div>
						</details>
					</div>',
					sprintf(
						_n(
							'%d post was scheduled to be distributed across your network using Content Sync but has not been processed. This might indicate an issue with the background processing. You can manually process these updates now.',
							'%d post updates were scheduled to be distributed across your network using Content Sync but have not been processed. This might indicate an issue with the background processing. You can manually process these updates now.',
							$stuck_items_count,
							'contentsync'
						),
						$stuck_items_count
					)
				);

				echo '<div class="notice notice-warning is-dismissible">';
				echo $message;
				echo '</div>';
			} else {
				// On other pages, show a simpler notice
				echo '<div class="notice notice-warning is-dismissible">';
				echo '<p>' . sprintf(
					_n(
						'Some posts were scheduled to be distributed across your network using Content Sync but has not been processed. You can manually process these updates from the %s.',
						'Some posts were scheduled to be distributed across your network using Content Sync but have not been processed. You can manually process these updates from the %s.',
						'contentsync'
					),
					'<a href="' . esc_url( admin_url( 'admin.php?page=contentsync_queue' ) ) . '">' . __( 'Content Sync Queue', 'contentsync' ) . '</a>'
				) . '</p>';
				echo '</div>';
			}
		}
	}

	/**
	 * AJAX handler for running a single distribution item
	 */
	public function ajax_run_distribution_item() {
		// Verify nonce
		if ( ! isset( $_POST['_ajax_nonce'] ) || ! wp_verify_nonce( $_POST['_ajax_nonce'], 'contentsync_run_all_stuck' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce', 'contentsync' ) ) );
		}

		// Check permissions
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'contentsync' ) ) );
		}

		// Get and validate item ID
		$item_id = isset( $_POST['item_id'] ) ? intval( $_POST['item_id'] ) : 0;
		if ( ! $item_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid item ID', 'contentsync' ) ) );
		}

		// Get the distribution item
		$item = Distribution_Item_Service::get( $item_id );
		if ( ! $item ) {
			wp_send_json_error( array( 'message' => __( 'Distribution item not found', 'contentsync' ) ) );
		}

		// Run the distribution
		$result = Distributor::distribute_item( $item_id );

		if ( $result !== false ) {
			wp_send_json_success(
				array(
					'message' => __( 'Item distributed successfully', 'contentsync' ),
					'item_id' => $item_id,
				)
			);
		} else {
			wp_send_json_error(
				array(
					'message' => __( 'Failed to distribute item', 'contentsync' ),
					'item_id' => $item_id,
				)
			);
		}
	}
}
