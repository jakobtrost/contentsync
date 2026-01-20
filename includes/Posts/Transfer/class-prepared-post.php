<?php

namespace Contentsync

use Contentsync\Translations\Translation_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class used to implement the Prepared_Post object.
 *
 * @since 2.17.0
 *
 * This class attaches all post meta options, taxonomy terms and
 * other post properties to a customized WP_Post object. It also gets
 * all nested templates, forms, images etc. inside the post content,
 * dynamic meta or set as thumbnail and prepares them for later
 * replacement.
 *
 * @see     WP_Post
 * @source  /wp-includes/class-wp-post.php
 */
#[AllowDynamicProperties]
class Prepared_Post {

	/**
	 * Post ID.
	 *
	 * @since 3.5.0
	 * @var int
	 */
	public $ID;

	/**
	 * The post's slug.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	public $post_name = '';

	/**
	 * The post's title.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	public $post_title = '';

	/**
	 * The post's type, like post or page.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	public $post_type = 'post';

	/**
	 * The post's local publication time.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	public $post_date = '0000-00-00 00:00:00';

	/**
	 * The post's GMT publication time.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	public $post_date_gmt = '0000-00-00 00:00:00';

	/**
	 * Post meta info of the post.
	 *
	 * @var array
	 */
	public $meta = array();

	/**
	 * Assigned terms of the post.
	 *
	 * @var array
	 */
	public $terms = array();

	/**
	 * Nested posts, keyed by ID.
	 *
	 * @var array[]
	 * Nested post arrays keyed by post_id:
	 *   {{post_id}} => array(
	 *     @property int    ID         ID of the nested post.
	 *     @property string post_name  Post name (slug) of the nested post.
	 *     @property string post_type  Post type of the nested post.
	 *     @property string front_url  Frontend URL of the nested post.
	 *     @property string file_path  (only for attachments)
	 *   )
	 */
	public $nested = array();

	/**
	 * Nested terms of the post.
	 *
	 * @var array
	 */
	public $nested_terms = array();

	/**
	 * Attached media file (only when the post is an attachment).
	 *
	 * @var array
	 *     @property string name           Post name (slug) of the media file (eg. 'my-image.jpg')
	 *     @property string path           DIR path of the media file (eg. '/htdocs/www/public/wp-content/uploads/sites/9/2025/10/my-image.jpg').
	 *     @property string url            URL to the media file (eg. 'https://jakobtrost.de/wp-content/uploads/sites/9/2025/10/my-image.jpg').
	 *     @property string relative_path  Relative path to the wp upload basedir (eg. '/2025/10/my-image.jpg'). @since 2.18.0
	 */
	public $media = array();

	/**
	 * Language information of the post.
	 *
	 * @var array
	 *     @property string code       The post's language code (eg. 'en')
	 *     @property string tool       The plugin used to setup the translation.
	 *     @property array  post_ids   All translated post IDs keyed by language code.
	 *     @property array  args       Additional arguments (depends on the tool)
	 */
	public $language = array();

	/**
	 * The arguments used to export the post.
	 *
	 * @since new
	 *
	 * This takes precedence over the default arguments, passed to
	 * the Class __construct() function: @param $export_arguments.
	 *
	 * @var array
	 *    @property bool  append_nested   Append nested posts to the export.
	 *    @property bool  whole_posttype  Export the whole post type.
	 *    @property bool  all_terms       Export all terms of the post.
	 *    @property bool  resolve_menus   Resolve navigation links to custom links.
	 *    @property bool  translations    Include translations of the post.
	 *    @property array query_args      Additional query arguments.
	 */
	public $export_arguments = array();

	/**
	 * Conflict action: What to do if a conflicting post already exists.
	 *
	 * @since new
	 *
	 * A conflicting post is a post with the same post_name and post_type.
	 *
	 * @var string 'keep|replace|skip'
	 *    @default 'keep'    Keep the existing post and insert the new one with a new ID.
	 *    @value   'replace' Replace the existing post with the new one.
	 *    @value   'skip'    Skip the post if a conflicting post already exists.
	 */
	public $conflict_action = 'keep';

	/**
	 * Import action: What to do with the post on/after import.
	 *
	 * @since new
	 *
	 * @var string 'insert|draft|trash|delete'
	 *    @default 'update'  Insert or update the post if it already exists.
	 *    @value   'draft'   Set the post to draft.
	 *    @value   'trash'   Move the post to trash.
	 *    @value   'delete'  Delete the post permanently.
	 */
	public $import_action = 'insert';

	/**
	 * Post hierarchy: The hierarchy of the post.
	 *
	 * @since 2.18.0
	 *
	 * @var array
	 *    @property array $parent      Information about the parent post.
	 *        @property int $id        The parent post ID.
	 *        @property string $name   The parent post name (slug).
	 *        @property string $type   The parent post type.
	 *    @property array[] $children  Information about the child posts (array of arrays).
	 *        @property int $id        The child post ID.
	 *        @property string $name   The child post name (slug).
	 *        @property string $type   The child post type.
	 */
	public $post_hierarchy = array();


	/**
	 * =================================================================
	 *                          Functions
	 * =================================================================
	 */

	/**
	 * Constructor.
	 *
	 * @param WP_Post|object|array|int $post  Post object or Post ID.
	 * @param array                    $export_arguments  Additional arguments.
	 */
	public function __construct( $post, $export_arguments = array() ) {

		// support array as input
		if ( is_array( $post ) ) {
			$post = (object) $post;
		}

		// support post_id as input
		if ( ! is_object( $post ) ) {
			$post = get_post( $post );
		}

		// not a post object
		if ( ! $post || ! is_object( $post ) || ! isset( $post->ID ) ) {
			return;
		}

		Logger::add( sprintf( "========= PREPARE POST %s '%s' =========", $post->ID, $post->post_title ) );

		// set base object vars from WP_Post
		foreach ( get_object_vars( $post ) as $key => $value ) {
			$this->$key = $value;
		}

		/**
		 * Parse arguments.
		 * The export_arguments defined within the post take precedence
		 * over the default arguments passed to the constructor.
		 *
		 * @property array $export_arguments
		 */
		$this->export_arguments = wp_parse_args( $this->export_arguments, $export_arguments );

		/**
		 * Prepare the custom post object properties.
		 */
		$this->prepare_nested_posts();
		$this->prepare_nested_terms();
		$this->prepare_strings();
		$this->prepare_meta();
		$this->prepare_terms();
		$this->prepare_media();
		$this->prepare_language();
		$this->prepare_menus();
		$this->prepare_post_hierarchy(); /** @since 2.18.0 */
	}

	/**
	 * Prepare nested posts in content.
	 */
	public function prepare_nested_posts() {

		Logger::add( 'Prepare nested posts.' );

		$nested_posts = array();

		if ( empty( $this->post_content ) ) {
			Logger::add( '=> post content is empty' );
			return;
		}

		// get regex patterns
		$replace_id_patterns = (array) get_nested_post_patterns( $this->ID, $this );

		foreach ( $replace_id_patterns as $key => $pattern ) {

			// doing it wrong
			if (
				! isset( $pattern['search'] ) ||
				! is_array( $pattern['search'] ) ||
				count( $pattern['search'] ) < 2 ||
				! isset( $pattern['replace'] ) ||
				! is_array( $pattern['replace'] )
			) {
				continue;
			}

			$match_regex = '/' . implode( '([\da-z\-\_]+?)', $pattern['search'] ) . '/';
			$regex_group = isset( $pattern['group'] ) ? (int) $pattern['group'] : 2;
			Logger::add( '  - test regex: ' . esc_attr( $match_regex ) );

			// search for all occurrences
			preg_match_all( $match_regex, $this->post_content, $matches );
			$found_posts = isset( $matches[ $regex_group ] ) ? $matches[ $regex_group ] : null;
			if ( ! empty( $found_posts ) ) {

				Logger::add( "  Replace '" . $key . "':" );
				foreach ( $found_posts as $name_or_id ) {

					$nested_post = null;
					$nested_id   = $name_or_id;

					// WP_Post->ID
					if ( is_numeric( $name_or_id ) ) {
						$nested_post = get_post( $name_or_id );
					}
					// WP_Post->post_name
					else {
						if ( isset( $pattern['post_type'] ) ) {
							$args = (object) array(
								'post_name' => $name_or_id,
								'post_type' => $pattern['post_type'],
							);
						} else {
							$args = $name_or_id;
						}
						// get post
						$nested_post = \Contentsync\get_post_by_name_and_type( $args );
						if ( $nested_post ) {
							$nested_id = $nested_post->ID;
						}
					}

					if ( ! $nested_post ) {
						Logger::add( "  - post with id or name '$name_or_id' could not be found." );
						continue;
					}

					$search_regex   = '/' . implode( $nested_id, $pattern['search'] ) . '/';
					$replace_string = implode( '{{' . $nested_id . '}}', $pattern['replace'] );

					// replace in post_content
					$this->post_content = preg_replace( $search_regex, $replace_string, $this->post_content );

					// collect in $nested_posts
					$nested_posts[ $nested_id ] = $nested_post;
				}
			}
		}

		/**
		 * advancedFilter
		 *
		 * @since 2.8.0
		 */
		preg_match_all( '/\"advancedFilter\":(\[.*\])/', $this->post_content, $matches );
		if ( $matches ) {
			// debug("advancedFilter");
			foreach ( $matches[1] as $match ) {
				$res = json_decode( $match );
				if ( $res ) {
					$changed = false;
					foreach ( $res as $i => $filter ) {
						if ( $filter->name == 'include' && ! empty( $filter->include ) ) {
							foreach ( $filter->include as $j => $post_id ) {
								if ( is_numeric( $post_id ) ) {
									$res[ $i ]->include[ $j ] = '{{' . $post_id . '}}';
									$nested_posts[ $post_id ] = get_post( $post_id );
									$changed                  = true;
								}
							}
						}
					}
					if ( $changed ) {
						$encoded            = str_replace( array( '"{{', '}}"' ), array( '{{', '}}' ), json_encode( $res ) );
						$this->post_content = str_replace( $match, $encoded, $this->post_content );
					}
				}
			}
		}

		// now collect the posts in $this->nested
		foreach ( $nested_posts as $nested_id => $nested_post ) {

			if ( isset( $this->nested[ $nested_id ] ) ) {
				continue;
			}

			if ( ! $nested_post ) {
				$this->nested[ $nested_id ] = null;
			}

			$this->nested[ $nested_id ] = array(
				'ID'        => $nested_id,
				'post_name' => $nested_post->post_name,
				'post_type' => $nested_post->post_type,
				'front_url' => $nested_post->post_type === 'attachment' ? wp_get_attachment_url( $nested_post->ID ) : get_permalink( $nested_id ),
			);
			if ( $nested_post->post_type === 'attachment' ) {
				// $this->nested[ $nested_id ]['file_path'] = get_attached_file( $nested_post->ID );
				// remove '-scaled' suffix (https://wp-kama.com/2284/the-scaled-suffix-for-images)
				$file_url                   = str_replace( '-scaled.', '.', wp_get_attachment_url( $nested_id ) );
				$file_path                  = str_replace( '-scaled.', '.', get_attached_file( $nested_id ) );
				$this->nested[ $nested_id ] = array(
					'ID'        => $nested_id,
					'post_name' => $nested_post->post_name,
					'post_type' => $nested_post->post_type,
					'front_url' => $file_url,
					'file_path' => $file_path,
				);

			} else {
				$this->nested[ $nested_id ] = array(
					'ID'        => $nested_id,
					'post_name' => $nested_post->post_name,
					'post_type' => $nested_post->post_type,
					'front_url' => get_permalink( $nested_id ),
				);

				// also replace the front url inside the post content
				$this->post_content = str_replace( $this->nested[ $nested_id ]['front_url'], '{{' . $nested_id . '-front-url}}', $this->post_content );
			}

			Logger::add(
				sprintf(
					"  - nested post '%s' attached for export.\r\n     * ID: %s\r\n     * TYPE: %s\r\n     * URL: %s",
					$nested_post->post_name,
					$nested_id,
					$nested_post->post_type,
					$this->nested[ $nested_id ]['front_url']
				)
			);
		}

		Logger::add( '=> nested elements were prepared' );
	}

	/**
	 * Prepare nested terms inside the post content.
	 */
	public function prepare_nested_terms() {
		Logger::add( 'Prepare nested terms.' );

		$nested_term_ids = array();

		if ( empty( $this->post_content ) ) {
			Logger::add( '=> post content is empty' );
			return;
		}

		// get patterns
		$replace_id_patterns = (array) get_nested_term_patterns( $this->ID, $this );

		foreach ( $replace_id_patterns as $key => $pattern ) {

			// doing it wrong
			if (
				! isset( $pattern['search'] ) ||
				! is_array( $pattern['search'] ) ||
				count( $pattern['search'] ) < 2 ||
				! isset( $pattern['replace'] ) ||
				! is_array( $pattern['replace'] )
			) {
				continue;
			}

			$match_regex = '/' . implode( '([\da-z\-\_]+?)', $pattern['search'] ) . '/';
			$regex_group = isset( $pattern['group'] ) ? (int) $pattern['group'] : 2;
			Logger::add( '  - test regex: ' . esc_attr( $match_regex ) );

			// search for all occurrences
			preg_match_all( $match_regex, $this->post_content, $matches );
			$found_terms = isset( $matches[ $regex_group ] ) ? $matches[ $regex_group ] : null;
			if ( ! empty( $found_terms ) ) {

				Logger::add( '  - replace ' . $key . ':' );
				foreach ( $found_terms as $term_id ) {

					// default value for term_ids
					if ( $term_id == 0 || $term_id == -1 ) {
						continue;
					}

					$search_regex   = '/' . implode( $term_id, $pattern['search'] ) . '/';
					$replace_string = implode( '{{t_' . $term_id . '}}', $pattern['replace'] );

					// replace in $this->post_content
					$this->post_content = preg_replace( $search_regex, $replace_string, $this->post_content );

					// collect in $nested_term_ids
					$nested_term_ids[] = $term_id;
				}
			}
		}

		/**
		 * taxQuery and advancedFilter
		 *
		 * @since 2.8.0
		 */
		preg_match_all( '/\"taxQuery\":(\{.*?\})/', $this->post_content, $matches );
		if ( $matches ) {
			// debug("taxQuery");
			foreach ( $matches[1] as $match ) {
				$res = json_decode( $match );
				if ( $res ) {
					$changed = false;
					foreach ( $res as $tax => $terms ) {
						foreach ( $terms as $i => $term_id ) {
							$res->{$tax}[ $i ] = '{{t_' . $term_id . '}}';
							$nested_term_ids[] = $term_id;
							$changed           = true;
						}
					}
					if ( $changed ) {
						$encoded            = str_replace( array( '"{{', '}}"' ), array( '{{', '}}' ), json_encode( $res ) );
						$this->post_content = str_replace( $match, $encoded, $this->post_content );
					}
				}
			}
		}
		preg_match_all( '/\"advancedFilter\":(\[.*\])/', $this->post_content, $matches );
		if ( $matches ) {
			// debug("advancedFilter");
			foreach ( $matches[1] as $match ) {
				$res = json_decode( $match );
				if ( $res ) {
					$changed = false;
					foreach ( $res as $i => $filter ) {
						if ( $filter->name == 'taxonomy' && ! empty( $filter->terms ) ) {
							foreach ( $filter->terms as $j => $term_id ) {
								if ( intval( $term_id ) == $term_id ) {
									$res[ $i ]->terms[ $j ] = '{{t_' . $term_id . '}}';
									$nested_term_ids[]      = $term_id;
									$changed                = true;
								}
							}
						}
					}
					if ( $changed ) {
						$encoded            = str_replace( array( '"{{', '}}"' ), array( '{{', '}}' ), json_encode( $res ) );
						$this->post_content = str_replace( $match, $encoded, $this->post_content );
					}
				}
			}
		}

		// collect in $this->nested_terms
		foreach ( array_unique( $nested_term_ids ) as $term_id ) {
			if ( isset( $this->nested_terms[ $term_id ] ) ) {
				continue;
			}

			$term_object = get_term( $term_id );
			if ( ! $term_object || is_wp_error( $term_object ) ) {
				Logger::add( "  - term with id '$term_id' could not be found." );
				$this->nested_terms[ $term_id ] = null;
			} else {
				Logger::add( "  - term with id '$term_id' found.", $term_object );
				$this->nested_terms[ $term_id ] = $term_object;
			}
		}

		Logger::add( '=> nested terms were prepared' );
	}

	/**
	 * Replace strings for export
	 */
	public function prepare_strings() {
		$this->post_content = $this->replace_dynamic_post_strings( $this->post_content, $this->ID );
	}

	/**
	 * Replace strings for export
	 *
	 * @param string $subject
	 * @param int    $post_id
	 *
	 * @return string $subject
	 */
	private function replace_dynamic_post_strings( $subject, $post_id ) {

		if ( empty( $subject ) ) {
			return $subject;
		}

		// get patterns
		$replace_strings = (array) get_nested_string_patterns( $subject, $post_id );
		foreach ( $replace_strings as $name => $string ) {
			$subject = str_replace( $string, '{{' . $name . '}}', $subject );
		}

		return $subject;
	}

	/**
	 * Prepare meta for consumption
	 */
	public function prepare_meta() {
		Logger::add( 'Prepare post meta.' );

		$meta = get_post_meta( $this->ID );

		// Transfer all meta
		foreach ( $meta as $meta_key => $meta_array ) {
			foreach ( $meta_array as $meta_value ) {

				// don't prepare blacklisted meta
				if ( in_array( $meta_key, \Contentsync\get_blacklisted_meta_for_export( 'export', $this->ID ), true ) ) {
					continue;
				}
				// skip certain meta keys
				elseif ( \Contentsync\maybe_skip_meta_option( $meta_key, $meta_value, 'export', $this->ID ) ) {
					continue;
				}

				$meta_value = maybe_unserialize( $meta_value );

				/**
				 * Filter to modify specific post meta values before export.
				 *
				 * This filter allows developers to customize individual post meta values
				 * during export. The filter name is dynamic based on the meta key,
				 * allowing for targeted modifications of specific meta fields.
				 *
				 * @filter contentsync_export_post_meta-{{meta_key}}
				 *
				 * @param mixed $meta_value      The meta value to be exported.
				 * @param int   $post_id        The ID of the post being exported.
				 * @param array $export_arguments The export arguments passed to the constructor.
				 *
				 * @return mixed                The modified meta value for export.
				 */
				$meta_value = apply_filters( 'contentsync_export_post_meta-' . $meta_key, $meta_value, $this->ID, $this->export_arguments );

				$this->meta[ $meta_key ][] = $meta_value;
			}
		}

		Logger::add( '=> post meta prepared' );
	}

	/**
	 * Prepare terms associated with the post.
	 */
	public function prepare_terms() {

		Logger::add( 'Prepare taxonomy terms.' );

		$posttype_meta = (array) get_post_meta( $this->ID, 'posttype_settings', true );
		$is_taxonomy   = $posttype_meta && isset( $posttype_meta['is_taxonomy'] ) && $posttype_meta['is_taxonomy'];
		$tax_slug      = $posttype_meta && isset( $posttype_meta['slug'] ) ? $posttype_meta['slug'] : '';

		/**
		 * If the post is a dynamic taxonomy, only prepare the associated terms.
		 */
		if ( $is_taxonomy ) {

			// get all terms of the taxonomy
			$terms = get_terms(
				array(
					'taxonomy'   => $tax_slug,
					'hide_empty' => false,
				)
			);

			foreach ( $terms as $term ) {
				if ( isset( $term->term_id ) ) {
					$this->terms[ $term->term_id ] = $term;
				}
			}

			return;
		}

		$prepared_terms = array();

		/**
		 * All other post types might have terms assigned to them.
		 */
		$taxonomies = get_object_taxonomies( $this );

		if ( empty( $taxonomies ) ) {
			Logger::add( '=> no taxonomy terms found' );
			return array();
		}

		/**
		 * Apply filters to the taxonomies.
		 *
		 * @filter synced_post_export_taxonomies_before_prepare
		 *
		 * @param array $taxonomies The taxonomies to be exported.
		 * @param int   $post_id    The ID of the post being exported.
		 * @param object $post     The Prepared_Post object.
		 *
		 * @return array The filtered taxonomies.
		 */
		$taxonomies = apply_filters( 'synced_post_export_taxonomies_before_prepare', $taxonomies, $this->ID, $this );

		foreach ( $taxonomies as $taxonomy ) {

			/**
			 * Retrieve post terms directly from the database.
			 *
			 * @since 1.2.7
			 *
			 * WPML attaches a lot of filters to the function wp_get_object_terms(). This results
			 * in terms of the wrong language beeing attached to a post export. This function performs
			 * way more consistent in all tests. Therefore it completely replaced it in this class.
			 *
			 * @deprecated since 1.2.7: $terms = wp_get_object_terms( $this->ID, $taxonomy );
			 */
			$terms = get_post_taxonomy_terms( $this->ID, $taxonomy );

			if ( empty( $terms ) ) {
				$prepared_terms[ $taxonomy ] = array();
				Logger::add( "  - no terms found for taxonomy '$taxonomy'." );
			} else {
				$count = count( $terms );
				Logger::add(
					sprintf(
						"  - %s %s of taxonomy '%s' prepared:\r\n    - %s",
						$count,
						$count > 1 ? 'terms' : 'term',
						$taxonomy,
						implode(
							"\r\n    - ",
							array_map(
								function ( $term ) {
									return "{$term->name} (#{$term->term_id})";
								},
								$terms
							)
						)
					)
				);
				/**
				 * Nest parent terms.
				 *
				 * @since 1.2.8
				 */
				$ids = array_map(
					function ( $term ) {
						return $term->term_id;
					},
					$terms
				);
				foreach ( $terms as $i => $term ) {
					if ( $term->name == get_stylesheet() ) {
						$term->name = '{{theme}}';
					}
					if ( $term->slug == get_stylesheet() ) {
						$term->slug = '{{theme}}';
					}
					$prepared_terms[ $taxonomy ][ $i ] = $this->get_term_parents( $term, $ids );
				}
			}
		}

		/**
		 * Filter to modify the terms after they have been prepared.
		 *
		 * @filter synced_post_export_terms_after_prepare
		 *
		 * @param array $terms The terms to be exported.
		 * @param int   $post_id The ID of the post being exported.
		 * @param object $post The Prepared_Post object.
		 *
		 * @return array The filtered terms.
		 */
		$this->terms = apply_filters( 'synced_post_export_terms_after_prepare', $prepared_terms, $this->ID, $this );

		Logger::add( '=> all taxonomy terms prepared' );
	}

	/**
	 * Get all parent terms by replacing the ID with the actual term object recursively.
	 *
	 * @param WP_Term $term     The term object.
	 * @param array   $prepared   All already prepared term IDs.
	 *
	 * @return WP_Term $term    The term object with nested parent term object(s)
	 */
	public function get_term_parents( $term, $prepared ) {

		if ( $term->parent != 0 && ! in_array( $term->parent, $prepared ) ) {
			Logger::add( "    - parent of '" . $term->term_id . "' found: '" . $term->parent . "'" );
			$parent = get_term( $term->parent );
			// Logger::add( json_encode( $parent ) );
			$term->parent = $this->get_term_parents( $parent, $prepared );
		}

		return $term;
	}

	/**
	 * Format media items for export
	 */
	public function prepare_media() {

		if ( $file_path = get_attached_file( $this->ID ) ) {

			$file_path = str_replace( '-scaled.', '.', $file_path );
			$file_name = wp_basename( $file_path );
			$file_url  = str_replace( '-scaled.', '.', wp_get_attachment_url( $this->ID ) );

			/**
			 * @since 2.18.0 Get the relative path to the uploads basedir.
			 * - default: /2025/10/my-image.jpg
			 * - option 'uploads_use_yearmonth_folders' set to false: /my-image.jpg
			 *
			 * We need to export this information to later replace the relative part of the url with the new
			 * relative path of the uploaded file on the destination site, as the upload directory and the option
			 * 'uploads_use_yearmonth_folders' might be different.
			 */
			$relative_path = str_replace( wp_upload_dir( null, true, true )['basedir'], '', $file_path );

			$this->media = array(
				'name'          => $file_name,     // my-image.jpg
				'url'           => $file_url,      // https://jakobtrost.de/wp-content/uploads/sites/9/2025/10/my-image.jpg
				'path'          => $file_path,     // /htdocs/www/public/wp-content/uploads/sites/9/2025/10/my-image.jpg
				/** @since 2.18.0 */
				'relative_path' => $relative_path, // /2025/10/my-image.jpg
			);

			Logger::add( sprintf( "The file '%s' was added to the post.", $file_name ) );
		}
	}

	/**
	 * Get all necessary language information.
	 *
	 * @since 2.19.0 Refactored to use Translation_Manager::prepare_post_language_data()
	 */
	public function prepare_language() {

		Logger::add( 'Prepare post language info.' );

		// Use Translation_Manager to prepare all language data in one call
		$this->language = Translation_Manager::prepare_post_language_data(
			$this,
			$this->export_arguments['translations']
		);

		Logger::add( '=> post language info prepared', $this->language );
	}


	/**
	 * Prepare menus by converting navigation blocks into custom links:
	 *
	 * Converts navigation blocks with post-type references to custom links:
	 * - wp:navigation-link
	 * - wp:navigation-submenu
	 * - And any other wp:navigation-* blocks
	 *
	 * From:
	 * <!-- wp:navigation-link {"label":"Sticky Navbar","type":"page","id":548,"url":"{{site_url}}/sticky-navbar/","kind":"post-type"} /-->
	 * <!-- wp:navigation-submenu {"label":"External Links","type":"page","id":3876,"url":"https://google.com/","kind":"post-type","className":""} -->
	 *
	 * To:
	 * <!-- wp:navigation-link {"label":"Sticky Navbar","url":"{{site_url}}/sticky-navbar/","kind":"custom"} /-->
	 * <!-- wp:navigation-submenu {"label":"External Links","url":"https://google.com/","kind":"custom","className":""} -->
	 */
	public function prepare_menus() {

		if (
			! isset( $this->export_arguments['resolve_menus'] ) ||
			! $this->export_arguments['resolve_menus']
		) {
			return;
		}

		Logger::add( 'Prepare nested menus.' );

		$subject = $this->post_content;

		// return if subject doesn't contain any navigation blocks
		if ( strpos( $subject, 'wp:navigation-' ) === false ) {
			Logger::add( '=> no navigation blocks found' );
			return $subject;
		}

		// loop through all navigation blocks (self-closing and opening tags)
		$subject = preg_replace_callback(
			'/<!-- wp:(navigation-[a-zA-Z-]+) (.*?) (\/-->|-->)/',
			function ( $matches ) {

				$block_name      = $matches[1]; // e.g., "navigation-link", "navigation-submenu", "navigation-mega-menu"
				$attributes_json = $matches[2];
				$closing         = $matches[3]; // "/-->" for self-closing, "-->" for opening tag

				// get the navigation block attributes
				$attributes = json_decode( $attributes_json, true );

				// if json decode failed or already a custom link, return the original string
				if ( ! is_array( $attributes ) || ! isset( $attributes['kind'] ) || $attributes['kind'] === 'custom' ) {
					return $matches[0];
				}

				// change kind into custom
				$attributes['kind'] = 'custom';

				// remove type & id
				unset( $attributes['type'] );
				unset( $attributes['id'] );

				// return the updated navigation block
				return '<!-- wp:' . $block_name . ' ' . json_encode( $attributes ) . ' ' . $closing;
			},
			$subject
		);

		Logger::add( '=> nested menus were resolved' );

		/**
		 * Filter to modify the post content after resolving navigation menus.
		 *
		 * This filter allows developers to customize post content after navigation
		 * links have been converted to static links during export. It's useful for
		 * applying additional content modifications or custom formatting.
		 *
		 * @filter synced_post_export_resolve_menus
		 *
		 * @param string $subject  The post content after menu resolution.
		 * @param int    $post_id  The ID of the post being exported.
		 * @param object $post     The Prepared_Post object.
		 *
		 * @return string          The modified post content for export.
		 */
		$this->post_content = apply_filters( 'synced_post_export_resolve_menus', $subject, $this->ID, $this );
	}

	/**
	 * Prepare the post hierarchy.
	 *
	 * @since 2.18.0
	 *
	 * We collect the post hierarchy information in the Prepared_Post object. This contains
	 * information about the parent post (if any) and all child posts (if any are found).
	 * This information is used during the import process to try to restore the same hierarchy
	 * based on the posts provided on the destination site.
	 * @see \Contentsync\Post_Import::set_post_hierarchy()
	 *
	 * @var array $this->post_hierarchy Information about the post hierarchy.
	 *    @property array $parent       Information about the parent post.
	 *        @property int $id         The parent post ID.
	 *        @property string $name    The parent post name (slug).
	 *        @property string $type    The parent post type.
	 *    @property array[] $children   Information about the child posts (array of arrays).
	 *        @property int $id         The child post ID.
	 *        @property string $name    The child post name (slug).
	 *        @property string $type    The child post type.
	 *
	 * @return array|null $this->post_hierarchy Information about the post hierarchy.
	 */
	public function prepare_post_hierarchy() {

		if ( ! isset( $this->export_arguments['append_nested'] ) || ! $this->export_arguments['append_nested'] ) {
			return null;
		}

		$this->post_hierarchy = array(
			'parent'   => array(),
			'children' => array(),
		);

		if ( $this->post_parent ) {
			$parent_post = get_post( $this->post_parent );
			if ( $parent_post ) {
				$this->post_hierarchy['parent'] = array(
					'id'   => $parent_post->ID,
					'name' => $parent_post->post_name,
					'type' => $parent_post->post_type,
				);
			}
		}

		// check if there are any child posts
		$child_posts = get_posts(
			array(
				'post_parent' => $this->ID,
				'post_type'   => $this->post_type,
				'post_status' => 'publish',
				'numberposts' => -1,
			)
		);
		if ( $child_posts ) {
			foreach ( $child_posts as $child_post ) {
				$this->post_hierarchy['children'][] = array(
					'id'   => $child_post->ID,
					'name' => $child_post->post_name,
					'type' => $child_post->post_type,
				);
			}
		}

		return $this->post_hierarchy;
	}


	/**
	 * =================================================================
	 *                          Additional WP_Post default args
	 * =================================================================
	 */

	/**
	 * The post's content.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	public $post_content = '';

	/**
	 * The unique identifier for a post, not necessarily a URL, used as the feed GUID.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	public $guid = '';

	/**
	 * ID of post author.
	 *
	 * A numeric string, for compatibility reasons.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	public $post_author = 0;

	/**
	 * The post's excerpt.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	public $post_excerpt = '';

	/**
	 * The post's status.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	public $post_status = 'publish';

	/**
	 * Whether comments are allowed.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	public $comment_status = 'open';

	/**
	 * Whether pings are allowed.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	public $ping_status = 'open';

	/**
	 * The post's password in plain text.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	public $post_password = '';

	/**
	 * URLs queued to be pinged.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	public $to_ping = '';

	/**
	 * URLs that have been pinged.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	public $pinged = '';

	/**
	 * The post's local modified time.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	public $post_modified = '0000-00-00 00:00:00';

	/**
	 * The post's GMT modified time.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	public $post_modified_gmt = '0000-00-00 00:00:00';

	/**
	 * A utility DB field for post content.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	public $post_content_filtered = '';

	/**
	 * ID of a post's parent post.
	 *
	 * @since 3.5.0
	 * @var int
	 */
	public $post_parent = 0;

	/**
	 * A field used for ordering posts.
	 *
	 * @since 3.5.0
	 * @var int
	 */
	public $menu_order = 0;

	/**
	 * An attachment's mime type.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	public $post_mime_type = '';

	/**
	 * Cached comment count.
	 *
	 * A numeric string, for compatibility reasons.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	public $comment_count = 0;

	/**
	 * Stores the post object's sanitization level.
	 *
	 * Does not correspond to a DB field.
	 *
	 * @since 3.5.0
	 * @var string
	 */
	public $filter;
}
