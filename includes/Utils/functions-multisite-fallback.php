<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fallback functions for single site installations
 */
if ( ! is_multisite() ) {
	if ( ! function_exists( 'switch_to_blog' ) ) {
		function switch_to_blog( $blog_id = '' ) { }
	}
	if ( ! function_exists( 'restore_current_blog' ) ) {
		function restore_current_blog() { }
	}
	if ( ! function_exists( 'network_site_url' ) ) {
		function network_site_url() {
			return site_url();
		}
	}
	if ( ! function_exists( 'get_blog_permalink' ) ) {
		function get_blog_permalink( $blog_id, $post_id ) {
			return get_permalink( $post_id );
		}
	}
}
