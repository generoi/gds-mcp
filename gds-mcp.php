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

use GeneroWP\MCP\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

if (file_exists($composer = __DIR__.'/vendor/autoload.php')) {
    require_once $composer;
}

Plugin::getInstance();
