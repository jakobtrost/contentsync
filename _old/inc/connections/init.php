<?php

namespace Contentsync\Connections;

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! defined('GREYD_REST_NAMESPACE') ) {
	define('GREYD_REST_NAMESPACE', 'greyd/v1');
}

require_once __DIR__ . '/connections-helper.php';
require_once __DIR__ . '/init-endpoints.php';
require_once __DIR__ . '/remote-operations.php';

// backend
if ( is_admin() ) {
	require_once __DIR__ . '/connections-page.php';
	require_once __DIR__ . '/class-connections-list-table.php';
}