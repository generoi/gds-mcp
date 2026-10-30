<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Polylang;

use GeneroWP\MCP\Integrations\Polylang\MachineTranslateAbility;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Factory;
use WP_UnitTestCase;

class MachineTranslateAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_execute_returns_error_without_polylang(): void
    {
        if (function_exists('pll_get_post_language')) {
            $this->markTestSkipped('Polylang is active.');
        }

        $postId = self::factory()->post->create();
        $result = (new MachineTranslateAbility)->execute([
            'id' => $postId,
            'lang' => 'en',
        ]);

        $this->assertWPError($result);
    }

    public function test_execute_returns_error_without_polylang_pro(): void
    {
        if (! function_exists('pll_get_post_language')) {
            $this->markTestSkipped('Polylang is not active.');
        }

        if (class_exists(Factory::class)) {
            $this->markTestSkipped('Polylang Pro machine translation is available.');
        }

        $postId = self::factory()->post->create();
        $result = (new MachineTranslateAbility)->execute([
            'id' => $postId,
            'lang' => 'en',
        ]);

        $this->assertWPError($result);
        $this->assertSame('machine_translation_not_available', $result->get_error_code());
    }

    public function test_overwriting_a_translation_needs_edit_post_on_it(): void
    {
        $this->setUpLanguages();
        $author = self::factory()->user->create(['role' => 'author']);
        $source = self::factory()->post->create(['post_author' => $author]);
        $translation = self::factory()->post->create(['post_author' => self::factory()->user->create(['role' => 'editor'])]);
        pll_set_post_language($source, 'en');
        pll_set_post_language($translation, 'fi');
        pll_save_post_translations(['en' => $source, 'fi' => $translation]);

        wp_set_current_user($author);
        $this->assertTrue(current_user_can('edit_post', $source));
        $error = MachineTranslateAbility::targetPermissionError($source, 'fi');
        $this->assertWPError($error);
        $this->assertSame('forbidden', $error->get_error_code());

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->assertNull(MachineTranslateAbility::targetPermissionError($source, 'fi'));
    }

    public function test_creating_a_translation_needs_create_posts(): void
    {
        $this->setUpLanguages();
        $page = self::factory()->post->create(['post_type' => 'page']);
        pll_set_post_language($page, 'en');

        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $this->assertWPError(MachineTranslateAbility::targetPermissionError($page, 'fi'), 'Authors cannot create pages.');

        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->assertNull(MachineTranslateAbility::targetPermissionError($page, 'fi'));
    }

    private function setUpLanguages(): void
    {
        if (! function_exists('pll_save_post_translations')) {
            $this->markTestSkipped('Polylang is not active.');
        }

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
        $model->update_default_lang('en');
        $model->clean_languages_cache();
    }
}
