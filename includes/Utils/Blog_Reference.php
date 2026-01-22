<?php

namespace Contentsync\Utils;

use Contentsync\Utils\Urls;

defined( 'ABSPATH' ) || exit;

class Blog_Reference {

	/**
	 * The blog ID.
	 *
	 * @var int
	 */
	public $blog_id;

	/**
	 * The database prefix.
	 *
	 * @var string
	 */
	public $db_prefix;

	/**
	 * The blog name.
	 *
	 * @var string eg. 'Example Blog'
	 */
	public $name;

	/**
	 * The blog domain.
	 *
	 * @var string eg. 'example.com'
	 */
	public $domain;

	/**
	 * The blog protocol.
	 *
	 * @var string eg. 'https' or 'http'
	 */
	public $protocol;

	/**
	 * The blog site URL.
	 *
	 * @var string eg. 'https://example.com'
	 */
	public $site_url;

	/**
	 * Constructor.
	 *
	 * @param int $blog_id The blog ID.
	 *
	 * @throws \Exception If the blog ID is not a number.
	 */
	public function __construct( $blog_id ) {

		if ( ! is_numeric( $blog_id ) ) {
			throw new \Exception( 'Blog ID must be a number' );
		}

		global $wpdb;

		if ( ! is_multisite() ) {
			$this->blog_id   = 1;
			$this->db_prefix = $wpdb->get_blog_prefix();
			$this->name      = get_bloginfo( 'name' );
			$this->site_url  = get_site_url();
			$this->domain    = Urls::get_nice_url( $this->site_url );
			$this->protocol  = strpos( $this->site_url, 'https:' ) === 0 ? 'https' : 'http';
		} else {

			$site = get_site( $blog_id );

			if ( ! $site ) {
				throw new \Exception( 'Blog details not found' );
			}

			$this->blog_id   = $blog_id;
			$this->db_prefix = $wpdb->get_blog_prefix( $blog_id );
			$this->name      = get_bloginfo( 'name', $blog_id );
			$this->site_url  = $site->domain . $site->path;
			$this->domain    = Urls::get_nice_url( substr( $this->site_url, 0, -1 ) );
			$this->protocol  = strpos( $this->site_url, 'https:' ) === 0 ? 'https' : 'http';
		}
	}
}
