<?php

/**
 * PHPUnit bootstrap file.
 */

// Composer autoloader must be loaded before WP_PHPUNIT__DIR is available.
require_once dirname(__DIR__).'/vendor/autoload.php';

// Give access to tests_add_filter() function.
require_once getenv('WP_PHPUNIT__DIR').'/includes/functions.php';

// Polylang needs PLL_ADMIN defined to initialize without pre-existing languages.
// Without this, Polylang skips init_context() and $GLOBALS['polylang'] is never set.
if (! defined('PLL_ADMIN')) {
    define('PLL_ADMIN', true);
}

/**
 * Load gds-mcp and integration plugins in muplugins_loaded.
 *
 * wp-phpunit uses a fresh DB where no plugins are "activated".
 * We manually require plugin files so they initialize.
 */
tests_add_filter('muplugins_loaded', function () {
    $pluginsDir = dirname(__DIR__, 2);

    $integrations = [
        'polylang-pro/polylang.php',
        'polylang/polylang.php',
        'wordpress-seo/wp-seo.php',
        'safe-redirect-manager/safe-redirect-manager.php',
        'redirection/redirection.php',
        'stream/stream.php',
    ];

    foreach ($integrations as $plugin) {
        $path = $pluginsDir.'/'.$plugin;
        if (file_exists($path)) {
            require_once $path;
        }
    }

    require dirname(__DIR__).'/gds-mcp.php';
});

// Start up the WP testing environment.
require getenv('WP_PHPUNIT__DIR').'/includes/bootstrap.php';

// Configure Polylang languages in the fresh test DB.
if (function_exists('PLL') && PLL() && isset(PLL()->model)) {
    $model = PLL()->model;

    // Find the languages definition file (Pro or free).
    $pluginsDir = dirname(__DIR__, 2);
    foreach (['polylang-pro/vendor/wpsyntex/polylang/settings/languages.php', 'polylang/settings/languages.php'] as $path) {
        $languagesFile = $pluginsDir.'/'.$path;
        if (file_exists($languagesFile)) {
            break;
        }
        $languagesFile = null;
    }
    $knownLanguages = $languagesFile ? include $languagesFile : [];

    foreach ([
        ['name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'term_group' => 0],
        ['name' => 'Finnish', 'slug' => 'fi', 'locale' => 'fi', 'term_group' => 1],
        ['name' => 'Swedish', 'slug' => 'sv', 'locale' => 'sv_SE', 'term_group' => 2],
    ] as $lang) {
        if ($model->get_language($lang['slug'])) {
            continue;
        }
        $defaults = $knownLanguages[$lang['locale']] ?? [];
        $model->add_language(array_merge($defaults, $lang));
    }

    // Set English as default.
    $model->update_default_lang('en');
    $model->clean_languages_cache();
}

// Create Redirection DB tables and default group.
$redirectionDbFile = dirname(__DIR__, 2).'/redirection/database/database.php';
if (class_exists('Red_Item') && file_exists($redirectionDbFile)) {
    require_once $redirectionDbFile;
    $schemaFile = dirname(__DIR__, 2).'/redirection/database/schema/latest.php';
    if (file_exists($schemaFile)) {
        require_once $schemaFile;
    }
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    if (class_exists('Red_Latest_Database')) {
        (new Red_Latest_Database)->install();
    }
    // Redirection requires group 1 for creating redirects.
    if (class_exists('Red_Group') && ! Red_Group::get(1)) {
        Red_Group::create('Redirections', 1);
    }
}

// Create Stream DB tables if the plugin is loaded.
if (function_exists('wp_stream_get_instance')) {
    $stream = wp_stream_get_instance();
    if (isset($stream->install) && method_exists($stream->install, 'install')) {
        $stream->install->install($stream->get_version());
    }
}
