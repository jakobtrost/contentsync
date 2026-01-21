<?php

namespace Contentsync\Admin\Views\Sync;

class Overview {

	/**
	 * Screen options for the admin pages
	 */
	public static function add_screen_options() {
		$args = array(
			'label'   => __( 'Entries per page:', 'contentsync' ),
			'default' => self::$args['posts_per_page'],
			'option'  => 'globals_per_page',
		);

		add_screen_option( 'per_page', $args );

		$this->Global_List_Table = new Global_List_Table( self::$args['posts_per_page'] );
	}

	/**
	 * Save the admin screen option
	 */
	public function save_screen_options( $status, $option, $value ) {

		if ( 'globals_per_page' == $option ) {
			return $value;
		}

		return $status;
	}
}
