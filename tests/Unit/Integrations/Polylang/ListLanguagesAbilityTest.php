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

    public function test_execute_returns_languages(): void
    {
        if (! function_exists('pll_get_post_language') || ! function_exists('PLL') || ! PLL()) {
            $this->markTestSkipped('Polylang not available.');
        }

        $result = ListLanguagesAbility::instance()->execute([]);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);

        $lang = $result[0];
        $this->assertArrayHasKey('slug', $lang);
        $this->assertArrayHasKey('name', $lang);
    }

    public function test_default_language_is_flagged(): void
    {
        if (! function_exists('pll_default_language') || ! function_exists('PLL') || ! PLL()) {
            $this->markTestSkipped('Polylang not available.');
        }

        $result = ListLanguagesAbility::instance()->execute([]);
        $defaultLangs = array_filter($result, fn ($l) => ! empty($l['is_default']));

        $this->assertCount(1, $defaultLangs);
        $this->assertSame('en', array_values($defaultLangs)[0]['slug']);
    }
}
