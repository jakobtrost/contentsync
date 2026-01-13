<?php
/**
 * Bootstrap the distribution subsystem.
 *
 * This file is used to include all the files needed for the distribution
 * queue to work. It loads the database schema definition for the queue
 * table, the destination and item classes used to model distribution
 * targets and tasks, the logger used during distribution, and the
 * administrative functions for the queue. Include this file from your
 * plugin’s main init routine to ensure that the distribution framework is
 * properly initialised.
 *
 * @since 2.17.0
 */

/**
 * Load the database.
 */
require_once __DIR__ . '/db.php';

/**
 * Load the classes.
 */
require_once __DIR__ . '/classes/class-destination.php';
require_once __DIR__ . '/classes/class-post-destination.php';
require_once __DIR__ . '/classes/class-blog-destination.php';
require_once __DIR__ . '/classes/class-remote-destination.php';
require_once __DIR__ . '/classes/class-distribution-item.php';
require_once __DIR__ . '/classes/class-logger.php';

/**
 * Load the functions.
 */
include_once __DIR__ . '/distributor.php';
include_once __DIR__ . '/queue-admin-page.php';