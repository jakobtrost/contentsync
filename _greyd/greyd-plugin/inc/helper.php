<?php
/**
 * Utility functions for internal usage.
 */
namespace Greyd;

if ( !defined( 'ABSPATH' ) ) exit;

new Helper;
class Helper {

	/*
	====================================================================================
		Basics
	====================================================================================
	*/

	/**
	 * Check if Greyd.Suite Theme is installed and active.
	 */
	public static function is_contentsync_classic() {

		// check if Greyd.Suite is active
		if ( defined( 'GREYD_CLASSIC_VERSION' ) || class_exists("\basics") ) {
			return true;
		}

		// check if Greyd Theme is active
		if ( defined( 'GREYD_THEME_CONFIG' ) ) {
			return false;
		}

		// check if Greyd.Suite is installed
		$_current_main_theme = !empty( wp_get_theme()->parent() ) ? wp_get_theme()->parent() : wp_get_theme();
		return strpos( $_current_main_theme->get('Name'), "GREYD.SUITE" ) !== false;
	}

	public static function is_fse() {
		return current_theme_supports( 'block-templates' );
	}

	/**
	 * Check if this is the new greyd theme
	 */
	public static function is_contentsync_fse() {
		return defined( 'GREYD_THEME_CONFIG' );
	}

	/**
	 * @deprecated Can be removed in future versions
	 */
	public static function is_contentsync_suite() {
		return self::is_contentsync_classic();
	}

	/**
	 * Check if the current setup is with block editor and Greyd.Blocks.
	 * @see functions.php for details
	 */
	public static function is_contentsync_blocks() {
		return function_exists('is_contentsync_blocks') && is_contentsync_blocks() ? true : false;
	}

	/**
	 * @since 2.5.0 Define if the current setup supports Beta Features.
	 * 
	 * @return bool
	 */
	public static function is_contentsync_beta() {

		if ( defined( 'GREYD_ENABLE_BETA_BLOCKS') ) {
			return constant( 'GREYD_ENABLE_BETA_BLOCKS' );
		}

		if ( defined( 'IS_GREYD_BETA') ) {
			return constant( 'IS_GREYD_BETA' );
		}

		if ( \Greyd\Helper::is_contentsync_classic() ) {
			return false;
		}

		if ( defined( 'GUTENBERG_VERSION') ) {
			return true;
		}

		return false;
	}

	public static function is_contentsync_alpha() {
		return defined( 'IS_GREYD_ALPHA' ) && constant( 'IS_GREYD_ALPHA' );
	}

	/**
	 * Whether we are in a REST REQUEST. Similar to is_admin().
	 */
	public static function is_rest_request() {
		return defined('REST_REQUEST') && REST_REQUEST;
	}

	/**
	 * Check if the currently visited page is a block editor page.
	 * @link https://wordpress.stackexchange.com/questions/309862/check-if-gutenberg-is-currently-in-use
	 */
	public static function is_gutenberg_page() {
		if (function_exists( 'is_gutenberg_page' ) && is_gutenberg_page()) {
			// The Gutenberg plugin is on.
			return true;
		}
		$current_screen = get_current_screen();
		if (method_exists( $current_screen, 'is_block_editor' ) && $current_screen->is_block_editor()) {
			// Gutenberg page on 5+.
			return true;
		}
		return false;
	}

	/**
	 * Filter WP_Post ID.
	 * @since 1.3.9
	 * 
	 * Supported:
	 * - See if WPML translation exists.
	 * - See if Polylang translation exists.
	 * 
	 * @param int $post_id       WP_Post ID.
	 * @param string $post_type  Post type, defaults to 'post'.
	 * @return int
	 */
	public static function filter_post_id( $post_id, $post_type='post' ) {

		$translation_tool = self::get_translation_tool();

		switch ( $translation_tool ) {
			case 'wpml':
				/**
				 * WPML: filter Post ID and look for translated version.
				 * @see https://wpml.org/wpml-hook/wpml_object_id/
				 */
				$filtered_id = apply_filters( 'wpml_object_id', $post_id, $post_type, null, null );
				if ( $filtered_id && $filtered_id != $post_id ) {
					$post_id = $filtered_id;
				}
				break;
			case 'polylang':
				$filtered_id = pll_get_post( $post_id );
				if ( $filtered_id && $filtered_id != $post_id ) {
					$post_id = $filtered_id;
				}
				break;
		}

		$post_id = apply_filters( 'contentsync_filter_post_id', intval( $post_id ), $post_type );
		return $post_id;
	}

	/**
	 * Get & filter the current home URL.
	 * 
	 * Supported:
	 * - Polylang
	 * 
	 * @since 2.0.0
	 * 
	 * @return string
	 */
	public static function get_home_url() {
		
		$home_url = get_home_url();

		// polylang support
		if ( function_exists( 'pll_home_url' ) ) {
			$home_url = pll_home_url();
		}

		$home_url = apply_filters( 'contentsync_filter_home_url', $home_url );

		return $home_url;
	}

	/**
	 * Filter an url.
	 * @since 1.3.9
	 * 
	 * Supported:
	 * - See if WPML translation exists.
	 * 
	 * @param string $url
	 * @return string
	 */
	public static function filter_url( $url ) {

		if ( empty($url) ) return $url;

		$firstChar = substr($url, 0, 1);

		if ( $firstChar === '/' || $firstChar === '#' || $firstChar === '?' ) {
			// relative link
			return apply_filters( 'contentsync_filter_url', $url );
		}
		else if ( empty( parse_url($url)['scheme'] ) ) {
			// scheme is missing
			$url = 'https://'.$url;
		}

		// check if content_url() is part of url
		$is_content_url = strpos( $url, content_url() ) !== false;

		// do not filter /wp-content urls by language
		if ( ! $is_content_url ) {

			/**
			 * @filter wpml_permalink
			 * Filters a WordPress permalink and converts it to a language-specific
			 * permalink based on the language URL format set in the WPML language settings.
			 * 
			 * @see https://wpml.org/documentation/support/wpml-coding-api/wpml-hooks-reference/#hook-662194
			 * 
			 * @param string $permalink      The WordPress generated URL to filter. (required)
			 * @param string $language_code  The language to convert the url into. It accepts a 2-letter code.
			 *                               When set to null, it falls back to default language for root page,
			 *                               or current language in all other cases. Default is null. (optional)
			 * @param bool $full_resolution  Enable full conversion of hard-coded URLs. (optional)
			 * 
			 * @note This filter is supported by WPML & Polylang.
			 */
			$filtered_url = apply_filters( 'wpml_permalink', $url, self::get_language_code() );
			if ( $filtered_url && $filtered_url != $url && $filtered_url != trailingslashit($url) ) {
				if ( self::is_url_from_current_host( $url ) ) {
					// __debug( '[WPML] - Raw url: ' . $url . ' | filtered url: ' . $filtered_url );
					$url = $filtered_url;
				}
			}
		}

		/**
		 * Filter url.
		 * 
		 * @filter contentsync_filter_url
		 */
		$url = apply_filters( 'contentsync_filter_url', $url );

		return strval( $url );
	}

	/**
	 * Check if a url is from this site by comparing PHP_URL_HOST.
	 * @since 1.7.3
	 * 
	 * @param string $url
	 * @return string
	 */
	public static function is_url_from_current_host( $url ) {

		$url_host = parse_url( $url, PHP_URL_HOST );
		
		if ( is_string( $url_host ) ) {
			$url_host = str_replace( 'www.', '', $url_host );
			/**
			 * Remove TLD from host.
			 * This is necessary to support domain mapping with tools like WPML.
			 * Otherwise domains like 'example.com' and 'example.de' would be
			 * treated as different sites.
			 */
			$url_host = preg_replace( '/\.[a-z]+$/', '', $url_host );
		} else {
			$url_host = '';
		}
	
		$home_url_host = parse_url( home_url(), PHP_URL_HOST );
	
		if ( is_string( $home_url_host ) ) {
			$home_url_host = str_replace( 'www.', '', $home_url_host );
			$home_url_host = preg_replace( '/\.[a-z]+$/', '', $home_url_host );
		} else {
			$home_url_host = '';
		}
	
		// URL does not belong to this site.
		if ( $url_host && $url_host !== $home_url_host ) {
			// __debug( 'URL does not belong to this site: ' . $url . ' | ' . $url_host . ' !== ' . $home_url_host );
			return false;
		}

		return true;
	}

	/*
	====================================================================================
		Messages and Infos 
	====================================================================================
	*/

	/**
	 * Render a frontend message box.
	 * @param string $msg   The message to show.
	 * @param string $mode  Style of the notice (error, warning, success, info).
	 */
	public static function show_frontend_message($msg, $mode="info") {
		if ($mode != "info" && $mode != "success" && $mode != "danger") $mode = "info";
		return "<div class='message {$mode}'>{$msg}</div>";
	}

	/**
	 * Show wordpress style notice in top of page.
	 * @param string $msg   The message to show.
	 * @param string $mode  Style of the notice (error, warning, success, info).
	 * @param bool $list    Add to hub msg list (default: false).
	 */
	public static function show_message($msg, $mode='info', $list=false) {
		if (empty($msg)) return;
		if ($list) echo "<p class='hub_msg msg_list {$mode}'>{$msg}</p>";
		else echo "<div class='notice notice-{$mode} is-dismissible'><p>{$msg}</p></div>";
	}

	/**
	 * Render Infobox in Backend.
	 * @param array $atts
	 *      @property string above      Bold Headline.
	 *      @property string text       Infotext.
	 *      @property string class      Extra class(es)).
	 *      @property string style      Style of the notice (success, warning, alert, new).
	 *      @property string styling    Color Style of the notice (green, orange, red).
	 * @param bool $echo    Directly output the Content, or return contents (default: false).
	 */
	public static function render_info_box($atts=[], $echo=false) {

		$above      = isset($atts['above']) ? '<b>'.esc_attr($atts['above']).'</b>' : '';
		$text       = isset($atts['text']) ? '<span>'.html_entity_decode(esc_attr($atts['text'])).'</span>' : '';
		$class      = isset($atts['class']) ? esc_attr($atts['class']) : '';

		$styling    = isset($atts['style']) ? esc_attr($atts['style']) : ( isset($atts['styling']) ? esc_attr($atts['styling']) : '' );
		if ($styling == 'success' || $styling == 'green') $info_icon = 'dashicons-yes';
		else if ($styling == 'warning' || $styling == 'orange') $info_icon = 'dashicons-warning';
		else if ($styling == 'alert' || $styling == 'red' || $styling == 'danger' || $styling == 'error') $info_icon = 'dashicons-warning';
		else if ($styling == 'new') $info_icon = 'dashicons-megaphone';
		else $info_icon = 'dashicons-info';

		$return = "<div class='contentsync_info_box {$styling} {$class}'><span class='dashicons {$info_icon}'></span><div>{$above}{$text}</div></div>";
		if ($echo) echo $return;
		return $return;
	}

	/**
	 * Render small Infopopup with toggle in Backend.
	 * @param string $content   Infotext.
	 * @param string $className Extra class names.
	 * @param bool $echo        Directly output the Content, or return contents (default: false).
	 */
	public static function render_info_popup($content="", $className="", $echo=false) {
		if ( empty($content) ) return false;
		$return = "<span class='contentsync_popup_wrapper'>".
			"<span class='toggle dashicons dashicons-info'></span>".
			"<span class='popup {$className}'>{$content}</span>".
		"</span>";
		if ($echo) echo $return;
		return $return;
	}

	/**
	 * Similar to render_info_popup but bigger.
	 * @param string $content   Infotext.
	 * @param string $className Extra class names.
	 * @param bool $echo        Directly output the Content, or return contents (default: false).
	 */
	public static function render_info_dialog($content="", $className="", $echo=false) {
		$return = "<span class='contentsync_popup_wrapper'>".
			"<span class='toggle dashicons dashicons-info'></span>".
			"<dialog class='{$className}'>{$content}</dialog>".
		"</span>";
		if ($echo) echo $return;
		return $return;
	}

	/**
	 * Render a feature tag.
	 * @param string $content   Content of the tag.
	 * @param string $className Extra class names.
	 * @return string           HTML element.
	 */
	public static function render_feature_tag( $content="", $className="" ) {
		if ( empty($content) ) {
			$content = __("Beta", 'contentsync_hub');
		}
		return sprintf(
			'<span class="feature-tag %s">%s</span>',
			$className,
			$content
		);
	}

	/**
	 * Render a Dashicon.
	 * https://developer.wordpress.org/resource/dashicons
	 * @param string $icon  Dashicon slug.
	 * @param bool $echo    Directly output the Content, or return contents (default: false).
	 */
	public static function render_dashicon($icon, $echo=false) {
		$icon = str_replace( "dashicons-", "", $icon );
		$return = "<span class='dashicons dashicons-$icon'></span>";
		if ($echo) echo $return;
		return $return;
	}
	
	/**
	 * render a multiselect dropdown
	 * 
	 * @param string $name      name of the input
	 * @param array  $options   all options as $value => $label
	 * @param array  $args      optional arguments for the element (value, placeholder, classes...)
	 * 
	 * @return string html element
	 */
	public static function render_multiselect($name, $options, $args=[]) {

		if ( !class_exists( '\Greyd\Multiselects' ) ) {
			global $config;
			require_once dirname( __DIR__ ) . '/features/multiselects/init.php';
		}

		return \Greyd\Multiselects::render( $name, $options, $args );
	}

	/*
	====================================================================================
		Plugins
	====================================================================================
	*/

	/**
	 * Get all active Plugins from Option.
	 * Including all active sitewide Plugins.
	 * @param string $mode  all|site|global (default: all)
	 */
	public static function active_plugins($mode = 'all') {

		$plugins = array();

		// get all active plugins
		if ( $mode == 'all' || $mode == 'site' ) {
			$plugins = get_option('active_plugins');
			if ( !is_array($plugins) ) {
				$plugins = array();
			}
		}

		// on multisite, get all active sitewide plugins as well
		if (
			is_multisite()
			&& ( $mode == 'all' || $mode == 'global' )
		) {
			$plugins_multi = get_site_option('active_sitewide_plugins');
			if ( is_array($plugins_multi) && !empty($plugins_multi) ) {
				foreach ($plugins_multi as $key => $value) {
					$plugins[] = $key;
				}
				$plugins = array_unique($plugins);
				sort($plugins);
			}
		}

		return $plugins;
	}

	/**
	 * Check if single Plugin is active.
	 */
	public static function is_active_plugin($file) {
		// check for active plugins
		$plugins = self::active_plugins();
		$active = false;
		if (in_array($file, $plugins)) $active = true;
		return $active;
	}

	/**
	 * Alternative for 'file_get_contents', but with error handling.
	 * This function handles a couple of possible mistakes accessing file contents.
	 * 
	 * - curl is first and preferred file handling
	 * - if not available, allow_url_fopen is checked (msg when false)
	 * - then, file_get_contents is used
	 * - if it fails, http authentication is used
	 * - returns false if everything fails
	 * 
	 * @param string $file  Full URL of file.
	 * @return string|bool  Contents of the file or false.
	 */
	public static function get_file_contents($file) {
		// normalize path/url
		$file = wp_normalize_path($file);

		/**
		 * Adjust script memory limit (default: 2048M)
		 * @filter 'contentsync_hub_process_set_memory_limit'
		 */
		ini_set('memory_limit', apply_filters( 'contentsync_hub_process_set_memory_limit', '2048M' ));
		/**
		 * Adjust execution timeout (default: 3600)
		 * @filter 'contentsync_hub_process_set_timeout'
		 */
		$timeout = apply_filters( 'contentsync_hub_process_set_timeout', 3600 );
		set_time_limit($timeout);

		// try curl first;
		// @since global Hub
		if (function_exists("curl_init") && function_exists("curl_exec")) {
			// make url (for curl)
			$file_url = self::abspath_to_url( $file );
			if ( strpos($file_url, 'http') === 0) {
				$curl = curl_init();
				curl_setopt($curl, CURLOPT_AUTOREFERER, TRUE);
				curl_setopt($curl, CURLOPT_HEADER, 0);
				curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
				curl_setopt($curl, CURLOPT_URL, $file_url);
				curl_setopt($curl, CURLOPT_FOLLOWLOCATION, TRUE);
				curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
				curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
				curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 0);
				curl_setopt($curl, CURLOPT_TIMEOUT, $timeout); // timeout in seconds
				curl_setopt($curl, CURLOPT_COOKIE, 'wordpress_logged_in');
				
				if ( isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW']) ) {
					$auth = base64_encode( $_SERVER['PHP_AUTH_USER'].":".$_SERVER['PHP_AUTH_PW'] );
					curl_setopt($curl, CURLOPT_HTTPHEADER, array("Authorization: Basic ".$auth));  
				}

				$contents = curl_exec($curl);
				curl_close($curl);

				if ( $contents ) return $contents;
			}
		}

		// convert the url to an absolute path (for file_get_contents)
		$file = self::url_to_abspath( $file );

		// with the prefix '@' the function doesn't throw a warning, as error handling is done below
		$contents   = @file_get_contents($file);
		if ( $contents ) return $contents;

		/**
		 *  Check if HTTP Authentication is enabled and pass it as $context
		 *  otherwise files may not be found, due to the athentication not being passed
		 */
		if ( isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW']) ) {
			$auth = base64_encode( $_SERVER['PHP_AUTH_USER'].":".$_SERVER['PHP_AUTH_PW'] );
			$context = stream_context_create([
				"http" => [
					"header" => "Authorization: Basic $auth"
				]
			]);
			$contents = file_get_contents($file, false, $context);
			if ( $contents ) return $contents;
		}

		// request failed
		if ( defined('WP_DEBUG') && WP_DEBUG === true ) {
			echo "<pre>HTTP request failed. Error was: ".( function_exists('error_get_last') ? error_get_last()['message'] : "unidentified error" )."</pre>";
			// check 'allow_url_fopen' setting
			if ( ini_get('allow_url_fopen') !== true ) {
				echo "<pre>Your server's PHP settings are not compatible with the Greyd.Suite. The variable 'allow_url_fopen' is deactivated, which leads to the website being displayed incorrectly. Please contact your server administrator to resolve the problem.</pre>";
			}
		}
		return false;

	}

	/**
	 * Convert an URL into an absolute filepath
	 */
	public static function url_to_abspath( $path ) {
		// if this is a relative path, convert it to an absolute one
		if ( strpos($path, get_site_url()) === 0 ) {
			$path = ABSPATH.substr( explode( get_site_url(), $path )[1], 1 );
		}
		// remove url params
		if ( strpos( $path, "?" ) !== false ) {
			$path = explode( "?", $path )[0];
		}
		return wp_normalize_path($path);
	}

	/**
	 * Convert an absolute filepath into an URL
	 */
	public static function abspath_to_url( $url ) {
		// if this is an absolute path, convert it to a url
		if ( strpos($url, wp_normalize_path(ABSPATH)) === 0 ) {
			$url = str_replace(
				untrailingslashit( wp_normalize_path(ABSPATH) ),
				get_site_url(),
				$url
			);
		}
		return $url;
	}

	/*
	====================================================================================
		Basic Posttype and Post functions 
	====================================================================================
	*/

	/**
	 * Get all public Posttypes.
	 * 
	 * @return object[] $all_posttypes  Array of all public Posttypes.
	 *      @property string slug   Posttype slug.
	 *      @property string title  Posttype title/label.
	 *      @property int count     Number of Posts with this Posttype.
	 */
	public static function get_all_posttypes() {
		$posttypes = get_post_types( array(
			'publicly_queryable' => true,
			'public' => true
		), 'objects' );

		/**
		 * Post type 'page' wasn't included anymore - but it should be.
		 * @since 1.2.9 (contentsync_suite)
		 */
		if ( !isset($posttypes['page']) ) {
			$posttypes['page'] = get_post_type_object('page');
		}

		$all_posttypes = array();
		foreach($posttypes as $posttype) {
			$all_posttypes[] = (object)array(
				'slug' => $posttype->name,
				'title' => $posttype->label,
				'count' => wp_count_posts($posttype->name)->publish,
			);
		}
		usort($all_posttypes, function($a, $b) {return strcasecmp($a->title, $b->title);});
		return $all_posttypes;
	}

	/**
	 * Get all published Posts of a Posttype.
	 * 
	 * @param string $posttype          Posttype slug.
	 * @param bool $from_all_languages  Whether to include all languages, default: true.
	 * @return object[] $all_posts      Array of all Posts.
	 *      @property int id            Post ID.
	 *      @property string slug       Post slug/name.
	 *      @property string title      Post title.
	 *      @property object|string     lang Post language.
	 */
	public static function get_all_posts( $posttype, $from_all_languages = true ) {

		// get from cache
		$cache_key = 'contentsync_all_posts_'.$posttype.'_lang-'.( $from_all_languages ? 'all' : 'current' );
		$cache_val = wp_cache_get( $cache_key, 'greyd' );
		if ( $cache_val ) return $cache_val;

		$args = array(
			'posts_per_page'   => -1,
			'post_type'        => $posttype,
			'post_status'      => 'publish'
		);

		/**
		 * Get posts from all languages.
		 * Supports:
		 * - WPML
		 * - Polylang
		 */
		if ( $from_all_languages ) {
			$args['suppress_filters'] = true;
			$args['lang']             = '';
		} else {
			$args['suppress_filters'] = false;
		}

		$posts = get_posts( $args );

		$all_posts = array();
		foreach( $posts as $post ) {
			// $language_details = apply_filters('wpml_post_language_details', NULL, $post->ID);
			// if (!$language_details) $language_details = get_locale();
			$all_posts[] = (object)array(
				'id'    => $post->ID,
				'slug'  => $post->post_name,
				'title' => $post->post_title,
				'lang'  => self::get_post_language( $post ), // $language_details,
			);
		}
		usort($all_posts, function($a, $b) {return strcasecmp($a->title, $b->title);});

		// set cache
		wp_cache_set( $cache_key, $all_posts, 'greyd' );

		return $all_posts;
	}

	/**
	 * Get all Taxonomies of a Posttype.
	 * 
	 * @param string $posttype  Posttype slug.
	 * @return object[] $all_taxonomies  Array of all Taxonomies.
	 *      @property string slug   Taxonomy slug/name.
	 *      @property string title  Taxonomy title.
	 */
	public static function get_all_taxonomies( $posttype ) {
		$taxonomies = get_object_taxonomies($posttype, 'objects');
		$all_taxonomies = array();
		foreach($taxonomies as $taxonomy) {

			if (
				empty($taxonomy)
				|| ! is_object($taxonomy)
				|| $taxonomy->name === 'post_format'
			) continue;

			/**
			 * Exclude Polylang taxonomies.
			 */
			if ( function_exists( 'pll_count_posts' ) ) {
				if ( $taxonomy->name === 'language' ) continue;
				if ( $taxonomy->name === 'post_translations' ) continue;
			}

			$public = $taxonomy->publicly_queryable && $taxonomy->show_in_menu;
			$hierarchical = $taxonomy->hierarchical;

			$all_taxonomies[] = (object) array(
				'slug' => $taxonomy->name,
				'title' => $taxonomy->label,
				'public' => $public,
				'hierarchical' => $hierarchical
			);
		}
		usort($all_taxonomies, function($a, $b) {return strcasecmp($a->title, $b->title);});
		return $all_taxonomies;
	}

	/**
	 * Get all Terms of a Taxonomy.
	 * 
	 * @param string $posttype  Taxonomy slug.
	 * @return object[] $all_terms  Array of all Terms.
	 *      @property int id        Term ID.
	 *      @property string slug   Term slug/name.
	 *      @property string title  Term title.
	 *      @property int count     Number of Posts with this Term.
	 */
	public static function get_all_terms( $taxonomy, $args=array() ) {

		$args = wp_parse_args( $args, array(
			'hide_empty' => false,
			'suppress_filter' => false,
		) );
		$args['taxonomy'] = $taxonomy;

		$terms = get_terms( $args );
		
		$all_terms = array();
		foreach( (array) $terms as $term) {
			if ( !is_object($term) ) continue;
			$all_terms[] = (object) array(
				'id' => $term->term_id,
				'slug' => $term->slug,
				'title' => $term->name,
				'count' => $term->count,
			);
		}
		usort($all_terms, function($a, $b) {return strcasecmp($a->title, $b->title);});
		return $all_terms;
	}

	/**
	 * Get the primary Category slug of a Posttype.
	 * 
	 * @param string $posttype      Posttype slug.
	 * @return string|bool $slug    The Category slug or false.
	 */
	public static function get_category_slug( $posttype ) {
		$taxonomies = self::get_all_taxonomies($posttype);
		$slug = false;
		foreach($taxonomies as $taxonomy) {
			if (strpos($taxonomy->slug, 'category') > -1 ||
				strpos($taxonomy->slug, '_cat') > 0) {
				$slug = $taxonomy->slug;
				break;
			}
		}
		return $slug;
	}

	/**
	 * Get the primary Tag slug of a Posttype.
	 * 
	 * @param string $posttype      Posttype slug.
	 * @return string|bool $slug    The Tag slug or false.
	 */
	public static function get_tag_slug( $posttype ) {
		$taxonomies = self::get_all_taxonomies($posttype);
		$slug = false;
		foreach($taxonomies as $taxonomy) {
			if (strpos($taxonomy->slug, '_tag') > 0) {
				$slug = $taxonomy->slug;
				break;
			}
		}
		return $slug;
	}

	/**
	 * Get all Categories of a Post.
	 * 
	 * @param WP_Post $post     The Post.
	 * @return WP_Term[]|null   Array of WP_Term objects on success or null.
	 */
	public static function get_categories( $post ) {
		if ($post->post_type == 'post') $slug = 'category';
		else if ($post->post_type == 'product') $slug = 'product_cat';
		else $slug = $post->post_type.'_category';

		/** @since 1.3.0  (contentsync_suite) */
		$terms = self::get_post_taxonomy_terms($post->ID, $slug);

		if (is_array($terms)) $terms = array_unique($terms, SORT_REGULAR); 
		return $terms;
		// return wp_get_object_terms($post->ID, $slug);
	}

	/**
	 * Get all Tags of a Post.
	 * 
	 * @param WP_Post $post     The Post.
	 * @return WP_Term[]|null   Array of WP_Term objects on success or null.
	 */
	public static function get_tags( $post ) {
		$slug = $post->post_type.'_tag';

		/** @since 1.3.0 */
		$terms = self::get_post_taxonomy_terms($post->ID, $slug);

		if (is_array($terms)) $terms = array_unique($terms, SORT_REGULAR); 
		return $terms;
		// return wp_get_object_terms($post->ID, $slug);
	}

	/**
	 * Get image alt text with smart fallbacks.
	 * 
	 * @param int $attachment_id    Attachement post ID.
	 * @param string $type          'alt', 'title' or 'caption'
	 * 
	 * @return string
	 */
	public static function get_attachment_text( $attachment_id, $type='alt' ) {

		if ( empty($attachment_id) ) return '';

		// get ALT
		$alt_text = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( $type === 'alt' && !empty($alt_text) ) return self::clean_attachment_text($alt_text);

		// get the image post object
		$attachment = get_post( $attachment_id );
		if ( empty($attachment) ) return "";

		// get TITLE
		$title = $attachment->post_title;
		if ( $type === 'title' && !empty($title) ) return self::clean_attachment_text($title);

		// get CAPTION
		$caption = $attachment->post_excerpt;
		if ( $type === 'caption' && !empty($caption) ) return self::clean_attachment_text($caption);

		/**
		 * Filter whether alt texts should be autogenerated.
		 * @since 1.4.5
		 * 
		 * @param bool $enable          Whether autogeneration is enabled.
		 * @param int $attachment_id    Attachement post ID.
		 * @param string $type          'alt', 'title' or 'caption'
		 * 
		 * @return false
		 */
		$enable_alt_autogenerate = apply_filters( 'contentsync_autogenerate_attachment_image_alt', true, $attachment_id, $type );
		if ( !$enable_alt_autogenerate ) return "";

		// Fallbacks
		$fallback = '';
		if ( $type === 'alt' )
			$fallback = !empty( $caption ) ? $caption : $title;
		else if ( $type === 'title' )
			$fallback = !empty( $caption ) ? $caption : $alt_text;
		else if ( $type === 'caption' )
			$fallback = !empty( $title ) ? $title : $alt_text;

		return self::clean_attachment_text( $fallback );
	}
	public static function clean_attachment_text($text='') {
		return esc_attr( trim( strip_tags( $text ) ) );
	}

	/*
	====================================================================================
		String functions 
	====================================================================================
	*/

	/**
	 * Convert a string into a clean slug.
	 * Replaces all chars except for letters, numbers, dash and underscore.
	 * 
	 * @since 0.8.6 (contentsync_suite)
	 * 
	 * @param string $string    String to modify.
	 * @param bool $lowercase   Whether to convert the string to lowercase.
	 * @return string           Modified string.
	 */
	public static function get_clean_slug( $string, $lowercase = true ) {
		$return = preg_replace('/[^A-Za-z0-9_-]/', '', $string);
		if ($lowercase) $return = strtolower($return);
		return $return;
	}

	/**
	 * Improved version of rawurlencode.
	 * 
	 * @param mixed $value
	 * @return string
	 */
	public static function rawurlencode( $value ) {
		return str_replace(['%28', '%29'], ['(', ')'], rawurlencode( $value ));
	}

	/**
	 * Little helper function to get string between to substrings.
	 * 
	 * @see http://www.justin-cook.com/2006/03/31/php-parse-a-string-between-two-strings/
	 * 
	 * @param string $haystack  The full string to search.
	 * @param string $before    Substring before.
	 * @param string $after     Substring after.
	 * 
	 * @return string
	 */
	public static function get_string_between(string $haystack, string $before, string $after){
		$haystack = ' ' . $haystack;
		$ini = strpos($haystack, $before);
		if ($ini == 0) return '';
		$ini += strlen($before);
		$len = strpos($haystack, $after, $ini) - $ini;
		return substr($haystack, $ini, $len);
	}

	/**
	 * Whether an array is empty.
	 * This function checks if an array is empty, even if it contains empty sub-arrays.
	 * 
	 * @since 2.2.0
	 * 
	 * @param array $array
	 * 
	 * @return bool
	 */
	public static function is_array_empty($array) {

		// early return
		if ( empty($array) ) return true;

		// loop through array to check if all values are empty
		if ( is_array( $array ) ) {
			foreach ( $array as $key => $value ) {
				if ( is_array( $value ) && !self::is_array_empty( $value ) ) {
					return false;
				} else if ( !empty($value) ) {
					return false;
				}
			}
		}
		return true;
	}

	/*
	====================================================================================
		Enqueueing 
	====================================================================================
	*/

	/**
	 * Add css line to styles queue.
	 * @see Enqueue::add_custom_style()
	 */
	public static function add_custom_style( $css, $in_footer=true ) {
		return Enqueue::add_custom_style( $css, $in_footer );
	}

	/**
	 * Check if there are custom styles enqueued.
	 * @see Enqueue::has_custom_styles()
	 */
	public static function has_custom_styles() {
		return Enqueue::has_custom_styles();
	}

	/**
	 * Render the custom styles queue.
	 * @see Enqueue::render_custom_styles()
	 */
	public static function render_custom_styles() {
		Enqueue::render_custom_styles();
	}

	/*
	====================================================================================
		Language 
	====================================================================================
	*/

	/**
	 * Get the translation tool of this stage.
	 * @supports:
	 * - Polylang
	 * - WPML
	 */
	public static function get_translation_tool() {

		if ( function_exists('pll_current_language') ) {
			return 'polylang';
		}

		if ( defined('ICL_LANGUAGE_CODE') ) {
			return 'wpml';
		}

		return null;
	}

	/**
	 * Check if a translation tool is active.
	 * @supports:
	 * - Polylang
	 * - WPML
	 */
	public static function is_translation_tool_active() {
		return ! empty( self::get_translation_tool() );
	}

	/**
	 * Get current language.
	 * @supports:
	 * - Polylang
	 * - WPML
	 * - Default WP
	 */
	public static function get_language_code() {

		// polylang support
		if ( function_exists('pll_current_language') ) {
			return pll_current_language();
		}

		// wpml support
		if ( defined('ICL_LANGUAGE_CODE') ) {
			return ICL_LANGUAGE_CODE;
		}

		return explode('_', get_locale(), 2)[0];
	}

	/**
	 * Get the default language.
	 * @supports:
	 * - Polylang
	 * - WPML
	 */
	public static function get_default_language() {

		// polylang support
		if ( function_exists('pll_default_language') ) {
			return pll_default_language();
		}

		// wpml support
		$language = apply_filters( 'wpml_default_language', NULL );

		return $language ?? self::get_language_code();
	}

	/**
	 * Get the language object of a post
	 * 
	 * @param WP_Post|int $post         Current post object or post ID.
	 * @param string $language_default  Default language.
	 * 
	 * @return string   Post language slug when Polylang is active.
	 * @return array    Language details array when WPML is active.
	 * @return string   2-digit language slug when no multilanguage plugin is active.
	 */
	public static function get_post_language( $post, $language_default=null ) {

		// get default language
		if ( empty($language_default) ) {
			$language_default = self::is_translation_tool_active() ? self::get_default_language() : null;
		}
		
		// get post
		if ( is_int($post) ) {
			$post = get_post($post);
		}

		// no post, return default language
		if ( !$post || !is_object($post) ) {
			return $language_default;
		}

		// get language
		if ( function_exists('pll_get_post_language') ) {
			// polylang
			$language = pll_get_post_language( $post->ID );
		}
		else {
			// wpml
			$language = apply_filters( 'wpml_post_language_details', NULL, $post->ID );

			if ( is_array($language) && isset($language['language_code']) ) {
				$lang = $language['language_code'];
				
				if ( $lang !== $language_default ) {

					// get original id
					$id_original = apply_filters( 'wpml_object_id', $post->ID, $post->post_type, false, $language_default );
					if ($id_original && $id_original != $post->ID) {
						$language['original_id'] = $id_original;
					}
				}
			}
		}

		return $language ?? $language_default;
	}

	/**
	 * Retrieves the edit post link for post.
	 * 
	 * @see wp-includes/link-template.php
	 * 
	 * @param int|WP_Post $post Post ID or post object.
	 * 
	 * @return string The edit post link URL for the given post.
	 */
	public static function get_edit_post_link( $post ) {

		if ( !is_object($post) ) {
			$post = get_post($post);
		}

		if ( !$post || !is_object($post) || !isset($post->post_type) ) {
			return '';
		}

		switch ( $post->post_type ) {
			case 'wp_global_styles':

				// do not allow editing of global styles and font families from other themes
				if ( self::get_wp_template_theme( $post ) != get_option( 'stylesheet' ) ) {
					return null;
				}

				// wp-admin/site-editor.php?p=/styles
				return add_query_arg(
					array(
						// 'path'   => '/wp_global_styles', //get_edit_post_link
						// 'canvas' => 'edit',
						'p' => '/styles',
					),
					admin_url( 'site-editor.php' )
				);
				break;
			case 'wp_template':
				// wp-admin/site-editor.php?postType=wp_template&postId=greyd-theme//404&canvas=edit
				return add_query_arg(
					array(
						'postType' => $post->post_type,
						'postId'   => self::get_wp_template_theme( $post ) . '//' . $post->post_name,
						'canvas'   => 'edit',
					),
					admin_url( 'site-editor.php' )
				);
				break;
			case 'wp_template_part':
				// wp-admin/site-editor.php?postType=wp_template_part&postId=greyd-theme//footer&categoryId=footer&categoryType=wp_template_part&canvas=edit
				return add_query_arg(
					array(
						'postType'      => $post->post_type,
						'postId'        => self::get_wp_template_theme( $post ) . '//' . $post->post_name,
						'categoryId'    => $post->ID,
						'categoryType'  => $post->post_type,
						'canvas'        => 'edit',
					),
					admin_url( 'site-editor.php' )
				);
				break;
			case 'wp_navigation':
				// wp-admin/site-editor.php?postId=169&postType=wp_navigation&canvas=edit
				return add_query_arg(
					array(
						'postId'   => $post->ID,
						'postType' => $post->post_type,
						'canvas'   => 'edit',
					),
					admin_url( 'site-editor.php' )
				);
				break;
			case 'wp_block':
				// wp-admin/edit.php?post_type=wp_block
				return add_query_arg(
					array(
						'post'      => $post->ID,
						'action'    => 'edit',
					),
					admin_url( 'post.php' )
				);
				break;
			case 'wp_font_family':
				// wp-admin/site-editor.php?path=/wp_global_styles&canvas=edit
				return add_query_arg(
					array(
						// 'path'   => '/wp_global_styles',
						// 'canvas' => 'edit',
						'p' => '/styles',
					),
					admin_url( 'site-editor.php' )
				);
				break;
			default:
				$edit_post_link = get_edit_post_link( $post );
				if ( ! $edit_post_link ) {
					$edit_post_link = add_query_arg(
						array(
							'post'      => $post->ID,
							'action'    => 'edit',
						),
						admin_url( 'post.php' )
					);
				}
				return html_entity_decode( $edit_post_link );
				break;
		}
		return '';
	}

	/**
	 * Retrieves the theme slug for a wp_template post.
	 * 
	 * @param WP_Post $post Post object.
	 * 
	 * @return string The theme slug for the given template.
	 */
	public static function get_wp_template_theme( $post ) {
		$theme = wp_get_post_terms( $post->ID, 'wp_theme' );
		if ( $theme && is_array($theme) && isset($theme[0]) ) {
			return $theme[0]->name;
		}
		return '';
	}
}
