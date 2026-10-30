<?php

namespace GeneroWP\MCP\Tests\Unit;

use GeneroWP\MCP\Plugin;
use WP_UnitTestCase;

class PluginTest extends WP_UnitTestCase
{
    public function test_adapter_is_booted_through_its_plugin_class(): void
    {
        if (! class_exists('\\WP\\MCP\\Plugin')) {
            $this->markTestSkipped('MCP adapter not loaded.');
        }

        Plugin::getInstance()->bootstrapMcpAdapter();

        // Defined by WP\MCP\Plugin::constants(); McpAdapter on its own warns
        // that it runs as a bundled library when this is missing.
        $this->assertTrue(defined('WP_MCP_VERSION'));
        $this->assertFalse(has_action('admin_notices', [Plugin::class, 'missingAdapterNotice']));
    }

    public function test_plugin_does_not_require_the_adapter_plugin(): void
    {
        // Sites that load wordpress/mcp-adapter as a Composer library have no
        // mcp-adapter plugin, so the header would block activation there.
        $data = get_file_data(dirname(__DIR__, 2).'/gds-mcp.php', ['RequiresPlugins' => 'Requires Plugins']);

        $this->assertSame('', $data['RequiresPlugins']);
    }

    public function test_missing_adapter_notice_is_shown_to_plugin_managers_only(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        ob_start();
        Plugin::missingAdapterNotice();
        $this->assertStringContainsString('MCP Adapter', (string) ob_get_clean());

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        ob_start();
        Plugin::missingAdapterNotice();
        $this->assertSame('', ob_get_clean());
    }
}
