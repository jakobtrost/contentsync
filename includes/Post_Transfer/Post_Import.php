<?php

namespace Contentsync\Post_Transfer;

use WP_Error;
use Contentsync\Post_Sync\Post_Meta_Hooks;
use Contentsync\Utils\Files;
use Contentsync\Utils\Logger;
use Contentsync\Translations\Translation_Manager;

defined( 'ABSPATH' ) || exit;

class Post_Import extends Post_Transfer_Base {

	/**
	 * Array of post IDs to be imported and the new post IDs.
	 *
	 * @var array Key => Value pairs of post IDs.
	 */
	protected $post_id_map = array();

	/**
	 * Collect strings during import to replace them later.
	 *
	 * @var array Key => Value pairs of strings to be replaced.
	 */
	protected $collected_replace_strings = array();

	/**
	 * Import posts with all its meta, taxonomies, media etc.
	 *
	 * @param int[]|object[] $post_ids_or_objects  Array of post IDs or post objects.
	 * @param array          $arguments            Import arguments.
	 *
	 * @return Prepared_Post[]
	 */
	public function __construct( $post_ids_or_objects, $arguments = array() ) {
		parent::__construct( $post_ids_or_objects, $arguments );

		$this->collected_replace_strings = array();

		if ( is_array( $post_ids_or_objects ) ) {
			foreach ( $post_ids_or_objects as $key => $post ) {
				// If posts are keyed by ID and are Prepared_Post objects, use them directly
				if ( is_object( $post ) && property_exists( $post, 'post_type' ) ) {
					$this->posts[ $key ] = $post;
				}
			}
		}
	}

	/**
	 * Import posts with backward compatiblity and additional actions.
	 */
	public function import_posts() {

		/**
		 * @action contentsync_before_import_global_posts
		 */
		do_action( 'contentsync_before_import_global_posts', $this->posts, $this->arguments );

		// Get arguments from class properties
		$conflict_actions = isset( $this->arguments['conflict_actions'] ) ? $this->arguments['conflict_actions'] : array();

		$first_post = null;

		/**
		 * Filter to modify conflict actions before processing post imports.
		 *
		 * This filter allows developers to customize how conflicts between existing posts
		 * and posts being imported are handled. It's useful for implementing custom
		 * conflict resolution logic or modifying the default conflict handling behavior.
		 *
		 * @filter contentsync_import_conflict_actions
		 *
		 * @param array $conflict_actions   Array of existing posts with conflicts, keyed by original post ID. Example:
		 * [
		 *   456 => array(
		 *     'existing_post_id' => 123,
		 *     'conflict_action'  => 'replace',
		 *     'original_post_id' => 456,
		 *   ),
		 *   789 => array(
		 *     'existing_post_id' => 101,
		 *     'conflict_action'  => 'skip',
		 *     'original_post_id' => 789,
		 *   ),
		 * ]
		 * @param array $posts              Array of posts to be imported, keyed by post ID.
		 *
		 * @return array $conflict_actions  Modified array of conflict actions.
		 */
		$conflict_actions = (array) apply_filters( 'contentsync_import_conflict_actions', $conflict_actions, $this->posts );

		/**
		 * Loop through posts and insert them
		 */
		foreach ( $this->posts as $post_id => $post ) {

			Logger::add( '========= INSERT POST =========' );

			// typecasting $post arrays (eg. via remote requests)
			$post = is_array( $post ) ? (object) $post : $post;
			if ( ! is_object( $post ) ) {
				Logger::add( sprintf( "  - WP_Post object for post of ID '%s' not set correctly:", $post_id ), $post );
				continue;
			}

			$is_first_post = false;
			if ( ! $first_post ) {
				$first_post    = $post;
				$is_first_post = true;
			}

			Logger::add( sprintf( "Insert post '%s'.", $post->post_name ) );

			// handle the post language
			// Analyze whether this translation should be imported
			$translation_analysis = Translation_Manager::analyze_translation_import( $post, $this->post_id_map );

			// Handle the decision based on the analysis
			if ( ! $translation_analysis['should_import'] ) {

				// Log the reason for skipping
				switch ( $translation_analysis['reason'] ) {
					case 'skip_better_translation':
						Logger::add( '  - There is at least 1 supported language for this post: ' . implode( ', ', $translation_analysis['supported_translations'] ) );
						break;

					case 'reuse_imported':
						Logger::add( '  - Another translation of this post has already been imported - we skip this one.' );

						// Add the post to the class var for later replacement,
						// e.g. as nested posts inside the post content
						$this->post_id_map[ $post_id ] = $translation_analysis['reuse_post_id'];
						break;

					default:
						Logger::add( '  - We skip this post because of the reason: ' . $translation_analysis['reason'] );
						break;
				}

				// Delete all copies of this post (which is not supported, and a worse translation...)
				if ( isset( $conflict_actions[ $post_id ] ) && isset( $conflict_actions[ $post_id ]['post_id'] ) ) {
					$translated_id = $conflict_actions[ $post_id ]['post_id'];
					$deleted       = wp_trash_post( $translated_id );
					if ( $deleted ) {
						Logger::add( "  - This version (id: $translated_id ) was trashed, we import a better suited translation." );
					}
				}

				// Remove it from the import array to not change the existing post at all
				unset( $this->posts[ $post_id ] );
				unset( $this->post_id_map[ $post_id ] );
				continue;
			}

			// Post should be imported - log success if language was switched
			if ( $translation_analysis['language_switched'] ) {
				Logger::add( '  - language supported and switched!' );
			} elseif ( $translation_analysis['reason'] === 'import_fallback' ) {
				Logger::add( '  - There is no supported language for this post.' );
				Logger::add( '  - No other translation of this post has been imported - we import this one.' );
			}

			// Extract language data from analysis for use later in the import process
			$post_language_code       = $translation_analysis['language_code'];
			$translation_post_ids     = $translation_analysis['translation_ids'];
			$unsupported_translations = $translation_analysis['unsupported_translations'];

			/**
			 * Filter to modify the post array before importing a post.
			 *
			 * This filter allows developers to customize the post data that will be used
			 * when creating or updating posts during import. It's useful for modifying
			 * post attributes, adding custom fields, or implementing custom import logic.
			 *
			 * @filter import_synced_postarr
			 *
			 * @param array $postarr        Array of post parameters used for wp_insert_post().
			 * @param Prepared_Post $post  Preparred post object with meta, taxonomy terms, etc.
			 * @param bool $is_first_post   Whether this is the first post of the import batch.
			 *
			 * @return array $postarr       Modified array of post parameters for import.
			 */
			$postarr = apply_filters(
				'import_synced_postarr',
				array(
					'post_title'    => $post->post_title,
					'post_name'     => $post->post_name,
					'post_content'  => $post->post_content,
					'post_excerpt'  => $post->post_excerpt,
					'post_type'     => $post->post_type,
					'post_author'   => isset( $post->post_author ) ? $post->post_author : get_current_user_id(),
					'post_date_gmt' => $post->post_date_gmt,
					'post_status'   => $post->post_status,
					/**
					 * @property int $import_id
					 * The post ID to be used when inserting a new post.
					 * If specified, must not match any existing post ID. Default 0.
					 * @see https://developer.wordpress.org/reference/functions/wp_insert_post/
					 */
					'import_id'     => $post_id,
				),
				$post,
				$is_first_post
			);

			/**
			 * Get conflicting post and action.
			 */
			$conflict_action  = isset( $post->conflict_action ) ? $post->conflict_action : 'keep';
			$existing_post_id = 0;

			// if a conflict action is set for this post, this takes precedence.
			// this usually is a user decision from the backend form.
			if ( isset( $conflict_actions[ $post_id ] ) ) {
				$conflict_data    = (array) $conflict_actions[ $post_id ];
				$existing_post_id = $conflict_data['existing_post_id'];
				$conflict_action  = $conflict_data['conflict_action'];
			} else {
				$existing_post_id = $existing_post_id ? $existing_post_id : Post_Transfer_Service::get_existing_post_id( $post );
			}

			/**
			 * Filter to determine the import action for a post.
			 *
			 * This filter allows developers to customize how posts are handled during import,
			 * including whether to insert, update, set as draft, trash, or delete existing posts.
			 * It's useful for implementing custom import strategies or business logic.
			 *
			 * @filter contentsync_import_action
			 *
			 * @param string $import_action    The import action to be taken. ('insert'|'draft'|'trash'|'delete')
			 *   @default 'insert'  Insert or update the post if it already exists.
			 *   @value   'draft'   Set the post to draft status.
			 *   @value   'trash'   Move the post to trash.
			 *   @value   'delete'  Delete the post permanently.
			 * @param Prepared_Post $post     The post object being imported.
			 * @param int $existing_post_id    The ID of the existing post if there's a conflict.
			 *
			 * @return string                  The import action to be taken.
			 */
			$import_action = apply_filters( 'contentsync_import_action', isset( $post->import_action ) ? $post->import_action : 'insert', $post, $existing_post_id );

			if ( $import_action === 'draft' ) {
				$postarr['post_status'] = 'draft';
			} elseif ( $import_action === 'trash' ) {

				$postarr['post_status'] = 'trash';
				if ( $existing_post_id ) {
					// trash existing post
					Logger::add( '  - trash existing post with ID: ' . $existing_post_id );
					wp_trash_post( $existing_post_id );
				}

				// we do not insert trashed posts
				Logger::add( '  - import action is "trash", we stop here.' );
				unset( $this->posts[ $post_id ] );
				continue;
			} elseif ( $import_action === 'delete' ) {

				if ( $existing_post_id ) {
					// delete existing post
					Logger::add( '  - delete existing post with ID: ' . $existing_post_id );
					wp_delete_post( $existing_post_id, true );
				}

				// we do not insert deleted posts
				Logger::add( '  - import action is "delete", we stop here.' );
				unset( $this->posts[ $post_id ] );
				continue;
			}

			/**
			 * Filter to modify the conflict action for a specific post during import.
			 *
			 * This filter allows developers to customize how conflicts are resolved for individual
			 * posts during import. It's useful for implementing custom conflict resolution logic
			 * or overriding default conflict handling behavior on a per-post basis.
			 *
			 * @filter contentsync_import_conflict_action
			 *
			 * @param string $conflict_action    The conflict action to be taken ('replace'|'skip'|'keep').
			 * @param Prepared_Post $post       The post object being imported.
			 * @param int $existing_post_id      The ID of the existing post if there's a conflict.
			 *
			 * @return string                    The modified conflict action to be taken.
			 */
			$conflict_action = apply_filters( 'contentsync_import_conflict_action', $conflict_action, $post, $existing_post_id );

			/**
			 * Handle different conflict actions.
			 *
			 * (1) replace: Replace the existing post with the new one.
			 * (2) skip:    Skip this post and use the existing post.
			 * (3) keep:    Keep the existing post and insert the new one with a new ID. (default)
			 */
			if ( $conflict_action === 'replace' ) {

				if ( $existing_post_id ) {

					Logger::add( sprintf( '  - replace existing post with ID: %s.', $existing_post_id ) );

					// add @property ID to the array to replace the existing post.
					$postarr['ID'] = $existing_post_id;
				}
			} elseif ( $conflict_action === 'skip' ) {

				if ( $existing_post_id ) {

					$skip = true;

					// if this is an attachment, check if the files do exist
					if ( $postarr['post_type'] === 'attachment' ) {
						$file_path = get_attached_file( $existing_post_id );
						if ( ! file_exists( $file_path ) ) {

							// if the file does not exist, we import the attachment anyway
							$skip = false;

							// we reset some post data from $postarr to not update the existing post
							// except for reuploading the attachment file.
							$existing_post = get_post( $existing_post_id );
							$postarr       = array_merge(
								$postarr,
								array(
									'post_title'    => $existing_post->post_title,
									'post_name'     => $existing_post->post_name,
									'post_content'  => $existing_post->post_content,
									'post_excerpt'  => $existing_post->post_excerpt,
									'post_type'     => $existing_post->post_type,
									'post_author'   => $existing_post->post_author,
									'post_date_gmt' => $existing_post->post_date_gmt,
									'post_status'   => $existing_post->post_status,
								)
							);

							Logger::add( '  - file does not exist, we import the attachment even if the post should be skipped.' );
						} elseif ( isset( $post->media ) ) {

							if ( ! is_array( $post->media ) ) {
								$post->media = (array) $post->media;
							}

							if ( isset( $post->media['path'] ) ) {
								$this->add_attachment_files_to_replace_strings( $post->media, $file_path );
							}
						}
					}

					if ( $skip ) {

						Logger::add( sprintf( '  - skip this post and use the existing post: %s.', $existing_post_id ) );

						// add the post to the class var for later replacement,
						// eg. as nested posts inside the post content.
						$this->post_id_map[ $post_id ] = $existing_post_id;

						// remove it from the import array to not change it at all
						unset( $this->posts[ $post_id ] );
						unset( $this->post_id_map[ $post_id ] );

						continue;
					}
				}
			} else {
				Logger::add( '  - insert post with new ID' );
			}

			// now we insert the post
			Logger::add(
				'  - try to insert post with the following data:',
				array_map(
					function ( $value ) {
						return is_string( $value ) ? esc_attr( $value ) : $value;
					},
					$postarr
				)
			);
			$result = $this->create_post( $postarr, $post );

			if ( is_wp_error( $result ) ) {
				return $result;
			} elseif ( $result ) {
				$this->post_id_map[ $post_id ] = $result;

				/**
				 * Set the new post id for all unsupported translations of this post as well
				 */
				if ( isset( $unsupported_translations ) && ! empty( $unsupported_translations ) ) {
					foreach ( $unsupported_translations as $lang_code ) {
						if ( $lang_code != $post_language_code ) {
							$old_post_id                       = $translation_post_ids[ $lang_code ];
							$this->post_id_map[ $old_post_id ] = $post_id;
							Logger::add( "  - unsupported translation of this post has been linked with this post (old_post_id: $old_post_id, new_id: $post_id)" );
						}
					}
				}

				/**
				 * Set the post language after the post was inserted.
				 *
				 * post correctly and in order for actions and filters to work as expected,
				 * like setting taxonomy terms.
				 */
				if ( isset( $post_language_code ) ) {
					Logger::add( '  - set post language after the post was inserted: ' . $post_language_code );
					$result = Translation_Manager::set_post_language( $result, $post_language_code );
					if ( $result ) {
						Logger::add( '  - post language successfully set.' );
					} else {
						Logger::add( '  - post language could not be set.' );
					}
				}
			}
		}

		Logger::add( '========= ALL POSTS IMPORTED. NOW WE LOOP THROUGH THEM =========' );

		/**
		 * After we inserted all the posts, we can now do additional actions
		 */
		foreach ( $this->posts as $old_post_id => $post ) {

			$post = is_array( $post ) ? (object) $post : $post;

			if ( ! isset( $this->posts[ $old_post_id ] ) ) {
				continue;
			}

			$new_post_id = $this->post_id_map[ $old_post_id ];

			Logger::add( sprintf( "\r\n" . "Check new post '%s' (old id: %s)", $new_post_id, $old_post_id ) );

			// switch to the post's language
			Translation_Manager::switch_to_language_context( $post );

			// update the post content
			if ( ! empty( $post->post_content ) ) {

				$content = $post->post_content;

				// replace nested posts in post content
				$content = $this->replace_nested_posts( $content, $post );

				// switch to the post's language again as we could have been
				// switched during the replacement of nested posts.
				Translation_Manager::switch_to_language_context( $post );

				// replace nested terms in post content
				$content = $this->replace_nested_terms( $content, $post );

				// replace strings in post content
				$content = $this->replace_strings( $content, $new_post_id );

				/**
				 * Filter to modify post content before it's imported into the database.
				 *
				 * This filter allows developers to customize post content during import,
				 * such as cleaning up HTML, replacing placeholders, or applying custom
				 * formatting before the content is saved to the database.
				 *
				 * @filter contentsync_filter_post_content_before_post_import
				 *
				 * @param string    $content    The post content after string replacements.
				 * @param int       $post_id    The ID of the newly created/updated post.
				 * @param object    $post       The original Prepared_Post object.
				 *
				 * @return string               The modified post content for import.
				 */
				$content = apply_filters( 'contentsync_filter_post_content_before_post_import', $content, $new_post_id, $post );

				// update the post content
				$result = wp_update_post(
					array(
						'ID'           => $new_post_id,
						'post_content' => wp_slash( $content ),
					),
					true,
					false
				);

				if ( is_wp_error( $result ) ) {
					Logger::add( '  - post-content could not be updated.' );
				} else {
					Logger::add( '  - post-content successfully updated.' );
				}
			}

			/**
			 * This needs to be done before additional actions, as we call 'wp_update_post'
			 * to update the post-content.
			 */
			$this->set_post_hierarchy( $new_post_id, $post );

			/**
			 * ------   I M P O R T A N T   ------
			 *
			 * All additonal actions to the post, like adding post-meta options or
			 * setting taxonomy terms need to be done AFTER we called 'wp_update_post'
			 * to update the post-content.
			 * Otherwise those changes are overwritten!
			 */

			// set meta options
			if ( ! empty( $post->meta ) ) {
				$this->set_meta( $new_post_id, $post->meta, $post );
			}

			// set terms
			if ( ! empty( $post->terms ) ) {
				$this->set_taxonomy_terms( $new_post_id, $post->terms, $post );
			}

			// set translations
			$this->set_translations( $new_post_id, $post );

			// replace thumbnail ID
			if ( $thumbnail_id  = get_post_thumbnail_id( $new_post_id ) ) {
				Logger::add( sprintf( "Replace thumbnail for post '%s'.", $post->post_name ) );
				$result = false;
				if ( isset( $this->post_id_map[ $thumbnail_id ] ) ) {
					$result = set_post_thumbnail( $new_post_id, $this->post_id_map[ $thumbnail_id ] );
				}
				if ( $result ) {
					Logger::add( sprintf( "  - thumbnail ID changed from '%s' to '%s'", $thumbnail_id, $this->post_id_map[ $thumbnail_id ] ) );
				} else {
					Logger::add( sprintf( "  - thumbnail ID '%s' could not be changed.", $thumbnail_id ) );
				}
			}

			/**
			 * Add action to handle additional actions after a post was imported.
			 *
			 * @action 'contentsync_after_import_post'
			 */
			do_action( 'contentsync_after_import_post', $new_post_id, $post );
		}

		/**
		 * @action contentsync_after_import_global_posts
		 */
		do_action( 'contentsync_after_import_global_posts', $this->posts, $this->arguments );

		// delete temporary files if they exist
		if ( isset( $this->arguments['zip_file'] ) && ! empty( $this->arguments['zip_file'] ) ) {
			$this->delete_tmp_files();
		}

		return true;
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
			'contentsync_import_default_arguments',
			array(
				/**
				 * @property array $conflict_actions Array of posts that already exist on the current blog.
				 *                                   Keyed by the same ID as in the @param $posts.
				 *                                   @property post_id: ID of the current post.
				 *                                   @property action: Action to be done (skip|replace|keep)
				 */
				'conflict_actions' => array(),
				/**
				 * @property string $zip_file        Path to imported ZIP archive.
				 */
				'zip_file'         => null,
			)
		);
	}

	/**
	 * Parse import arguments.
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
		 * @filter contentsync_import_arguments
		 *
		 * @param array $parsed_arguments The parsed arguments.
		 * @param array $arguments        The original arguments.
		 *
		 * @return array The filtered parsed arguments.
		 */
		return apply_filters( 'contentsync_import_arguments', $parsed_arguments, $arguments );
	}

	/**
	 * Insert a single post
	 *
	 * if $postarr['ID'] is set, the post gets updated. otherwise a new post is created.
	 *
	 * @param array         $postarr      All arguments to be set via wp_insert_post().
	 * @param Prepared_Post $post         Preparred post object.
	 *
	 * @return int|WP_Error         Post-ID on success. WP_Error on failure.
	 */
	private function create_post( $postarr, $post ) {

		Logger::add( '  - create post with the following attributes: ', $postarr );

		// normal post
		if ( $postarr['post_type'] !== 'attachment' ) {

			// insert post
			$new_post_id = wp_insert_post( $postarr, true, false );

			// error
			if ( is_wp_error( $new_post_id ) ) {
				Logger::add( 'Post could not be inserted: ' . $new_post_id->get_error_message() );
			}
			// success
			else {
				Logger::add( sprintf( "\r\nPost inserted with the ID '%s'", strval( $new_post_id ) ) );
			}
		}
		// attachment
		else {

			$media       = isset( $post->media ) ? (array) $post->media : null;
			$new_post_id = $this->insert_attachment( $postarr, $media );

			// error
			if ( is_wp_error( $new_post_id ) ) {
				Logger::add( 'Attachment could not be inserted: ' . $new_post_id->get_error_message() );
			}
			// success
			else {
				Logger::add( sprintf( "\r\nAttachment inserted with the ID '%s'", strval( $new_post_id ) ) );
			}
		}

		return $new_post_id;
	}

	/**
	 * Insert an attachment.
	 *
	 * @param array $postarr          All arguments to be set via wp_insert_post().
	 * @param array $media_file_info   Array with the media file info.
	 *     @property string name           Post name (slug) of the media file.
	 *     @property string path           DIR path of the media file.
	 *     @property string url            URL to the media file.
	 *     @property string relative_path  Relative path to the wp upload basedir.
	 *
	 * @return int|WP_Error              Post-ID on success. WP_Error on failure.
	 */
	private function insert_attachment( $postarr, $media_file_info ) {

		if (
			! $media_file_info
			|| ! is_array( $media_file_info )
			|| ! isset( $media_file_info['name'] )
			|| ! isset( $media_file_info['url'] )
		) {
			return new WP_Error( 'media_file_info', 'Media file info is missing.' );
		}

		$filename = $media_file_info['name'];

		// get the file from the zip
		if ( isset( $this->arguments['zip_file'] ) && ! empty( $this->arguments['zip_file'] ) ) {
			$file_data = $this->get_media_file_contents( $this->arguments['zip_file'], $filename );
		}
		// get the file from the remote url
		elseif ( isset( $media_file_info['url'] ) && ! empty( $media_file_info['url'] ) ) {
			$file_data = Files::get_remote_file_contents( $media_file_info['url'] );
		}

		// get the post date
		if ( isset( $postarr['post_date_gmt'] ) ) {
			$post_date = $postarr['post_date_gmt'];
		} elseif ( isset( $postarr['post_date'] ) ) {
			$post_date = $postarr['post_date'];
		} else {
			$post_date = wp_date( 'Y-m-d H:i:s', time() );
		}

		// create the upload folder
		$time_folder = date( 'Y\/m', strtotime( $post_date ) );
		$upload_dir  = wp_upload_dir( $time_folder, true, true );
		$path        = wp_mkdir_p( $upload_dir['path'] ) ? $upload_dir['path'] : $upload_dir['basedir'];
		$file        = $path . '/' . $filename;

		if ( ! $file ) {
			return new WP_Error( 'file', 'Attachment file path could not be created.' );
		}

		// delete old files if attachment is being replaced
		if ( isset( $postarr['ID'] ) ) {
			Logger::add( '  - delete old attachment files.' );

			$result = $this->delete_current_attachment_files( $postarr['ID'], $file );

			if ( ! $result ) {
				Logger::add( '  - old attachment files could not be deleted.' );
			} else {
				Logger::add( '  - old attachment files deleted.' );
			}
		}

		// upload the file
		$bytes = file_put_contents( $file, $file_data );
		if ( $bytes === false ) {
			return new WP_Error( 'file', 'Attachment file ' . $file . ' could not be written.' );
		}

		Logger::add( "  - attachment file '$file' written (size: {$bytes}b)." );

		// add mime type
		$postarr['post_mime_type'] = wp_check_filetype( $filename, null )['type'];

		// insert post
		$new_post_id = wp_insert_attachment( $postarr, $file );

		if ( is_wp_error( $new_post_id ) ) {
			return $new_post_id;
		}

		Logger::add( '  - regenerate attachment meta data.' );

		// regenerate attachment metadata
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_data = wp_generate_attachment_metadata( $new_post_id, $file );
		$result      = wp_update_attachment_metadata( $new_post_id, $attach_data );
		if ( $result === false ) {
			return new WP_Error( 'meta', 'Attachment meta data could not be updated.' );
		}

		// add all media file path to the replace strings array
		$this->add_attachment_files_to_replace_strings( $media_file_info, $file );

		return $new_post_id;
	}

	/**
	 * Delete all current attachment files.
	 *
	 * @see EnableMediaReplace\Replacer->removeCurrent()
	 * @link https://github.com/short-pixel-optimizer/enable-media-replace/blob/master/classes/replacer.php
	 *
	 * @param int    $post_id           The attachment Post ID.
	 * @param string $new_file_path     URL to the new file (does not exist yet).
	 *
	 * @return bool
	 */
	private function delete_current_attachment_files( $post_id, $new_file_path ) {

		$old_file_path = get_attached_file( $post_id );
		$meta          = wp_get_attachment_metadata( $post_id );
		$backup_sizes  = get_post_meta( $post_id, '_wp_attachment_backup_sizes', true );
		$result        = wp_delete_attachment_files( $post_id, $meta, $backup_sizes, $old_file_path );

		// @todo replace occurences of the new file path on the entire website
		if ( $new_file_path !== $old_file_path ) {

		}

		return $result;
	}

	/**
	 * Add the attachment filepaths to the replace strings array.
	 *
	 * @param array  $media_file_info       The media file info array.
	 *     @property string name           Name of the media file (eg. 'my-image.jpg').
	 *     @property string path           DIR path of the media file (eg. '/htdocs/www/public/wp-content/uploads/sites/9/2025/10/my-image.jpg').
	 *     @property string url            URL to the media file (eg. 'https://jakobtrost.de/wp-content/uploads/sites/9/2025/10/my-image.jpg').
	 *     @property string relative_path  Relative path to the wp upload basedir (eg. '/2025/10/my-image.jpg').
	 * @param string $new_file             The entire path to the new file (eg. '/other-server/wp-content/uploads/my-image.jpg').
	 */
	private function add_attachment_files_to_replace_strings( $media_file_info, $new_file ) {

		$new_relative_path = str_replace( wp_upload_dir( null, true, true )['basedir'], '', $new_file );

		if ( is_object( $media_file_info ) ) {
			$media_file_info = (array) $media_file_info;
		}

		/**
		 *
		 * We need to use this path to replace the relative part of the old url with the new
		 * relative path of the uploaded file here, as the upload directory and the option
		 * 'uploads_use_yearmonth_folders' were probably different when the file was exported.
		 */
		if ( isset( $media_file_info['relative_path'] ) ) {
			$old_relative_path = $media_file_info['relative_path'];
		} elseif ( isset( $media_file_info['path'] ) ) {
			/**
			 * If the 'relative_path' is not present (eg. old export did not contain this information),
			 * we need to evaluate the 'path' of the old file and try to extract the relative path to
			 * the former wp upload basedir. Examples:
			 *     Default:
			 *         '/some-server-we-dont-know/wp-content/uploads/2025/10/my-image.jpg'
			 *         '/some-server-we-dont-know/wp-content/uploads/sites/9/2025/10/my-image.jpg' (multisite)
			 *             → '/2025/10/my-image.jpg'
			 *     Option 'uploads_use_yearmonth_folders' set to false:
			 *         '/some-server-we-dont-know/wp-content/uploads/my-image.jpg'
			 *         '/some-server-we-dont-know/wp-content/uploads/sites/9/my-image.jpg' (multisite)
			 *             → '/my-image.jpg'
			 */
			$old_relative_path = $media_file_info['path'];

			// first we check if the path contains '/wp-content'
			if ( strpos( $old_relative_path, '/wp-content' ) !== false ) {
				$old_relative_path = explode( '/wp-content', $old_relative_path )[1];
			}
			// then we check if the path contains '/uploads'
			if ( strpos( $old_relative_path, '/uploads' ) !== false ) {
				$old_relative_path = explode( '/uploads', $old_relative_path )[1];
			}
			// then we check if the path contains '/sites/' + a numeric value (old blog ID)
			if ( strpos( $old_relative_path, '/sites' ) !== false ) {
				$regex = '/\/sites\/(\d+)\//';
				preg_match( $regex, $old_relative_path, $matches );
				if ( isset( $matches[1] ) ) {
					$old_relative_path = '/' . explode( '/sites/' . $matches[1] . '/', $old_relative_path )[1];
				}
			}
		} else {
			Logger::add( '  - no relative path found for the old file.', $media_file_info );
			return false;
		}

		// remove filetype from the paths
		$old_file_path = preg_replace( '/\.[^.]+$/', '', $old_relative_path );
		$new_file_path = preg_replace( '/\.[^.]+$/', '', $new_relative_path );

		$this->collected_replace_strings[ $old_file_path ] = $new_file_path;

		// replace suffix '-scaled' from the old file path
		$old_file_path = str_replace( '-scaled', '', $old_file_path );

		$this->collected_replace_strings[ $old_file_path ] = $new_file_path;

		return true;
	}

	/**
	 * Replace all nested posts inside a subject, often the post content.
	 *
	 * @param  string $subject  The subject were the strings need to be replaced.
	 * @param  object $post     Preparred post object with the @property 'nested'.
	 *
	 * @return string $content  Content with all nested elements replaced.
	 */
	private function replace_nested_posts( $subject, $post ) {

		// get $post->nested
		$nested = isset( $post->nested ) ? ( is_array( $post->nested ) || is_object( $post->nested ) ? (array) $post->nested : null ) : null;

		if (
			empty( $nested ) ||
			empty( $subject )
		) {
			Logger::add( sprintf( "No nested elements found for post '%s'.", $post->post_name ) );
			return $subject;
		}

		Logger::add( sprintf( "Replace nested elements for post '%s'.", $post->post_name ) );

		foreach ( $nested as $nested_id => $nested_postarr ) {

			// cast to array
			$nested_postarr = ! is_array( $nested_postarr ) ? (array) $nested_postarr : $nested_postarr;

			$replace_string = $this->get_nested_post_replacement( $nested_id, $nested_postarr );

			// replace the string
			$marked_string = '{{' . $nested_id . '}}';
			$subject       = str_replace( $marked_string, $replace_string, $subject );

			// replace the front url if post ID was found
			if ( is_numeric( $replace_string ) ) {

				$replace_front_url = $nested_postarr['post_type'] === 'attachment' ? wp_get_attachment_url( $replace_string ) : get_permalink( $replace_string );

				// replace '{{' . $nested_id . '-front-url}}'
				$subject = str_replace( '{{' . $nested_id . '-front-url}}', $replace_front_url, $subject );
			}

			Logger::add( sprintf( "  - replace '%s' with '%s'.", $marked_string, $replace_string ) );
		}

		Logger::add( '=> nested elements were replaced' );
		return $subject;
	}

	/**
	 * Get the replacement for the original ID.
	 *
	 * This function looks for an imported or existing post by ID,
	 * name and posttype. If nothing is found, either the original
	 * ID, post_name or frontend url (for attachments) are returned.
	 */
	private function get_nested_post_replacement( $nested_id, $nested_postarr ) {

		// post imported: use the imported post-ID
		if ( isset( $this->posts[ $nested_id ] ) ) {
			$replace_string = $this->posts[ $nested_id ]->ID;
		}
		// no postarr set: use the initial post-ID
		elseif ( ! $nested_postarr || ! is_array( $nested_postarr ) ) {
			$replace_string = $nested_id;
		}
		// post exists: use existing post-ID
		elseif ( $existing_post = Post_Transfer_Service::get_post_by_name_and_type( (object) $nested_postarr ) ) {
			$replace_string = $existing_post->ID;
		}
		// attachments: use the frontend url
		elseif ( $nested_postarr['post_type'] === 'attachment' ) {
			$replace_string = $nested_postarr['front_url'];
		}
		// fallback: use the name
		else {
			$replace_string = $nested_postarr['post_name'];
		}
		return $replace_string;
	}

	/**
	 * Replace all nested terms inside a subject, often the post content.
	 *
	 * @param  string $subject  The subject were the strings need to be replaced.
	 * @param  object $post     Preparred post object with the @property 'nested'.
	 *
	 * @return string $content  Content with all nested elements replaced.
	 */
	private function replace_nested_terms( $subject, $post ) {

		// get $post->nested_terms
		$nested = isset( $post->nested_terms ) ? ( is_array( $post->nested_terms ) || is_object( $post->nested_terms ) ? (array) $post->nested_terms : null ) : null;

		if (
			empty( $nested ) ||
			empty( $subject )
		) {
			Logger::add( sprintf( "No nested elements found for post '%s'.", $post->post_name ) );
			return $subject;
		}

		Logger::add( sprintf( "Replace nested terms for post '%s'.", $post->post_name ) );

		foreach ( $nested as $nested_id => $nested_term_object ) {

			if ( empty( $nested_term_object ) ) {
				Logger::add( '  - term object is not set correctly.', $nested_term_object );
				continue;
			}

			if ( ! is_object( $nested_term_object ) ) {
				$nested_term_object = (object) $nested_term_object;
			}

			if ( ! isset( $nested_term_object->taxonomy ) || ! isset( $nested_term_object->slug ) ) {
				Logger::add( '  - term object is not set correctly.', $nested_term_object );
				continue;
			}

			$term_object = get_term_by( 'slug', $nested_term_object->slug, $nested_term_object->taxonomy );

			if ( $term_object ) {
				$replace_string = $term_object->term_id;
				Logger::add( "  - term of taxonomy '{$nested_term_object->taxonomy}' with slug '{$nested_term_object->slug}' found.", $term_object );
			} else {

				$new_term_ids = $this->insert_taxonomy_terms( $nested_term_object->taxonomy, array( $nested_term_object ) );
				if ( ! empty( $new_term_ids ) ) {
					$replace_string = $new_term_ids[0];
					Logger::add( "  - term of taxonomy '{$nested_term_object->taxonomy}' with slug '{$nested_term_object->slug}' inserted.", $new_term_ids );
				} else {
					$replace_string = $nested_id;
					Logger::add( "  - term of taxonomy '{$nested_term_object->taxonomy}' with slug '{$nested_term_object->slug}' could not be found." );
				}
			}

			// replace the string
			$marked_string = '{{' . $nested_id . '}}';
			if ( strpos( $subject, '{{t_' . $nested_id . '}}' ) !== false ) {
				$marked_string = '{{t_' . $nested_id . '}}';
			}
			$subject = str_replace( $marked_string, $replace_string, $subject );

			Logger::add( sprintf( "  - replace '%s' with '%s'.", $marked_string, $replace_string ) );
		}

		Logger::add( '=> nested elements were replaced' );
		return $subject;
	}

	/**
	 * Replace strings in subject
	 *
	 * @param string $subject
	 * @param int    $post_id
	 *
	 * @return string $subject
	 */
	private function replace_strings( $subject, $post_id, $log = true ) {

		if ( empty( $subject ) ) {
			return $subject;
		}

		if ( $log ) {
			Logger::add( 'Replace strings.' );
		}

		// get patterns
		$replace_strings = (array) Nested_Content_Patterns::get_string_patterns( $subject, $post_id );
		foreach ( $replace_strings as $name => $string ) {
			$subject = str_replace( '{{' . $name . '}}', $string, $subject );
			if ( $log ) {
				Logger::add( sprintf( "  - '%s' was replaced with '%s'.", $name, $string ) );
			}
		}

		if ( ! empty( $this->collected_replace_strings ) ) {
			foreach ( $this->collected_replace_strings as $original => $new ) {
				if ( strpos( $subject, $original ) !== false ) {
					$subject = str_replace( $original, $new, $subject );
					if ( $log ) {
						Logger::add( sprintf( "  - '%s' was replaced with '%s'.", $original, $new ) );
					}
				} elseif ( $log ) {
						Logger::add( sprintf( "  - '%s' was not found.", $original ) );
				}
			}
		}

		if ( $log ) {
			Logger::add( '=> strings were replaced' );
		}

		return $subject;
	}

	/**
	 * Given an array of meta, set meta to another post.
	 *
	 * @param int           $post_id      Post ID.
	 * @param array         $meta         Array of meta as key => value.
	 * @param Prepared_Post $post         The prepared post object (optional).
	 */
	private function set_meta( $post_id, $meta, $post = null ) {
		Logger::add( 'Set post meta.' );

		$existing_meta = (array) get_post_meta( $post_id );

		foreach ( (array) $meta as $meta_key => $meta_values ) {

			// don't import blacklisted meta
			if ( in_array( $meta_key, Post_Meta_Hooks::get_blacklisted_meta_for_export( 'import', $post_id ), true ) ) {
				continue;
			}
			// skip certain meta keys
			elseif ( Post_Meta_Hooks::maybe_skip_meta_option( $meta_key, $meta_values, 'import', $post_id ) ) {
				continue;
			}

			foreach ( (array) $meta_values as $meta_placement => $meta_value ) {
				$has_prev_value = (
						isset( $existing_meta[ $meta_key ] )
						&& is_array( $existing_meta[ $meta_key ] )
						&& array_key_exists( $meta_placement, $existing_meta[ $meta_key ] )
					) ? true : false;
				if ( $has_prev_value ) {
					$prev_value = maybe_unserialize( $existing_meta[ $meta_key ][ $meta_placement ] );
				}

				if ( ! is_array( $meta_value ) ) {
					$meta_value = maybe_unserialize( $meta_value );
				}

				/**
				 * Filter to modify specific post meta values before import.
				 *
				 * This filter allows developers to customize individual post meta values
				 * during import. The filter name is dynamic based on the meta key,
				 * allowing for targeted modifications of specific meta fields.
				 *
				 * @filter import_synced_post_meta-{{meta_key}}
				 *
				 * @param mixed $meta_value     The meta value to be imported.
				 * @param int   $post_id        The ID of the post being imported.
				 * @param Prepared_Post $post  The original Prepared_Post object.
				 *
				 * @return mixed             The modified meta value for import.
				 */
				$meta_value = apply_filters( 'import_synced_post_meta-' . $meta_key, $meta_value, $post_id, $post );

				if ( $has_prev_value ) {
					update_post_meta( $post_id, $meta_key, $meta_value, $prev_value );
				} else {
					add_post_meta( $post_id, $meta_key, $meta_value );
				}
			}
		}
		Logger::add( '=> post meta set' );
	}

	/**
	 * Given an array of terms by taxonomy, set those terms to another post. This function will cleverly merge
	 * terms into the post and create terms that don't exist.
	 *
	 * @param int    $post_id        Post ID.
	 * @param array  $taxonomy_terms Array with taxonomy as key and array of terms as values.
	 * @param object $post          The prepared post object.
	 */
	private function set_taxonomy_terms( $post_id, $taxonomy_terms, $post ) {
		Logger::add( 'Set taxonomy terms.' );

		/**
		 * If the post is a dynamic taxonomy, insert the terms without assigning them to the post.
		 */
		if (
			property_exists( $post, 'meta' )
			&& is_array( $post->meta )
			&& isset( $post->meta['posttype_settings'][0] )
			&& isset( $post->meta['posttype_settings'][0]['is_taxonomy'] )
		) {

			$taxonomy_name = sanitize_title( $post->meta['posttype_settings'][0]['slug'] );

			// we need to register the taxonomy first
			if ( ! taxonomy_exists( $taxonomy_name ) ) {
				$post_types = isset( $post->meta['posttype_settings'][0]['posttypes'] ) ? array_values( $post->meta['posttype_settings'][0]['posttypes'] ) : 'post';
				register_taxonomy( $taxonomy_name, $post_types );
			}

			$new_term_ids = $this->insert_taxonomy_terms( $taxonomy_name, $taxonomy_terms );
		} else {
			foreach ( (array) $taxonomy_terms as $taxonomy => $terms ) {

				/**
				 * Skip this taxonomy during import.
				 *
				 * @filter contentsync_import_skip_taxonomy
				 *
				 * @param bool $skip            Whether to skip the taxonomy.
				 * @param string $taxonomy      The taxonomy to skip.
				 * @param array $terms          The terms to skip.
				 * @param Prepared_Post $post  The Prepared_Post object.
				 *
				 * @return bool Whether to skip the taxonomy.
				 */
				$skip_this_taxonomy = apply_filters( 'contentsync_import_skip_taxonomy', false, $taxonomy, $terms, $post );
				if ( $skip_this_taxonomy ) {
					Logger::add( "  - taxonomy '{$taxonomy}' is skipped." );
					continue;
				}

				/**
				 * Filter the terms to be inserted before inserting them.
				 *
				 * @filter contentsync_import_terms_before_insert
				 *
				 * @param array $terms          The terms to be inserted.
				 * @param string $taxonomy      The taxonomy.
				 * @param Prepared_Post $post  The Prepared_Post object.
				 *
				 * @return array The filtered terms.
				 */
				$terms = apply_filters( 'contentsync_import_terms_before_insert', $terms, $taxonomy, $post );

				$term_ids = $this->insert_taxonomy_terms( $taxonomy, $terms, $post->post_type );

				// set term ids
				$new_term_ids = wp_set_object_terms( $post_id, $term_ids, $taxonomy );

				if ( is_wp_error( $new_term_ids ) ) {
					Logger::add( "  - term ids of taxonomy '$taxonomy' could not be set to post: " . $new_term_ids->get_error_message() );
				} else {
					Logger::add( "  - term ids '" . implode( ', ', $new_term_ids ) . "' of taxonomy '$taxonomy' set to post." );
				}
			}
		}

		Logger::add( isset( $new_term_ids ) ? '=> all taxonomy terms set' : '=> no taxonomy terms' );
	}

	/**
	 * Insert all terms of a taxonomy.
	 *
	 * @param string $taxonomy          The taxonomy slug.
	 * @param array  $terms              The terms array.
	 * @param string $post_type         The post type.
	 *
	 * @return array|false $term_ids    The term IDs of the inserted terms.
	 */
	private function insert_taxonomy_terms( string $taxonomy, $terms, $post_type = null ) {

		// make sure taxonomy exists
		if ( ! taxonomy_exists( $taxonomy ) ) {
			Logger::add( "  - taxonomy '{$taxonomy}' doesn't exist." );
			return false;
		}

		$term_ids        = array();
		$term_id_mapping = array();

		foreach ( (array) $terms as $term_array ) {
			if ( ! is_array( $term_array ) ) {
				$term_array = (array) $term_array;
			}

			if ( ! isset( $term_array['slug'] ) ) {
				continue;
			}

			/**
			 * Skip this term during import.
			 *
			 * @filter contentsync_import_skip_term
			 *
			 * @param bool $skip         Whether to skip the term.
			 * @param array $term_array  The term array.
			 * @param string $taxonomy   The taxonomy.
			 * @param string $post_type  The post type.
			 *
			 * @return bool Whether to skip the term.
			 */
			$skip_this_term = apply_filters( 'contentsync_import_skip_term', false, $term_array, $taxonomy, $post_type );
			if ( $skip_this_term ) {
				Logger::add( "  - term '{$term_array['name']}' of taxonomy '$taxonomy' is skipped." );
				continue;
			}

			if ( $term_array['name'] == '{{theme}}' ) {
				$term_array['name'] = get_stylesheet();
			}
			if ( $term_array['slug'] == '{{theme}}' ) {
				$term_array['slug'] = get_stylesheet();
			}

			$term = get_term_by( 'slug', $term_array['slug'], $taxonomy );

			if ( empty( $term ) ) {

				$term = wp_insert_term(
					$term_array['name'],
					$taxonomy,
					array(
						'slug'        => $term_array['slug'],
						'description' => isset( $term_array['description'] ) ? $term_array['description'] : '',
					)
				);

				if ( is_wp_error( $term ) ) {
					Logger::add( "    - term '{$term_array['name']}' of taxonomy '$taxonomy' could not be inserted: " . $term->get_error_message() );
				} else {
					$term_id_mapping[ $term_array['term_id'] ] = $term['term_id'];
					$term_ids[]                                = $term['term_id'];
					Logger::add( "    - term '{$term_array['name']}' of taxonomy '$taxonomy' inserted with id '{$term['term_id']}'." );
				}
			} else {
				$term_id_mapping[ $term_array['term_id'] ] = $term->term_id;
				$term_ids[]                                = $term->term_id;
				Logger::add( "    - term '{$term_array['name']}' of taxonomy '$taxonomy' found with id {$term->term_id}." );
			}
		}

		// parents
		foreach ( (array) $terms as $term_array ) {
			if ( ! is_array( $term_array ) ) {
				$term_array = (array) $term_array;
			}

			/**
			 * Skip this term during import.
			 *
			 * @filter contentsync_import_skip_term
			 *
			 * @param bool $skip         Whether to skip the term.
			 * @param array $term_array  The term array.
			 * @param string $taxonomy   The taxonomy.
			 * @param string $post_type  The post type.
			 *
			 * @return bool Whether to skip the term.
			 */
			$skip_this_term = apply_filters( 'contentsync_import_skip_term', false, $term_array, $taxonomy, $post_type );
			if ( $skip_this_term ) {
				Logger::add( "  - term '{$term_array['name']}' of taxonomy '$taxonomy' is skipped." );
				continue;
			}

			$parent = '';

			if ( isset( $term_array['parent'] ) && is_array( $term_array['parent'] ) ) {
				$parent = $this->set_taxonomy_term_parents( $taxonomy, $term_array['parent'], $term_id_mapping );
			} elseif ( isset( $term_array['parent'] ) && is_object( $term_array['parent'] ) ) {
				$parent = $this->set_taxonomy_term_parents( $taxonomy, (array) $term_array['parent'], $term_id_mapping );
			} elseif (
				isset( $term_array['parent'] )
				&& ! empty( $term_array['parent'] )
				&& ( is_string( $term_array['parent'] ) || is_numeric( $term_array['parent'] ) )
				&& isset( $term_id_mapping[ $term_array['parent'] ] )
			) {
				$parent = $term_id_mapping[ $term_array['parent'] ];
			}

			$term = wp_update_term(
				$term_id_mapping[ $term_array['term_id'] ],
				$taxonomy,
				array(
					'parent' => $parent,
				)
			);
		}

		return empty( $term_ids ) ? false : $term_ids;
	}

	/**
	 * Set a terms parent term.
	 * On post-export, the parents term object is nested into the original term object.
	 * On import, the nested parents get resolved recursively, created if not existent, but not assigned to the post.
	 * That way, a term hierarchy is preserved even if the post does not have all terms of the hierarchy assigend.
	 *
	 * @param string $taxonomy      The terms taxonomy.
	 * @param object $terms_array   The terms array (serialized WP_Term object)
	 * @param array  $prepared       All already set term IDs.
	 *
	 * @return int|empty $term_id   The parents term ID.
	 */
	private function set_taxonomy_term_parents( $taxonomy, $term_array, $prepared ) {

		// get parent term
		$term_id = '';
		$term    = get_term_by( 'slug', $term_array['slug'], $taxonomy );
		if ( empty( $term ) ) {
			// make parent term
			$term = wp_insert_term(
				$term_array['name'],
				$taxonomy,
				array(
					'slug'        => $term_array['slug'],
					'description' => isset( $term_array['description'] ) ? $term_array['description'] : '',
				)
			);
			if ( is_wp_error( $term ) ) {
				Logger::add( "      - parent term '{$term_array['name']}' of taxonomy '$taxonomy' could not be inserted: " . $term->get_error_message() );
			} else {
				$term_id = $term['term_id'];
				Logger::add( "      - parent term '{$term_array['name']}' of taxonomy '$taxonomy' inserted with id '{$term['term_id']}'." );
			}
		} else {
			$term_id = $term->term_id;
			Logger::add( "      - parent term '{$term_array['name']}' of taxonomy '$taxonomy' found with id {$term->term_id}." );
		}

		// set parent
		if ( ! empty( $term_id ) ) {

			$parent = '';

			if ( is_array( $term_array['parent'] ) ) {
				$parent = $this->set_taxonomy_term_parents( $taxonomy, $term_array['parent'], $prepared );
			} elseif ( is_object( $term_array['parent'] ) ) {
				$parent = $this->set_taxonomy_term_parents( $taxonomy, (array) $term_array['parent'], $prepared );
			} elseif (
				! empty( $term_array['parent'] )
				&& ( is_string( $term_array['parent'] ) || is_numeric( $term_array['parent'] ) )
				&& isset( $prepared[ $term_array['parent'] ] )
			) {
				$parent = $prepared[ $term_array['parent'] ];
			}

			$term = wp_update_term(
				$term_id,
				$taxonomy,
				array(
					'parent' => $parent,
				)
			);
		}

		return $term_id;
	}

	/**
	 * Set the language of a post and link it to it's source post if possible.
	 *
	 * @param int           $post_id      Post ID on this stage.
	 * @param Prepared_Post $post         Old Prepared_Post object (Post ID might differ).
	 *
	 * @return bool
	 */
	private function set_translations( $post_id, $post ) {
		// Validate that we have language data
		if ( ! isset( $post->language ) || empty( $post->language ) ) {
			return false;
		}

		// Delegate everything to Translation_Manager
		// It handles:
		// - Validation of language data structure
		// - Tool compatibility checks (Polylang -> WPML)
		// - Mapping original IDs to new IDs via $this->posts
		// - WPML trid lookups
		// - Polylang translation group setup
		// - Tool-specific translation setting
		return Translation_Manager::set_translations_from_import(
			$post_id,
			$post->language,
			$this->post_id_map
		);
	}

	/**
	 * Set the post hierarchy.
	 *
	 * During export, we collected data about the previous post hierarchy. This contains
	 * information about the parent post (if any) and all child posts (if any are found).
	 * This information is used now to try to restore the same hierarchy based on the posts
	 * that exist on the destination site or are part of the posts that are being imported.
	 *
	 * @see Prepared_Post::prepare_post_hierarchy()
	 *
	 * @param int           $post_id      Post ID on this stage.
	 * @param Prepared_Post $post         Old Prepared_Post object (Post ID might differ).
	 *
	 * @return void
	 */
	private function set_post_hierarchy( $post_id, $post ) {
		Logger::add( 'Set post hierarchy.' );

		/**
		 * @see Prepared_Post->post_hierarchy property.
		 *
		 * @var array $post->post_hierarchy Information about the post hierarchy.
		 *    @property array $parent       Information about the parent post.
		 *        @property int $id         The parent post ID.
		 *        @property string $name    The parent post name (slug).
		 *        @property string $type    The parent post type.
		 *    @property array[] $children   Information about the child posts (array of arrays).
		 *        @property int $id         The child post ID.
		 *        @property string $name    The child post name (slug).
		 *        @property string $type    The child post type.
		 */
		if ( isset( $post->post_hierarchy ) && ! empty( $post->post_hierarchy ) ) {

			if ( ! is_array( $post->post_hierarchy ) ) {
				$post->post_hierarchy = (array) $post->post_hierarchy;
			}

			// set post parent
			if ( isset( $post->post_hierarchy['parent'] ) && ! empty( $post->post_hierarchy['parent'] ) ) {

				$post_parent_info = $post->post_hierarchy['parent'];

				if ( ! is_array( $post_parent_info ) ) {
					$post_parent_info = (array) $post_parent_info;
				}

				Logger::add( '  - post parent information:', $post_parent_info );

				if ( isset( $post_parent_info['id'] ) && ! empty( $post_parent_info['id'] ) ) {

					$new_parent_post_id = 0;

					// if the parent post is part of the posts that are being imported, use the new post ID
					if ( isset( $this->posts[ $post_parent_info['id'] ] ) ) {
						$new_parent_post_id = $this->posts[ $post_parent_info['id'] ]->ID;
					} else {
						$parent_posts = get_posts(
							array(
								'post_type'   => $post_parent_info['type'],
								'name'        => $post_parent_info['name'],
								'post_status' => 'publish',
								'numberposts' => 1,
							)
						);
						if ( $parent_posts ) {
							$new_parent_post_id = $parent_posts[0]->ID;
							Logger::add(
								sprintf(
									"  - parent post with ID %s found by name '%s' and type '%s'",
									$new_parent_post_id,
									$post_parent_info['name'],
									$post_parent_info['type']
								)
							);
						}
					}

					if ( $new_parent_post_id ) {
						$result = wp_update_post(
							array(
								'ID'          => $post_id,
								'post_parent' => $new_parent_post_id,
							),
							true,
							false
						);
						if ( is_wp_error( $result ) ) {
							Logger::add( '  - post_parent could not be updated. Error: ' . $result->get_error_message() );
						} else {
							Logger::add( '  - post_parent successfully updated.' );
						}
					} else {
						Logger::add( sprintf( "  - post_parent '%s' could not be found.", $post_parent_info['id'] ) );
					}
				}
				Logger::add( '=> post parent set' );
			}

			// set post children
			if ( isset( $post->post_hierarchy['children'] ) && ! empty( $post->post_hierarchy['children'] ) ) {

				if ( ! is_array( $post->post_hierarchy['children'] ) ) {
					$post->post_hierarchy['children'] = (array) $post->post_hierarchy['children'];
				}

				foreach ( $post->post_hierarchy['children'] as $child_post_info ) {
					if ( ! is_array( $child_post_info ) ) {
						$child_post_info = (array) $child_post_info;
					}

					$new_child_post_id = 0;

					// if the child post is part of the posts that are being imported, use the new post ID
					if ( isset( $this->posts[ $child_post_info['id'] ] ) ) {
						$new_child_post_id = $this->posts[ $child_post_info['id'] ]->ID;
					} else {
						// if the child post is not part of the posts that are being imported, try to find it by name and type
						// we only look for posts that do not have a parent yet to not overwrite existing hierarchies.
						$child_posts = get_posts(
							array(
								'post_type'   => $child_post_info['type'],
								'name'        => $child_post_info['name'],
								'post_status' => 'publish',
								'numberposts' => 1,
								'post_parent' => 0, // if posts do already have a parent, they will be ignored
							)
						);
						if ( $child_posts ) {
							$new_child_post_id = $child_posts[0]->ID;
							Logger::add(
								sprintf(
									"  - child post with ID %s found by name '%s' and type '%s'",
									$new_child_post_id,
									$child_post_info['name'],
									$child_post_info['type']
								)
							);
						}
					}

					if ( $new_child_post_id ) {
						$result = wp_update_post(
							array(
								'ID'          => $new_child_post_id,
								'post_parent' => $post_id,
							),
							true,
							false
						);
						if ( is_wp_error( $result ) ) {
							Logger::add( '  - the child post could not be updated. Error: ' . $result->get_error_message() );
						} else {
							Logger::add( '  - the child post was successfully updated.' );
						}
					} else {
						Logger::add( sprintf( '  - child post could not be found.', $child_post_info['id'] ) );
					}
				}
				Logger::add( '=> post children set' );
			}
		}
		// set post_parent (old way)
		elseif ( isset( $post->post_parent ) && ! empty( $post->post_parent ) ) {

			$old_parent_post_id = isset( $this->posts[ $post->post_parent ] ) ? $this->posts[ $post->post_parent ]->ID : null;
			if ( $old_parent_post_id ) {
				$result = wp_update_post(
					array(
						'ID'          => $post_id,
						'post_parent' => $old_parent_post_id,
					),
					true,
					false
				);
				if ( is_wp_error( $result ) ) {
					Logger::add( '  - post_parent could not be updated.' );
				} else {
					Logger::add( '  - post_parent successfully updated.' );
				}
			} else {
				Logger::add( sprintf( "  - post_parent '%s' could not be found.", $post->post_parent ) );
			}
			Logger::add( '=> post parent set (old way)' );
		}
	}

	/**
	 * Get the file contents of media file inside imported zip archive
	 *
	 * @param string $filepath  Relative path to the zip including filename.
	 * @param string $medianame Name of the media file.
	 *
	 * @return mixed            String with error message on failure.
	 *                          Array of contents on success.
	 */
	private function get_media_file_contents( $filepath, $medianame ) {

		if ( ! file_exists( $filepath ) ) {
			__( 'The ZIP archive could not be found. It may have been moved or deleted.', 'contentsync' );
		} else {
			Logger::add( sprintf( '  - ZIP archive "%s" found.', $filepath ) );
		}

		// open 'posts.json' file inside zip archive
		$zip        = 'zip://' . $filepath . '#media/' . $medianame;
		$media_file = file_get_contents( $zip );

		if ( ! $media_file ) {
			return sprintf( __( "The file '%s' could not be found in the ZIP archive.", 'contentsync' ), 'media/' . $medianame );
		} else {
			Logger::add( sprintf( '  - file "%s" found.', 'posts.json' ) );
		}

		return $media_file;
	}

	/**
	 * Delete all temporary files for import
	 *
	 * usually called after a successfull import
	 */
	private function delete_tmp_files() {
		$path = Files::get_wp_content_folder_path( 'tmp' );
		$dir  = substr( $path, -1 ) === '/' ? substr( $path, 0, -1 ) : $path;

		foreach ( scandir( $dir ) as $item ) {
			if ( $item == '.' || $item == '..' ) {
				continue;
			}
			$file = $dir . DIRECTORY_SEPARATOR . $item;
			if ( ! is_dir( $file ) ) {
				unlink( $file );
			}
		}
		Logger::add( sprintf( "All files inside folder '%s' deleted.", $dir ) );
	}
}
