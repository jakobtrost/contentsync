<?php
/**
 * Content Sync condition object
 *
 * This file defines the `Content_Condition` class used to
 * encapsulate a rule for selecting posts to include in clusters or to
 * make global automatically. Each condition stores identifiers for the
 * cluster and blog it belongs to, the post type, taxonomy and term
 * filters, export arguments and an optional arbitrary filter array.
 * The class includes a static `get_instance` method to load a
 * condition from the database and a constructor that assigns
 * properties. Use this class to inspect or manipulate content
 * conditions within your own code.
 *
 */
namespace Contentsync\Cluster;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Content_Condition {


	/**
	 * Content Condition ID
	 *
	 * @var int
	 */
	public $ID;

	/**
	 * Cluster ID
	 *
	 * @var int
	 */
	public $contentsync_cluster_id;

	/**
	 * Blog ID
	 *
	 * @var int
	 */
	public $blog_id;

	/**
	 * Posttype
	 *
	 * @var string
	 */
	public $post_type;

	/**
	 * Make posts global automatically
	 *
	 * @var bool
	 */
	public $make_posts_global_automatically;

	/**
	 * Taxonomy
	 *
	 * @var string
	 */
	public $taxonomy;

	/**
	 * Terms
	 *
	 * @var array
	 */
	public $terms;

	/**
	 * The arguments used to export the posts.
	 *
	 *
	 * @var array
	 *    @property bool  append_nested   Append nested posts to the export.
	 *    @property bool  whole_posttype  Export the whole post type.
	 *    @property bool  all_terms       Export all terms of the post.
	 *    @property bool  resolve_menus   Resolve navigation links to custom links.
	 *    @property bool  translations    Include translations of the post.
	 */
	public $export_arguments;

	/**
	 * Filter
	 *
	 * @var array
	 */
	public $filter;


	public static function get_instance( $content_condition_id ) {
		global $wpdb;

		$content_condition_id = (int) $content_condition_id;
		if ( ! $content_condition_id ) {
			return false;
		}
		$table_name         = $wpdb->base_prefix . 'cluster_content_conditions';
		$_content_condition = $wpdb->get_row( "SELECT * FROM $table_name WHERE ID = $content_condition_id" );

		if ( ! $_content_condition ) {
			return false;
		}

		return new Content_Condition( $_content_condition );
	}

	/**
	 * Constructor.
	 *
	 *
	 * @param Content_Condition|object $content_condition Content Condition object.
	 */
	public function __construct( $content_condition ) {
		foreach ( get_object_vars( $content_condition ) as $key => $value ) {
			if ( 'filter' === $key ) {
				$this->$key = unserialize( $value );
				continue;
			}
			if ( 'terms' === $key ) {
				$this->$key = unserialize( $value );
				continue;
			}
			if ( 'export_arguments' === $key ) {
				$this->$key = unserialize( $value );
				continue;
			}
			if ( 'id' === $key ) {
				$this->ID = (int) $value;
				continue;
			}
			if ( 'make_posts_global_automatically' === $key ) {
				$this->$key = (bool) $value;
				continue;
			}

			$this->$key = $value;
		}
	}

	public function set( $key, $value ) {
		$this->$key = $value;
	}

	public function get( $key ) {
		return $this->$key;
	}

	public function insert() {
		global $wpdb;

		$wpdb->insert(
			$wpdb->base_prefix . 'cluster_content_conditions',
			array(
				'contentsync_cluster_id'          => $this->contentsync_cluster_id,
				'blog_id'                         => $this->blog_id,
				'post_type'                       => $this->post_type,
				'filter'                          => serialize( $this->filter ),
				'make_posts_global_automatically' => $this->make_posts_global_automatically,
				'taxonomy'                        => $this->taxonomy,
				'terms'                           => serialize( $this->terms ),
				'export_arguments'                => serialize( $this->export_arguments ),
			)
		);
	}

	/**
	 * Get all content conditions by properties
	 * eg. get all content conditions by blog ID AND post type
	 *
	 * @param array $args  Array of properties to filter by, supported properties are:
	 *                     contentsync_cluster_id, blog_id, post_type, taxonomy, terms
	 *
	 * @return Content_Condition[]|false
	 */
	public static function get_conditions_by( $args ) {
		global $wpdb;

		$valid_args = array(
			'contentsync_cluster_id',
			'blog_id',
			'post_type',
			'taxonomy',
			'terms',
			'export_arguments',
		);

		$sql_args = array();

		foreach ( $args as $key => $value ) {
			if ( in_array( $key, $valid_args ) ) {
				$sql_args[ $key ] = is_numeric( $value ) ? (int) $value : $value;
			}
		}

		// error_log( "get conditions by: " . print_r( $sql_args, true ) );

		// prepare sql phrase to be like: {{key}} = %s AND {{key}} = %s AND ...
		$sql_phrase = implode( ' = %s AND ', array_keys( $sql_args ) ) . ' = %s';

		$prepare = $wpdb->prepare(
			"SELECT * FROM {$wpdb->base_prefix}cluster_content_conditions WHERE " . $sql_phrase,
			array_values( $sql_args )
		);

		$results = $wpdb->get_results( $prepare );

		if ( empty( $results ) ) {
			return false;
		}

		$conditions = array();
		foreach ( $results as $condition ) {
			$condition_object             = new Content_Condition( $condition );
			$conditions[ $condition->ID ] = $condition_object;
		}

		return $conditions;
	}
}
