<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Polylang;

use GeneroWP\MCP\Integrations\Polylang\ListLanguagesAbility;
use WP_UnitTestCase;

class ListLanguagesAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        // Recreate languages since wp-phpunit rolls back the DB between tests.
        if (function_exists('PLL') && PLL() && isset(PLL()->model)) {
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

    public function test_execute_returns_error_without_polylang(): void
    {
        if (function_exists('pll_get_post_language')) {
            $this->markTestSkipped('Polylang is active.');
        }

        $result = ListLanguagesAbility::execute([]);
        $this->assertWPError($result);
    }

    public function test_execute_returns_languages(): void
    {
        if (! function_exists('pll_get_post_language') || ! function_exists('PLL') || ! PLL()) {
            $this->markTestSkipped('Polylang not available.');
        }

        $result = ListLanguagesAbility::execute([]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('default', $result);
        $this->assertArrayHasKey('languages', $result);
        $this->assertNotEmpty($result['languages']);

        $lang = $result['languages'][0];
        $this->assertArrayHasKey('slug', $lang);
        $this->assertArrayHasKey('name', $lang);
        $this->assertArrayHasKey('locale', $lang);
        $this->assertArrayHasKey('active', $lang);
        $this->assertArrayHasKey('is_default', $lang);
        $this->assertArrayHasKey('order', $lang);
    }

    public function test_default_language_is_flagged(): void
    {
        if (! function_exists('pll_default_language') || ! function_exists('PLL') || ! PLL()) {
            $this->markTestSkipped('Polylang not available.');
        }

        $result = ListLanguagesAbility::execute([]);
        $default = $result['default'];

        $defaultLangs = array_filter($result['languages'], fn ($l) => $l['is_default']);
        $this->assertCount(1, $defaultLangs);
        $this->assertSame($default, array_values($defaultLangs)[0]['slug']);
    }

    public function test_permission_denied_for_guest(): void
    {
        wp_set_current_user(0);
        $result = ListLanguagesAbility::checkPermission();
        $this->assertWPError($result);
    }
}
