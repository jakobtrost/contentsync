<?php

namespace Contentsync\Posts\Transfer;

use Contentsync\Utils\Files;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Post_Export extends Post_Transfer_Base {

	/**
	 * Holds all WP_Media objects for a post transfer, keyed by post ID.
	 *
	 * @var array
	 */
	protected $media = array();

	/**
	 * Export posts with all its meta, taxonomies, media etc.
	 *
	 * @param int|object|int[]|object[] $post_or_posts  Post ID, post object, array of post IDs or array of post objects.
	 * @param array                     $arguments            Export arguments.
	 *
	 * @return Prepared_Post[]
	 */
	public function __construct( $post_or_posts, $arguments = array() ) {

		parent::__construct( $post_or_posts, $arguments );

		if ( $post_or_posts && is_array( $post_or_posts ) ) {
			foreach ( $post_or_posts as $post_or_id ) {

				// if the post object has export_arguments, use them to overwrite the default arguments
				if ( is_object( $post_or_id ) && isset( $post_or_id->export_arguments ) ) {
					$arguments = wp_parse_args( $post_or_id->export_arguments, $this->arguments );
				} else {
					$arguments = $this->arguments;
				}

				$this->prepare_post( $post_or_id, $arguments );
			}
		} else {
			$this->prepare_post( $post_or_posts, $arguments );
		}
	}

	/**
	 * Prepare post for export and add it to the posts array.
	 *
	 * @param int|object $post_id_or_object  Post ID or post object.
	 * @param array      $arguments          Arguments to overwrite the default arguments of the post transfer class.
	 *
	 * @return Prepared_Post|bool  Prepared_Post on success. False on failure.
	 */
	public function prepare_post( $post_id_or_object, $arguments = array() ) {

		$arguments = wp_parse_args( $arguments, $this->arguments );

		if ( is_object( $post_id_or_object ) ) {
			$post_id = $post_id_or_object->ID;
		} else {
			$post_id = $post_id_or_object;
		}

		// return if we're already processed this post
		if ( isset( $this->posts[ $post_id ] ) ) {
			Logger::add( "Post '$post_id' already processed" );
			return $this->posts[ $post_id ];
		}

		/**
		 * First we append the post object to the class var. We do this to
		 * kind of 'reserve' the position of the post inside the array.
		 */
		$this->posts[ $post_id ] = $post_id;

		/**
		 * Create a new Prepared_Post object.
		 */
		$post = new Prepared_Post( $post_id_or_object, $arguments );

		/**
		 * Check if prepared post is valid
		 */
		if ( empty( $post->ID ) ) {
			unset( $this->posts[ $post_id ] );
			Logger::add( "Post '$post_id' not found or invalid" );
			return false;
		}

		/**
		 * Now we update the post in the class var.
		 */
		$this->posts[ $post_id ] = $post;

		/**
		 * Let's save the media to the class var.
		 */
		if ( ! empty( $post->media ) ) {
			$this->media[ $post_id ] = $post->media;
		}

		/**
		 * The post thumbnail always has to be included in the export,
		 * because WP references it with an ID, therefore it needs to
		 * be accessable as a post.
		 */
		if ( $thumbnail_id = get_post_thumbnail_id( $post ) ) {
			$this->prepare_post( $thumbnail_id, $arguments );
		}

		/**
		 * Now we loop through all the nested posts (if the option is set).
		 */
		if ( isset( $arguments['append_nested'] ) && $arguments['append_nested'] ) {
			foreach ( $post->nested as $nested_id => $nested_name ) {
				$this->prepare_post( $nested_id, $arguments );
			}
		}

		/**
		 * Now we loop through all translations of this post (if the option is set).
		 */
		if (
			( isset( $arguments['translations'] ) && $arguments['translations'] )
			&& isset( $post->language )
			&& isset( $post->language['post_ids'] )
			&& is_countable( $post->language['post_ids'] )
			&& count( $post->language['post_ids'] )
		) {
			foreach ( $post->language['post_ids'] as $lang => $translated_post_id ) {
				$this->prepare_post( $translated_post_id, $arguments );
			}
		}

		return $post;
	}

	/**
	 * Export the posts to a zip file.
	 *
	 * @return string|bool The filepath of the exported zip file or false if the export failed.
	 */
	public function export_to_zip() {
		return $this->create_export_zip_file();
	}

	/**
	 * Get the first post, often used to determine the post type.
	 *
	 * @return Prepared_Post|bool The first post or false if no posts are available.
	 */
	public function get_first_post() {
		return reset( $this->posts );
	}

	/**
	 * ================================================
	 * PRIVATE METHODS
	 * ================================================
	 */

	/**
	 * Get the default arguments.
	 *
	 * @return array The default arguments.
	 */
	private function get_default_arguments() {
		return apply_filters(
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
	}

	/**
	 * Parse export arguments.
	 *
	 * @param array $arguments The arguments to parse.
	 *
	 * @return array The parsed arguments.
	 */
	private function parse_arguments( $arguments ) {

		$default_arguments = $this->get_default_arguments();

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

	/**
	 * Get the filename for the export.
	 *
	 * @return string The filename.
	 */
	private function get_filename() {

		// vars
		$filename = array();

		// bulk export
		if ( is_array( $this->posts ) && count( $this->posts ) ) {
			$bulk       = true;
			$first_post = reset( $this->posts );
		}

		if ( ! $first_post || ! is_object( $first_post ) ) {
			return 'post-export';
		}

		if ( count( $this->posts ) === 1 ) {
			// add post name to filename
			$filename[] = $first_post->post_name;
		}

		// get the post type
		$post_type     = isset( $first_post->post_type ) ? $first_post->post_type : 'post';
		$post_type_obj = get_post_type_object( $post_type );
		if ( $post_type_obj && isset( $post_type_obj->labels ) ) {
			$post_type = $post_type_obj->labels->name;
		} else {
			$post_type = $post_type . ( $bulk ? 's' : '' );
		}

		// add post type to filename
		$filename[] = $post_type;

		// add site title to filename
		$filename[] = get_bloginfo( 'name' );

		// add date to filename
		$filename[] = date( 'Y-m-d' );

		// cleanup strings
		foreach ( $filename as $k => $string ) {
			$filename[ $k ] = preg_replace( '/[^a-z_]/', '', strtolower( preg_replace( '/-+/', '_', $string ) ) );
		}

		return implode( '-', $filename );
	}

	/**
	 * Write export as a .zip archive
	 *
	 * @return mixed $path      Path to the archive. False on failure.
	 */
	private function create_export_zip_file() {

		if ( ! is_array( $this->posts ) || count( $this->posts ) === 0 ) {
			return false;
		}

		// set monthly folder
		$folder = date( 'y-m' );
		$path   = Files::get_wp_content_folder_path( $folder );

		// write the temporary posts.json file
		$json_name = 'posts.json';
		$json_path = $path . $json_name;
		$json_file = fopen( $json_path, 'w' );

		if ( ! $json_file ) {
			return false;
		}

		fwrite( $json_file, json_encode( $this->posts, JSON_PRETTY_PRINT ) );
		fclose( $json_file );

		// create a zip archive
		$zip      = new \ZipArchive();
		$zip_name = str_replace( '.zip', '', $this->get_filename() ) . '.zip';
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
			if ( is_array( $this->media ) && count( $this->media ) > 0 ) {
				foreach ( $this->media as $post_id => $_media ) {
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
}
