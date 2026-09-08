<?php

/**
 * Remove everything the OAuth module stores.
 *
 * Deleting the plugin must not leave live credentials behind: grants live in
 * user meta and refresh tokens and client registrations in options, none of
 * which WordPress cleans up on its own. Written as direct queries rather than
 * through the module's own classes, since uninstall runs without the plugin
 * loaded.
 */
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
        $wpdb->esc_like('_gds_mcp_oauth_grant_').'%'
    )
);

foreach (['gds_mcp_oauth_rt_', 'gds_mcp_oauth_client_'] as $prefix) {
    $names = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like($prefix).'%'
        )
    );

    foreach ($names as $name) {
        delete_option($name);
    }
}

delete_option('gds_mcp_oauth_clients');
wp_clear_scheduled_hook('gds_mcp_oauth_cleanup');
