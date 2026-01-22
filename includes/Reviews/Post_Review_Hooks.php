<?php
/**
 * Post review hooks provider.
 *
 * Registers hooks related to post review functionality.
 */

namespace Contentsync\Reviews;

use Contentsync\Utils\Hooks_Base;
use Contentsync\Utils\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Post review hooks provider class.
 */
class Post_Review_Hooks extends Hooks_Base {

	/**
	 * Register hooks that run everywhere.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'contentsync_prepared_posts_for_distribution', array( $this, 'replace_posts_with_previous_version_before_distribution' ), 10, 3 );
	}

	/**
	 * Before we distribute posts, we need to replace all posts that have not
	 * been reviewed yet with the previous version.
	 *
	 * @see prepare_posts_for_distribution()
	 * @filter contentsync_prepared_posts_for_distribution
	 *
	 * @param Prepared_Post[] $prepared_posts  Array of prepared posts.
	 * @param int[]           $post_ids         Array of post IDs.
	 * @param array           $export_args      Export arguments.
	 *
	 * @return Prepared_Post[]
	 */
	public function replace_posts_with_previous_version_before_distribution( $prepared_posts, $post_ids, $export_args ) {

		Logger::add( 'replace_posts_with_previous_version_before_distribution' );
		// Logger::add( 'prepared_posts', $prepared_posts );
		// Logger::add( 'post_ids', $post_ids );
		// Logger::add( 'export_args', $export_args );

		foreach ( $prepared_posts as $key => $post ) {

			$post_review = Post_Review_Service::get_post_review_by_post( $post->ID, get_current_blog_id(), array( 'new', 'in_review' ) );
			// Logger::add( 'post_review', $post_review );

			if ( $post_review && $post_review->previous_post ) {

				// if post_status is 'auto-draft', the post has not existed yet, therefore it needs to be removed from the array
				if ( $post_review->previous_post->post_status === 'auto-draft' ) {
					unset( $prepared_posts[ $key ] );
					continue;
				}

				$prepared_posts[ $key ] = $post_review->previous_post;
			}
		}

		return $prepared_posts;
	}
}
