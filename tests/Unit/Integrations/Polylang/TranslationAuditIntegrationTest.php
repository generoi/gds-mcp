<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Polylang;

use GeneroWP\MCP\Integrations\Polylang\TranslationAuditAbility;
use GeneroWP\MCP\Tests\TestCase;

class TranslationAuditIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        if (! function_exists('pll_set_post_language') || ! function_exists('pll_default_language')) {
            $this->markTestSkipped('Polylang is not active.');
        }
    }

    public function test_audit_finds_untranslated_content(): void
    {
        $languages = pll_languages_list(['fields' => 'slug']);
        if (count($languages) < 2) {
            $this->markTestSkipped('Need at least 2 languages.');
        }

        // Create a post with only the default language.
        $this->createPost([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_title' => 'Untranslated Post',
        ]);

        $result = TranslationAuditAbility::execute(['post_type' => 'post']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertGreaterThan(0, $result['summary']['total_posts']);

        // Should find missing translations.
        $allMissing = [];
        foreach ($result['by_type'] as $type) {
            foreach ($type['missing'] as $missing) {
                $allMissing[] = $missing['title'];
            }
        }
        $this->assertContains('Untranslated Post', $allMissing);
    }

    public function test_audit_returns_structure(): void
    {
        $result = TranslationAuditAbility::execute([]);

        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('total_posts', $result['summary']);
        $this->assertArrayHasKey('fully_translated', $result['summary']);
        $this->assertArrayHasKey('languages', $result);
        $this->assertArrayHasKey('by_type', $result);
    }
}
