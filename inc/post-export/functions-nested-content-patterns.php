<?php

if ( ! function_exists( 'get_nested_post_patterns' ) ) {

	/**
	 * Get the patterns to replace post ids in post_content.
	 * 
	 * @deprecated since 2.0: Post_Export::regex_nested_posts()
	 *
	 * @param int     $post_id      The WP_Post ID.
	 * @param WP_Post $post         The WP_Post Object
	 *
	 * @return array[] with the following structure:
	 *
	 * @property array  search      Regex around the ID to look for.
	 *                              Implodes with: '([\da-z\-\_]+?)'
	 *                              Slashes are automatically set around the regex.
	 * @property array  replace     Strings around the ID to replace by.
	 *                              Implodes with: '{{'.$post_id.'}}'
	 * @property string post_type   (optional) Post type of the found ID.
	 *                              If set and a post_name is found, it looks
	 *                              for the post-ID by post_name & post_type.
	 * @property int    group       (optional) Number of regex-group that contains
	 *                              the ID. Default: 2
	 */
	function get_nested_post_patterns( $post_id = 0, $post = null ) {

		/**
		 * Filter to customize regex patterns for finding nested posts in post content.
		 * 
		 * This filter allows developers to add custom regex patterns for detecting
		 * and replacing nested post references in post content during export.
		 * It's useful for supporting custom block types or content structures.
		 * 
		 * @filter contentsync_regex_nested_posts
		 * 
		 * @param array   $patterns     Array of regex pattern arguments for post detection.
		 * @param int     $post_id      The WP_Post ID being exported.
		 * @param WP_Post $post         The WP_Post Object being exported.
		 * 
		 * @return array                Modified array of regex patterns for post detection.
		 */
		return (array) apply_filters(
			'contentsync_regex_nested_posts',
			array(

				/**
				 * ----------------- Posttypes -----------------
				 */
	
				/**
				 * Find nested Reusables & patterns
				 */
				'core/reusable' => array(
					'search'    => array( '<!-- wp:block {(.*?)\"ref\":', '}' ),
					'replace'   => array( '<!-- wp:block {$1"ref":', '}' ),
					'post_type' => 'wp_block'
				),
				'core/pattern' => array(
					'search'    => array( '<!-- wp:pattern {(.*?)\"slug\":\"', '\"' ),
					'replace'   => array( '<!-- wp:pattern {$1"slug":"', '"' ),
					'post_type' => 'wp_block'
				),
	
				/**
				 * Find nested wp-template-parts
				 */
				'core/template-part' => array(
					'search'    => array( '<!-- wp:template-part {(.*?)\"slug\":\"', '\"' ),
					'replace'   => array( '<!-- wp:template-part {$1"slug":"', '"' ),
					'post_type' => 'wp_template_part'
				),
	
				/**
				 * Find nested wp_navigation (navigation menus)
				 */
				'core/navigation' => array(
					'search'    => array( '<!-- wp:navigation {(.*?)\"ref\":', '}' ),
					'replace'   => array( '<!-- wp:navigation {$1"ref":', '}' ),
					'post_type' => 'wp_navigation'
				),
				'core/navigation-with-attributes' => array(
					'search'    => array( '<!-- wp:navigation {(.*?)\"ref\":', ',' ),
					'replace'   => array( '<!-- wp:navigation {$1"ref":', ',' ),
					'post_type' => 'wp_navigation'
				),
				'core/navigation-deprecated' => array(
					'search'    => array( '<!-- wp:navigation {(.*?)\"navigationMenuId\":', '}' ),
					'replace'   => array( '<!-- wp:navigation {$1"navigationMenuId":', '}' ),
					'post_type' => 'wp_navigation'
				),
				'core/navigation-deprecated-with-attributes' => array(
					'search'    => array( '<!-- wp:navigation {(.*?)\"navigationMenuId\":', ',' ),
					'replace'   => array( '<!-- wp:navigation {$1"navigationMenuId":', ',' ),
					'post_type' => 'wp_navigation'
				),
	
	
				/**
				 * ----------------- Media files -----------------
				 */
	
				/**
				 * Find nested images in core blocks.
				 * 
				 * Supported blocks: image, cover, media-text, file
				 */
				'core/image' => array(
					'search'    => array( '<!-- wp:image {(.*?)\"id\":', ',' ),
					'replace'   => array( '<!-- wp:image {$1"id":', ',' ),
					'post_type' => 'attachment',
				),
				'core/image-class' => array(
					'search'    => array( 'class=\"([^\"]*?)wp-image-', '(\s|\")' ),
					'replace'   => array( 'class="$1wp-image-', '$2' ),
					'post_type' => 'attachment',
				),
				'core/cover' => array(
					'search'    => array( '<!-- wp:cover {(.*?)\"id\":', ',' ),
					'replace'   => array( '<!-- wp:cover {$1"id":', ',' ),
					'post_type' => 'attachment',
				),
				'core/media-text ' => array(
					'search'    => array( '<!-- wp:media-text {(.*?)\"mediaId\":', ',' ),
					'replace'   => array( '<!-- wp:media-text {$1"mediaId":', ',' ),
					'post_type' => 'attachment',
				),
				'core/file' => array(
					'search'    => array( '<!-- wp:file {(.*?)\"id\":', ',' ),
					'replace'   => array( '<!-- wp:file {$1"id":', ',' ),
					'post_type' => 'attachment',
				),
	
				/**
				 * Find nested videos in core blocks.
				 * 
				 * Supported blocks: video
				 */
				'core/video' => array(
					'search'    => array( '<!-- wp:video {([^}]*?)\"id\":', ',' ),
					'replace'   => array( '<!-- wp:video {$1"id":', ',' ),
					'post_type' => 'attachment',
				),
			),
			$post_id,
			$post
		);
	}
}

if ( ! function_exists( 'get_nested_string_patterns' ) ) {
	/**
	 * Get all the strings to replace inside post_content.
	 * 
	 * @deprecated since 2.0: Post_Export::regex_nested_strings()
	 *
	 * @param string $subject   The string to query (usually post content).
	 * @param int    $post_id   The post ID.
	 *
	 * @return string[]
	 *
	 * Default:
	 * @property string upload_url              http://website.de/wp-content/uploads
	 * @property string upload_url_enc          http%3A%2F%2Fwebsite.de%2Fwp-content%2Fuploads
	 * @property string upload_url_enc_twice    http%253A%252F%252Fwebsite.de%252Fwp-content%252Fuploads
	 * @property string site_url                http://website.de/
	 * @property string site_url_enc            http%3A%2F%2Fwebsite.de%2F
	 * @property string site_url_enc_twice      http%253A%252F%252Fwebsite.de%252F
	 */
	function get_nested_string_patterns( $subject, $post_id ) {
		$site_url       = get_site_url();
		$site_url_enc   = urlencode( $site_url );
		$upload_url     = wp_upload_dir( null, true, true )['baseurl'];
		$upload_url_enc = urlencode( $upload_url );

		/**
		 * @since 2.18.0
		 * If either upload url or site url does not have 'https://' protocol, but the other
		 * does, add the protocol to the one that does not have it.
		 */
		if ( strpos( $upload_url, 'https://' ) !== 0 && strpos( $site_url, 'https://' ) === 0 ) {
			$upload_url = 'https://' . str_replace( 'http://', '', $upload_url );
		} else if ( strpos( $upload_url, 'https://' ) === 0 && strpos( $site_url, 'https://' ) !== 0 ) {
			$site_url = 'https://' . str_replace( 'http://', '', $site_url );
		}

		/**
		 * Filter to customize string replacement patterns for post content export.
		 * 
		 * This filter allows developers to add custom string patterns that should
		 * be replaced with placeholders during export. It's useful for handling
		 * site-specific URLs, paths, or other dynamic content.
		 * 
		 * @filter contentsync_regex_nested_strings
		 * 
		 * @param string[]  $strings   Array of strings to be replaced, keyed by placeholder name.
		 * @param string    $content   The post content being processed.
		 * @param int       $post_id   The ID of the post being exported.
		 * 
		 * @return string[]            Modified array of strings to be replaced.
		 */
		return apply_filters(
			'contentsync_regex_nested_strings',
			array(
				'upload_url'           => $upload_url,
				'upload_url_enc'       => $upload_url_enc,
				'upload_url_enc_twice' => urlencode( $upload_url_enc ),
				'site_url'             => $site_url,
				'site_url_enc'         => $site_url_enc,
				'site_url_enc_twice'   => urlencode( $site_url_enc ),
				'theme'                => '"'.get_stylesheet().'"'
			),
			$subject,
			$post_id
		);
	}
}

if ( ! function_exists( 'get_nested_term_patterns' ) ) {
	/**
	 * Get the patterns to replace term ids in post_content.
	 * 
	 * @deprecated since 2.0: Post_Export::regex_nested_terms()
	 *
	 * @param int     $post_id      The WP_Post ID.
	 * @param WP_Post $post         The WP_Post Object
	 *
	 * @return array[] @see regex_nested_posts() for details.
	 */
	function get_nested_term_patterns( $post_id, $post ) {
		
		/**
		 * Filter to customize regex patterns for finding nested terms in post content.
		 * 
		 * This filter allows developers to add custom regex patterns for detecting
		 * and replacing nested taxonomy term references in post content during export.
		 * It's useful for supporting custom term-based content structures.
		 * 
		 * @filter contentsync_regex_nested_terms
		 * 
		 * @param array   $patterns     Array of regex pattern arguments for term detection.
		 * @param int     $post_id      The WP_Post ID being exported.
		 * @param WP_Post $post         The WP_Post Object being exported.
		 * 
		 * @return array                Modified array of regex patterns for term detection.
		 */
		return (array) apply_filters(
			'contentsync_regex_nested_terms',
			array(
				/**
				 * The taxQuery regex did not work.
				 * @since 2.8.0 taxQuery terms are processed in Post_Export::prepare_nested_terms()
				 */
				// 'taxQuery' => array(
				// 	'search'    => array( '\"taxQuery\":\{([^\}]+)(\[|,)', '(\]|,)' ),
				// 	'replace'   => array( '"taxQuery":{$1$2', '$4' ),
				// 	'group'     => 3
				// ),
			),
			$post_id,
			$post
		);
	}
}
