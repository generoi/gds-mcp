<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Polylang;

use GeneroWP\MCP\Integrations\Polylang\CreateTranslationAbility;
use GeneroWP\MCP\Tests\TestCase;

/**
 * @group polylang-integration
 */
class CreateTranslationIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        if (! function_exists('pll_set_post_language') || ! function_exists('pll_default_language')) {
            $this->markTestSkipped('Polylang is not active.');
        }
    }

    public function test_creates_translation_and_links_via_polylang(): void
    {
        $sourceId = $this->createPost([
            'post_title' => 'Original Post',
            'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
            'post_type' => 'post',
            'post_status' => 'publish',
        ]);

        $defaultLang = pll_default_language();
        $targetLang = $defaultLang === 'en' ? 'fi' : 'en';

        // Check target language exists.
        $languages = pll_languages_list(['fields' => 'slug']);
        if (! in_array($targetLang, $languages, true)) {
            $this->markTestSkipped("Target language '{$targetLang}' not configured.");
        }

        $result = CreateTranslationAbility::execute([
            'source_post_id' => $sourceId,
            'language' => $targetLang,
            'post_title' => 'Translated Post',
            'post_status' => 'draft',
        ]);

        $this->assertIsArray($result);
        $this->assertGreaterThan(0, $result['id']);
        $this->assertSame('Translated Post', $result['title']);
        $this->assertSame('draft', $result['status']);
        $this->assertSame($targetLang, $result['language']);
        $this->assertSame($sourceId, $result['source_post_id']);

        // Verify Polylang translation link.
        $translations = pll_get_post_translations($sourceId);
        $this->assertArrayHasKey($targetLang, $translations);
        $this->assertSame($result['id'], $translations[$targetLang]);

        // Verify content was copied from source.
        $translatedPost = get_post($result['id']);
        $this->assertStringContainsString('Hello', $translatedPost->post_content);
    }

    public function test_rejects_duplicate_translation(): void
    {
        $sourceId = $this->createPost(['post_type' => 'post', 'post_status' => 'publish']);

        $defaultLang = pll_default_language();
        $targetLang = $defaultLang === 'en' ? 'fi' : 'en';

        $languages = pll_languages_list(['fields' => 'slug']);
        if (! in_array($targetLang, $languages, true)) {
            $this->markTestSkipped("Target language '{$targetLang}' not configured.");
        }

        // Create first translation.
        CreateTranslationAbility::execute([
            'source_post_id' => $sourceId,
            'language' => $targetLang,
            'post_title' => 'First',
        ]);

        // Try to create duplicate.
        $result = CreateTranslationAbility::execute([
            'source_post_id' => $sourceId,
            'language' => $targetLang,
            'post_title' => 'Duplicate',
        ]);

        $this->assertWPError($result);
        $this->assertSame('translation_exists', $result->get_error_code());
    }

    public function test_copies_meta_and_taxonomies(): void
    {
        $sourceId = $this->createPost([
            'post_type' => 'post',
            'post_status' => 'publish',
        ]);
        update_post_meta($sourceId, 'custom_field', 'test_value');
        wp_set_object_terms($sourceId, ['test-cat'], 'category');

        $defaultLang = pll_default_language();
        $targetLang = $defaultLang === 'en' ? 'fi' : 'en';

        $languages = pll_languages_list(['fields' => 'slug']);
        if (! in_array($targetLang, $languages, true)) {
            $this->markTestSkipped("Target language '{$targetLang}' not configured.");
        }

        $result = CreateTranslationAbility::execute([
            'source_post_id' => $sourceId,
            'language' => $targetLang,
        ]);

        $this->assertIsArray($result);

        // Meta should be copied.
        $this->assertSame('test_value', get_post_meta($result['id'], 'custom_field', true));

        // Taxonomy terms should be copied.
        $terms = wp_get_object_terms($result['id'], 'category', ['fields' => 'slugs']);
        $this->assertContains('test-cat', $terms);
    }
}
