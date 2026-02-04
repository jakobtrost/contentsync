<?php

namespace Contentsync\Admin\Utils;

use Contentsync\Post_Transfer\Post_Transfer_Service;

defined( 'ABSPATH' ) || exit;

/**
 * Static helper class for admin rendering utilities.
 */
class Admin_Render {

	/**
	 * Check if current edit.php screen supports content sync functionality
	 *
	 * @return bool
	 */
	public static function is_current_edit_screen_supported() {

		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$supported = false;
		$screen    = get_current_screen();

		if ( is_object( $screen ) && isset( $screen->base ) ) {

			if (
				$screen->base === 'edit' // edit (overview) screen
				|| $screen->base === 'upload' // media (overview) screen
			) {
				$current_post_type = isset( $screen->post_type ) ? $screen->post_type : 'post';
				$supported         = in_array( $current_post_type, Post_Transfer_Service::get_supported_post_types() );
			}
		}

		/**
		 * Filter to customize whether the current screen supports content sync functionality.
		 *
		 * This filter allows developers to extend or modify the logic that determines
		 * which admin screens should display content sync options. It's useful
		 * for adding support to custom post type screens or implementing custom
		 * screen detection logic.
		 *
		 * @filter contentsync_is_current_edit_screen_supported
		 *
		 * @param bool  $supported Whether the current screen supports export/import.
		 * @param object $screen   The current WP_Screen object.
		 *
		 * @return bool           Whether the current screen supports export/import.
		 */
		return apply_filters( 'contentsync_is_current_edit_screen_supported', $supported, $screen );
	}

	/**
	 * Check if current post.php screen supports content sync functionality
	 *
	 * @return bool
	 */
	public static function is_current_post_screen_supported() {

		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$supported = false;
		$screen    = get_current_screen();

		if ( is_object( $screen ) && isset( $screen->base ) ) {

			if ( $screen->base === 'post' ) {
				$current_post_type = isset( $screen->post_type ) ? $screen->post_type : 'post';
				$supported         = in_array( $current_post_type, Post_Transfer_Service::get_supported_post_types() );
			}
			// on site editor screen
			elseif ( $screen->base === 'site-editor' ) {
				$supported = true;
			}
		}

		/**
		 * Filter to customize whether the current screen supports content sync functionality.
		 *
		 * This filter allows developers to extend or modify the logic that determines
		 * which admin screens should display content sync options. It's useful
		 * for adding support to custom post type screens or implementing custom
		 * screen detection logic.
		 *
		 * @filter contentsync_is_current_post_screen_supported
		 *
		 * @param bool  $supported Whether the current screen supports export/import.
		 * @param object $screen   The current WP_Screen object.
		 *
		 * @return bool           Whether the current screen supports export/import.
		 */
		return apply_filters( 'contentsync_is_current_post_screen_supported', $supported, $screen );
	}

	/**
	 * Show WordPress style notice in top of page.
	 *
	 * @param string $msg   The message to show.
	 * @param string $mode  Style of the notice (error, warning, success, info).
	 */
	public static function render_admin_notice( $msg, $mode = 'info' ) {
		if ( empty( $msg ) ) {
			return;
		}
		echo "<div class='notice notice-{$mode} is-dismissible'><p>{$msg}</p></div>";
	}

	/**
	 * Make the contentsync status box
	 *
	 * @param string $status Either 'root', 'linked', 'unlinked', 'info', 'error'
	 * @param string $text   The text to display.
	 * @param bool   $show_icon Whether to show the icon.
	 *
	 * @return string The HTML of the status box.
	 */
	public static function make_admin_icon_status_box( $status = 'root', $text = '', $show_icon = true ) {

		// generate the title based on the status
		$titles = array(
			'root'     => __( 'Global synced post', 'contentsync' ),
			'linked'   => __( 'Global linked post', 'contentsync' ),
			'unlinked' => __( 'Local post', 'contentsync' ),
			'error'    => __( 'Error', 'contentsync' ),
			'info'     => __( 'Info', 'contentsync' ),
		);
		$title  = isset( $titles[ $status ] ) ? $titles[ $status ] : null;

		// generate the color based on the status
		$color  = '';
		$colors = array(
			// purple
			'root'    => 'purple',
			'export'  => 'purple',
			'purple'  => 'purple',
			// green
			'success' => 'green',
			'import'  => 'green',
			'green'   => 'green',
			'linked'  => 'green',
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

		$icon_url = CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Utils/assets/icon/icon-' . $status . '.svg';
		$icon     = $show_icon ? '<img src="' . esc_url( $icon_url ) . '" style="width:auto;height:16px;">' : '';

		self::maybe_enqueue_stylesheet( 'contentsync-info-box', CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Utils/assets/css/contentsync-info-box.css' );
		self::maybe_enqueue_stylesheet( 'contentsync-status', CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Utils/assets/css/contentsync-status.css' );

		return sprintf(
			'<span %1$s class="contentsync-info-box %2$s contentsync-status">%3$s%4$s</span>',
			/* title    */ ( $title && empty( $text ) ) ? 'data-tooltip="' . preg_replace( '/\s{1}/', '&nbsp;', $title ) . '"' : '',
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
	public static function make_admin_info_box( $atts = array() ) {

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

		self::maybe_enqueue_stylesheet( 'contentsync-info-box', CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Utils/assets/css/contentsync-info-box.css' );

		return "<div class='contentsync-info-box {$styling} {$class}'><span class='dashicons {$info_icon}'></span><div>{$above}{$text}</div></div>";
	}

	/**
	 * Make small admin info popup with toggle.
	 *
	 * @param string $content   Infotext.
	 * @param string $className Extra class names.
	 * @param string $toggle_icon Icon slug.
	 *
	 * @return string The HTML of the tooltip popup.
	 */
	public static function make_admin_tooltip_popup( $content = '', $className = '', $toggle_icon = 'info' ) {
		if ( empty( $content ) ) {
			return false;
		}

		self::maybe_enqueue_stylesheet( 'contentsync-tooltip', CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Utils/assets/css/contentsync-tooltip.css' );

		return "<span class='contentsync-tooltip-wrapper'>" .
			"<span class='toggle dashicons dashicons-{$toggle_icon}'></span>" .
			"<span class='popup {$className}'>{$content}</span>" .
		'</span>';
	}

	/**
	 * Make admin info dialog similar to make_admin_tooltip_popup but bigger.
	 *
	 * @param string $content   Infotext.
	 * @param string $className Extra class names.
	 */
	public static function make_admin_tooltip_dialog( $content = '', $className = '' ) {
		if ( empty( $content ) ) {
			return false;
		}

		self::maybe_enqueue_stylesheet( 'contentsync-tooltip', CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Utils/assets/css/contentsync-tooltip.css' );

		return "<span class='contentsync-tooltip-wrapper'>" .
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
	public static function make_dashicon( $icon ) {
		$icon = str_replace( 'dashicons-', '', $icon );
		return "<span class='dashicons dashicons-$icon'></span>";
	}

	/**
	 * Make tabs.
	 *
	 * @param array  $tabs   Tabs array with label, url and class.
	 *                      Example: array( 'label' => 'Label', 'url' => 'www.example.com', 'class' => 'active' ).
	 * @param string $anchor Anchor ID.
	 *
	 * @return string HTML tabs.
	 */
	public static function make_tabs( $tabs = array(), $anchor = '' ) {
		if ( empty( $tabs ) ) {
			return '';
		}

		self::maybe_enqueue_stylesheet( 'contentsync-tabs', CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Utils/assets/css/contentsync-tabs.css' );

		return "<div class='contentsync-tabs' id='{$anchor}'>" . implode(
			'',
			array_map(
				function ( $tab ) {
					return "<a href='{$tab['url']}' class='tab {$tab['class']}'>{$tab['label']}</a>";
				},
				$tabs
			)
		) . '</div>';
	}

	/**
	 * Checks if a stylesheet is already enqueued. If not, enqueues it.
	 *
	 * @param string $stylesheet The stylesheet slug.
	 * @param string $url        The URL of the stylesheet.
	 */
	public static function maybe_enqueue_stylesheet( $stylesheet, $url ) {
		if ( ! wp_style_is( $stylesheet, 'enqueued' ) ) {
			wp_enqueue_style(
				$stylesheet,
				$url,
				array(),
				CONTENTSYNC_VERSION
			);
		}
	}
}
