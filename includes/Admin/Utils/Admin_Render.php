<?php

namespace Contentsync\Admin\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Static helper class for admin rendering utilities.
 */
class Admin_Render {

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
	 */
	public static function make_admin_icon_status_box( $status = 'export', $text = '', $show_icon = true ) {

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

		$icon_url = CONTENTSYNC_PLUGIN_URL . 'includes/Admin/Utils/assets/icon/' . $status . '.svg';
		$icon     = $show_icon ? '<img src="' . esc_url( $icon_url ) . '" style="width:auto;height:16px;">' : '';

		self::maybe_enqueue_stylesheet( 'contentsync-info-box', CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Utils/assets/css/contentsync-info-box.css' );
		self::maybe_enqueue_stylesheet( 'contentsync-status-box', CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Utils/assets/css/contentsync-status-box.css' );

		return sprintf(
			'<span %1$s class="contentsync-info-box %2$s contentsync-status">%3$s%4$s</span>',
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
	 */
	public static function make_admin_tooltip_popup( $content = '', $className = '' ) {
		if ( empty( $content ) ) {
			return false;
		}

		self::maybe_enqueue_stylesheet( 'contentsync-tooltip', CONTENTSYNC_PLUGIN_URL . '/includes/Admin/Utils/assets/css/contentsync-tooltip.css' );

		return "<span class='contentsync-tooltip-wrapper'>" .
			"<span class='toggle dashicons dashicons-info'></span>" .
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
