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

        // Ensure languages exist (WP_UnitTestCase rollback removes them).
        $model = PLL()->model;
        $model->clean_languages_cache();
        foreach ([
            ['name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'term_group' => 0],
            ['name' => 'Finnish', 'slug' => 'fi', 'locale' => 'fi', 'term_group' => 1],
        ] as $lang) {
            if (! $model->get_language($lang['slug'])) {
                $model->add_language($lang);
            }
        }
        $model->update_default_lang('en');
        $model->clean_languages_cache();
    }

    public function test_creates_translation_and_links_via_polylang(): void
    {
        $sourceId = $this->createPost([
            'title' => 'Original Post',
            'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
            'post_type' => 'post',
            'status' => 'publish',
        ]);

        $defaultLang = pll_default_language();
        $targetLang = $defaultLang === 'en' ? 'fi' : 'en';

        // Check target language exists.
        $languages = pll_languages_list(['fields' => 'slug']);
        if (! in_array($targetLang, $languages, true)) {
            $this->markTestSkipped("Target language '{$targetLang}' not configured.");
        }

        $result = (new CreateTranslationAbility)->execute([
            'source_id' => $sourceId,
            'lang' => $targetLang,
            'title' => 'Translated Post',
            'status' => 'draft',
        ]);

        $this->assertIsArray($result);
        $this->assertGreaterThan(0, $result['id']);
        $this->assertSame('Translated Post', get_the_title($result['id']));
        $this->assertSame('draft', $result['status']);
        $this->assertArrayNotHasKey('content', $result, 'The result is a summary unless quiet is false.');

        // Verify Polylang translation link.
        clean_post_cache($sourceId);
        clean_post_cache($result['id']);
        $translations = pll_get_post_translations($sourceId);
        $this->assertArrayHasKey($targetLang, $translations, 'Translation should be linked. Got: '.wp_json_encode($translations));
        $this->assertSame($result['id'], $translations[$targetLang]);

        // Verify content was copied from source.
        $translatedPost = get_post($result['id']);
        $this->assertStringContainsString('Hello', $translatedPost->post_content);
    }

    public function test_rejects_duplicate_translation(): void
    {
        $sourceId = $this->createPost(['post_type' => 'post', 'status' => 'publish']);

        $defaultLang = pll_default_language();
        $targetLang = $defaultLang === 'en' ? 'fi' : 'en';

        $languages = pll_languages_list(['fields' => 'slug']);
        if (! in_array($targetLang, $languages, true)) {
            $this->markTestSkipped("Target language '{$targetLang}' not configured.");
        }

        // Create first translation.
        $first = (new CreateTranslationAbility)->execute([
            'source_id' => $sourceId,
            'lang' => $targetLang,
            'title' => 'First',
        ]);
        $this->assertIsArray($first, 'First translation should succeed');
        clean_post_cache($sourceId);

        // Try to create duplicate.
        $result = (new CreateTranslationAbility)->execute([
            'source_id' => $sourceId,
            'lang' => $targetLang,
            'title' => 'Duplicate',
        ]);

        $this->assertWPError($result);
        $this->assertSame('translation_exists', $result->get_error_code());
    }

    public function test_preserves_backslashes_in_content_and_meta(): void
    {
        $content = '<!-- wp:paragraph {"placeholder":"say \"hi\" \u003cb\u003e"} --><p>C:\temp</p><!-- /wp:paragraph -->';
        $json = '{"label":"a \"quoted\" \u003cword\u003e"}';
        $sourceId = $this->createPost(['post_type' => 'post', 'post_content' => wp_slash($content)]);
        update_post_meta($sourceId, 'json_field', wp_slash($json));

        $result = (new CreateTranslationAbility)->execute(['source_id' => $sourceId, 'lang' => 'fi']);

        $this->assertIsArray($result);
        $this->assertSame($content, get_post($result['id'])->post_content);
        $this->assertSame($json, get_post_meta($result['id'], 'json_field', true));
    }

    public function test_published_translation_keeps_password_and_discussion_settings(): void
    {
        $sourceId = $this->createPost([
            'post_type' => 'post',
            'post_status' => 'publish',
            'post_password' => 'hunter2',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ]);

        $result = (new CreateTranslationAbility)->execute(['source_id' => $sourceId, 'lang' => 'fi', 'status' => 'publish']);

        $this->assertIsArray($result);
        $translation = get_post($result['id']);
        $this->assertSame('hunter2', $translation->post_password);
        $this->assertSame('closed', $translation->comment_status);
        $this->assertSame('closed', $translation->ping_status);
    }

    public function test_translation_keeps_the_product_identifiers_a_duplicate_drops(): void
    {
        $sourceId = $this->createPost(['post_type' => 'post']);
        $keys = ['_sku' => 'SKU-1', '_global_unique_id' => '0123456789012', 'total_sales' => '12', '_wc_review_count' => '3'];
        foreach ($keys as $key => $value) {
            update_post_meta($sourceId, $key, $value);
        }

        $result = (new CreateTranslationAbility)->execute(['source_id' => $sourceId, 'lang' => 'fi']);

        // Polylang for WooCommerce shares these across a product's languages.
        foreach ($keys as $key => $value) {
            $this->assertSame($value, get_post_meta($result['id'], $key, true), $key);
        }
    }

    public function test_contributor_cannot_publish_a_translation(): void
    {
        $contributor = self::factory()->user->create(['role' => 'contributor']);
        wp_set_current_user($contributor);
        $sourceId = $this->createPost(['post_type' => 'post', 'post_author' => $contributor, 'post_status' => 'draft']);

        $result = (new CreateTranslationAbility)->execute(['source_id' => $sourceId, 'lang' => 'fi', 'status' => 'publish']);

        $this->assertWPError($result);
        $this->assertSame('forbidden', $result->get_error_code());
        $this->assertEmpty(pll_get_post_translations($sourceId)['fi'] ?? null, 'Nothing is created.');
    }

    public function test_copies_meta_and_taxonomies(): void
    {
        $sourceId = $this->createPost([
            'post_type' => 'post',
            'status' => 'publish',
        ]);
        update_post_meta($sourceId, 'custom_field', 'test_value');
        wp_set_object_terms($sourceId, ['test-cat'], 'category');

        $defaultLang = pll_default_language();
        $targetLang = $defaultLang === 'en' ? 'fi' : 'en';

        $languages = pll_languages_list(['fields' => 'slug']);
        if (! in_array($targetLang, $languages, true)) {
            $this->markTestSkipped("Target language '{$targetLang}' not configured.");
        }

        $result = (new CreateTranslationAbility)->execute([
            'source_id' => $sourceId,
            'lang' => $targetLang,
        ]);

        $this->assertIsArray($result);

        // Meta should be copied.
        $this->assertSame('test_value', get_post_meta($result['id'], 'custom_field', true));

        // Taxonomy terms should be copied.
        $terms = wp_get_object_terms($result['id'], 'category', ['fields' => 'slugs']);
        $this->assertContains('test-cat', $terms);
    }
}
