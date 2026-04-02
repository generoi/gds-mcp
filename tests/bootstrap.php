<?php

/**
 * PHPUnit bootstrap file.
 *
 * Works in two environments:
 * - wp-env: WP_PHPUNIT__DIR is set by wp-phpunit package
 * - DDEV:   WP_TESTS_DIR points to the WP test suite
 */

// Composer autoloader must be loaded before WP_PHPUNIT__DIR is available.
require_once dirname(__DIR__).'/vendor/autoload.php';

// Give access to tests_add_filter() function.
require_once getenv('WP_PHPUNIT__DIR').'/includes/functions.php';

// Load the plugin and any available integration plugins.
tests_add_filter('muplugins_loaded', function () {
    require dirname(__DIR__).'/gds-mcp.php';
});

// Start up the WP testing environment.
require getenv('WP_PHPUNIT__DIR').'/includes/bootstrap.php';
