<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Polylang;

use GeneroWP\MCP\Integrations\Polylang\LinkTranslationsAbility;
use GeneroWP\MCP\Tests\TestCase;

/**
 * @group polylang-integration
 */
class LinkTranslationsAbilityTest extends TestCase
{
    private LinkTranslationsAbility $ability;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->ability = new LinkTranslationsAbility;

        if (! function_exists('pll_set_post_language') || ! function_exists('pll_default_language')) {
            $this->markTestSkipped('Polylang is not active.');
        }

        // WP_UnitTestCase rollback removes language terms; re-initialize them.
        $model = PLL()->model;
        $model->clean_languages_cache();
        foreach ([
            ['name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'term_group' => 0],
            ['name' => 'Finnish', 'slug' => 'fi', 'locale' => 'fi', 'term_group' => 1],
            ['name' => 'Swedish', 'slug' => 'sv', 'locale' => 'sv_SE', 'term_group' => 2],
        ] as $lang) {
            if (! $model->get_language($lang['slug'])) {
                $model->add_language($lang);
            }
        }
        $model->update_default_lang('en');
        $model->clean_languages_cache();
    }

    public function test_link_two_posts_as_translations(): void
    {
        $enId = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About Us']);
        $fiId = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Tietoa']);
        pll_set_post_language($enId, 'en');
        pll_set_post_language($fiId, 'fi');

        $result = $this->ability->execute([
            'translations' => ['en' => $enId, 'fi' => $fiId],
        ]);

        $this->assertIsArray($result);
        $this->assertSame(['en' => $enId, 'fi' => $fiId], $result['linked']);

        // Verify the polylang relationship was saved
        clean_post_cache($enId);
        clean_post_cache($fiId);
        $enTranslations = pll_get_post_translations($enId);
        $this->assertArrayHasKey('fi', $enTranslations, 'Translation should be linked. Got: '.wp_json_encode($enTranslations));
        $this->assertSame($fiId, $enTranslations['fi']);
        $this->assertSame($enId, $enTranslations['en']);
    }

    public function test_link_normalizes_language_even_if_not_pre_assigned(): void
    {
        // Idempotent safety: if the caller forgot to set language on one post,
        // this ability still assigns it before linking.
        $enId = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $fiId = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        // Only enId gets its language set beforehand:
        pll_set_post_language($enId, 'en');
        // fiId is NOT pre-assigned

        $result = $this->ability->execute([
            'translations' => ['en' => $enId, 'fi' => $fiId],
        ]);

        $this->assertIsArray($result);
        $this->assertSame('fi', pll_get_post_language($fiId));
    }

    public function test_rejects_unknown_language(): void
    {
        $enId = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $xxId = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);

        $result = $this->ability->execute([
            'translations' => ['en' => $enId, 'xx' => $xxId],
        ]);

        $this->assertWPError($result);
        $this->assertSame('invalid_language', $result->get_error_code());
    }

    public function test_rejects_nonexistent_post(): void
    {
        $enId = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);

        $result = $this->ability->execute([
            'translations' => ['en' => $enId, 'fi' => 99999999],
        ]);

        $this->assertWPError($result);
        $this->assertSame('post_not_found', $result->get_error_code());
    }

    public function test_rejects_mismatched_post_types(): void
    {
        $pageId = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $postId = self::factory()->post->create(['post_type' => 'post', 'post_status' => 'publish']);

        $result = $this->ability->execute([
            'translations' => ['en' => $pageId, 'fi' => $postId],
        ]);

        $this->assertWPError($result);
        $this->assertSame('mismatched_post_types', $result->get_error_code());
    }

    public function test_rejects_duplicate_post_id_across_languages(): void
    {
        $id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);

        $result = $this->ability->execute([
            'translations' => ['en' => $id, 'fi' => $id],
        ]);

        $this->assertWPError($result);
        $this->assertSame('duplicate_post_ids', $result->get_error_code());
    }

    public function test_rejects_too_few_translations(): void
    {
        $id = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);

        $result = $this->ability->execute([
            'translations' => ['en' => $id],
        ]);

        $this->assertWPError($result);
        $this->assertSame('too_few_translations', $result->get_error_code());
    }

    public function test_relinking_post_already_in_a_group_is_refused_without_confirm(): void
    {
        // Establish an en↔fi group.
        $en = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $fi1 = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        pll_set_post_language($en, 'en');
        pll_set_post_language($fi1, 'fi');
        $this->ability->execute(['translations' => ['en' => $en, 'fi' => $fi1]]);

        // Now try to relink en to a DIFFERENT fi — that would orphan fi1.
        $fi2 = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        pll_set_post_language($fi2, 'fi');

        $result = $this->ability->execute(['translations' => ['en' => $en, 'fi' => $fi2]]);

        $this->assertWPError($result);
        $this->assertSame('destructive_relink', $result->get_error_code());

        // The original group must be intact since the call was refused.
        clean_post_cache($en);
        $this->assertSame($fi1, pll_get_post_translations($en)['fi'] ?? null);
    }

    public function test_relinking_with_confirm_destructive_succeeds(): void
    {
        $en = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $fi1 = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        pll_set_post_language($en, 'en');
        pll_set_post_language($fi1, 'fi');
        $this->ability->execute(['translations' => ['en' => $en, 'fi' => $fi1]]);

        $fi2 = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        pll_set_post_language($fi2, 'fi');

        $result = $this->ability->execute([
            'translations' => ['en' => $en, 'fi' => $fi2],
            'confirm_destructive' => true,
        ]);

        $this->assertIsArray($result);
        clean_post_cache($en);
        $this->assertSame($fi2, pll_get_post_translations($en)['fi'] ?? null, 'Confirmed relink should repoint fi to fi2.');
    }

    public function test_relinking_that_changes_a_posts_language_is_refused(): void
    {
        // A post currently in English, asked to be linked as the Finnish slot.
        $p = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $other = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        pll_set_post_language($p, 'en');
        pll_set_post_language($other, 'en');

        $result = $this->ability->execute(['translations' => ['en' => $other, 'fi' => $p]]);

        $this->assertWPError($result);
        $this->assertSame('destructive_relink', $result->get_error_code());
    }

    public function test_link_three_languages(): void
    {
        $configured = pll_languages_list();
        if (! in_array('sv', $configured, true)) {
            $this->markTestSkipped('SV language not configured.');
        }

        $en = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $fi = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);
        $sv = self::factory()->post->create(['post_type' => 'page', 'post_status' => 'publish']);

        $result = $this->ability->execute([
            'translations' => ['en' => $en, 'fi' => $fi, 'sv' => $sv],
        ]);

        $this->assertIsArray($result);
        clean_post_cache($en);
        clean_post_cache($fi);
        clean_post_cache($sv);
        $enTranslations = pll_get_post_translations($en);
        $this->assertArrayHasKey('fi', $enTranslations);
        $this->assertArrayHasKey('sv', $enTranslations);
        $this->assertSame($fi, $enTranslations['fi']);
        $this->assertSame($sv, $enTranslations['sv']);
    }
}
