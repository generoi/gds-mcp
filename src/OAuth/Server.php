<?php

namespace GeneroWP\MCP\OAuth;

use Closure;

/**
 * OAuth 2.1 authorization server for the MCP endpoint.
 *
 * MCP clients (Claude Desktop, claude.ai, Claude Code) authenticate against a
 * remote MCP server with OAuth only — there is no field for a WordPress
 * application password. This module makes the site its own authorization
 * server so each person connects with their own WordPress account and keeps
 * their own capabilities, instead of the whole organisation sharing one
 * credential.
 *
 * Endpoints are served from `parse_request` rather than the REST API: the
 * discovery documents and the token endpoint must be reachable while logged
 * out, and sites routinely lock down `/wp-json/` for anonymous visitors.
 *
 * Disabled unless the site defines `GDS_MCP_OAUTH` as true.
 *
 * @see https://www.rfc-editor.org/rfc/rfc9728 Protected resource metadata
 * @see https://www.rfc-editor.org/rfc/rfc8414 Authorization server metadata
 * @see https://www.rfc-editor.org/rfc/rfc7591 Dynamic client registration
 */
final class Server
{
    /**
     * Path segment carrying the authorization, token, registration and
     * revocation endpoints, relative to the site home.
     */
    public const PATH = 'mcp-oauth';

    public static function register(): void
    {
        if (! self::enabled()) {
            return;
        }

        add_action('parse_request', [self::class, 'route'], 5);

        BearerAuth::register();
        Admin::register();

        add_action('gds_mcp_oauth_cleanup', [Grants::class, 'purgeExpired']);
        add_action('init', function (): void {
            if (! wp_next_scheduled('gds_mcp_oauth_cleanup')) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'gds_mcp_oauth_cleanup');
            }
        });
    }

    public static function enabled(): bool
    {
        $enabled = defined('GDS_MCP_OAUTH') && GDS_MCP_OAUTH;

        return (bool) apply_filters('gds-mcp/oauth_enabled', $enabled);
    }

    /**
     * Capability a user must hold to authorize an MCP client at all.
     *
     * Individual abilities are gated separately by CapabilityPolicy — this is
     * the coarse "may connect" gate.
     */
    public static function capability(): string
    {
        return (string) apply_filters('gds-mcp/oauth_capability', 'edit_posts');
    }

    /**
     * Dispatch the OAuth endpoints, which live outside the REST API.
     */
    public static function route(): void
    {
        $handler = self::match(self::requestPath());
        if ($handler === null) {
            return;
        }

        try {
            $handler();
        } catch (ResponseException $response) {
            $response->send();
            exit;
        }
    }

    /**
     * Resolve a request path to the endpoint that answers it.
     */
    public static function match(string $path): ?Closure
    {
        $home = self::homePath();
        $local = $home !== '' && str_starts_with($path, $home)
            ? substr($path, strlen($home))
            : $path;
        $local = '/'.ltrim($local, '/');

        // RFC 8414 §3: for an issuer with a path component the metadata lives
        // at /.well-known/oauth-authorization-server{path}. Serve the bare path
        // too, which is what a root-installed site advertises.
        if ($path === '/.well-known/oauth-authorization-server'.$home
            || $path === '/.well-known/oauth-authorization-server') {
            return fn () => Metadata::serveAuthorizationServer();
        }

        // RFC 9728 §3.1: clients probe the path-suffixed document first, then
        // the bare one. The suffix names which resource is being asked about.
        $prefix = '/.well-known/oauth-protected-resource';
        if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
            $suffix = substr($path, strlen($prefix));

            return fn () => Metadata::serveProtectedResource($suffix);
        }

        return match ($local) {
            '/'.self::PATH.'/authorize' => fn () => Authorize::handle(),
            '/'.self::PATH.'/token' => fn () => Token::handle(),
            '/'.self::PATH.'/register' => fn () => Clients::handleRegistration(),
            '/'.self::PATH.'/revoke' => fn () => Token::handleRevocation(),
            default => null,
        };
    }

    public static function issuer(): string
    {
        return untrailingslashit(home_url());
    }

    public static function endpoint(string $name): string
    {
        return home_url('/'.self::PATH.'/'.$name);
    }

    /**
     * Path of the current request, without query string or trailing slash.
     */
    public static function requestPath(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);

        return '/'.trim($path, '/');
    }

    /**
     * Path WordPress is installed under, '' when the site is at the root.
     */
    public static function homePath(): string
    {
        return untrailingslashit((string) wp_parse_url(home_url(), PHP_URL_PATH));
    }

    /**
     * Scheme, host and port of the site, taken from home_url() rather than the
     * request so a spoofed Host header cannot steer a redirect.
     */
    public static function origin(): string
    {
        $parts = wp_parse_url(home_url());
        $origin = $parts['scheme'].'://'.$parts['host'];

        return isset($parts['port']) ? $origin.':'.$parts['port'] : $origin;
    }

    public static function currentUrl(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';

        return self::origin().esc_url_raw($uri);
    }

    /**
     * OAuth requires TLS; local development environments are exempt so the
     * flow can be exercised over a self-signed DDEV certificate.
     */
    public static function isSecure(): bool
    {
        if (is_ssl() || str_starts_with(home_url(), 'https://')) {
            return true;
        }

        if (in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        return in_array(wp_get_environment_type(), ['local', 'development'], true);
    }

    /**
     * RFC 6749 §5.2 error response.
     */
    public static function error(string $code, string $description, int $status = 400): ResponseException
    {
        return ResponseException::json(
            ['error' => $code, 'error_description' => $description],
            $status
        );
    }

    /**
     * Answer a CORS preflight for the endpoints browser-based clients call.
     */
    public static function handlePreflight(string $methods): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'OPTIONS') {
            return;
        }

        throw ResponseException::noContent(204, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => $methods.', OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Authorization, MCP-Protocol-Version',
            'Access-Control-Max-Age' => '86400',
        ]);
    }
}
