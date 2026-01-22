<?php
/**
 * Cluster Service hooks provider.
 *
 * This class registers WordPress hooks related to cluster services,
 * specifically the cron action for checking clusters on date changes.
 */
namespace Contentsync\Cluster;

use Contentsync\Distribution\Distributor;
use Contentsync\Utils\Hooks_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Cluster Service Hooks
 */
final class Cluster_Service_Hooks extends Hooks_Base {

	/**
	 * Register hooks that run everywhere.
	 */
	public function register() {
		add_action( 'cron_action_check_cluster', array( $this, 'check_clusters_on_date_change' ), 10, 1 );
	}

	/**
	 * Scheduler action - syncs cluster posts and re-schedules.
	 *
	 * If posts are synced based on date conditions, we see if the post ids have changed.
	 * If they have, we distribute the posts again.
	 *
	 * @param array $clusters_with_date_condition_before Clusters with date conditions before the check.
	 */
	public function check_clusters_on_date_change( $clusters_with_date_condition_before ) {

		$clusters_with_date_condition = array();
		foreach ( Cluster_Service::get_clusters_with_date_mode_condition() as $cluster_id => $cluster ) {
			$clusters_with_date_condition[ $cluster_id ] = array(
				'posts' => Cluster_Service::get_cluster_posts_per_blog( $cluster_id ),
			);
		}

		if ( ! empty( $clusters_with_date_condition ) ||
			! empty( $clusters_with_date_condition_before )
		) {
			$cluster_ids_to_be_synced = array_merge(
				array_keys( $clusters_with_date_condition ),
				array_keys( $clusters_with_date_condition_before )
			);

			foreach ( $cluster_ids_to_be_synced as $cluster_id ) {
				if ( ! isset( $clusters_with_date_condition[ $cluster_id ] ) ) {
					continue;
				}

				$before = isset( $clusters_with_date_condition_before[ $cluster_id ] ) ? $clusters_with_date_condition_before[ $cluster_id ] : array();

				// compare post_ids
				$flattened_post_ids = array();
				foreach ( $clusters_with_date_condition[ $cluster_id ]['posts'] as $blog_id => $posts ) {
					foreach ( $posts as $post_id => $post ) {
						$flattened_post_ids[] = $blog_id . '-' . $post_id;
					}
				}
				sort( $flattened_post_ids );

				// ... with post_ids before
				$flattened_post_ids_before = array();
				foreach ( $before['posts'] as $blog_id => $posts ) {
					foreach ( $posts as $post_id => $post ) {
						$flattened_post_ids_before[] = $blog_id . '-' . $post_id;
					}
				}
				sort( $flattened_post_ids_before );

				// -> if post_ids are different, distribute posts
				if ( $flattened_post_ids !== $flattened_post_ids_before ) {
					Distributor::distribute_cluster_posts( $cluster_id, $before );
				}
			}
		}

		// Logger::add("re-schedule");
		Cluster_Service::schedule_cluster_date_check();
	}
}
