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

        $result = (new CreateTermTranslationAbility)->execute([
            'source_id' => $term['term_id'],
            'taxonomy' => 'category',
            'lang' => $targetLang,
            'name' => 'Translated Term',
            'slug' => 'translated-term',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('Translated Term', $result['name']);
        $this->assertSame($targetLang, $result['lang']);

        // Verify Polylang translation link.
        $translations = pll_get_term_translations($term['term_id']);
        $this->assertArrayHasKey($targetLang, $translations);
        $this->assertSame($result['id'], $translations[$targetLang]);
    }

    public function test_keeps_backslashes_and_copies_term_meta(): void
    {
        $term = wp_insert_term('Source', 'category');
        pll_set_term_language($term['term_id'], 'en');
        add_term_meta($term['term_id'], 'hero_image', '42');
        add_term_meta($term['term_id'], 'json_field', wp_slash('{"a":"\"q\""}'));
        add_term_meta($term['term_id'], 'product_count_product_cat', '7');
        // Which keys follow is Polylang's call, as when translating in
        // wp-admin; ACF and Polylang for WooCommerce add theirs here.
        add_filter('pll_copy_term_metas', fn (array $keys) => [...$keys, 'hero_image', 'json_field']);
        // Polylang loads its sync module only when languages exist at boot,
        // which in the test database they don't; on a site they do.
        $loadedSync = ! isset(PLL()->sync);
        if ($loadedSync) {
            PLL()->sync = PLL() instanceof \PLL_Admin_Base ? new \PLL_Admin_Sync(PLL()) : new \PLL_Sync(PLL());
        }

        try {
            $result = (new CreateTermTranslationAbility)->execute([
                'source_id' => $term['term_id'],
                'taxonomy' => 'category',
                'lang' => 'fi',
                'name' => 'C:\Temp',
                'description' => 'Path C:\Users',
            ]);
        } finally {
            if ($loadedSync) {
                unset(PLL()->sync);
            }
        }

        $this->assertIsArray($result);
        $this->assertSame('C:\Temp', get_term($result['id'])->name);
        $this->assertSame('Path C:\Users', get_term($result['id'])->description);
        $this->assertSame('42', get_term_meta($result['id'], 'hero_image', true));
        $this->assertSame('{"a":"\"q\""}', get_term_meta($result['id'], 'json_field', true));
        $this->assertSame('', get_term_meta($result['id'], 'product_count_product_cat', true));
    }

    public function test_rejects_invalid_language(): void
    {
        $term = wp_insert_term('Test Term', 'category');

        $result = (new CreateTermTranslationAbility)->execute([
            'source_id' => $term['term_id'],
            'taxonomy' => 'category',
            'lang' => 'xx',
        ]);

        $this->assertWPError($result);
        $this->assertSame('invalid_language', $result->get_error_code());
    }
}
