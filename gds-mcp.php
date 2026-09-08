<?php

/*
Plugin Name:  GDS MCP
Plugin URI:   https://genero.fi
Description:  MCP abilities for content management, translations, and forms
Version:      1.0.0
Author:       Genero
Author URI:   https://genero.fi/
License:      MIT License
License URI:  http://opensource.org/licenses/MIT
*/

use GeneroWP\MCP\OAuth\Grants;
use GeneroWP\MCP\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

if (file_exists($composer = __DIR__.'/vendor/autoload.php')) {
    require_once $composer;
}

Plugin::getInstance();

// Deactivating is the first thing anyone reaches for when a connection is
// suspected of misuse, and it looks like revocation: bearer authentication
// stops and the connections screen goes with the menu. Without this the tokens
// are only dormant — reactivating, or a deploy restoring the plugin, brings
// every one of them back inside its 30-day life, and the screen that would
// have listed them was unavailable for the whole window.
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('gds_mcp_oauth_cleanup');

    if (class_exists(Grants::class)) {
        Grants::revokeAll();
    }
});
