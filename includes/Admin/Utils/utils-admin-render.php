<?php

namespace Contentsync\Admin\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Show WordPress style notice in top of page.
 *
 * @param string $msg   The message to show.
 * @param string $mode  Style of the notice (error, warning, success, info).
 */
function render_admin_notice( $msg, $mode = 'info' ) {
	if ( empty( $msg ) ) {
		return;
	}
	echo "<div class='notice notice-{$mode} is-dismissible'><p>{$msg}</p></div>";
}

/**
 * Make the contentsync status box
 */
function make_admin_icon_status_box( $status = 'export', $text = '', $show_icon = true ) {

	$status = $status === 'root' ? 'export' : ( $status === 'linked' ? 'import' : $status );

	// generate the title based on the status
	$titles = array(
		'export' => __( 'Root post', 'contentsync' ),
		'import' => __( 'Linked post', 'contentsync' ),
		'error'  => __( 'Error', 'contentsync' ),
		'info'   => __( 'Info', 'contentsync' ),
	);
	$title  = isset( $titles[ $status ] ) ? $titles[ $status ] : null;

	// generate the color based on the status
	$color  = '';
	$colors = array(
		// purple
		'export'  => 'purple',
		'purple'  => 'purple',
		// green
		'success' => 'green',
		'import'  => 'green',
		'green'   => 'green',
		// blue
		'info'    => 'blue',
		'started' => 'blue',
		'blue'    => 'blue',
		// red
		'error'   => 'red',
		'failed'  => 'red',
		'red'     => 'red',
		// yellow
		'warning' => 'yellow',
		'yellow'  => 'yellow',
	);
	$color  = isset( $colors[ $status ] ) ? $colors[ $status ] : $color;

	// add fallback text based on the status
	if ( empty( $text ) ) {
		$texts = array(
			'failed'  => __( 'Failed', 'contentsync' ),
			'success' => __( 'Completed', 'contentsync' ),
			'started' => __( 'Started', 'contentsync' ),
			'init'    => __( 'Scheduled', 'contentsync' ),
		);
		$text  = isset( $texts[ $status ] ) ? $texts[ $status ] : $text;
	}

	$icon = $show_icon ? '<img src="' . esc_url( plugins_url( 'assets/icon/' . $status . '.svg', __DIR__ ) ) . '" style="width:auto;height:16px;">' : '';

	return sprintf(
		'<span %1$s class="contentsync_info_box %2$s contentsync_status">%3$s%4$s</span>',
		/* title    */ $title ? 'data-title="' . preg_replace( '/\s{1}/', '&nbsp;', $title ) . '"' : '',
		/* color    */ $color,
		/* icon     */ $icon,
		/* text     */ ! empty( $text ) ? '<span>' . $text . '</span>' : ''
	);
}

/**
 * Make admin info box.
 *
 * @param array $atts
 *      @property string above      Bold Headline.
 *      @property string text       Infotext.
 *      @property string class      Extra class(es)).
 *      @property string style      Style of the notice (success, warning, alert, new).
 *      @property string styling    Color Style of the notice (green, orange, red).
 */
function make_admin_info_box( $atts = array() ) {

	$above = isset( $atts['above'] ) ? '<b>' . esc_attr( $atts['above'] ) . '</b>' : '';
	$text  = isset( $atts['text'] ) ? '<span>' . html_entity_decode( esc_attr( $atts['text'] ) ) . '</span>' : '';
	$class = isset( $atts['class'] ) ? esc_attr( $atts['class'] ) : '';

	$styling = isset( $atts['style'] ) ? esc_attr( $atts['style'] ) : ( isset( $atts['styling'] ) ? esc_attr( $atts['styling'] ) : '' );
	if ( $styling == 'success' || $styling == 'green' ) {
		$info_icon = 'dashicons-yes';
	} elseif ( $styling == 'warning' || $styling == 'orange' ) {
		$info_icon = 'dashicons-warning';
	} elseif ( $styling == 'alert' || $styling == 'red' || $styling == 'danger' || $styling == 'error' ) {
		$info_icon = 'dashicons-warning';
	} elseif ( $styling == 'new' ) {
		$info_icon = 'dashicons-megaphone';
	} else {
		$info_icon = 'dashicons-info';
	}

	return "<div class='contentsync_info_box {$styling} {$class}'><span class='dashicons {$info_icon}'></span><div>{$above}{$text}</div></div>";
}

/**
 * Make small admin info popup with toggle.
 *
 * @param string $content   Infotext.
 * @param string $className Extra class names.
 */
function make_admin_info_popup( $content = '', $className = '' ) {
	if ( empty( $content ) ) {
		return false;
	}
	return "<span class='contentsync_popup_wrapper'>" .
		"<span class='toggle dashicons dashicons-info'></span>" .
		"<span class='popup {$className}'>{$content}</span>" .
	'</span>';
}

/**
 * Make admin info dialog similar to make_admin_info_popup but bigger.
 *
 * @param string $content   Infotext.
 * @param string $className Extra class names.
 */
function make_admin_info_dialog( $content = '', $className = '' ) {
	return "<span class='contentsync_popup_wrapper'>" .
		"<span class='toggle dashicons dashicons-info'></span>" .
		"<dialog class='{$className}'>{$content}</dialog>" .
	'</span>';
}

/**
 * Make an admin dashicon.
 * https://developer.wordpress.org/resource/dashicons
 *
 * @param string $icon  Dashicon slug.
 */
function make_dashicon( $icon ) {
	$icon = str_replace( 'dashicons-', '', $icon );
	return "<span class='dashicons dashicons-$icon'></span>";
}
