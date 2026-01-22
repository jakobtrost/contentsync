<?php

namespace Contentsync\Utils;

/**
 * Base class for hook provider classes.
 *
 * Extend this class in any hooks class and override the protected
 * registration methods as needed:
 *
 * - register()          - hooks that should run on both frontend and admin
 * - register_frontend() - hooks that should run only on the frontend
 * - register_admin()    - hooks that should run only in the admin area
 *
 * Subclasses may implement none, some, or all of these methods. The public
 * register() method will call only the methods that exist and are callable,
 * using is_admin() to decide between frontend and admin.
 */
abstract class Hooks_Base {

	/**
	 * Register hooks for this provider.
	 *
	 * Calls register_common() first (if implemented), then either
	 * register_admin() or register_frontend() depending on context.
	 *
	 * This is the only method that should be called from loaders.
	 */
	public function __construct() {
		// Hooks that should always run, regardless of context.
		if ( method_exists( $this, 'register' ) ) {
			// @phpstan-ignore-next-line Allow dynamic dispatch for subclasses.
			$this->register();
		}

		if ( is_admin() ) {
			if ( method_exists( $this, 'register_admin' ) ) {
				// @phpstan-ignore-next-line Allow dynamic dispatch for subclasses.
				$this->register_admin();
			}
		} elseif ( method_exists( $this, 'register_frontend' ) ) {
			// @phpstan-ignore-next-line Allow dynamic dispatch for subclasses.
			$this->register_frontend();
		}
	}
}
