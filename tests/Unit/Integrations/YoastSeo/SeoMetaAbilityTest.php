<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\YoastSeo;

use GeneroWP\MCP\Integrations\YoastSeo\GetSeoMetaAbility;
use GeneroWP\MCP\Integrations\YoastSeo\UpdateSeoMetaAbility;
use WP_UnitTestCase;

class SeoMetaAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_get_seo_meta_returns_fields(): void
    {
        $postId = self::factory()->post->create();
        update_post_meta($postId, '_yoast_wpseo_title', 'SEO Title');
        update_post_meta($postId, '_yoast_wpseo_metadesc', 'SEO Description');
        update_post_meta($postId, '_yoast_wpseo_focuskw', 'focus keyword');

        $result = GetSeoMetaAbility::execute(['post_id' => $postId]);

        $this->assertIsArray($result);
        $this->assertSame('SEO Title', $result['title']);
        $this->assertSame('SEO Description', $result['metadesc']);
        $this->assertSame('focus keyword', $result['focuskw']);
    }

    public function test_get_seo_meta_returns_error_for_missing_post(): void
    {
        $result = GetSeoMetaAbility::execute(['post_id' => 999999]);
        $this->assertWPError($result);
    }

    public function test_update_seo_meta_sets_fields(): void
    {
        $postId = self::factory()->post->create();

        $result = UpdateSeoMetaAbility::execute([
            'post_id' => $postId,
            'title' => 'New SEO Title',
            'metadesc' => 'New description',
            'focuskw' => 'new keyword',
        ]);

        $this->assertSame('New SEO Title', $result['title']);
        $this->assertSame('New SEO Title', get_post_meta($postId, '_yoast_wpseo_title', true));
        $this->assertSame('New description', get_post_meta($postId, '_yoast_wpseo_metadesc', true));
    }

    public function test_permission_denied_for_guest(): void
    {
        wp_set_current_user(0);
        $result = GetSeoMetaAbility::checkPermission(['post_id' => 1]);
        $this->assertWPError($result);
    }
}
