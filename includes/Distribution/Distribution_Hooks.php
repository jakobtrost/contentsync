<?php
/**
 * Distribution hooks provider.
 *
 * Registers hooks for the distribution system.
 */

namespace Contentsync\Distribution;

use Contentsync\Utils\Hooks_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Distribution hooks provider class.
 *
 * Registers the action scheduler hook for distributing items.
 */
class Distribution_Hooks extends Hooks_Base {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'contentsync_distribute_item', array( Distributor::class, 'distribute_item' ), 10, 1 );
	}
}
