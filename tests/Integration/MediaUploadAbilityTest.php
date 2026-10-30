<?php

namespace GeneroWP\MCP\Tests\Integration;

use GeneroWP\MCP\Http\PublicUrlGuard;
use GeneroWP\MCP\Tests\AbilityTestCase;
use GeneroWP\MCP\Tests\Fixtures\RedirectingTransport;

class MediaUploadAbilityTest extends AbilityTestCase
{
    /** 1x1 red PNG pixel, base64 encoded. */
    private const PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8D4HwAFBQIAX8jx0gAAAABJRU5ErkJggg==';

    public function test_ability_is_registered(): void
    {
        $this->assertAbilityRegistered('gds/media-upload');
    }

    // ── Base64 uploads ────────────────────────────────────────────

    public function test_upload_base64_image(): void
    {
        $result = $this->assertAbilitySuccess('gds/media-upload', [
            'base64' => self::PIXEL_PNG,
            'filename' => 'test-pixel.png',
        ]);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('url', $result);
        $this->assertGreaterThan(0, $result['id']);
        $this->assertStringContainsString('test-pixel', $result['url']);
        $this->assertSame('image', $result['media_type']);
        $this->assertSame('image/png', $result['mime_type']);

        wp_delete_attachment($result['id'], true);
    }

    public function test_upload_with_alt_text_and_title(): void
    {
        $result = $this->assertAbilitySuccess('gds/media-upload', [
            'base64' => self::PIXEL_PNG,
            'filename' => 'hero-image.png',
            'alt_text' => 'A red pixel',
            'title' => 'Hero Image',
            'caption' => 'The smallest hero.',
        ]);

        $this->assertSame('Hero Image', $result['title']);
        $this->assertSame('A red pixel', $result['alt_text']);
        $this->assertSame('The smallest hero.', get_post($result['id'])->post_excerpt);

        wp_delete_attachment($result['id'], true);
    }

    public function test_upload_with_post_parent(): void
    {
        $postId = $this->createPost(['post_type' => 'post', 'post_status' => 'publish']);

        $result = $this->assertAbilitySuccess('gds/media-upload', [
            'base64' => self::PIXEL_PNG,
            'filename' => 'attached.png',
            'post_parent' => $postId,
        ]);

        $this->assertSame($postId, get_post($result['id'])->post_parent);

        wp_delete_attachment($result['id'], true);
    }

    public function test_post_parent_requires_edit_post_on_it(): void
    {
        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        $othersPost = $this->createPost(['post_type' => 'post', 'post_status' => 'publish', 'post_author' => self::factory()->user->create(['role' => 'editor'])]);

        $this->assertAbilityError('gds/media-upload', [
            'base64' => self::PIXEL_PNG,
            'filename' => 'attached.png',
            'post_parent' => $othersPost,
        ], 'forbidden');
        $this->assertSame([], get_children(['post_parent' => $othersPost, 'post_type' => 'attachment']));
    }

    public function test_upload_strips_data_uri_prefix(): void
    {
        $result = $this->assertAbilitySuccess('gds/media-upload', [
            'base64' => 'data:image/png;base64,'.self::PIXEL_PNG,
            'filename' => 'data-uri.png',
        ]);

        $this->assertSame('image/png', $result['mime_type']);

        wp_delete_attachment($result['id'], true);
    }

    // ── Validation ────────────────────────────────────────────────

    public function test_requires_source(): void
    {
        $this->assertAbilityError('gds/media-upload', [
            'filename' => 'no-data.png',
        ], 'missing_source');
    }

    public function test_rejects_both_base64_and_url(): void
    {
        $this->assertAbilityError('gds/media-upload', [
            'base64' => self::PIXEL_PNG,
            'url' => 'https://example.com/image.png',
            'filename' => 'both.png',
        ], 'ambiguous_source');
    }

    public function test_base64_requires_filename(): void
    {
        $this->assertAbilityError('gds/media-upload', [
            'base64' => self::PIXEL_PNG,
        ], 'missing_filename');
    }

    public function test_rejects_invalid_base64(): void
    {
        $this->assertAbilityError('gds/media-upload', [
            'base64' => 'not-valid-base64!!!',
            'filename' => 'bad.png',
        ], 'invalid_base64');
    }

    public function test_rejects_non_http_url(): void
    {
        $this->assertAbilityError('gds/media-upload', [
            'url' => 'file:///etc/passwd',
            'filename' => 'exploit.txt',
        ], 'invalid_url');
    }

    public function test_rejects_plain_http_url(): void
    {
        $this->assertAbilityError('gds/media-upload', ['url' => 'http://example.com/a.png'], 'invalid_url');
    }

    public function test_refuses_a_download_that_redirects_to_a_non_public_address(): void
    {
        $host = WebFetchAbilityTest::hostCoreLetsThrough();
        RedirectingTransport::install("https://{$host}/secret.png");
        PublicUrlGuard::$resolver = static fn (string $host): array => ['fe80::1'];

        try {
            $error = $this->assertAbilityError('gds/media-upload', ['url' => 'https://93.184.216.34/a.png']);
        } finally {
            RedirectingTransport::uninstall();
            PublicUrlGuard::$resolver = null;
        }

        $this->assertStringContainsString('private or reserved address (fe80::1)', $error->get_error_message());
        $this->assertCount(1, RedirectingTransport::$requested, 'The redirect target is never requested.');
    }

    // ── Permissions ───────────────────────────────────────────────

    public function test_upload_blocked_for_subscriber(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $this->assertAbilityError('gds/media-upload', [
            'base64' => self::PIXEL_PNG,
            'filename' => 'blocked.png',
        ], 'ability_invalid_permissions');
    }

    // ── Filename sanitization ─────────────────────────────────────

    public function test_sanitizes_filename(): void
    {
        $result = $this->assertAbilitySuccess('gds/media-upload', [
            'base64' => self::PIXEL_PNG,
            'filename' => '../../../etc/passwd.png',
        ]);

        // sanitize_file_name strips path traversal
        $this->assertStringNotContainsString('..', $result['url']);

        wp_delete_attachment($result['id'], true);
    }

    public function test_url_without_extension_gets_one_from_the_file_type(): void
    {
        $pixel = base64_decode(self::PIXEL_PNG);
        $filter = static function ($pre, $args) use ($pixel) {
            if (! empty($args['filename'])) {
                file_put_contents($args['filename'], $pixel);
            }

            return ['headers' => ['content-type' => 'image/png'], 'body' => '', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => $args['filename'] ?? null];
        };
        add_filter('pre_http_request', $filter, 10, 2);

        try {
            $result = $this->assertAbilitySuccess('gds/media-upload', ['url' => 'https://tools.example.com/a/AbCdEfGhIjKlMnOpQrStUv']);
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }

        $this->assertSame('image/png', $result['mime_type']);
        $this->assertStringEndsWith('.png', $result['url']);

        wp_delete_attachment($result['id'], true);
    }
}
