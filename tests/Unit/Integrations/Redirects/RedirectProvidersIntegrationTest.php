<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Redirects;

use GeneroWP\MCP\Integrations\Redirects\Providers\Redirection;
use GeneroWP\MCP\Integrations\Redirects\Providers\SafeRedirectManager;
use GeneroWP\MCP\Integrations\Redirects\Providers\YoastRedirects;
use WP_UnitTestCase;

/**
 * Test each redirect provider directly, bypassing the priority detection.
 */
class RedirectProvidersIntegrationTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    // ── Safe Redirect Manager ────────────────────────────────────

    public function test_srm_create_and_list(): void
    {
        if (! SafeRedirectManager::isAvailable()) {
            $this->markTestSkipped('Safe Redirect Manager not active.');
        }

        $from = '/srm-test-'.uniqid();
        $result = SafeRedirectManager::create($from, '/srm-destination', ['status_code' => 301]);

        $this->assertIsArray($result);
        $this->assertSame('safe-redirect-manager', $result['provider']);
        $this->assertSame($from, $result['redirect']['from']);
        $this->assertSame(301, $result['redirect']['status_code']);

        // Verify it shows up in list.
        $list = SafeRedirectManager::list(50, 1);
        $froms = array_column($list['redirects'], 'from');
        $this->assertContains($from, $froms);
    }

    public function test_srm_list_is_paged(): void
    {
        if (! SafeRedirectManager::isAvailable()) {
            $this->markTestSkipped('Safe Redirect Manager not active.');
        }

        for ($i = 0; $i < 7; $i++) {
            SafeRedirectManager::create('/srm-paged-'.$i.'-'.uniqid(), '/dest', []);
        }

        $first = SafeRedirectManager::list(5, 1);
        $second = SafeRedirectManager::list(5, 2);

        $this->assertCount(5, $first['redirects']);
        $this->assertGreaterThanOrEqual(7, $first['total']);
        $this->assertSame([], array_intersect(array_column($first['redirects'], 'id'), array_column($second['redirects'], 'id')));
    }

    // ── Redirection ──────────────────────────────────────────────

    public function test_redirection_create_and_list(): void
    {
        if (! Redirection::isAvailable()) {
            $this->markTestSkipped('Redirection plugin not active.');
        }

        $from = '/redirection-test-'.uniqid();
        $result = Redirection::create($from, '/redirection-destination', ['status_code' => 302]);

        $this->assertIsArray($result);
        $this->assertSame('redirection', $result['provider']);
        $this->assertSame($from, $result['redirect']['from']);
        $this->assertSame(302, $result['redirect']['status_code']);

        // Verify it shows up in list.
        $list = Redirection::list(50, 1);
        $this->assertGreaterThanOrEqual(1, $list['total']);
        $froms = array_column($list['redirects'], 'from');
        $this->assertContains($from, $froms);
    }

    // ── Yoast Redirects ──────────────────────────────────────────

    public function test_yoast_create_and_list(): void
    {
        if (! YoastRedirects::isAvailable()) {
            $this->markTestSkipped('Yoast SEO not active.');
        }

        $postId = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Yoast Redirect Source',
        ]);

        $result = YoastRedirects::create((string) $postId, 'https://example.com/destination', []);

        $this->assertIsArray($result);
        $this->assertSame('yoast', $result['provider']);
        $this->assertSame($postId, $result['redirect']['id']);
        $this->assertSame('https://example.com/destination', $result['redirect']['to']);

        // Verify meta was stored.
        $stored = get_post_meta($postId, '_yoast_wpseo_redirect', true);
        $this->assertSame('https://example.com/destination', $stored);

        // Verify it shows up in list.
        $list = YoastRedirects::list(50, 1);
        $this->assertGreaterThanOrEqual(1, $list['total']);
        $ids = array_column($list['redirects'], 'id');
        $this->assertContains($postId, $ids);
    }
}
