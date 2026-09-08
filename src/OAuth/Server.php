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
 * Endpoints are served from `parse_request` rather than the REST API, because
 * the authorization endpoint cannot live there: `rest_cookie_check_errors()`
 * calls `wp_set_current_user(0)` for any cookie-authenticated request that
 * carries no `wp_rest` nonce, and a client redirecting a browser to the
 * consent screen has no nonce to send — the endpoint would see every visitor
 * as logged out. The discovery documents also sit at `/.well-known/`, outside
 * `/wp-json/` entirely, and OAuth error bodies have a shape of their own that
 * the REST error envelope would rewrite.
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
        // …and clients also try the OpenID-style location under the issuer
        // itself. A site at the root serves both from the same request path.
        if ($path === '/.well-known/oauth-authorization-server'.$home
            || $local === '/.well-known/oauth-authorization-server') {
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
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = (string) wp_parse_url(self::clean($uri), PHP_URL_PATH);

        return '/'.trim($path, '/');
    }

    /**
     * Read an OAuth parameter from a request array without mangling it.
     *
     * `sanitize_text_field()` deletes percent-encoded sequences and collapses
     * whitespace. Applied to an opaque `state`, a PKCE verifier or a token it
     * silently rewrites a value that is then compared byte for byte, so these
     * are only stripped of control characters — which is what would let a
     * value break out into a header — and length-capped.
     *
     * @param  array<string, mixed>  $source
     */
    public static function param(array $source, string $key, int $max = 2048): string
    {
        if (! isset($source[$key]) || ! is_string($source[$key])) {
            return '';
        }

        return substr(self::clean(wp_unslash($source[$key])), 0, $max);
    }

    /**
     * Strip control characters, including the CR and LF of header injection.
     */
    public static function clean(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]/', '', $value));
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
        // The request itself has to be encrypted, not merely the canonical
        // URL: a site reachable over both schemes would otherwise hand out
        // consent screens and tokens in the clear.
        if (is_ssl()) {
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
