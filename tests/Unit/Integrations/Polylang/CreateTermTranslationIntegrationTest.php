<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Polylang;

use GeneroWP\MCP\Integrations\Polylang\CreateTermTranslationAbility;
use WP_Syntex\Polylang_Pro\Integrations\ACF\Dispatcher;
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
        add_term_meta($term['term_id'], 'synced_key', 'once');
        // Free Polylang: its meta sync listens on pll_duplicate_term and
        // copies the pll_copy_term_metas keys; there is no ACF integration.
        $this->withPolylangSync();
        add_filter('pll_copy_term_metas', fn (array $keys) => [...$keys, 'synced_key']);
        $this->assertNotFalse(has_action('pll_duplicate_term'));

        $result = (new CreateTermTranslationAbility)->execute([
            'source_id' => $term['term_id'],
            'taxonomy' => 'category',
            'lang' => 'fi',
            'name' => 'C:\Temp',
            'description' => 'Path C:\Users',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('C:\Temp', get_term($result['id'])->name);
        $this->assertSame('Path C:\Users', get_term($result['id'])->description);
        // ACF term fields and other meta are copied, as nothing else will.
        $this->assertSame('42', get_term_meta($result['id'], 'hero_image', true));
        $this->assertSame('{"a":"\"q\""}', get_term_meta($result['id'], 'json_field', true));
        $this->assertSame('', get_term_meta($result['id'], 'product_count_product_cat', true));
        $this->assertSame(['once'], get_term_meta($result['id'], 'synced_key'), 'Polylang wrote it; not copied a second time.');
    }

    public function test_leaves_acf_meta_to_polylang_pro_when_its_integration_listens(): void
    {
        if (class_exists('WP_Syntex\\Polylang_Pro\\Integrations\\ACF\\Dispatcher', false)) {
            $this->markTestSkipped('Polylang Pro is loaded; this test stands in for it.');
        }
        require_once dirname(__DIR__, 3).'/Fixtures/PolylangProAcfDispatcher.php';
        $term = wp_insert_term('Source with ACF', 'category');
        pll_set_term_language($term['term_id'], 'en');
        add_term_meta($term['term_id'], 'acf_field_set_to_ignore', 'x');
        Dispatcher::$calls = [];
        add_action('pll_duplicate_term', ['WP_Syntex\\Polylang_Pro\\Integrations\\ACF\\Dispatcher', 'on_duplicate_term'], 10, 3);

        $result = (new CreateTermTranslationAbility)->execute(['source_id' => $term['term_id'], 'taxonomy' => 'category', 'lang' => 'fi', 'name' => 'Lähde ACF']);

        $this->assertIsArray($result, is_wp_error($result) ? $result->get_error_message() : '');
        $this->assertSame([[$term['term_id'], $result['id'], 'fi']], Dispatcher::$calls);
        $this->assertSame('', get_term_meta($result['id'], 'acf_field_set_to_ignore', true), 'Copying is Polylang Pro\'s call, which may be "ignore".');
    }

    /**
     * Polylang loads its sync module only when languages exist at boot; in
     * the test database they don't, so load it as a site with languages has.
     */
    private function withPolylangSync(): void
    {
        // Its constructor hooks the listeners, which the test teardown
        // removes again.
        PLL()->sync = PLL() instanceof \PLL_Admin_Base ? new \PLL_Admin_Sync(PLL()) : new \PLL_Sync(PLL());
    }

    protected function tearDown(): void
    {
        if (function_exists('PLL') && PLL()) {
            unset(PLL()->sync);
        }
        parent::tearDown();
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
