<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Polylang;

use GeneroWP\MCP\Integrations\Polylang\TranslationAuditAbility;
use GeneroWP\MCP\Tests\TestCase;

/**
 * @group polylang-integration
 */
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

        $result = (new TranslationAuditAbility)->execute(['post_type' => 'post']);

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

    public function test_audit_with_lang_classifies_by_the_whole_translation_group(): void
    {
        // WP_UnitTestCase rollback removes language terms; re-add them.
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
        $model->clean_languages_cache();

        $alone = $this->createPost(['post_type' => 'post', 'post_status' => 'publish']);
        pll_set_post_language($alone, 'en');

        $en = $this->createPost(['post_type' => 'post', 'post_status' => 'publish']);
        $fi = $this->createPost(['post_type' => 'post', 'post_status' => 'publish']);
        pll_set_post_language($en, 'en');
        pll_set_post_language($fi, 'fi');
        pll_save_post_translations(['en' => $en, 'fi' => $fi]);

        $result = (new TranslationAuditAbility)->execute(['post_type' => 'post', 'lang' => 'fi', 'per_page' => 500]);

        // The post with no translation at all is untranslated, not partial.
        $this->assertSame(1, $result['summary']['untranslated']);
        $this->assertSame(0, $result['summary']['partially_translated']);
        $this->assertSame(1, $result['summary']['fully_translated']);
    }

    public function test_audit_is_paged(): void
    {
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->createPost(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => "Paged {$i}"]);
        }

        $result = (new TranslationAuditAbility)->execute(['post_type' => 'post', 'per_page' => 2, 'page' => 2]);

        $this->assertSame(2, $result['per_page']);
        $this->assertSame(2, $result['page']);
        $this->assertGreaterThanOrEqual(3, $result['total']);
        $this->assertSame((int) ceil($result['total'] / 2), $result['total_pages']);
        $this->assertLessThanOrEqual(2, $result['summary']['total_posts']);
    }

    public function test_audit_returns_structure(): void
    {
        $result = (new TranslationAuditAbility)->execute([]);

        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('total_posts', $result['summary']);
        $this->assertArrayHasKey('fully_translated', $result['summary']);
        $this->assertArrayHasKey('languages', $result);
        $this->assertArrayHasKey('by_type', $result);
    }
}
