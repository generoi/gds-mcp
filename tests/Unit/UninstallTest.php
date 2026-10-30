<?php

namespace GeneroWP\MCP\Tests\Unit;

use WP_UnitTestCase;

class UninstallTest extends WP_UnitTestCase
{
    public function test_uninstall_removes_the_oauth_credentials(): void
    {
        update_option('gds_mcp_oauth_rt_abc', ['user' => 1]);
        update_option('gds_mcp_oauth_client_xyz', ['name' => 'Claude']);
        update_option('gds_mcp_oauth_clients', ['xyz']);
        $userId = self::factory()->user->create();
        update_user_meta($userId, 'wp_gds_mcp_oauth_grant_xyz', ['scope' => 'mcp']);
        update_user_option($userId, 'gds_mcp_trusted_hosts', ['a.example']);
        update_option('gds_mcp_trusted_hosts', ['b.example']);

        if (! defined('WP_UNINSTALL_PLUGIN')) {
            define('WP_UNINSTALL_PLUGIN', 'gds-mcp/gds-mcp.php');
        }
        require dirname(__DIR__, 2).'/uninstall.php';

        $this->assertFalse(get_option('gds_mcp_oauth_rt_abc'));
        $this->assertFalse(get_option('gds_mcp_oauth_client_xyz'));
        $this->assertFalse(get_option('gds_mcp_oauth_clients'));
        global $wpdb;
        $this->assertSame('0', $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE '%gds\\_mcp\\_oauth\\_grant\\_%'"));
        $this->assertSame('0', $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE '%gds\\_mcp\\_trusted\\_hosts'"));
        $this->assertFalse(get_option('gds_mcp_trusted_hosts'));
    }
}
