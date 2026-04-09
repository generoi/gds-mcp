<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Polylang;

use GeneroWP\MCP\Integrations\Polylang\UpdateStringTranslationAbility;
use WP_UnitTestCase;

class UpdateStringTranslationAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_execute_returns_error_without_polylang(): void
    {
        if (function_exists('pll_get_post_language')) {
            $this->markTestSkipped('Polylang is active.');
        }

        $result = UpdateStringTranslationAbility::execute([
            'string' => 'Test',
            'lang' => 'en',
            'translation' => 'Test EN',
        ]);
        $this->assertWPError($result);
    }

    public function test_permission_denied_for_editor(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $result = UpdateStringTranslationAbility::checkPermission();
        $this->assertWPError($result);
    }
}
