<?php

namespace GeneroWP\MCP\Tests;

use WP_REST_Request;

/**
 * Base test case for MCP integration tests.
 *
 * Tests abilities through the REST API delegation layer (rest_do_request)
 * to verify the full execute path including REST parameter handling,
 * response formatting, and Polylang integration.
 *
 * Note: The MCP JSON-RPC transport layer (session management, tools/call routing)
 * cannot be tested via rest_do_request() because session headers rely on
 * rest_post_dispatch which only fires in serve_request(), not dispatch().
 * For MCP protocol-level testing, use the STDIO transport or HTTP client tests.
 */
class McpIntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure REST server is initialized so routes are registered.
        rest_get_server();
    }

    /**
     * Call a REST API endpoint internally via rest_do_request.
     */
    protected function restGet(string $route, array $params = []): \WP_REST_Response
    {
        $request = new WP_REST_Request('GET', $route);
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_do_request($request);
    }

    /**
     * Call a REST API endpoint internally via rest_do_request.
     */
    protected function restPost(string $route, array $body = []): \WP_REST_Response
    {
        $request = new WP_REST_Request('POST', $route);
        $request->set_header('Content-Type', 'application/json');
        foreach ($body as $key => $value) {
            $request->set_param($key, $value);
        }

        return rest_do_request($request);
    }
}
