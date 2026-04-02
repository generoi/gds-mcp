<?php

/**
 * PHPUnit bootstrap file.
 */

// Composer autoloader must be loaded before WP_PHPUNIT__DIR is available.
require_once dirname(__DIR__).'/vendor/autoload.php';

// Give access to tests_add_filter() function.
require_once getenv('WP_PHPUNIT__DIR').'/includes/functions.php';

/**
 * Load gds-mcp and integration plugins in muplugins_loaded.
 *
 * wp-phpunit uses a fresh DB where no plugins are "activated".
 * We manually require plugin files so they initialize.
 * Pattern from wp-snellman-m3-routes: dirname(__DIR__, 2) = plugins dir.
 */
tests_add_filter('muplugins_loaded', function () {
    $pluginsDir = dirname(__DIR__, 2); // /wp-content/plugins/

    // Load integration plugins if they exist.
    $integrations = [
        'polylang-pro/polylang.php', // Try Pro first (local dev).
        'polylang/polylang.php',    // Fall back to free (CI).
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

    // Load our plugin last.
    require dirname(__DIR__).'/gds-mcp.php';
});

// Start up the WP testing environment.
require getenv('WP_PHPUNIT__DIR').'/includes/bootstrap.php';

// Configure Polylang languages in the test DB.
if (function_exists('PLL') && PLL() && isset(PLL()->model)) {
    $languagesFile = dirname(__DIR__, 2).'/polylang/settings/languages.php';
    $knownLanguages = file_exists($languagesFile) ? include $languagesFile : [];
    $model = PLL()->model;

    foreach ([
        ['name' => 'English', 'slug' => 'en', 'locale' => 'en_US'],
        ['name' => 'Finnish', 'slug' => 'fi', 'locale' => 'fi'],
        ['name' => 'Swedish', 'slug' => 'sv', 'locale' => 'sv_SE'],
    ] as $lang) {
        if ($model->get_language($lang['slug'])) {
            continue;
        }
        $defaults = $knownLanguages[$lang['locale']] ?? [];
        $model->add_language(array_merge($defaults, $lang));
    }
}
