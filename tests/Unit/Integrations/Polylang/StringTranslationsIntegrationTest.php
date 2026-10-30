<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Polylang;

use GeneroWP\MCP\Integrations\Polylang\ListStringTranslationsAbility;
use GeneroWP\MCP\Integrations\Polylang\RegisteredStrings;
use GeneroWP\MCP\Integrations\Polylang\UpdateStringTranslationAbility;
use WP_UnitTestCase;

/**
 * @group polylang-integration
 */
class StringTranslationsIntegrationTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        if (! function_exists('pll_register_string') || ! class_exists('PLL_Admin_Strings')) {
            $this->markTestSkipped('Polylang string translations not available.');
        }
    }

    public function test_list_returns_registered_strings(): void
    {
        pll_register_string('test_mcp_string', 'Hello World', 'GDS MCP Tests');

        $result = (new ListStringTranslationsAbility)->execute([]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('strings', $result);
        $this->assertArrayHasKey('groups', $result);
        $this->assertContains('GDS MCP Tests', $result['groups']);
    }

    public function test_list_filters_by_group(): void
    {
        pll_register_string('test_group_filter', 'Group Filter Test', 'GDS MCP Tests');

        $result = (new ListStringTranslationsAbility)->execute(['group' => 'GDS MCP Tests']);

        $this->assertIsArray($result);
        $names = array_column($result['strings'], 'name');
        $this->assertContains('test_group_filter', $names);
    }

    public function test_strings_registered_in_wp_admin_are_listed_outside_it(): void
    {
        if (! PLL() instanceof \PLL_Admin_Base) {
            $this->markTestSkipped('Polylang does not run its admin context here.');
        }
        pll_register_string('test_remembered', 'Remembered in admin', 'GDS MCP Tests');
        RegisteredStrings::remember();
        $this->assertContains('Remembered in admin', array_column(get_option('gds_mcp_pll_registered_strings'), 'string'));

        // A REST or WP-CLI request: pll_register_string() kept nothing there.
        update_option('gds_mcp_pll_registered_strings', [md5('Only in admin') => ['name' => 'only_admin', 'string' => 'Only in admin', 'context' => 'GDS MCP Tests', 'multiline' => false]], false);
        $polylang = $GLOBALS['polylang'];
        $GLOBALS['polylang'] = new \stdClass;
        try {
            $strings = RegisteredStrings::all();
        } finally {
            $GLOBALS['polylang'] = $polylang;
        }

        $this->assertContains('Only in admin', array_column($strings, 'string'));
        // Registered with Polylang itself, whose import accepts a translation
        // only for a string registered in that request.
        $this->assertArrayHasKey(md5('Only in admin'), \PLL_Admin_Strings::get_strings());
    }

    public function test_remembering_drops_strings_no_longer_registered(): void
    {
        if (! PLL() instanceof \PLL_Admin_Base) {
            $this->markTestSkipped('Polylang does not run its admin context here.');
        }
        update_option('gds_mcp_pll_registered_strings', [md5('Gone') => ['name' => 'gone', 'string' => 'Gone', 'context' => 'GDS MCP Tests', 'multiline' => false]], false);

        RegisteredStrings::remember();

        $this->assertNotContains('Gone', array_column(get_option('gds_mcp_pll_registered_strings'), 'string'));
    }

    public function test_update_and_read_string_translation(): void
    {
        $languages = pll_languages_list(['fields' => 'slug']);
        if (empty($languages)) {
            $this->markTestSkipped('No languages configured.');
        }

        $targetLang = $languages[0];
        $testString = 'MCP Test String '.uniqid();
        pll_register_string('test_update_string', $testString, 'GDS MCP Tests');

        // Update translation.
        $updateResult = (new UpdateStringTranslationAbility)->execute([
            'string' => $testString,
            'lang' => $targetLang,
            'translation' => 'Translated: '.$testString,
        ]);

        $this->assertIsArray($updateResult);
        $this->assertSame($testString, $updateResult['string']);
        $this->assertSame('Translated: '.$testString, $updateResult['translation']);

        // Verify it appears in the list.
        $listResult = (new ListStringTranslationsAbility)->execute(['search' => $testString]);
        $found = false;
        foreach ($listResult['strings'] as $str) {
            if ($str['string'] === $testString && ($str['translations'][$targetLang] ?? null) === 'Translated: '.$testString) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Updated string translation should appear in list.');
    }

    public function test_translations_are_filtered_for_users_without_unfiltered_html(): void
    {
        // WP_UnitTestCase rollback removes language terms; Polylang's cache
        // may still list them.
        $model = PLL()->model;
        $model->clean_languages_cache();
        if (! $model->get_language('fi')) {
            $model->add_language(['name' => 'Finnish', 'slug' => 'fi', 'locale' => 'fi', 'term_group' => 1]);
            $model->clean_languages_cache();
        }
        add_filter('map_meta_cap', fn ($caps, $cap) => $cap === 'unfiltered_html' ? ['do_not_allow'] : $caps, 10, 2);
        $lang = $model->get_language('fi');
        $testString = 'Kses String '.uniqid();
        pll_register_string('test_kses_string', $testString, 'GDS MCP Tests');
        $stored = function () use ($lang, $testString): string {
            $mo = new \PLL_MO;
            $mo->import_from_db($lang);

            return $mo->translate($testString);
        };

        $result = (new UpdateStringTranslationAbility)->execute(['string' => $testString, 'lang' => $lang->slug, 'translation' => 'Hei<script>alert(1)</script>']);

        $this->assertSame('Heialert(1)', $result['translation']);
        $this->assertSame('Heialert(1)', $stored());

        // An undo payload is filtered the same way.
        $restored = apply_filters('gds-mcp/restore_snapshot', null, ['kind' => 'restore-string', 'data' => ['string' => $testString, 'lang' => $lang->slug, 'translation' => 'Moi<script>alert(2)</script>']]);

        $this->assertNotWPError($restored);
        $this->assertSame('Moialert(2)', $stored());

        // With no translation yet, one that repeats the original is still
        // filtered: Polylang compares with the previous translation, not
        // the original.
        $withMarkup = 'Original <script>x</script> '.uniqid();
        pll_register_string('test_kses_original', $withMarkup, 'GDS MCP Tests');
        $result = (new UpdateStringTranslationAbility)->execute(['string' => $withMarkup, 'lang' => $lang->slug, 'translation' => $withMarkup]);

        $this->assertStringNotContainsString('<script>', $result['translation']);
    }
}
