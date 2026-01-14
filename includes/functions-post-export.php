<?php
/**
 * Export & Import Post Helper
 */
namespace Contentsync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the relative path to the export folder.
 *
 * @var string
 */
$contentsync_export_basic_path = null;

/**
 * Whether logs are echoed.
 * Usually set via function @see post_export_enable_logs();
 * Logs are especiallly usefull when debugging ajax actions.
 *
 * @var bool
 */
$contentsync_export_logs = false;

/**
 * Holds the current language code
 *
 * @var string
 */
$contentsync_export_language_code = null;

/**
 * Get all supported posttypes for global contents
 */
function get_export_post_types() {

	// check cache
	if ( $cache = wp_cache_get( 'get_export_post_types', 'synced_post_export' ) ) {
		return $cache;
	}

	$include = array( 'page', 'post', 'attachment', 'wp_template', 'wp_template_part', 'wp_block', 'wp_navigation' );
	$exclude = array();

	$posttypes = array_keys( get_post_types( array( '_builtin' => false ) ) );

	$supported = array_diff( array_merge( $include, $posttypes ), $exclude );

	// Set cache
	wp_cache_set( 'get_export_post_types', $supported, 'synced_post_export' );

	return $supported;
}

/**
 * Get path to the export folder. Use this path to write files.
 *
 * @param string $folder Folder inside wp-content/backup/posts/
 *
 * @return string $path
 */
function get_export_file_path( $folder = '' ) {
	global $contentsync_export_basic_path;

	// get basic path from var
	if ( $contentsync_export_basic_path ) {
		$path = $contentsync_export_basic_path;
	}
	// init basic path
	else {
		$path = WP_CONTENT_DIR . '/backup';

		if ( ! file_exists( $path ) ) {
			do_action( 'post_export_log', sprintf( '  - create folder "%s".', $path ) );
			mkdir( $path, 0755, true );
		}
		$path .= '/posts';
		if ( ! file_exists( $path ) ) {
			do_action( 'post_export_log', sprintf( '  - create folder "%s".', $path ) );
			mkdir( $path, 0755, true );
		}
		$path .= '/';

		// save in var
		$contentsync_export_basic_path = $path;
	}

	// get directory
	if ( ! empty( $folder ) ) {
		$path .= $folder;
		if ( ! file_exists( $path ) ) {
			do_action( 'post_export_log', sprintf( '  - create folder "%s".', $path ) );
			mkdir( $path, 0755, true );
		}
	}

	$path .= '/';
	return $path;
}

/**
 * Convert file path to absolute path. Use this path to download a file.
 *
 * @param string $path File path.
 *
 * @return string $path
 */
function convert_wp_content_dir_to_path( $path ) {
	$url = str_replace(
		WP_CONTENT_DIR,
		WP_CONTENT_URL,
		$path
	);

	if ( is_ssl() ) {
		$url = str_replace( 'http://', 'https://', $url );
	}

	return $url;
}

/**
 * Returns list of blacklisted meta keys that should not be exported.
 *
 * This function provides a list of meta keys that are automatically
 * excluded from post exports. The list can be customized using the
 * 'contentsync_export_blacklisted_meta' filter.
 *
 * @param string $context      The context of the operation (export or import). @since 2.18.0
 * @param int    $post_id      The ID of the post being exported or imported. @since 2.18.0
 *
 * @return array Array of meta keys to exclude from export or import.
 */
function get_blacklisted_meta_for_export( $context = 'export', $post_id = 0 ) {
	/**
	 * Filter to customize the list of blacklisted meta keys for export or import.
	 *
	 * This filter allows developers to add or remove meta keys that should
	 * be excluded from post exports or imports. It's useful for preventing sensitive
	 * or site-specific meta data from being exported or imported.
	 *
	 * @filter contentsync_export_blacklisted_meta
	 * @filter contentsync_import_blacklisted_meta @since 2.18.0
	 *
	 * @param array $blacklisted_meta Array of meta keys to exclude from export or import.
	 * @param int   $post_id        The ID of the post being exported or imported.
	 *
	 * @return array                   Modified array of blacklisted meta keys.
	 */
	return apply_filters(
		'contentsync_' . $context . '_blacklisted_meta',
		array(
			'_wp_attached_file',
			'_wp_attachment_metadata',
			'_edit_lock',
			'_edit_last',
			'_wp_old_slug',
			'_wp_old_date',
			'_wpb_vc_js_status',
		),
		$post_id
	);
}

/**
 * Check whether to skip a certain meta key and not im- or export it
 *
 * @param string $meta_key     The meta key being evaluated.
 * @param mixed  $meta_value   The meta value being evaluated.
 * @param string $context      The context of the operation (export or import). @since 2.18.0
 * @param int    $post_id      The ID of the post being exported or imported. @since 2.18.0
 *
 * @return bool                Whether to skip the meta option.
 */
function maybe_skip_meta_option( $meta_key, $meta_value, $context = 'export', $post_id = 0 ) {

	$skip = false;

	// skip empty options
	if ( $meta_value === '' ) {
		// do_action( "post_export_log","  - skipped empty meta option for '$meta_key'");
		$skip = true;
	}
	// skip oembed meta options
	elseif ( strpos( $meta_key, '_oembed_' ) === 0 ) {
		do_action( 'post_export_log', "  - skipped oembed option '$meta_key'" );
		$skip = true;
	}

	/**
	 * Filter to determine whether a specific meta option should be skipped during export or import.
	 *
	 * This filter allows developers to implement custom logic for determining
	 * whether specific meta keys or values should be excluded from export or import.
	 * It's useful for implementing site-specific export/import rules or business logic.
	 *
	 * @filter contentsync_export_maybe_skip_meta_option
	 * @filter contentsync_import_maybe_skip_meta_option @since 2.18.0
	 *
	 * @param bool   $skip_meta    Whether to skip the meta option (default: false).
	 * @param string $meta_key     The meta key being evaluated.
	 * @param mixed  $meta_value   The meta value being evaluated.
	 * @param int    $post_id      The ID of the post being exported or imported. @since 2.18.0
	 *
	 * @return bool                Whether to skip the meta option.
	 */
	return apply_filters( 'contentsync_' . $context . '_maybe_skip_meta_option', $skip, $meta_key, $meta_value, $post_id );
}

/**
 * Get post by name and post_type
 *
 * eg. checks if post already exists.
 *
 * @param object|string $post   WP_Post object or post_name
 *
 * @return bool|object False on failure, WP_Post on success.
 */
function get_post_by_name_and_type( $post ) {

	$post_name = is_object( $post ) ? (string) $post->post_name : (string) $post;
	$post_type = is_object( $post ) ? (string) $post->post_type : get_export_post_types();
	$args      = array(
		'name'        => $post_name,
		'post_type'   => $post_type,
		'numberposts' => 1,
		'post_status' => array( 'publish', 'pending', 'draft', 'future', 'private', 'inherit' ),
	);

	// only get post of same language
	if ( Translation_Manager::switch_to_language_context( $post ) ) {
		$args['suppress_filters'] = false;
	} else {
		$args['suppress_filters'] = true;
		$args['lang']             = '';
	}

	// query
	$result = get_posts( $args );

	if ( is_array( $result ) && isset( $result[0] ) ) {
		do_action( 'post_export_log', sprintf( "  - %s found with ID '%s'.", $post->post_type, $result[0]->ID ) );
		return $result[0];
	} else {
		do_action( 'post_export_log', sprintf( "  - Post '%s' not found by name and post type.", $post_name ) );
		return false;
	}
}

/**
 * Get existing post ID by name and post_type
 *
 * @param object|string $post   WP_Post object or post_name
 *
 * @return int 0 on failure, post ID on success.
 */
function get_existing_post_id( $post ) {
	$existing_post = get_post_by_name_and_type( $post );
	if ( $existing_post && isset( $existing_post->ID ) ) {
		do_action( 'post_export_log', sprintf( '  - existing post with ID: %s.', $existing_post->ID ) );
		return $existing_post->ID;
	}
	return 0;
}

/**
 * Return error to frontend
 */
function post_export_return_error( $message = '' ) {
	global $contentsync_export_logs;
	if ( $contentsync_export_logs ) {
		echo "\r\n\r\n";
	}
	wp_die( 'error::' . $message );
}

/**
 * Return success to frontend
 */
function post_export_return_success( $message = '' ) {
	global $contentsync_export_logs;
	if ( $contentsync_export_logs ) {
		echo "\r\n\r\n";
	}
	wp_die( 'success::' . $message );
}

/**
 * Toggle debug logs
 *
 * @param bool $enable
 */
function post_export_enable_logs( $enable = true ) {
	global $contentsync_export_logs;
	$contentsync_export_logs = (bool) $enable;

	add_action( 'post_export_log', __NAMESPACE__ . '\\post_export_log', 10, 2 );
}

/**
 * Echo a log
 */
function post_export_log( $message, $var = 'do_not_log' ) {
	global $contentsync_export_logs;
	if ( $contentsync_export_logs ) {
		if ( ! empty( $message ) ) {
			echo "\r\n" . $message;
		}
		if ( $var !== 'do_not_log' ) {
			echo "\r\n";
			print_r( $var );
			echo "\r\n";
		}
	}
}

/**
 * Replace strings for export
 *
 * @param string $subject
 * @param int    $post_id
 *
 * @return string $subject
 */
function replace_dynamic_post_strings( $subject, $post_id, $log = true ) {

	if ( empty( $subject ) ) {
		return $subject;
	}

	if ( $log ) {
		do_action( 'post_export_log', "\r\n" . 'Prepare strings.' );
	}

	// get patterns
	$replace_strings = (array) get_nested_string_patterns( $subject, $post_id );
	foreach ( $replace_strings as $name => $string ) {

		if ( $log ) {
			if ( strpos( $subject, $string ) !== false ) {
				do_action( 'post_export_log', sprintf( "  - '%s' was prepared for export (%s → {{%s}}).", $name, $string, $name ) );
			} else {
				do_action( 'post_export_log', sprintf( "  - '%s' was not found in the subject (%s).", $name, $string ) );
			}
		}
		$subject = str_replace( $string, '{{' . $name . '}}', $subject );
	}

	return $subject;
}

/**
 * Parse export arguments.
 *
 * @since 2.18.0
 *
 * @param array $arguments The arguments to parse.
 *
 * @return array The parsed arguments.
 */
function parse_post_export_arguments( $arguments ) {

	$default_arguments = apply_filters(
		'contentsync_export_default_arguments',
		array(
			'append_nested'  => true,
			'whole_posttype' => false,
			'all_terms'      => false,
			'resolve_menus'  => true,
			'translations'   => false,
			'query_args'     => array(),
		)
	);

	if ( ! is_array( $arguments ) ) {
		return $default_arguments;
	}

	$parsed_arguments = wp_parse_args( $arguments, $default_arguments );

	/**
	 * Filter the parsed export arguments.
	 *
	 * @filter contentsync_export_arguments
	 *
	 * @param array $parsed_arguments The parsed arguments.
	 * @param array $arguments        The original arguments.
	 *
	 * @return array The filtered parsed arguments.
	 */
	return apply_filters( 'contentsync_export_arguments', $parsed_arguments, $arguments );
}
