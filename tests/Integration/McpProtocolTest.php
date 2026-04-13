<?php

namespace GeneroWP\MCP\Tests\Integration;

use GeneroWP\MCP\Tests\AbilityTestCase;
use WP\MCP\Core\McpAdapter;

/**
 * End-to-end tests through the MCP protocol layer (JSON-RPC tools/call).
 *
 * Tests the full path: McpAdapter → McpServer → RequestRouter → ToolsHandler
 * → WP_Ability::execute() → response formatting.
 *
 * This covers:
 * - Tool name resolution (ability name → MCP tool)
 * - JSON-RPC request/response formatting
 * - Error handling (not_found, permission errors)
 * - The structuredContent response envelope
 */
class McpProtocolTest extends AbilityTestCase
{
    private $router;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(McpAdapter::class)) {
            $this->markTestSkipped('MCP adapter is not loaded.');
        }

        $adapter = McpAdapter::instance();
        $servers = $adapter->get_servers();
        if (empty($servers)) {
            $this->markTestSkipped('No MCP servers registered.');
        }

        $server = reset($servers);
        $context = $server->create_transport_context();
        $this->router = $context->request_router;

        // Verify gds/* tools are exposed (requires mcp.public filter).
        $response = $this->router->route_request('tools/list', [], 0);
        $toolNames = array_column($response['tools'] ?? [], 'name');
        if (! in_array('gds/help', $toolNames, true)) {
            $this->markTestSkipped('MCP adapter loaded but gds/* tools not registered (mcp.public filter may not be active).');
        }
    }

    /**
     * Call a tool through the MCP JSON-RPC protocol.
     */
    private function callTool(string $name, array $arguments = [], int $requestId = 1): array
    {
        return $this->router->route_request('tools/call', [
            'name' => $name,
            'arguments' => $arguments,
        ], $requestId);
    }

    // ── Tools Discovery ───────────────────────────────────────────

    public function test_tools_list_includes_gds_abilities(): void
    {
        $response = $this->router->route_request('tools/list', [], 1);

        $this->assertIsArray($response);
        $this->assertArrayHasKey('tools', $response);

        $toolNames = array_column($response['tools'], 'name');
        $this->assertContains('gds/help', $toolNames);
        $this->assertContains('gds/content-list', $toolNames);
    }

    public function test_tools_list_includes_input_schema(): void
    {
        $response = $this->router->route_request('tools/list', [], 1);

        foreach ($response['tools'] as $tool) {
            if (! str_starts_with($tool['name'], 'gds/')) {
                continue;
            }
            $this->assertArrayHasKey('inputSchema', $tool, "Tool '{$tool['name']}' missing inputSchema.");
        }
    }

    // ── Successful Tool Calls ─────────────────────────────────────

    public function test_call_help_tool(): void
    {
        $response = $this->callTool('gds/help');

        $this->assertArrayHasKey('content', $response);
        $this->assertIsArray($response['content']);
        $this->assertNotEmpty($response['content']);

        // Content should be text format
        $this->assertSame('text', $response['content'][0]['type']);

        // Should have structuredContent
        $this->assertArrayHasKey('structuredContent', $response);
        $this->assertArrayHasKey('groups', $response['structuredContent']);
        $this->assertArrayHasKey('total', $response['structuredContent']);
    }

    public function test_call_content_list_tool(): void
    {
        $this->createPost(['post_type' => 'post', 'post_status' => 'publish']);

        $response = $this->callTool('gds/content-list', [
            'type' => 'posts',
            'per_page' => 1,
        ]);

        $this->assertArrayHasKey('structuredContent', $response);
        $this->assertArrayHasKey('posts', $response['structuredContent']);
        $this->assertArrayHasKey('total', $response['structuredContent']);
    }

    public function test_call_content_read_tool(): void
    {
        $id = $this->createPost([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'MCP Protocol Test',
        ]);

        $response = $this->callTool('gds/content-read', [
            'type' => 'pages',
            'id' => $id,
        ]);

        $this->assertArrayHasKey('structuredContent', $response);
        $this->assertSame($id, $response['structuredContent']['id']);
    }

    public function test_call_terms_list_tool(): void
    {
        $response = $this->callTool('gds/terms-list', [
            'taxonomy' => 'categories',
            'hide_empty' => false,
        ]);

        $this->assertArrayHasKey('structuredContent', $response);
        $this->assertIsArray($response['structuredContent']);
    }

    public function test_call_site_map_tool(): void
    {
        $response = $this->callTool('gds/site-map');

        $this->assertArrayHasKey('content', $response);
        $this->assertArrayHasKey('structuredContent', $response);
    }

    public function test_call_translation_audit_tool(): void
    {
        if (! function_exists('pll_get_post_language')) {
            $this->markTestSkipped('Polylang not active.');
        }

        $response = $this->callTool('gds/translations-audit', [
            'post_type' => 'post',
        ]);

        $this->assertArrayHasKey('structuredContent', $response);
        $this->assertArrayHasKey('summary', $response['structuredContent']);
    }

    // ── Error Handling ────────────────────────────────────────────

    public function test_call_nonexistent_tool_returns_error(): void
    {
        $response = $this->callTool('gds/nonexistent-tool');

        // Should be a JSON-RPC error, not a tool result
        $this->assertArrayHasKey('error', $response);
    }

    public function test_call_with_invalid_input_returns_error(): void
    {
        $response = $this->callTool('gds/content-list', [
            'type' => 'nonexistent_type',
        ]);

        // Should have isError flag in the content
        $this->assertTrue(
            ! empty($response['isError']) || ! empty($response['error']),
            'Invalid input should produce an error response.'
        );
    }

    // ── Response Format ───────────────────────────────────────────

    public function test_response_content_is_json_text(): void
    {
        $response = $this->callTool('gds/help');

        $content = $response['content'][0];
        $this->assertSame('text', $content['type']);

        // The text should be valid JSON matching the structuredContent
        $decoded = json_decode($content['text'], true);
        $this->assertNotNull($decoded, 'Content text should be valid JSON.');
        $this->assertSame($response['structuredContent'], $decoded);
    }
}
