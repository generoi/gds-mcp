<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Polylang;

use GeneroWP\MCP\Integrations\Polylang\TranslationAuditAbility;
use WP_UnitTestCase;

class TranslationAuditAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_execute_returns_error_without_polylang(): void
    {
        if (function_exists('pll_get_post_language')) {
            $this->markTestSkipped('Polylang is active; cannot test missing Polylang behavior.');
        }

        $result = TranslationAuditAbility::execute([]);

        $this->assertWPError($result);
        $this->assertSame('polylang_not_active', $result->get_error_code());
    }

    public function test_permission_denied_for_guest(): void
    {
        wp_set_current_user(0);

        $result = TranslationAuditAbility::checkPermission();

        $this->assertWPError($result);
        $this->assertSame('authentication_required', $result->get_error_code());
    }

    public function test_permission_granted_for_editor(): void
    {
        $result = TranslationAuditAbility::checkPermission();

        $this->assertTrue($result);
    }
}
