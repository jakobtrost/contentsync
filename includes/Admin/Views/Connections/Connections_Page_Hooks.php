<?php
/**
 * Hook provider for the connections admin page.
 */
namespace Contentsync\Admin\Views\Connections;

use Contentsync\Admin\Utils\Admin_Render;
use Contentsync\Api\Site_Connection;
use Contentsync\Utils\Hooks_Base;
use Contentsync\Utils\Urls;
use Contentsync\Api\Remote_Request;

defined( 'ABSPATH' ) || exit;

class Connections_Page_Hooks extends Hooks_Base {

	const CONNECTIONS_PAGE_POSITION = 70;

	/**
	 * Holds errors
	 */
	public static $errors = array();

	/**
	 * Register admin-only hooks.
	 */
	public function register_admin() {
		// add the menu items & pages
		add_action( 'admin_menu', array( $this, 'add_submenu_item' ), self::CONNECTIONS_PAGE_POSITION );
		add_action( 'network_admin_menu', array( $this, 'add_submenu_item' ), self::CONNECTIONS_PAGE_POSITION );

		// add the redirect actions
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_auth_page' ), 99 );
		add_action( 'admin_init', array( $this, 'maybe_add_site_connection' ), 99 );

		// check all connections on pageload
		add_action( 'current_screen', array( $this, 'check_connections' ) );

		// display errors
		add_action( 'admin_notices', array( $this, 'display_errors' ), 99 );
		add_action( 'network_admin_notices', array( $this, 'display_errors' ) );
	}

	/**
	 * Add the menu
	 */
	public function add_submenu_item() {

		if ( is_multisite() && ! is_super_admin() ) {
			return;
		}

		$page_slug = add_submenu_page(
			'contentsync', // parent slug
			__( 'Connections', 'contentsync_hub' ),  // page title
			( is_network_admin() ? '' : '→ ' ) . __( 'Connections', 'contentsync_hub' ), // menu title
			'manage_options', // capability
			is_network_admin() ? 'site_connections' : network_admin_url( 'admin.php?page=site_connections' ), // slug
			is_network_admin() ? array( $this, 'render_admin_page' ) : '', // function
			self::CONNECTIONS_PAGE_POSITION // position
		);
	}

	/**
	 * Render the admin page
	 */
	public function render_admin_page() {

		// start of wrapper
		echo '<div class="wrap"><h1>' . __( 'Connections to other WordPress websites', 'contentsync_hub' ) . '</h1>';

		// connection declined -> add error
		if ( isset( $_GET['success'] ) && $_GET['success'] === 'false' ) {
			Admin_Render::render_admin_notice( __( 'The connection was not approved.', 'contentsync_hub' ), 'error' );
		}

		// display the table
		$Connections_List_Table = new Connections_List_Table();
		$Connections_List_Table->render_table();

		// add connection form
		echo '<h1 style="margin-top:2em;">' . __( 'Add connection', 'contentsync_hub' ) . '</h1>' .
		'<form method="post" class="add_site_connection">' .
			'<input type="hidden" name="_nonce" value="' . wp_create_nonce( Site_Connection::get_option_name() ) . '" />' .
			( is_ssl() ? '' : Admin_Render::make_admin_info_box(
				array(
					'style' => 'warning',
					'above' => __( 'Missing SSL certificate', 'contentsync_hub' ),
					'text'  => __( 'We noticed that this site does not have a valid SSL certificate. This can lead to problems when setting up a link, as this requires a secure and encrypted connection.', 'contentsync_hub' ),
				)
			) ) .
			'<p>' . __( 'In order to connect to another site, you must have valid admin access to that site. For multisites you need super admin access.', 'contentsync_hub' ) . '</p>' .
			'<p>' . __( 'In addition, a valid SSL certificate should be available on both sides and the Content Sync Plugin should be active and up-to-date.', 'contentsync_hub' ) . '</p>' .
			'<p>' . sprintf(
				__( 'To add a connection to this page on another page, enter the URL %s.', 'contentsync_hub' ),
				'<code>' . Urls::get_network_url() . '</code>'
			) . '</p>' .
			'<!-- <label for="page_url">' . __( 'URL of the website or main page', 'contentsync_hub' ) . '</label> -->' .
			'<div class="flex">' .
				'<input class="regular-text" type="text" name="page_url" id="page_url" value="" placeholder="' . __( 'https://www.multsite-parent.com', 'contentsync_hub' ) . '" />' .
				'<button type="submit" name="submit" class="button button-primary large">' . __( 'Request connection', 'contentsync_hub' ) . '</button>' .
			'</div>' .
		'</form>';

		// end of wrapper
		echo '</div>';
	}

	/**
	 * Redirect to the authorization page on the 'to be added' site
	 */
	public function maybe_redirect_to_auth_page() {

		if (
			empty( $_POST ) ||
			empty( $_GET ) ||
			! isset( $_GET['page'] )
		) {
			return;
		}

		// only on this page
		if ( $_GET['page'] === 'site_connections' || ( $_GET['page'] === 'contentsync_hub' && isset( $_GET['tab'] ) && $_GET['tab'] === 'connections' ) ) {
			// I think it's better to read this way...
		} else {
			return;
		}

		// verify the nonce
		$nonce = isset( $_POST['_nonce'] ) ? $_POST['_nonce'] : null;
		if ( ! $nonce || wp_verify_nonce( $nonce, Site_Connection::get_option_name() ) !== 1 ) {
			self::add_error( __( 'The request could not be verified.', 'contentsync_hub' ) );
		}

		// get the input url
		$input_url = isset( $_POST['page_url'] ) ? esc_attr( $_POST['page_url'] ) : '';

		// check for errors
		if ( empty( $input_url ) ) {
			self::add_error( __( "You didn't enter a URL.", 'contentsync_hub' ) );
		} elseif ( ! preg_match( '/((http|https)\:\/\/)?[a-zA-Z0-9\.\/\?\:@\-_=#]+\.([a-zA-Z0-9\&\.\/\?\:@\-_=#])*/', $input_url ) ) {
			self::add_error( sprintf( __( '%s is not a valid URL.', 'contentsync_hub' ), '<strong>' . $input_url . '</strong>' ) );
		}
		if ( count( self::get_errors() ) ) {
			return;
		}

		// generate the final request url
		$input_auth_url = preg_replace( '/\/$/', '', $input_url ) . '/wp-admin/authorize-application.php';
		$success_path   = $_GET['page'] === 'site_connections' ? 'admin.php?page=site_connections' : 'admin.php?page=contentsync_hub&tab=connections';
		$success_url    = is_network_admin() ? network_admin_url( $success_path ) : admin_url( $success_path );

		$app_name   = urlencode( sprintf( __( 'Connection for %s', 'contentsync_hub' ), Urls::get_network_url() ) );
		$query_args = array(
			'app_name'    => $app_name,
			'app_id'      => urlencode( wp_generate_uuid4() ),
			'success_url' => urlencode( $success_url ),
		);
		$final_url  = add_query_arg( $query_args, esc_url( $input_auth_url ) );

		wp_redirect( $final_url );
		exit;
	}

	/**
	 * Add a connection to the database.
	 * Usually we've just been redirected from the authorization page.
	 */
	public function maybe_add_site_connection() {

		if ( ! isset( $_GET['user_login'] ) || ! isset( $_GET['password'] ) || ! isset( $_GET['site_url'] ) ) {
			return;
		}

		$site_url   = esc_attr( $_GET['site_url'] );
		$nice_url   = Urls::get_nice_url( $site_url );
		$connection = array(
			'site_name'  => Remote_Request::get_site_name( $site_url ),
			'user_login' => esc_attr( $_GET['user_login'] ),
			'password'   => str_rot13( esc_attr( $_GET['password'] ) ),
			'site_url'   => $site_url,
			'active'     => true,
			'contents'   => true,
			'search'     => true,
		);
		$update     = Site_Connection::add( $connection );

		// update successfull
		if ( $update ) {
			set_transient(
				'contentsync_transient_notice',
				'success::' . sprintf(
					__( 'The connection to the %s page has been saved. Be sure to add the connection in the other direction as well.', 'contentsync_hub' ),
					"<strong>$nice_url</strong>"
				) . '<br><br>' .
				sprintf(
					__( 'To do this, go to the admin or network admin area of the page %1$s and enter the URL %2$s at "Add Connection".', 'contentsync_hub' ),
					"<a href='" . esc_url( $site_url ) . "' target='_blank'>$nice_url</a>",
					'<strong>' . Urls::get_network_url() . '</strong>'
				)
			);
		}
		// update failed
		elseif ( $update === false ) {
			set_transient(
				'contentsync_transient_notice',
				'error::' . sprintf(
					__( 'The connection to the %s page could not be saved. Please try again.', 'contentsync_hub' ),
					'<strong>' . $nice_url . '</strong>'
				)
			);
		}

		$url = remove_query_arg(
			array(
				'user_login',
				'password',
				'site_url',
				'success',
			)
		);
		wp_redirect( $url );
		exit;
	}

	/**
	 * Check all saved connections, if any are inactive
	 */
	public function check_connections( $current_screen ) {

		// only execute on connections admin sub page
		if ( strpos( $current_screen->base, 'site_connections' ) === false &&
			strpos( $current_screen->base, 'global-content' ) !== 0 &&
			strpos( $current_screen->base, 'contentsync' ) === false &&
			strpos( $current_screen->base, 'contentsync_hub' ) === false
		) {
			return;
		}

		// get connections
		$connections = Site_Connection::get_all();
		if ( ! is_array( $connections ) || empty( $connections ) ) {
			return;
		}

		// check this site for HTTP Authorization
		if ( isset( $_SERVER['PHP_AUTH_USER'] ) ) {
			self::add_error(
				'<strong>' . sprintf(
					__( 'This page uses server-side authentication, such as HTTP password protection.', 'contentsync_hub' ),
					''
				) .
				'</strong><br><br>' .
				sprintf(
					__( 'However, the link already sends the WordPress application password as "Basic Authorization" to the other server. Multiple authorizations are not allowed according to %s. We recommend that you disable HTTP authentication or whitelist the IP addresses of all other connections. It is best to contact your hoster or administrator for this.', 'contentsync_hub' ),
					"<a href='https://www.rfc-editor.org/rfc/rfc7230#section-3.2.2' target='_blank'>RFC7230</a>"
				)
			);
		}

		// check connections
		$changed = false;
		foreach ( $connections as $k => $connection ) {

			// we've just deleted this connection
			if ( isset( $_GET['delete'] ) && urldecode( $_GET['delete'] ) == $k ) {
				continue;
			}

			// we don't use this connection
			if ( isset( $connection['contents'] ) && $connection['contents'] === false && isset( $connection['search'] ) && $connection['search'] === false ) {
				continue;
			}

			// get current state
			$response = Remote_Request::check_connection_authentication( $connection['site_url'] );

			// display an error if necessary
			if ( $response !== true ) {

				$response = empty( $response ) ? 'incorrect_password' : $response;

				switch ( $response ) {
					case 'incorrect_password':
					case 'rest_not_authorized':
						$response_help_text = sprintf(
							__( 'The status %s indicates that the app permission on the target page has been revoked or is not working correctly.', 'contentsync_hub' ),
							"<code>$response</code>"
						);
						break;
					case 'rest_not_connected':
						$response_help_text = sprintf(
							__( 'The status %s usually means that no connection to this page has yet been stored on the target page.', 'contentsync_hub' ),
							"<code>$response</code>"
						);
						break;
					case '401':
						$response_help_text = sprintf(
							__( 'The status %1$s usually means that additional authentication is required on the target page, e.g. an HTTP password. However, the link already sends the WordPress application password to the server as "Basic Authorization". Multiple authorizations are not allowed according to %2$s. We recommend you to disable HTTP authentication or whitelist the IP address of this server (%3$s). It is best to contact your hoster or administrator for this.', 'contentsync_hub' ),
							"<code>$response</code>",
							"<a href='https://www.rfc-editor.org/rfc/rfc7230#section-3.2.2' target='_blank'>RFC7230</a>",
							"<code>{$_SERVER['SERVER_ADDR']}</code>"
						);
						break;
					default:
						$response_help_text = '';
				}

				self::add_error(
					'<strong>' .
						sprintf(
							__( 'The connection to the %1$s page is inactive (status: %2$s).', 'contentsync_hub' ),
							"<a href='{$connection["site_url"]}'>{$connection["site_url"]}</a>",
							"<code>{$response}</code>"
						) .
					'</strong><br><br><strong>' .
						sprintf(
							__( 'Please make sure that the connection is created from both sides. This means that you must also request and confirm the connection on the site %s.', 'contentsync_hub' ),
							"<a href='{$connection["site_url"]}'>{$connection["site_url"]}</a>"
						) .
					'</strong><br><br>' .
					__( 'Please also check whether the app authorization is created on this and the target page for the respective stored user. In case of doubt, delete the links and the respective permissions and create them again.', 'contentsync_hub' ) .
					"<br><br>{$response_help_text}<br><br>" .
					__( 'The stored app permissions can be found in the respective user profile via "Application Passwords".', 'contentsync_hub' )
				);
			}

			// convert to truthy value
			$active = $response === true;

			// update connection array if changed
			if ( ! isset( $connection['active'] ) || $active !== $connection['active'] ) {
				$connection['active'] = $active;
				$connections[ $k ]    = $connection;
				$changed              = true;
			}
		}

		// update connections
		if ( $changed ) {
			Site_Connection::update_all( $connections );
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
				Admin_Render::render_admin_notice( $error, 'error' );
			}
		}
	}
}
