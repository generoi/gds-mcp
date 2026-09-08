<?php

namespace GeneroWP\MCP\OAuth;

use RuntimeException;

/**
 * A finished HTTP response, thrown from an endpoint and sent at the router
 * boundary.
 *
 * OAuth endpoints answer and stop — they never fall through to WordPress.
 * Carrying that as an exception instead of `exit` keeps the handlers callable
 * from tests, where a redirect target or an error code is the thing under
 * test.
 */
final class ResponseException extends RuntimeException
{
    /**
     * @param  array<string, string>  $headers
     */
    private function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
        parent::__construct($body, $status);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public static function json(array $data, int $status = 200, array $headers = []): self
    {
        return new self(
            $status,
            array_merge([
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store',
                // Discovery documents are read cross-origin by browser-based
                // clients; every document served here is public.
                'Access-Control-Allow-Origin' => '*',
            ], $headers),
            (string) wp_json_encode($data)
        );
    }

    public static function redirect(string $url): self
    {
        return new self(302, ['Location' => $url, 'Cache-Control' => 'no-store'], '');
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self(
            $status,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Cache-Control' => 'no-store',
                // The consent screen grants the visitor's own account in one
                // click, and it renders outside wp-admin and wp-login, where
                // WordPress would send these itself. Framed, that click can be
                // stolen — the nonce rides along with it.
                'X-Frame-Options' => 'DENY',
                'Content-Security-Policy' => "frame-ancestors 'none'",
            ],
            $body
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    public static function noContent(int $status, array $headers = []): self
    {
        return new self($status, $headers, '');
    }

    /**
     * Where a redirect response points, or null for anything else.
     */
    public function location(): ?string
    {
        return $this->headers['Location'] ?? null;
    }

    /**
     * Decoded body of a JSON response.
     *
     * @return array<string, mixed>|null
     */
    public function data(): ?array
    {
        $data = json_decode($this->body, true);

        return is_array($data) ? $data : null;
    }

    public function send(): void
    {
        status_header($this->status);
        foreach ($this->headers as $name => $value) {
            header($name.': '.$value);
        }

        echo $this->body; // phpcs:ignore WordPress.Security.EscapeOutput -- Built escaped by the endpoint.
    }
}
