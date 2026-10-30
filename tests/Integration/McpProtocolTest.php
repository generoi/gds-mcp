<?php

namespace GeneroWP\MCP\Tests\Integration;

use GeneroWP\MCP\Tests\AbilityTestCase;
use WP\MCP\Core\McpAdapter;

/**
 * End-to-end tests through the MCP protocol layer (JSON-RPC tools/call).
 *
 * Requests go through the server's REST route, the adapter's public contract,
 * rather than its internal RequestRouter (whose signature changed in 0.7):
 * route → transport → ToolsHandler → WP_Ability::execute() → response.
 *
 * This covers:
 * - Tool name resolution (ability name → MCP tool)
 * - JSON-RPC request/response formatting
 * - Error handling (not_found, permission errors)
 * - The structuredContent response envelope
 */
class McpProtocolTest extends AbilityTestCase
{
    private const PROTOCOL_VERSION = '2025-06-18';

    private string $route;

    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(McpAdapter::class)) {
            $this->markTestSkipped('MCP adapter is not loaded.');
        }

        // The adapter registers its servers on rest_api_init; make sure that
        // has run in this process.
        if (McpAdapter::instance()->get_servers() === []) {
            $GLOBALS['wp_rest_server'] = null;
            rest_get_server();
        }

        $servers = McpAdapter::instance()->get_servers();
        if (empty($servers)) {
            $this->markTestSkipped('No MCP servers registered.');
        }

        $server = reset($servers);
        $this->route = '/'.$server->get_server_route_namespace().'/'.$server->get_server_route();

        $init = $this->post([
            'jsonrpc' => '2.0',
            'id' => 0,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'gds-mcp-tests', 'version' => '1.0'],
            ],
        ]);
        $this->sessionId = (string) ($init->get_headers()['Mcp-Session-Id'] ?? '');
        $this->assertNotSame('', $this->sessionId, 'initialize should open a session: '.wp_json_encode($init->get_data()));

        // Verify gds/* tools are exposed through their own registration metadata.
        $toolNames = array_column($this->rpc('tools/list')['tools'] ?? [], 'name');
        if (! in_array('gds/help', $toolNames, true)) {
            $this->markTestSkipped('MCP adapter loaded but gds/* tools not registered.');
        }
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function post(array $message): \WP_REST_Response
    {
        $request = new \WP_REST_Request('POST', $this->route);
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('Accept', 'application/json, text/event-stream');
        if (isset($this->sessionId)) {
            $request->set_header('Mcp-Session-Id', $this->sessionId);
            $request->set_header('MCP-Protocol-Version', self::PROTOCOL_VERSION);
        }
        $request->set_body((string) wp_json_encode($message));

        return rest_do_request($request);
    }

    /**
     * Send a JSON-RPC request; returns its result, or ['error' => …].
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = [], int $requestId = 1): array
    {
        $message = ['jsonrpc' => '2.0', 'id' => $requestId, 'method' => $method];
        if ($params !== []) {
            $message['params'] = $params;
        }
        $data = json_decode((string) wp_json_encode($this->post($message)->get_data()), true) ?: [];

        return isset($data['error']) ? ['error' => $data['error']] : ($data['result'] ?? []);
    }

    /**
     * Call a tool through the MCP JSON-RPC protocol.
     */
    private function callTool(string $name, array $arguments = [], int $requestId = 1): array
    {
        return $this->rpc('tools/call', [
            'name' => $name,
            'arguments' => (object) $arguments,
        ], $requestId);
    }

    // ── Tools Discovery ───────────────────────────────────────────

    public function test_tools_list_includes_gds_abilities(): void
    {
        $response = $this->rpc('tools/list');

        $this->assertIsArray($response);
        $this->assertArrayHasKey('tools', $response);

        $toolNames = array_column($response['tools'], 'name');
        $this->assertContains('gds/help', $toolNames);
        $this->assertContains('gds/content-list', $toolNames);
    }

    public function test_tools_list_includes_input_schema(): void
    {
        $response = $this->rpc('tools/list');

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
