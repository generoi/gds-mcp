<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Polylang;

use GeneroWP\MCP\Integrations\Polylang\CreateTermTranslationAbility;
use WP_UnitTestCase;

/**
 * @group polylang-integration
 */
class CreateTermTranslationIntegrationTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        if (! function_exists('pll_set_term_language') || ! function_exists('pll_default_language')) {
            $this->markTestSkipped('Polylang is not active.');
        }

        PLL()->model->clean_languages_cache();
    }

    public function test_creates_term_translation_and_links(): void
    {
        $defaultLang = pll_default_language();
        $targetLang = $defaultLang === 'en' ? 'fi' : 'en';

        $languages = pll_languages_list(['fields' => 'slug']);
        if (! in_array($targetLang, $languages, true)) {
            $this->markTestSkipped("Target language '{$targetLang}' not configured.");
        }

        $term = wp_insert_term('Original Term', 'category');
        pll_set_term_language($term['term_id'], $defaultLang);

        $result = CreateTermTranslationAbility::execute([
            'source_term_id' => $term['term_id'],
            'taxonomy' => 'category',
            'language' => $targetLang,
            'name' => 'Translated Term',
            'slug' => 'translated-term',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('Translated Term', $result['name']);
        $this->assertSame($targetLang, $result['language']);

        // Verify Polylang translation link.
        $translations = pll_get_term_translations($term['term_id']);
        $this->assertArrayHasKey($targetLang, $translations);
        $this->assertSame($result['id'], $translations[$targetLang]);
    }

    public function test_rejects_invalid_language(): void
    {
        $term = wp_insert_term('Test Term', 'category');

        $result = CreateTermTranslationAbility::execute([
            'source_term_id' => $term['term_id'],
            'taxonomy' => 'category',
            'language' => 'xx',
        ]);

        $this->assertWPError($result);
        $this->assertSame('invalid_language', $result->get_error_code());
    }
}
