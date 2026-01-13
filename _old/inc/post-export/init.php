<?php
namespace Contentsync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/functions-nested-content-patterns.php';

// Load main classes
require_once __DIR__ . '/class-translation-manager.php';
require_once __DIR__ . '/class-preparred-post.php';
require_once __DIR__ . '/post-export-helper.php';
require_once __DIR__ . '/class-post-export.php';
require_once __DIR__ . '/class-post-import.php';

if ( is_admin() ) {
	require_once __DIR__ . '/post-export-admin.php';
	require_once __DIR__ . '/theme-export/init.php';
}