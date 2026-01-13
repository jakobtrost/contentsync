<?php
/**
 * Includes an admin page to  overview & add global connections.
 */
namespace Contentsync\Connections;

use Contentsync\Main_Helper as Main_Helper;

if( ! defined( 'ABSPATH' ) ) exit;

new Connections_Page;
class Connections_Page {

	/**
	 * Holds errors
	 */
	public static $errors = array();
	
	/**
	 * Init class
	 */
	public function __construct() {

		// add the menu items & pages
		add_filter( "contentsync_dashboard_active_panels", array($this, "add_contentsync_dashboard_panel"), 10, 2 );
		add_action( (is_multisite() ? 'network_admin_menu' : 'admin_menu'), array($this, 'add_submenu'), 99 );
		add_filter( "contentsync_hub_pages", array($this, 'add_hub_page') );

		// add the redirect actions
		add_action( 'admin_init', array($this, 'maybe_redirect_to_auth_page'), 99 );
		add_action( 'admin_init', array($this, 'maybe_add_connection'), 99 );

		// check all connections on pageload
		add_action( 'current_screen', array($this, 'check_connections') );

		// display errors
		add_action( 'admin_notices', array($this, 'display_errors'), 99 );
		add_action( 'network_admin_notices', array($this, 'display_errors') );
	}

	public function remove_contentsync_feature($files) {
		// debug($files);
		foreach ( $files as $i => $file ) {
			if ( $file['slug'] == 'connections' ) {
				$files[$i]['Hidden'] = true;
				break;
			}
		}
		return $files;
	}
	public function remove_contentsync_dashboard_panel($panels) {
		unset( $panels[ 'site-connector' ] );
		return $panels;
	}
	public function add_contentsync_dashboard_panel($panels) {
		$panels[ 'site-connector' ] = true;
		return $panels;
	}

	/**
	 * Add the menu
	 */
	public function add_submenu() {

		/**
		 * If an old version of the 'Content Sync' plugin is active, this submenu
		 * is already added - so we don't add it twice.
		 */
		if ( class_exists('Content_Sync\Connections\Connections_Page') ) return;

		/**
		 * If the 'Content Sync' plugin is active, we add a submenu
		 */
		if ( class_exists('\Synced_Post') ) {
			$page_slug = add_submenu_page(
				'global_contents', //Admin::$args['slug'], // parent slug
				__( "Connections", 'contentsync_hub' ),  // page title
				__( "Connections", 'contentsync_hub' ), // menu title
				'manage_options', // capability
				'gc_connections', // slug
				array( $this, 'render_admin_page' ), // function
				60 // position
			);
		}
	}

	public function add_hub_page( $hub_tabs ) {
		$hub_tabs[] = array(
			"slug"      => "connections",
			"icon"      => "admin-site-alt",
			"class"     => "purple",
			"title"     => __("Connections", 'contentsync_hub'),
			"callback"  => array($this, "render_admin_page")
		);
		return $hub_tabs;
	}

	/**
	 * Render the admin page
	 */
	public function render_admin_page() {

		// start of wrapper
		echo "<div class='wrap'><h1>".__( "Connections to other WordPress websites", 'contentsync_hub' )."</h1>";

		// connection declined -> add error
		if ( isset($_GET["success"]) && $_GET["success"] === "false" ) {
			Main_Helper::show_message( __("The connection was not approved.", 'contentsync_hub'), "error" );
		}
		
		// display the table
		$Connections_List_Table = new Connections_List_Table();
		$Connections_List_Table->render_table();

		// add connection form
		echo "<h1 style='margin-top:2em;'>".__( "Add connection", 'contentsync_hub' )."</h1>
		<form method='post' class='add_connection'>
			<input type='hidden' name='_nonce' value='".wp_create_nonce(Connections_Helper::OPTION_NAME)."' />

			".( is_ssl() ? "" : Main_Helper::render_info_box([
					"style" => "warning",
					"above" =>__("Missing SSL certificate",'contentsync_hub'),
					"text"  => __("We noticed that this site does not have a valid SSL certificate. This can lead to problems when setting up a link, as this requires a secure and encrypted connection.", 'contentsync_hub')
			]) )."
			
			<label for='page_url'>".__( "URL of the website or main page", 'contentsync_hub' )."</label>
			<div class='flex'>
				<input class='large' type='text' name='page_url' id='page_url' value='' placeholder='".__( "https://www.multsite-parent.com", 'contentsync_hub' )."' />
				<button type='submit' name='submit' class='button button-primary large'>".__( "Request connection", 'contentsync_hub' )."</button>
			</div>
			<p>".__( "In order to connect to another site, you must have valid admin access to that site. For multisites you need super admin access.", 'contentsync_hub' )."</p>
			<p>".__( "In addition, a valid SSL certificate should be available on both sides and the Content Sync Plugin should be active and up-to-date.", 'contentsync_hub' )."</p>
			<p>".sprintf(
				__( "To add a connection to this page on another page, enter the URL %s.", 'contentsync_hub' ),
				"<code>".Connections_Helper::get_network_url()."</code>"
			)."</p>
		</form>";

		// end of wrapper
		echo "</div>";
	}

	/**
	 * Redirect to the authorization page on the 'to be added' site
	 */
	public function maybe_redirect_to_auth_page() {

		if (
			empty($_POST) || 
			empty($_GET) || 
			!isset($_GET['page'])
		) {
			return;
		}

		// only on this page
		if ( $_GET['page'] === "gc_connections" || ($_GET['page'] === "contentsync_hub" && isset($_GET['tab']) && $_GET['tab'] === "connections") ) {
			// I think it's better to read this way...
		} else {
			return;
		}

		// verify the nonce
		$nonce = isset( $_POST['_nonce'] ) ? $_POST['_nonce'] : null;
		if ( !$nonce || wp_verify_nonce( $nonce, Connections_Helper::OPTION_NAME ) !== 1 ) {
			self::add_error( __("The request could not be verified.", 'contentsync_hub') );
		}

		// get the input url
		$input_url = isset( $_POST['page_url'] ) ? esc_attr($_POST['page_url']) : "";

		// check for errors
		if ( empty($input_url) ) {
			self::add_error( __("You didn't enter a URL.", 'contentsync_hub') );
		}
		else if ( !preg_match('/((http|https)\:\/\/)?[a-zA-Z0-9\.\/\?\:@\-_=#]+\.([a-zA-Z0-9\&\.\/\?\:@\-_=#])*/', $input_url) ) {
			self::add_error( sprintf(__("%s is not a valid URL.", 'contentsync_hub'), "<strong>".$input_url."</strong>") );
		}
		if ( count( self::get_errors() ) ) return;
		
		// generate the final request url
		$input_auth_url = preg_replace("/\/$/", "", $input_url)."/wp-admin/authorize-application.php";
		$success_path   = $_GET['page'] === "gc_connections" ? "admin.php?page=gc_connections" : "admin.php?page=contentsync_hub&tab=connections";
		$success_url    = is_network_admin() ? network_admin_url( $success_path ) : admin_url( $success_path );

		$app_name       = urlencode( sprintf( __("Connection for %s", 'contentsync_hub'), Connections_Helper::get_network_url() ) );
		$query_args     = array(
			"app_name"      => $app_name,
			"app_id"        => urlencode( wp_generate_uuid4() ),
			"success_url"   => urlencode( $success_url )
		);
		$final_url      = add_query_arg( $query_args, esc_url($input_auth_url) );

		wp_redirect($final_url);
		exit;
	}

	/**
	 * Add a connection to the database.
	 * Usually we've just been redirected from the authorization page.
	 */
	public function maybe_add_connection() {

		if ( !isset($_GET["user_login"]) || !isset($_GET["password"]) || !isset($_GET["site_url"]) ) return;

		$site_url = esc_attr($_GET["site_url"]);
		$nice_url = Connections_Helper::get_nice_url( $site_url );
		$connection = array(
			"site_name"     => Connections_Helper::get_site_name($site_url),
			"user_login"    => esc_attr($_GET["user_login"]),
			"password"      => str_rot13(esc_attr($_GET["password"])),
			"site_url"      => $site_url,
			"active"        => true,
			"contents"      => true,
			"search"        => true,
		);
		$update = Connections_Helper::add_connection($connection);

		// update successfull
		if ($update) {
			set_transient(
				'contentsync_transient_notice',
				'success::'.sprintf(
					__("The connection to the %s page has been saved. Be sure to add the connection in the other direction as well.", 'contentsync_hub'),
					"<strong>$nice_url</strong>"
				)."<br><br>".
				sprintf(
					__("To do this, go to the admin or network admin area of the page %s and enter the URL %s at \"Add Connection\".", 'contentsync_hub'),
					"<a href='".esc_url($site_url)."' target='_blank'>$nice_url</a>",
					"<strong>".Connections_Helper::get_network_url()."</strong>"
				)
			);
		}
		// update failed
		else if ($update === false) {
			set_transient(
				'contentsync_transient_notice',
				'error::'.sprintf(
					__("The connection to the %s page could not be saved. Please try again.", 'contentsync_hub'),
					"<strong>".$nice_url."</strong>"
				)
			);
		}

		$url = remove_query_arg( array(
			"user_login", "password", "site_url", "success"
		) );
		wp_redirect( $url );
		exit;
	}

	/**
	 * Check all saved connections, if any are inactive
	 */
	public function check_connections( $current_screen ) {

		// only execute on connections admin sub page
		if (strpos($current_screen->base, "gc_connections") === false &&
			strpos($current_screen->base, "global-content") !== 0 &&
			strpos($current_screen->base, "global_contents") === false &&
			strpos($current_screen->base, "contentsync_hub") === false
		) return;

		// get connections
		$connections = Connections_Helper::get_connections();
		if ( !is_array($connections) || empty($connections) ) return;

		// check this site for HTTP Authorization
		if ( isset($_SERVER['PHP_AUTH_USER']) ) {
			self::add_error(
				"<strong>".sprintf(
					__("This page uses server-side authentication, such as HTTP password protection.", 'contentsync_hub'),
					""
				).
				"</strong><br><br>".
				sprintf(
					__("However, the link already sends the WordPress application password as \"Basic Authorization\" to the other server. Multiple authorizations are not allowed according to %s. We recommend that you disable HTTP authentication or whitelist the IP addresses of all other connections. It is best to contact your hoster or administrator for this.", 'contentsync_hub'),
					"<a href='https://www.rfc-editor.org/rfc/rfc7230#section-3.2.2' target='_blank'>RFC7230</a>"
				)
			);
		}

		// check connections
		$changed = false;
		foreach ( $connections as $k => $connection ) {

			// we've just deleted this connection
			if ( isset($_GET['delete']) && urldecode($_GET['delete']) == $k ) {
				continue;
			}

			// we don't use this connection
			if ( isset($connection['contents']) && $connection['contents'] === false && isset($connection['search']) && $connection['search'] === false ) {
				continue;
			}

			// get current state
			// Connections_Helper::enable_logs();
			$response = Connections_Helper::check_auth($connection);

			// display an error if necessary
			if ( $response !== true ) {

				$response = empty($response) ? "incorrect_password" : $response;

				switch ( $response ) {
					case "incorrect_password":
					case "rest_not_authorized":
						$response_help_text = sprintf(
							__("The status %s indicates that the app permission on the target page has been revoked or is not working correctly.", 'contentsync_hub'),
							"<code>$response</code>"
						);
						break;
					case "rest_not_connected":
						$response_help_text = sprintf(
							__("The status %s usually means that no connection to this page has yet been stored on the target page.", 'contentsync_hub'),
							"<code>$response</code>"
						);
						break;
					case "401":
						$response_help_text = sprintf(
							__("The status %s usually means that additional authentication is required on the target page, e.g. an HTTP password. However, the link already sends the WordPress application password to the server as \"Basic Authorization\". Multiple authorizations are not allowed according to %s. We recommend you to disable HTTP authentication or whitelist the IP address of this server (%s). It is best to contact your hoster or administrator for this.", 'contentsync_hub'),
							"<code>$response</code>",
							"<a href='https://www.rfc-editor.org/rfc/rfc7230#section-3.2.2' target='_blank'>RFC7230</a>",
							"<code>{$_SERVER['SERVER_ADDR']}</code>"
						);
						break;
					default:
						$response_help_text = "";
				}

				self::add_error(
					"<strong>".
						sprintf(
						__("The connection to the %s page is inactive (status: %s).", 'contentsync_hub'),
						"<a href='{$connection["site_url"]}'>{$connection["site_url"]}</a>",
						"<code>{$response}</code>"
						).
					"</strong><br><br><strong>".
						sprintf(
							__("Please make sure that the connection is created from both sides. This means that you must also request and confirm the connection on the site %s.", 'contentsync_hub'),
							"<a href='{$connection["site_url"]}'>{$connection["site_url"]}</a>"
						).
					"</strong><br><br>".
					__("Please also check whether the app authorization is created on this and the target page for the respective stored user. In case of doubt, delete the links and the respective permissions and create them again.", 'contentsync_hub').
					"<br><br>{$response_help_text}<br><br>".
					__("The stored app permissions can be found in the respective user profile via \"Application Passwords\".", 'contentsync_hub')
				);
			}

			// convert to truthy value
			$active = $response === true;

			// update connection array if changed
			if ( !isset($connection['active']) || $active !== $connection['active'] ) {
				$connection['active'] = $active;
				$connections[$k] = $connection;
				$changed = true;
			}
		}

		// update connections
		if ( $changed ) {
			Connections_Helper::update_connections($connections);
		}
	}

	/**
	 * Add an error.
	 */
	public static function add_error( $error ) {
		self::$errors[] = $error;
	}

	/**
	 * Get all errors.
	 */
	public static function get_errors() {
		return self::$errors;
	}

	/**
	 * Display all errors from this class.
	 */
	public function display_errors() {
		if ( count( self::get_errors() ) ) {
			foreach ( self::get_errors() as $error ) {
				Main_Helper::show_message( $error, "error" );
			}
		}
	}

	/**
	 * Check both functions used to retrieve network posts for differences.
	 * This function is used to optimize the remote function and to debug errors between the two.
	 * 
	 * This is a debug only function!
	 * 
	 * @see \Content_Sync\Connections\Remote_Operations::get_global_posts_for_endpoint();
	 * @see \Content_Sync\Main_Helper::get_all_network_posts();
	 */
	public function debug_network_posts_functions( $query=null ) {

		// get posts of both functions
		// schema: gid => post_title
		$all_remote_posts = array();
		$all_network_posts = array();
		$all_posts = array();

		if ( method_exists("Content_Sync\Main_Helper", "get_global_posts_for_endpoint") ) {
			$all_posts = \Content_Sync\Main_Helper::get_global_posts_for_endpoint( $query );
			foreach ( $all_posts as $post ) {
				$gid = $post->gc_post_id;
				$all_remote_posts[$gid] = $post->post_title;
			}
		}

		if ( method_exists("Content_Sync\Connections\Remote_Operations", "get_global_posts_for_endpoint") ) {
			$all_posts = \Content_Sync\Connections\Remote_Operations::get_global_posts_for_endpoint( $query );
			foreach ( $all_posts as $post ) {
				$gid = $post->gc_post_id;
				$all_remote_posts[$gid] = $post->post_title;
			}
		}

		if (class_exists("Content_Sync\Main_Helper")) {
			$all_posts = \Content_Sync\Main_Helper::get_all_network_posts( $query );
			foreach ( $all_posts as $post ) {
				$gid = $post->meta['gc_post_id'];
				$all_network_posts[$gid] = $post->post_title;
			}
		}

		$diff1 = array_diff($all_remote_posts, $all_remote_posts);
		$diff2 = array_diff($all_network_posts, $all_remote_posts);
		$diff = array_merge( $diff1, $diff2 );

		if ( !empty($diff) ) {
			debug($diff);
			debug($diff1);
			debug($diff2);
		}
	}
}