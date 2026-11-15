<?php

namespace GeneroWP\MCP\Tests\Unit\OAuth;

use GeneroWP\MCP\OAuth\Clients;
use GeneroWP\MCP\Tests\TestCase;

/**
 * Redirect URI rules. These decide where an authorization code may be sent,
 * so anything sloppy here is an open redirector with a live credential in the
 * query string.
 */
class ClientsTest extends TestCase
{
    public function test_https_and_loopback_uris_are_accepted(): void
    {
        $this->assertTrue(Clients::isAllowedRedirectUri('https://claude.ai/api/mcp/auth_callback'));
        $this->assertTrue(Clients::isAllowedRedirectUri('http://localhost:3118/callback'));
        $this->assertTrue(Clients::isAllowedRedirectUri('http://127.0.0.1:52301/callback'));
    }

    public function test_known_clients_callbacks_are_accepted_by_default(): void
    {
        $this->assertTrue(Clients::isAllowedRedirectUri('https://claude.com/api/mcp/auth_callback'));
        $this->assertTrue(Clients::isAllowedRedirectUri('https://chatgpt.com/connector_platform_oauth_redirect'));
        $this->assertTrue(Clients::isAllowedRedirectUri('https://global.consent.azure-apim.net/redirect/abc-123'));
        $this->assertTrue(Clients::isAllowedRedirectUri('https://teams.microsoft.com/api/platform/v1.0/oAuthRedirect'));
    }

    public function test_https_callbacks_on_other_hosts_are_rejected(): void
    {
        // Anyone can register, under any name: "Microsoft Copilot" with the
        // attacker's own callback must not get a code.
        $this->assertFalse(Clients::isAllowedRedirectUri('https://evil.example/callback'));
        $this->assertFalse(Clients::isAllowedRedirectUri('https://claude.ai.evil.example/api/mcp/auth_callback'));
        $this->assertFalse(Clients::isAllowedRedirectUri('https://evilconsent.azure-apim.net/redirect'));
    }

    public function test_the_filter_sets_the_allowed_hosts(): void
    {
        add_filter('gds-mcp/oauth_redirect_hosts', fn (array $hosts): array => [...$hosts, '*.example.org']);
        $this->assertTrue(Clients::isAllowedRedirectUri('https://app.example.org/callback'));
        $this->assertFalse(Clients::isAllowedRedirectUri('https://example.org/callback'), 'A wildcard covers subdomains only.');

        add_filter('gds-mcp/oauth_redirect_hosts', fn (): array => ['global.consent.azure-apim.net'], 20);
        $this->assertFalse(Clients::isAllowedRedirectUri('https://claude.ai/api/mcp/auth_callback'));
        $this->assertTrue(Clients::isAllowedRedirectUri('https://global.consent.azure-apim.net/redirect/abc'));
        // Loopback stays allowed: the code lands on the person's own machine.
        $this->assertTrue(Clients::isAllowedRedirectUri('http://127.0.0.1:52301/callback'));
    }

    public function test_a_client_whose_host_was_taken_off_the_list_no_longer_matches(): void
    {
        $client = ['redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']];
        add_filter('gds-mcp/oauth_redirect_hosts', fn (): array => ['chatgpt.com']);

        $this->assertFalse(Clients::matchRedirectUri($client, 'https://claude.ai/api/mcp/auth_callback'));
    }

    public function test_plaintext_and_malformed_uris_are_rejected(): void
    {
        $this->assertFalse(Clients::isAllowedRedirectUri('http://example.com/callback'));
        $this->assertFalse(Clients::isAllowedRedirectUri('/callback'));
        $this->assertFalse(Clients::isAllowedRedirectUri('javascript:alert(1)'));
        // A fragment would let a client smuggle the code somewhere else.
        $this->assertFalse(Clients::isAllowedRedirectUri('https://claude.ai/cb#/../evil'));
    }

    public function test_registered_uri_matches_exactly(): void
    {
        $client = ['redirect_uris' => ['https://claude.ai/api/mcp/auth_callback']];

        $this->assertTrue(Clients::matchRedirectUri($client, 'https://claude.ai/api/mcp/auth_callback'));
        $this->assertFalse(Clients::matchRedirectUri($client, 'https://claude.ai/api/mcp/auth_callback/x'));
        $this->assertFalse(Clients::matchRedirectUri($client, 'https://evil.example/api/mcp/auth_callback'));
    }

    public function test_loopback_uri_matches_on_any_port(): void
    {
        // Claude Code binds an ephemeral port per session (RFC 8252 §7.3).
        $client = ['redirect_uris' => ['http://localhost/callback']];

        $this->assertTrue(Clients::matchRedirectUri($client, 'http://localhost:3118/callback'));
        $this->assertTrue(Clients::matchRedirectUri($client, 'http://localhost:59123/callback'));
        $this->assertFalse(Clients::matchRedirectUri($client, 'http://localhost:3118/other'));
        $this->assertFalse(Clients::matchRedirectUri($client, 'http://127.0.0.1:3118/callback'));
    }
}
