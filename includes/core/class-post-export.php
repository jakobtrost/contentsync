<?php
/**
 * Export Post Controller
 *
 * This file enables advanced post exports inside WordPress.
 * Posts of all supported post types can be exported via the WordPress
 * backend (edit.php) and later be imported to any WordPress site.
 *
 * The export contains a JSON file that holds all post data as
 * Prepared_Post objects. The export also contains all media files
 * that are attached to the posts. Structure of the export:
 * - posts.json
 * - media/
 *   - media-file.jpg
 *
 * @since 0.8.4
 */
namespace Contentsync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

new Post_Export();
class Post_Export {

	/**
	 * Holds all Prepared_Post objects for export.
	 *
	 * @var Prepared_Post[]
	 */
	public static $posts = array();

	/**
	 * Holds all WP_Media objects for export.
	 *
	 * @var array
	 */
	public static $media = array();

	/**
	 * Constructor
	 */
	public function __construct() {

		// Export
		add_action( 'contentsync_ajax_mode_post_export', array( $this, 'handle_export' ) );

		// add bulk action callbacks
		add_action( 'admin_init', array( $this, 'add_bulk_action_callbacks' ) );
	}

	/**
	 * Handle the ajax export action
	 *
	 * @action 'contentsync_ajax_mode_post_export'
	 *
	 * @param array $data   holds the $_POST['data']
	 */
	public function handle_export( $data ) {

		\Contentsync\post_export_enable_logs();

		do_action( 'post_export_log', "\r\n\r\n" . 'HANDLE EXPORT' . "\r\n", $data );

		$post_id = isset( $data['post_id'] ) ? $data['post_id'] : '';
		$args    = array(
			'append_nested'  => isset( $data['nested'] ) ? true : false,
			'whole_posttype' => isset( $data['whole_posttype'] ) ? true : false,
			'all_terms'      => isset( $data['all_terms'] ) ? true : false,
			'resolve_menus'  => isset( $data['resolve_menus'] ) ? true : false,
			'translations'   => isset( $data['translations'] ) ? true : false,
		);
		if ( ! empty( $post_id ) ) {

			// export post
			$post = self::export_post( $post_id, $args );

			/**
			 * Create the export ZIP-archive.
			 * If posts & media are null, write_export_file() uses the class vars.
			 */
			$posts    = $media = ( ( isset( $args['append_nested'] ) && $args['append_nested'] ) ? null : array() );
			$filename = self::write_filename( $post, $args );
			$filepath = self::write_export_file( $filename, $posts, $media );

			if ( ! $filepath ) {
				\Contentsync\post_export_return_error( __( 'The export file could not be written.', 'contentsync_hub' ) );
			}

			post_export_return_success( \Contentsync\convert_wp_content_dir_to_path( $filepath ) );
		}
		\Contentsync\post_export_return_error( __( 'No valid post ID could found.', 'contentsync_hub' ) );
	}

	/**
	 * Get post with all its meta, taxonomies, media etc.
	 *
	 * @param int   $post_id
	 * @param array $args       Arguments.
	 *
	 * @return Prepared_Post
	 */
	public static function export_post( $post_id, $args = array() ) {

		$args = \Contentsync\parse_post_export_arguments( $args );

		// reset the class vars
		self::$posts = array();
		self::$media = array();

		// prepare the export based on this post
		$post = self::prepare_post( $post_id, $args );

		return $post;
	}

	/**
	 * Export posts with all its meta, taxonomies, media etc.
	 *
	 * @param int[] $post_ids Array of post IDs.
	 * @param array $args       Arguments.
	 *
	 * @return Prepared_Post[]
	 */
	public static function export_posts( $post_ids, $args = array() ) {

		$args = \Contentsync\parse_post_export_arguments( $args );

		// reset the class vars
		self::$posts = array();
		self::$media = array();

		if ( $post_ids && is_array( $post_ids ) ) {
			foreach ( $post_ids as $post_id ) {
				self::prepare_post( $post_id, $args );
			}
		}

		return self::$posts;
	}

	/**
	 * Export posts with all its meta, taxonomies, media etc.
	 *
	 * @since 2.18.0
	 *
	 * @param int[]|object[] $post_ids_or_objects   Array of post IDs or post objects.
	 * @param array          $args       Arguments.
	 *
	 * @return Prepared_Post[]
	 */
	public static function export_post_objects( $post_ids_or_objects, $args = array() ) {

		$args = \Contentsync\parse_post_export_arguments( $args );

		// reset the class vars
		self::$posts = array();
		self::$media = array();

		if ( $post_ids_or_objects && is_array( $post_ids_or_objects ) ) {
			foreach ( $post_ids_or_objects as $post_or_id ) {

				// if the post object has export_arguments, use them to overwrite the default arguments
				if ( is_object( $post_or_id ) && isset( $post_or_id->export_arguments ) ) {
					$args = wp_parse_args( $post_or_id->export_arguments, $args );
				}

				self::prepare_post( $post_or_id, $args );
			}
		}

		return self::$posts;
	}

	/**
	 * Prepare post for export.
	 *
	 * This function automatically sets the following class vars.
	 * Use them to export all nested posts at once.
	 *
	 * @var array class::$posts     Array of all preparred post objects.
	 * @var array class::$media     Array of all media files.
	 *
	 * @since 2.18.0 (plugin version) the method does support a post objects as first argument.
	 * @deprecated @param int $post_id WP_Post ID.
	 *
	 * @param int|object $post_id_or_object  Post ID or post object.
	 * @param array      $args                    Arguments.
	 *
	 * @return Prepared_Post|bool  Prepared_Post on success. False on failure.
	 */
	public static function prepare_post( $post_id_or_object, $args = array() ) {

		if ( is_object( $post_id_or_object ) ) {
			$post_id = $post_id_or_object->ID;
		} else {
			$post_id = $post_id_or_object;
		}

		// return if we're already processed this post
		if ( isset( self::$posts[ $post_id ] ) ) {
			do_action( 'post_export_log', "\r\n" . "Post '$post_id' already processed" );
			return self::$posts[ $post_id ];
		}

		/**
		 * First we append the post object to the class var. We do this to
		 * kind of 'reserve' the position of the post inside the array.
		 */
		self::$posts[ $post_id ] = $post_id;

		/**
		 * Create a new Prepared_Post object.
		 */
		$post = new Prepared_Post( $post_id_or_object, $args );

		/**
		 * Check if prepared post is valid
		 */
		if ( empty( $post->ID ) ) {
			unset( self::$posts[ $post_id ] );
			do_action( 'post_export_log', "\r\n" . "Post '$post_id' not found or invalid" );
			return false;
		}

		/**
		 * Now we update the post in the class var.
		 */
		self::$posts[ $post_id ] = $post;

		/**
		 * Let's save the media to the class var.
		 *
		 * We need this, so write_export_file() can access all the files at once.
		 */
		if ( ! empty( $post->media ) ) {
			self::$media[ $post_id ] = $post->media;
		}

		/**
		 * The post thumbnail always has to be included in the export,
		 * because WP references it with an ID, therefore it needs to
		 * be accessable as a post.
		 */
		if ( $thumbnail_id = get_post_thumbnail_id( $post ) ) {
			self::prepare_post( $thumbnail_id, $args );
		}

		/**
		 * Now we loop through all the nested posts (if the option is set).
		 */
		if ( isset( $args['append_nested'] ) && $args['append_nested'] ) {
			foreach ( $post->nested as $nested_id => $nested_name ) {
				self::prepare_post( $nested_id, $args );
			}
		}

		/**
		 * Now we loop through all translations of this post (if the option is set).
		 */
		if (
			( isset( $args['translations'] ) && $args['translations'] )
			&& isset( $post->language )
			&& isset( $post->language['post_ids'] )
			&& is_countable( $post->language['post_ids'] )
			&& count( $post->language['post_ids'] )
		) {
			foreach ( $post->language['post_ids'] as $lang => $translated_post_id ) {
				self::prepare_post( $translated_post_id, $args );
			}
		}

		return $post;
	}

	/**
	 * Add export bulk action callbacks.
	 */
	public function add_bulk_action_callbacks() {
		// usual posttypes
		foreach ( \Contentsync\get_export_post_types() as $posttype ) {
			add_filter( 'bulk_actions-edit-' . $posttype, array( $this, 'add_export_bulk_action' ) );
			add_filter( 'handle_bulk_actions-edit-' . $posttype, array( $this, 'handle_export_bulk_action' ), 10, 3 );
		}
		// media library
		add_filter( 'bulk_actions-upload', array( $this, 'add_export_bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_export_bulk_action' ), 10, 3 );
	}

	/**
	 * Add export to the bulk action dropdown
	 */
	public function add_export_bulk_action( $bulk_actions ) {
		$bulk_actions['contentsync_export'] = __( 'Export', 'contentsync_hub' );

		if ( ! empty( Translation_Manager::get_translation_tool() ) ) {
			$bulk_actions['contentsync_export_multilanguage'] = __( 'Export including translations', 'contentsync_hub' );
		}
		return $bulk_actions;
	}

	/**
	 * Handle export bulk action
	 *
	 * set via 'add_bulk_action_callbacks'
	 *
	 * @param string $sendback The redirect URL.
	 * @param string $doaction The action being taken.
	 * @param array  $items    Array of IDs of posts.
	 */
	public static function handle_export_bulk_action( $sendback, $doaction, $items ) {
		if ( count( $items ) === 0 ) {
			return $sendback;
		}
		if ( $doaction !== 'contentsync_export' && $doaction !== 'contentsync_export_multilanguage' ) {
			return $sendback;
		}

		$args = array(
			'append_nested' => true,
			'translations'  => $doaction === 'contentsync_export_multilanguage',
		);

		self::export_posts( $items, $args );

		$filename = self::write_filename( $items );
		$filepath = self::write_export_file( $filename );

		if ( $filepath ) {
			$href = \Contentsync\convert_wp_content_dir_to_path( $filepath );
			// $sendback = add_query_arg( 'download', $href, $sendback );
			$sendback = $href;
		} else {
			// set transient to display admin notice
			set_transient( 'contentsync_transient_notice', 'error::' . __( 'The export file could not be written.', 'contentsync_hub' ) );
		}

		return $sendback;
	}

	/**
	 * Write export as a .zip archive
	 *
	 * @param string $filename  Name of the final archive.
	 * @param array  $posts     Content of posts.json inside archive.
	 *                          Defaults to class var $posts.
	 * @param array  $media     Media files.
	 *                          Defaults to class var $media.
	 *
	 * @return mixed $path      Path to the archive. False on failure.
	 */
	public static function write_export_file( $filename, $posts = null, $media = null ) {

		do_action( 'post_export_log', "\r\n" . "Write export .zip archive '$filename'" );

		$posts_data = $posts ? $posts : self::$posts;
		$media_data = $media ? $media : self::$media;

		// set monthly folder
		$folder = date( 'y-m' );
		$path   = \Contentsync\get_export_file_path( $folder );

		// write the temporary posts.json file
		$json_name = 'posts.json';
		$json_path = $path . $json_name;
		$json_file = fopen( $json_path, 'w' );

		if ( ! $json_file ) {
			return false;
		}

		fwrite( $json_file, json_encode( $posts_data, JSON_PRETTY_PRINT ) );
		fclose( $json_file );

		// create a zip archive
		$zip      = new \ZipArchive();
		$zip_name = str_replace( '.zip', '', $filename ) . '.zip';
		$zip_path = $path . $zip_name;

		// delete previous zip archive
		if ( file_exists( $zip_path ) ) {
			unlink( $zip_path );
		}

		// add files to the zip archive
		if ( $zip->open( $zip_path, \ZipArchive::CREATE ) ) {

			// copy the json to the archive
			$zip->addFile( $json_path, $json_name );

			// add media
			$zip->addEmptyDir( 'media' );
			if ( is_array( $media_data ) && count( $media_data ) > 0 ) {
				foreach ( $media_data as $post_id => $_media ) {
					if ( isset( $_media['path'] ) && isset( $_media['name'] ) ) {
						$zip->addFile( $_media['path'], 'media/' . $_media['name'] );
					}
				}
			}

			$zip->close();
		} else {
			return false;
		}

		// delete temporary json file
		unlink( $json_path );

		// return path to file
		return $zip_path;
	}


	/**
	 * =================================================================
	 *                          Helper functions
	 * =================================================================
	 */

	/**
	 * @deprecated but might be used by other plugins.
	 * Use Post_Export_Helper::prepare_strings() instead.
	 */
	public static function prepare_strings( $subject, $post_id, $log = true ) {
		return \Contentsync\replace_dynamic_post_strings( $subject, $post_id, $log );
	}

	/**
	 * Get all posts from class var
	 *
	 * @return Prepared_Post[]
	 */
	public static function get_all_posts() {
		return self::$posts;
	}

	/**
	 * Get all posts from class var
	 *
	 * @return array[]
	 */
	public static function get_all_media() {
		return self::$media;
	}

	/**
	 * Create filename from export attributes
	 *
	 * @param Prepared_Post|Prepared_Post[] $posts
	 * @param array                         $args
	 *
	 * @return string $filename
	 */
	public static function write_filename( $posts, $args = array() ) {

		// vars
		$filename     = array();
		$default_args = array(
			'whole_posttype' => false,
			// we don't need other arguments to create the filename
		);
		$args = array_merge( $default_args, (array) $args );

		// bulk export
		if ( is_array( $posts ) && isset( $posts[0] ) ) {
			$bulk = true;
			$post = $posts[0];
			$post = ! is_object( $post ) ? get_post( $post ) : $post;
		}
		// single export
		elseif ( is_object( $posts ) ) {
			$bulk = false;
			$post = $posts;
			// add post name to filename
			$filename[] = $post->post_name;
		}
		// unknown export
		if ( ! isset( $post ) || ! $post || ! isset( $post->post_type ) ) {
			return 'post-export';
		}

		$post_type     = $post->post_type;
		$default_types = array(
			'post'              => 'post',
			'page'              => 'page',
			'attachment'        => 'media_file',
			'tp_forms'          => 'form',
			'dynamic_template'  => 'template',
			'tp_posttypes'      => 'posttype',
			'contentsync_popup' => 'popup',
		);

		// handle default posttypes
		if ( isset( $default_types[ $post_type ] ) ) {

			if ( isset( $args['whole_posttype'] ) && $args['whole_posttype'] && $post_type == 'tp_posttypes' ) {
				$post_type = 'posts_and_posttype';
			} else {
				$post_type = $default_types[ $post_type ] . ( $bulk ? 's' : '' );
			}
		}
		// handle other posttypes
		elseif ( $bulk ) {
			$post_type_obj = get_post_type_object( $post_type );
			if ( $post_type_obj && isset( $post_type_obj->labels ) ) {
				$post_type = $post_type_obj->labels->name;
			}
		}

		// add post type to filename
		$filename[] = $post_type;

		// add site title to filename
		$filename[] = get_bloginfo( 'name' );

		// cleanup strings
		foreach ( $filename as $k => $string ) {
			$filename[ $k ] = preg_replace( '/[^a-z_]/', '', strtolower( preg_replace( '/-+/', '_', $string ) ) );
		}

		return implode( '-', $filename );
	}


	/**
	 * =================================================================
	 *                          Compatiblity functions
	 * =================================================================
	 *
	 * @since 2.0
	 *
	 * These functions are used by external plugins:
	 * * get_supported_post_types
	 * * get_translation_tool
	 * * enable_logs
	 * * import_posts
	 * * import_get_conflict_posts_for_backend_form
	 * * import_get_conflict_actions_from_backend_form
	 *
	 * They are used by the old export class and are therefore
	 * still needed for backwards compatibility.
	 */

	public static $logs = false;

	public static function get_supported_post_types() {
		return \Contentsync\get_export_post_types();
	}

	public static function enable_logs() {
		return \Contentsync\post_export_enable_logs();
	}

	public static function import_posts( $posts, $conflict_actions = array(), $zip_file = '' ) {
		return Post_Import::import_posts( $posts, $conflict_actions, $zip_file );
	}

	public static function import_get_conflict_posts_for_backend_form( $posts ) {
		return Post_Import::import_get_conflict_posts_for_backend_form( $posts );
	}

	public static function import_get_conflict_actions_from_backend_form( $conflicts ) {
		return Post_Import::import_get_conflict_actions_from_backend_form( $conflicts );
	}
}
