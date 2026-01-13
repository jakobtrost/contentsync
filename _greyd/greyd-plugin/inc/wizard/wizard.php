<?php
/*
Feature Name:   Greyd Wizard
Description:    Enqueue general wizard styles & scripts.
Plugin URI:     https://jakobtrost.de
Author:         Greyd
Author URI:     https://jakobtrost.de
Version:        0.9
Text Domain:    contentsync_hub
Domain Path:    /languages/
Priority:       99
Forced:         true
*/
namespace Greyd;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

new Wizard( $config );
class Wizard {

	/**
	 * Holds global config args.
	 */
	private $config;

	/**
	 * Constructor
	 */
	public function __construct( $config ) {

		// set config
		$this->config  = (object) $config;
		
		add_action( 'admin_enqueue_scripts', array( $this, 'load_backend_scripts' ), 40 );
	}

	/**
	 * add basic scripts
	 */
	public function load_backend_scripts() {
		
		$uri = plugin_dir_url( __FILE__ ) . 'assets';

		wp_register_style(
			"greyd-wizard-style",
			$uri.'/css/admin-style.css',
			null,
			GREYD_VERSION,
			'all'
		);
		wp_enqueue_style(
			"greyd-wizard-style"
		);


		wp_register_script(
			"greyd-wizard-script",
			$uri.'/js/admin-script.js',
			array('wp-data', 'jquery', "greyd-admin-script"),
			GREYD_VERSION,
			true
		);
		wp_enqueue_script(
			"greyd-wizard-script"
		);

		if ( isset( $_GET['wizard'] ) ) {
			$script = '';
			$wizard = $_GET['wizard'];
			if ( $wizard == 'add' ) {
				$script .= 'jQuery(function() { greyd.wizard.open(); });';
			}
			if ( ! empty( $script ) ) {
				wp_add_inline_script( "greyd-wizard-script", $script, 'after' );
			}
		}
	}
}
