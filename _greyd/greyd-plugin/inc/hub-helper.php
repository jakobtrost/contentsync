<?php
/**
 * Helper functions for global connections.
 *
 * The Connections are build upon the WordPress application passwords to
 * ensure secure dommunication between the sites.
 *
 * @see https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/
 */
namespace Greyd\Hub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

new Hub_Helper();
class Hub_Helper {



	/**
	 * =================================================================
	 *                          Migrated
	 * =================================================================
	 */



	/**
	 * Blogs Cache.
	 *
	 * @var false|array
	 *      @property array local
	 *      @property array remote
	 */
	public static $_blogs_cache = false;

	/**
	 * Get basic information for all blogs.
	 * (only blogs > 0 and less attributes than get_all_blogs)
	 *
	 * @return array
	 *      @property int blog_id
	 *      @property string domain
	 *      @property string http
	 *      @property string name
	 *      @property string description
	 *      @property array attributes
	 *          @property string registered
	 *          @property string last_updated
	 *          @property bool public
	 *          @property bool archived (only multisite)
	 *          @property bool spam (only multisite)
	 *          @property bool mature (only multisite)
	 *          @property bool deleted (only multisite)
	 *          @property bool|string protected
	 *          @property bool|array staging
	 *      @property string theme_slug
	 *      @property array theme_mods
	 *      @property string upload_url
	 *      @property string network
	 */
	public static function get_basic_blogs() {

		// get all blogs and set cache
		if ( self::$_blogs_cache == false || ! isset( self::$_blogs_cache['local'] ) ) {
			self::get_the_blogs();
		}

		// use cache
		$all_blogs = array();
		foreach ( self::$_blogs_cache['local'] as $i => $blog ) {
			if ( $i == 'basic' || $i == 'unknown' ) {
				continue;
			}
			$all_blogs[] = array(
				'blog_id'     => $blog['blog_id'],
				'domain'      => $blog['domain'],
				'http'        => $blog['http'],
				'name'        => $blog['name'],
				'description' => $blog['description'],
				'attributes'  => $blog['attributes'],
				'theme_slug'  => $blog['theme_slug'],
				'theme_mods'  => $blog['theme_mods'],
				'upload_url'  => $blog['upload_url'],
				'network'     => $blog['network'],
			);
		}
		return $all_blogs;
	}

	/**
	 * Get all blogs with tables and infos via get_all_blogs() and sort them.
	 *
	 * @return array
	 */
	public static function get_the_blogs() {

		// check cache
		if ( self::$_blogs_cache !== false && isset( self::$_blogs_cache['local'] ) ) {
			// use cache
			return self::$_blogs_cache['local'];
		}

		$all_blogs = self::get_all_blogs();
		$blogs     = array_merge(
			$all_blogs['basic'],
			array_reverse( $all_blogs['blogs'] ),
			$all_blogs['unknown']
		);

		// set cache
		if ( self::$_blogs_cache == false ) {
			self::$_blogs_cache = array();
		}
		self::$_blogs_cache['local'] = $blogs;

		// debug($blogs);
		return $blogs;
	}

	/**
	 * Get all the blogs and their attributes
	 *
	 * @return array
	 *      @property array basic
	 *          @property array basic
	 *              @property int blog_id
	 *              @property string version
	 *              @property string language
	 *              @property string domain
	 *              @property string name
	 *              @property string description
	 *              @property string admin
	 *              @property string prefix
	 *              @property array tables Array of tablenames
	 *
	 *      @property array blogs Array of tables keyed by prefix
	 *          @property int blog_id
	 *          @property string name
	 *          @property string domain
	 *          @property string prefix
	 *          @property bool current Whether this is the current blog.
	 *          @property array tables Array of tablenames
	 *          @property array attributes
	 *              @property string registered
	 *              @property string last_updated
	 *              @property bool public
	 *              @property bool archived (only multisite)
	 *              @property bool spam (only multisite)
	 *              @property bool mature (only multisite)
	 *              @property bool deleted (only multisite)
	 *              @property bool|string protected
	 *              @property bool|array staging
	 *          @property string description
	 *          @property string admin
	 *          @property array plugins_list
	 *          @property array plugins
	 *          @property string theme
	 *          @property string theme_version
	 *          @property string theme_slug
	 *          @property string theme_main
	 *          @property string theme_main_version
	 *          @property string theme_main_slug
	 *          @property array theme_mods
	 *          @property string upload_url
	 *          @property string network
	 *
	 *      @property array unknown
	 *          @property array unknown
	 *              @property int blog_id
	 *              @property string domain
	 *              @property string name
	 *              @property string description
	 *              @property array tables Array of tablenames
	 */
	public static function get_all_blogs() {

		global $wpdb;
		// prepare arrays
		$tables_basic   = array(
			'basic' => array(
				'version'     => get_bloginfo( 'version' ),
				'language'    => get_bloginfo( 'language' ),
				'blog_id'     => 0,
				'domain'      => 'Basic WordPress',
				'name'        => '',
				'description' => '',
				'admin'       => '',
				'prefix'      => $wpdb->base_prefix,
				'tables'      => array(),
			),
		);
		$tables_blogs   = array();
		$tables_unknown = array(
			'unknown' => array(
				'blog_id'     => -1,
				'domain'      => __( 'Unknown tables', 'greyd_hub' ),
				'description' => __( 'Unknown tables of old websites or other installations', 'greyd_hub' ),
				'name'        => '',
				'admin'       => '',
				'tables'      => array(),
			),
		);

		if ( is_multisite() ) {

			// get all blogs
			$blogs = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->base_prefix . 'blogs order by blog_id desc' );

			foreach ( $blogs as $blog ) {
				// get blog info
				$blog_details = get_blog_details( $blog->blog_id );
				// debug($blog_details);
				// debug($blog_details->__get('protected'));
				$domainfull              = substr( $blog->domain . $blog->path, 0, -1 );
				$http                    = str_replace( '://' . $domainfull, '', $blog_details->home );
				$prefix                  = ( $blog->blog_id == 1 ) ? $wpdb->base_prefix : $wpdb->base_prefix . $blog->blog_id . '_';
				$current                 = ( $prefix == $wpdb->prefix ) ? true : false;
				$tables_blogs[ $prefix ] = array(
					'name'       => $blog_details->blogname,
					'blog_id'    => $blog->blog_id,
					'domain'     => $domainfull,
					'http'       => $http,
					'prefix'     => $prefix,
					'current'    => $current,
					'tables'     => array(),
					'attributes' => array(
						'registered'   => $blog_details->registered,
						'last_updated' => $blog_details->last_updated,
						'public'       => $blog_details->public,
						'archived'     => $blog_details->archived,
						'spam'         => $blog_details->spam,
						'mature'       => $blog_details->mature,
						'deleted'      => $blog_details->deleted,
						'protected'    => $blog_details->__get( 'protected' ),
						'staging'      => $blog_details->__get( 'staging' ),
					),
					'network'    => Main_Helper::get_nice_url( Admin::$urls->network_url ),
				);

				switch_to_blog( $blog->blog_id );
				// https://developer.wordpress.org/reference/functions/get_bloginfo/
				$theme                                    = ! empty( wp_get_theme()->parent() ) ? wp_get_theme()->parent() : wp_get_theme();
				$tables_blogs[ $prefix ]['description']   = get_bloginfo( 'description' );
				$tables_blogs[ $prefix ]['admin']         = get_bloginfo( 'admin_email' );
				$tables_blogs[ $prefix ]['plugins_list']  = Helper::active_plugins();
				$tables_blogs[ $prefix ]['plugins']       = array();
				$tables_blogs[ $prefix ]['theme']         = wp_get_theme()->get( 'Name' );
				$tables_blogs[ $prefix ]['theme_version'] = wp_get_theme()->get( 'Version' );
				$tables_blogs[ $prefix ]['theme_slug']    = wp_get_theme()->stylesheet;
				$tables_blogs[ $prefix ]['theme_main']    = $theme->get( 'Name' );
				$tables_blogs[ $prefix ]['theme_main_version'] = $theme->get( 'Version' );
				$tables_blogs[ $prefix ]['theme_main_slug']    = $theme->stylesheet;
				$tables_blogs[ $prefix ]['theme_mods']         = get_theme_mods();
				$tables_blogs[ $prefix ]['upload_url']         = wp_upload_dir()['baseurl'];
				restore_current_blog();
				// debug($tables_blogs[$prefix]);
			}
		} else {
			// get this blog
			$theme = ! empty( wp_get_theme()->parent() ) ? wp_get_theme()->parent() : wp_get_theme();
			// debug($wpdb);
			$domain = str_replace( array( 'https://', 'http://' ), '', get_bloginfo( 'url' ) );
			$http   = str_replace( '://' . $domain, '', get_bloginfo( 'url' ) );
			// $prefix = $wpdb->base_prefix;
			$prefix                  = $wpdb->prefix;
			$tables_blogs[ $prefix ] = array(
				'name'               => get_bloginfo( 'name' ),
				'description'        => get_bloginfo( 'description' ),
				'blog_id'            => 1,
				'domain'             => $domain,
				'http'               => $http,
				'admin'              => get_bloginfo( 'admin_email' ),
				'prefix'             => $prefix,
				'current'            => true,
				'tables'             => array(),
				'attributes'         => array(
					'registered'   => get_user_option( 'user_registered', 1 ),
					'last_updated' => get_lastpostdate(),
					'public'       => get_option( 'blog_public', '0' ),
					// not available on singlesite
					// 'archived' => '0',
					// 'spam' => '0',
					// 'mature' => '0',
					// 'deleted' => '0',
					'protected'    => get_option( 'protected', '0' ),
					'staging'      => get_option( 'greyd_staging', '0' ),
				),
				'plugins_list'       => Helper::active_plugins(),
				'plugins'            => array(),
				'theme'              => wp_get_theme()->get( 'Name' ),
				'theme_version'      => wp_get_theme()->get( 'Version' ),
				'theme_slug'         => wp_get_theme()->stylesheet,
				'theme_main'         => $theme->get( 'Name' ),
				'theme_main_version' => $theme->get( 'Version' ),
				'theme_main_slug'    => $theme->stylesheet,
				'theme_mods'         => get_theme_mods(),
				'upload_url'         => wp_upload_dir()['baseurl'],
				'network'            => Main_Helper::get_nice_url( Admin::$urls->network_url ),
			);
		}

		// plugins
		foreach ( $tables_blogs as $blogkey => $blog ) {
			foreach ( $tables_blogs[ $blogkey ]['plugins_list'] as $key => $plugin ) {
				$plugin_path = Admin::$urls->plugins_path . $plugin;
				if ( is_dir( $plugin_path ) || file_exists( $plugin_path ) ) {
					$plugin_data                                 = get_plugin_data( $plugin_path, false, false );
					$tables_blogs[ $blogkey ]['plugins'][ $key ] = array(
						'name'      => $plugin_data['Name'] . ' (' . $plugin_data['Version'] . ')',
						'installed' => true,
					);
				} else {
					$plugin_name                                 = $tables_blogs[ $blogkey ]['plugins_list'][ $key ];
					$plugin_name                                 = strpos( $plugin_name, '/' ) !== false ? explode( '/', $plugin_name, 2 )[0] : $plugin_name;
					$tables_blogs[ $blogkey ]['plugins'][ $key ] = array(
						'name'      => $plugin_name,
						'installed' => false,
					);
				}
			}
		}

		// get tables
		$tables     = $wpdb->get_results( 'SHOW TABLES FROM `' . $wpdb->dbname . '`' );
		$tables_all = array();
		foreach ( $tables as $table ) {
			$tablename = $table->{'Tables_in_' . $wpdb->dbname};
			// https://codex.wordpress.org/Database_Description#Multisite_Table_Overview
			if ( $tablename == $wpdb->base_prefix . 'blogs' ||
				$tablename == $wpdb->base_prefix . 'blog_versions' ||
				$tablename == $wpdb->base_prefix . 'blogmeta' ||
				$tablename == $wpdb->base_prefix . 'registration_log' ||
				$tablename == $wpdb->base_prefix . 'signups' ||
				$tablename == $wpdb->base_prefix . 'site' ||
				$tablename == $wpdb->base_prefix . 'sitemeta' ||
				$tablename == $wpdb->base_prefix . 'sitecategories' ||
				$tablename == $wpdb->base_prefix . 'users' ||
				$tablename == $wpdb->base_prefix . 'usermeta' ) {
				$tables_basic['basic']['tables'][] = $tablename;
				continue;
			}
			$tables_all[] = $tablename;
		}
		foreach ( $tables_blogs as $table_prefix => $table_value ) {
			for ( $i = 0; $i < count( $tables_all ); $i++ ) {
				if ( preg_match( '/^' . $table_prefix . '\D/', $tables_all[ $i ] ) === 1 ) {
					$tables_blogs[ $table_prefix ]['tables'][] = $tables_all[ $i ];
					$tables_all[ $i ]                          = '';
				}
			}
		}
		$tables_unknown['unknown']['tables'] = array_filter( $tables_all );

		return array(
			'basic'   => $tables_basic,
			'blogs'   => $tables_blogs,
			'unknown' => $tables_unknown,
		);
	}

	/**
	 * Get basic blogs info from remote connection.
	 *
	 * @param array $connection
	 * @return array|WP_Error   @see get_basic_blogs() or error.
	 */
	public static function get_basic_blogs_remote( $network_url, $connection ) {

		// get all remote blogs and set cache
		if ( self::$_blogs_cache == false || ! isset( self::$_blogs_cache['remote'][ $network_url ] ) ) {
			$response = self::get_all_blogs_remote( $network_url, $connection );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		// use cache
		$all_blogs = array();
		foreach ( self::$_blogs_cache['remote'][ $network_url ] as $i => $blog ) {
			if ( $i == 'basic' || $i == 'unknown' ) {
				continue;
			}
			$all_blogs[] = array(
				'blog_id'     => $blog['blog_id'],
				'domain'      => $blog['domain'],
				'http'        => $blog['http'],
				'name'        => $blog['name'],
				'description' => $blog['description'],
				'attributes'  => isset( $blog['attributes'] ) ? $blog['attributes'] : array(),
				'theme_slug'  => $blog['theme_slug'],
				'theme_mods'  => $blog['theme_mods'],
				'upload_url'  => isset( $blog['upload_url'] ) ? $blog['upload_url'] : '',
				'network'     => $blog['network'],
				'is_remote'   => true,
			);
		}
		return $all_blogs;
	}

	/**
	 * Get all blogs info from remote connection.
	 *
	 * @param array $connection
	 *
	 * @return WP_Error On REST Error.
	 * @return array|WP_Error   @see get_all_blogs() or error.
	 */
	public static function get_all_blogs_remote( $network_url, $connection ) {

		// check cache
		if ( self::$_blogs_cache !== false && isset( self::$_blogs_cache['remote'][ $network_url ] ) ) {
			// use cache
			return self::$_blogs_cache['remote'][ $network_url ];
		}

		ob_start();
		if ( method_exists( '\Greyd\Connections\Connections_Helper', 'send_request' ) ) {
			$response = \Greyd\Connections\Connections_Helper::send_request( $connection, 'remote_blogs' );
		}
		ob_end_clean();
		// debug($response);

		if ( ! $response ) {
			// false means that restroute "remote_blogs" responded an error or is not there
			// maybe old hub version without endpoint
			// @see global content "REST API Error" for details
			return new \WP_Error( 'no_rest_route', __( 'An error has occurred. Please check the connections on both sides and make sure that all plugins are up to date.', 'greyd_hub' ) );
		}
		if ( ! is_object( $response ) || empty( $response ) ) {
			// string 'empty' or empty array means that restroute is ok, but actually nothing was found there
			return new \WP_Error( 'empty', __( 'No content was found on the connected installation.', 'greyd_hub' ) );
		}

		$blogs = json_decode( json_encode( $response ), true );

		// compatibility with old endpoint
		$blog_table_name = $blogs['basic']['basic']['prefix'] . 'blogs';
		$is_multisite    = isset( $blogs['basic']['basic']['tables'] ) && array_search( $blog_table_name, $blogs['basic']['basic']['tables'] );
		$action          = $is_multisite ? "https://{$network_url}/wp-admin/network/admin.php?page=greyd_hub" : "https://{$network_url}/wp-admin/admin.php?page=greyd_hub";

		$blogs = array_merge(
			$blogs['basic'],
			array_reverse( $blogs['blogs'] )
		);

		// set cache
		if ( self::$_blogs_cache == false ) {
			self::$_blogs_cache = array();
		}
		if ( ! isset( self::$_blogs_cache['remote'] ) ) {
			self::$_blogs_cache['remote'] = array();
		}
		self::$_blogs_cache['remote'][ $network_url ] = $blogs;

		return $blogs;
	}

	/**
	 * Get basic blogs from all networks.
	 *
	 * @see get_basic_blogs()
	 * @see get_basic_blogs_remote()
	 *
	 * @return array $networks  All basic Blogs by network.
	 */
	public static function get_basic_networks() {

		// get all blogs
		$all_blogs = self::get_basic_blogs();
		$networks  = array( Main_Helper::get_nice_url( Admin::$urls->network_url ) => $all_blogs );

		if ( Admin::$connections ) {
			// get global blogs
			foreach ( Admin::$connections as $network_url => $connection ) {
				$remote_blogs = self::get_basic_blogs_remote( $network_url, $connection );
				// debug( $remote_blogs, true );
				if ( is_wp_error( $remote_blogs ) ) {
					continue;
				}

				foreach ( $remote_blogs as $i => $blog ) {
					if ( ! isset( $networks[ $network_url ] ) ) {
						$networks[ $network_url ] = array();
					}
					$networks[ $network_url ][] = $blog;
				}
			}
			// debug($network_url);
		}
		// debug($networks);

		return $networks;
	}

	/**
	 * Get all blogs from all networks.
	 *
	 * @see get_all_blogs()
	 * @see get_all_blogs_remote()
	 *
	 * @return array  $blogs  All Blogs.
	 *      @property object local      All local Blogs.
	 *      @property object remote     All remote Blogs by network. (optional)
	 */
	public static function get_all_networks() {

		// get data for all blogs
		$blogs = array(
			'local' => self::get_the_blogs(),
		);
		if ( Admin::$connections ) {
			$blogs['remote'] = array();
			foreach ( Admin::$connections as $network_url => $connection ) {
				$blogs['remote'][ $network_url ] = self::get_all_blogs_remote( $network_url, $connection );
			}
		}

		return $blogs;
	}
}
