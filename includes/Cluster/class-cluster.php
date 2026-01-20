<?php

/**
 * Global Cluster object
 *
 * This file declares the `Cluster` class, which models a cluster of
 * destinations within the Content Sync system. A cluster defines a
 * collection of blogs or sites to which content should be distributed,
 * as well as review and content condition settings. The class stores
 * properties such as cluster ID, title, destination IDs, enable reviews
 * flag, reviewer IDs and content conditions. It provides static
 * methods to retrieve a cluster instance from the database and an
 * `insert` method to update its persistent data. Use this class when
 * managing clusters programmatically.
 *
 * @since 2.17.0
 */
namespace Contentsync\Cluster;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Cluster {

	/**
	 * Cluster ID.
	 *
	 * @var int
	 */
	public $ID;

	/**
	 * Cluster title.
	 *
	 * @var string
	 */
	public $title;

	/**
	 * Destination IDs.
	 *
	 * @var array
	 */
	public $destination_ids;

	/**
	 * Enable reviews.
	 *
	 * @var bool
	 */
	public $enable_reviews;

	/**
	 * Reviewer IDs.
	 *
	 * @var array
	 */
	public $reviewer_ids;

	/**
	 * Content conditions IDs
	 *
	 * @var array
	 */
	public $content_conditions;


	public static function get_instance( $cluster_id ) {
		global $wpdb;

		$cluster_id = (int) $cluster_id;
		if ( ! $cluster_id ) {
			return false;
		}
		$table_name = $wpdb->base_prefix . 'contentsync_clusters';
		$_cluster   = $wpdb->get_row( "SELECT * FROM $table_name WHERE ID = $cluster_id" );

		if ( ! $_cluster ) {
			return false;
		}

		return new Cluster( $_cluster );
	}

	/**
	 * Constructor.
	 *
	 * @param Cluster|object $cluster Cluster object.
	 */
	public function __construct( $cluster ) {
		foreach ( get_object_vars( $cluster ) as $key => $value ) {
			if ( 'id' === $key ) {
				$this->ID = (int) $value;
				continue;
			} elseif ( 'enable_reviews' === $key ) {
				$this->$key = (bool) $value;
				continue;
			} elseif ( 'destination_ids' === $key ) {
				$this->$key = empty( $value ) ? array() : explode( ',', $value );
				continue;
			} elseif ( 'reviewer_ids' === $key ) {
				$this->$key = empty( $value ) ? array() : explode( ',', $value );
				continue;
			} elseif ( 'content_conditions' === $key ) {
				$this->$key = empty( $value ) ? array() : array_map( '\Contentsync\Cluster\get_cluster_content_condition_by_id', (array) unserialize( $value ) );
				continue;
			}

			$this->$key = $value;
		}
	}

	public function get( $key ) {
		return key_exists( $key, get_object_vars( $this ) ) ? $this->$key : false;
	}
	public function insert() {
		global $wpdb;

		$wpdb->update(
			$wpdb->base_prefix . 'contentsync_clusters',
			array(
				'title'              => sanitize_text_field( $this->title ),
				'destination_ids'    => $this->destination_ids,
				'enable_reviews'     => $this->enable_reviews,
				'reviewer_ids'       => $this->reviewer_ids,
				'content_conditions' => $this->content_conditions,
			),
			array( 'ID' => $this->ID )
		);
	}
}
