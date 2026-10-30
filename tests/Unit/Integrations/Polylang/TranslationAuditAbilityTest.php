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

        $result = (new TranslationAuditAbility)->execute([]);

        $this->assertWPError($result);
        $this->assertSame('polylang_not_active', $result->get_error_code());
    }

    public function test_permission_depends_on_status(): void
    {
        $this->assertTrue(TranslationAuditAbility::permissionCheck(['status' => 'publish']));
        $this->assertTrue(TranslationAuditAbility::permissionCheck(['status' => 'draft']), 'Editors may audit drafts.');

        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $this->assertTrue(TranslationAuditAbility::permissionCheck([]));
        $this->assertFalse(TranslationAuditAbility::permissionCheck(['status' => 'draft']), 'Authors may not audit other users\' drafts.');

        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $this->assertFalse(TranslationAuditAbility::permissionCheck([]));
    }
}
