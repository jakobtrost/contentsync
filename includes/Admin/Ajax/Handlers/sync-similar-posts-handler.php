<?php
/**
 * Sync Similar Posts AJAX Handler
 *
 * Handles AJAX requests for finding similar synced posts.
 *
 * @package Contentsync
 * @subpackage Admin\Ajax
 */

namespace Contentsync\Admin\Ajax;

use Contentsync\Posts\Sync\Post_Connection_Map;
use Contentsync\Posts\Sync\Post_Meta;
use Contentsync\Posts\Sync\Synced_Post_Service;
use Contentsync\Posts\Sync\Synced_Post_Query;
use Contentsync\Posts\Sync\Synced_Post_Utils;
use Contentsync\Utils\Urls;

defined( 'ABSPATH' ) || exit;

/**
 * Sync Similar Posts Handler Class
 */
class Sync_Similar_Posts_Handler extends Contentsync_Ajax_Handler {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct( 'contentsync_similar_posts' );
	}

	/**
	 * Handle the AJAX request
	 *
	 * @param array $data Request data containing post_id.
	 */
	protected function handle( $data ) {
		$post_id = isset( $data['post_id'] ) ? intval( $data['post_id'] ) : 0;

		if ( empty( $post_id ) ) {
			$this->send_fail( __( 'post_id is not defined.', 'contentsync' ) );
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			$this->send_fail( sprintf( __( "Post with ID '%s' could not be found.", 'contentsync' ), $post_id ) );
			return;
		}

		$similar_posts = $this->get_similar_synced_posts( $post );

		if ( ! $similar_posts ) {
			$this->send_fail( __( 'No similar posts found.', 'contentsync' ) );
			return;
		}

		$this->send_success( json_encode( $similar_posts ) );
	}

	/**
	 * Find similar synced posts.
	 *
	 * Criteria:
	 * * not from current blog
	 * * same posttype
	 * * post_name has at least 90% similarity
	 *
	 * @param WP_Post $post
	 *
	 * @return array of similar posts
	 */
	private function get_similar_synced_posts( $post ) {

		$found   = array();
		$blog_id = get_current_blog_id();
		$net_url = Urls::get_network_url();

		if ( ! isset( $post->post_name ) ) {
			return $found;
		}

		// we're replacing ending numbers after a dash (footer-2 becomes footer)
		$regex     = '/\-[0-9]{0,2}$/';
		$post_name = preg_replace( $regex, '', $post->post_name );

		// find and list all similar posts
		$all_posts = Synced_Post_Query::get_all_synced_posts();

		foreach ( $all_posts as $synced_post ) {

			$synced_post = Synced_Post_Service::new_synced_post( $synced_post );
			$gid         = Post_Meta::get_values( $synced_post, 'synced_post_id' );

			list( $_blog_id, $_post_id, $_net_url ) = Synced_Post_Utils::explode_gid( $gid );

			// exclude posts from other posttypes
			if ( $post->post_type !== $synced_post->post_type ) {
				continue;
			}
			// exclude posts from current blog
			elseif ( empty( $_net_url ) && $blog_id == $_blog_id ) {
				continue;
			}
			// exclude if a connection to this site is already established
			elseif (
				( empty( $_net_url ) && isset( $synced_post->meta['contentsync_connection_map'][ $blog_id ] ) ) ||
				( ! empty( $_net_url ) && isset( $synced_post->meta['contentsync_connection_map'][ $net_url ][ $blog_id ] ) )
			) {
				continue;
			}

			// check the post_name for similarity
			$name = preg_replace( $regex, '', $synced_post->post_name );
			similar_text( $post_name, $name, $percent ); // store percentage in variable $percent

			// list, if similarity is at least 90%
			if ( intval( $percent ) >= 90 ) {

				// make sure to get the post links
				if ( empty( $synced_post->post_links ) ) {

					// retrieve the post including all post_links from url
					if ( ! empty( $_net_url ) ) {
						$synced_post = Synced_Post_Service::new_synced_post( Synced_Post_Query::get_synced_post( $gid ) );
					} else {
						$synced_post->post_links = Post_Connection_Map::get_local_post_links( $_blog_id, $_post_id );
					}
				}

				// add the post to the response
				$found[ $gid ] = $synced_post;
			}
		}

		return apply_filters( 'contentsync_get_similar_synced_posts', $found, $post );
	}
}

new Sync_Similar_Posts_Handler();
