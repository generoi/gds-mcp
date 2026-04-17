<?php

namespace GeneroWP\MCP\Abilities;

use WP_Error;

/**
 * Fetch a URL and return its content as text/markdown for LLM consumption.
 *
 * Security:
 * - wp_safe_remote_get() blocks private/internal IPs by default (SSRF defense)
 * - Only http/https schemes
 * - Response size capped at 500 KB
 * - 15s timeout
 * - Requires `read` capability (minimal — any logged-in user)
 *
 * Content negotiation:
 * - Sends `Accept: text/markdown, text/plain;q=0.9, text/html;q=0.8, * /*;q=0.1`
 *   so servers that support markdown output (e.g. Roots' SEO plugin) return
 *   markdown directly. Falls back to HTML → text conversion otherwise.
 */
final class WebFetchAbility
{
    private const MAX_BYTES = 500 * 1024;

    private const TIMEOUT = 15;

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/web-fetch', [
            'label' => 'Fetch Web Page',
            'description' => 'Fetch a URL and return its text content. Requests markdown via Accept header; falls back to HTML stripped to text. Blocks private IPs (SSRF-safe). Use for reading external pages, blog posts, competitor sites, docs, etc.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'Full https:// URL to fetch (plain http is not allowed).',
                    ],
                    'max_length' => [
                        'type' => 'integer',
                        'description' => 'Truncate returned content to this many characters (default 20000, max 100000).',
                    ],
                ],
                'required' => ['url'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'url' => ['type' => 'string'],
                    'final_url' => ['type' => 'string'],
                    'status' => ['type' => 'integer'],
                    'content_type' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'content' => ['type' => 'string'],
                    'truncated' => ['type' => 'boolean'],
                ],
            ],
            'permission_callback' => [self::class, 'permissionCheck'],
            'execute_callback' => [self::class, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => true,
                    'destructive' => false,
                    'idempotent' => true,
                ],
            ],
        ]);
    }

    public static function permissionCheck(): bool
    {
        return current_user_can('read');
    }

    public static function execute(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $url = (string) ($input['url'] ?? '');
        $maxLength = min(100000, max(500, (int) ($input['max_length'] ?? 20000)));

        if ($url === '') {
            return new WP_Error('missing_url', 'The url parameter is required.');
        }

        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== 'https') {
            return new WP_Error('invalid_scheme', 'URL must use https. Plain http is not allowed.');
        }

        $host = wp_parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return new WP_Error('invalid_host', 'URL has no host component.');
        }

        // Defense-in-depth SSRF check: if the host is a literal IP, reject
        // private/reserved/loopback ranges directly. wp_safe_remote_get does
        // this too, but link-local (169.254/16, AWS metadata) has leaked
        // through in some WP versions. Belt + suspenders.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $isPublic = filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            );
            if (! $isPublic) {
                return new WP_Error('ssrf_blocked', 'Private or reserved IP addresses are not allowed.');
            }
        }

        // Optional allowlist. Sites that want to restrict which external
        // hosts the LLM can reach can add the filter returning an array of
        // allowed hostnames (exact match) or patterns like '*.example.com'.
        // Returning null (the default) means no restriction beyond SSRF.
        $allowlist = apply_filters('gds-mcp/web_fetch_allowed_hosts', null);
        if (is_array($allowlist) && ! self::hostMatchesAllowlist($host, $allowlist)) {
            return new WP_Error(
                'host_not_allowed',
                sprintf('Host "%s" is not in the allowlist.', $host)
            );
        }

        // wp_safe_remote_get blocks requests to private IP ranges (SSRF defense).
        // See WordPress http_request_host_is_external filter / wp_http_validate_url.
        $response = wp_safe_remote_get($url, [
            'timeout' => self::TIMEOUT,
            'redirection' => 3,
            'headers' => [
                'Accept' => 'text/markdown, text/plain;q=0.9, text/html;q=0.8, */*;q=0.1',
                'User-Agent' => self::userAgent(),
            ],
            'limit_response_size' => self::MAX_BYTES,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $contentType = wp_remote_retrieve_header($response, 'content-type') ?: '';
        $body = wp_remote_retrieve_body($response);

        if ($status >= 400) {
            return new WP_Error(
                'fetch_failed',
                sprintf('HTTP %d when fetching %s', $status, $url),
                ['status' => $status]
            );
        }

        $isMarkdown = str_contains($contentType, 'markdown') || str_contains($contentType, 'text/plain');
        $isHtml = str_contains($contentType, 'html');

        $title = '';
        if ($isHtml) {
            $title = self::extractTitle($body);
            $content = self::htmlToText($body);
        } elseif ($isMarkdown) {
            $content = trim($body);
            $title = self::extractMarkdownTitle($content);
        } else {
            // Unknown content type — return as-is (truncated)
            $content = $body;
        }

        $truncated = false;
        if (strlen($content) > $maxLength) {
            $content = substr($content, 0, $maxLength);
            $truncated = true;
        }

        return [
            'url' => $url,
            'final_url' => self::finalUrl($response, $url),
            'status' => $status,
            'content_type' => $contentType,
            'title' => $title,
            'content' => $content,
            'truncated' => $truncated,
        ];
    }

    private static function userAgent(): string
    {
        $site = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'wordpress';

        return sprintf('GDS-Assistant/1.0 (+https://%s)', $site);
    }

    private static function extractTitle(string $html): string
    {
        if (preg_match('/<title[^>]*>([^<]+)<\/title>/i', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    private static function extractMarkdownTitle(string $md): string
    {
        if (preg_match('/^#\s+(.+)$/m', $md, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    /**
     * Check whether a host matches any entry in the allowlist.
     * Entries may be exact hostnames or wildcards like "*.example.com".
     */
    private static function hostMatchesAllowlist(string $host, array $allowlist): bool
    {
        $host = strtolower($host);
        foreach ($allowlist as $allowed) {
            $allowed = strtolower(trim((string) $allowed));
            if ($allowed === '') {
                continue;
            }
            if ($allowed === $host) {
                return true;
            }
            // Wildcard: *.example.com matches foo.example.com and bar.baz.example.com
            if (str_starts_with($allowed, '*.')) {
                $suffix = substr($allowed, 1); // ".example.com"
                if (str_ends_with($host, $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Strip HTML to readable markdown-ish text. Extracts main content area
     * (<main>, <article>) if present, drops scripts/styles/chrome, preserves
     * headings, links, lists, and images as inline markdown.
     */
    private static function htmlToText(string $html): string
    {
        // Extract the main content area if present — pages wrapping content
        // in <main> or <article> get much cleaner output.
        if (preg_match('#<(main|article)\b[^>]*>(.*?)</\1>#is', $html, $main)) {
            $html = $main[2];
        }

        // Remove noisy non-content blocks.
        $html = preg_replace(
            '#<(script|style|noscript|iframe|svg|form|button|nav|footer|header|aside)\b[^>]*>.*?</\1>#is',
            ' ',
            $html
        ) ?? $html;

        // Strip HTML comments (often large in WP output — Yoast SEO etc.).
        $html = preg_replace('#<!--.*?-->#s', '', $html) ?? $html;

        // Convert headings to markdown-style.
        $html = preg_replace_callback(
            '#<h([1-6])[^>]*>(.*?)</h\1>#is',
            fn ($m) => "\n\n".str_repeat('#', (int) $m[1]).' '.trim(wp_strip_all_tags($m[2]))."\n\n",
            $html
        ) ?? $html;

        // Convert links.
        $html = preg_replace_callback(
            '#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
            fn ($m) => '['.trim(wp_strip_all_tags($m[2])).']('.$m[1].')',
            $html
        ) ?? $html;

        // Convert images to markdown.
        $html = preg_replace_callback(
            '#<img\b[^>]*src=["\']([^"\']+)["\'][^>]*(?:alt=["\']([^"\']*)["\'])?[^>]*/?>#is',
            fn ($m) => '![' . ($m[2] ?? '') . '](' . $m[1] . ')',
            $html
        ) ?? $html;

        // Convert list items to bullets.
        $html = preg_replace('#<li\b[^>]*>#i', "\n- ", $html) ?? $html;

        // Paragraphs / line breaks.
        $html = str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>'], "\n", $html);

        $text = wp_strip_all_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse excessive whitespace.
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        // Tidy bullet lines.
        $text = preg_replace('/\n +- /', "\n- ", $text) ?? $text;

        return trim($text);
    }

    private static function finalUrl(array $response, string $original): string
    {
        // WP doesn't expose the final URL directly on wp_remote_get responses,
        // but request data may include it via http_response headers.
        $http = $response['http_response'] ?? null;
        if (is_object($http) && method_exists($http, 'get_response_object')) {
            $resp = $http->get_response_object();
            if (is_object($resp) && isset($resp->url)) {
                return (string) $resp->url;
            }
        }

        return $original;
    }
}
