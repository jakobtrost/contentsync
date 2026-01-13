<?php
/**
 * New Ajax Handling.
 * This reduced version of the deprecated ajax_handler only uses the custom actions 'greyd_ajax_mode_*'.
 * To ensure compatibility with the old ajax_handler and already implemented ajax calls in js:
 * - the ajax action is now called 'greyd_admin_ajax'
 * - ajax_url and nonce are stored in the global greyd var
 */
namespace Greyd;

if ( !defined( 'ABSPATH' ) ) exit;

new Ajax($config);
class Ajax {

	/**
	 * Holds plugin config array
	 */
	private $config;
	
	/**
	 * Enable/Disable logging
	 */
	const DEBUG = true;

	/**
	 * Class constructor
	 */
	public function __construct($config) {
		// set config
		$this->config = (object)$config;

		/**
		 * Greyd Ajax handling:
		 * 
		 * In php, add action with prefix 'greyd_ajax_mode_' and callback function:
		 *   add_action( 'greyd_ajax_mode_my_mode',  array( $this, 'my_callback' ) );
		 * 
		 * In js, trigger an ajax call by using the action 'greyd_admin_ajax'
		 * and the global vars 'greyd.ajax_url' and 'greyd.nonce', e.g.:
		 *   $.post(
		 *       ajax.ajax_url, {
		 *           'action': greyd_admin_ajax,
		 *           '_ajax_nonce': ajax.nonce,
		 *           'mode': 'my_mode',
		 *           'data': data,
		 *       }, 
		 *       function(response) {
		 *           // js callback
		 *       }
		 *   );
		 * The php my_callback function will be called and the result passed to the js response.
		 */
		add_action( 'wp_ajax_nopriv_greyd_admin_ajax', array( $this, 'ajax' ) );
		add_action( 'wp_ajax_greyd_admin_ajax', array( $this, 'ajax' ) );
	}

	
	/*
	=======================================================================
		AJAX HANDLER V2
	=======================================================================
	*/

	public function ajax() {
		if ( ! check_ajax_referer('install') ) {
			$this->finish();
		}
			
		// early exit
		if (!isset($_POST['mode'])) $this->finish("error::The POST variable 'mode' needs to be set.");
		if (!isset($_POST['data']) && !isset($_FILES['data'])) $this->finish("error::".__("No data found", "greyd_hub"));
		// debug($_POST['mode']);
		
		// start
		// debug($_POST);
		$mode = $_POST['mode'];
		$post_data = isset($_POST['data']) ? $_POST['data'] : $_FILES['data'];
		if (self::DEBUG)
			echo "\r\n\r\n"."------------- debug start -------------"."\r\n\r\n"."MODE: ".$mode."\r\n";
		
		if ( has_action('greyd_ajax_mode_'.$mode) ) {
			// call custom action to trigger third party functions
			if (self::DEBUG)  echo "\r\n\r\n"."TRIGGER ACTION: greyd_ajax_mode_".$mode;
			
			// get content of action as response
			ob_start();
				do_action('greyd_ajax_mode_'.$mode, $post_data);
			$response = ob_get_clean();

			$this->finish($response);
		}
		else {
			// die otherwise
			$this->finish("error::unknown inquery");
		}
	}
	
	/**
	 * Die and send answer back to JS.
	 * Same as 'wp_die', but with debug logging.
	 */
	public function finish($msg="") {
		if (self::DEBUG) echo "\r\n\r\n"."------------- debug end -------------"."\r\n\r\n";
		wp_die($msg);
	}
	
}

