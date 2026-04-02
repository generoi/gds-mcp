<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Polylang;

use GeneroWP\MCP\Integrations\Polylang\ListStringTranslationsAbility;
use WP_UnitTestCase;

class ListStringTranslationsAbilityTest extends WP_UnitTestCase
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

        $result = ListStringTranslationsAbility::execute([]);
        $this->assertWPError($result);
    }

    public function test_permission_granted_for_editor(): void
    {
        $this->assertTrue(ListStringTranslationsAbility::checkPermission());
    }
}
