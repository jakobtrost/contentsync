<?php
/**
 * Bootstrap the cluster subsystem.
 *
 * This file is used to include all the classes, database definitions and
 * helper functions needed for the cluster and review features of
 * Content Sync. It loads the Contentsync_Cluster class, post review classes,
 * content condition classes, database table registrations, mail logic
 * and various helper functions. Include this file from your plugin’s
 * initialisation to ensure that cluster functionality—such as post
 * review workflows, condition‑based exports and reviewer notifications—is
 * available.
 *
 * @since 2.17.0
 */
require_once __DIR__.'/classes/class-contentsync-cluster.php';
require_once __DIR__.'/classes/class-contentsync-post-review.php';
require_once __DIR__.'/classes/class-contentsync-post-review-message.php';
require_once __DIR__.'/classes/class-contentsync-content-condition.php';
require_once __DIR__.'/db-tables.php';
require_once __DIR__.'/mails.php';
require_once __DIR__.'/cluster-functions.php';
require_once __DIR__.'/post-review-functions.php';
require_once __DIR__.'/content-condition-functions.php';
