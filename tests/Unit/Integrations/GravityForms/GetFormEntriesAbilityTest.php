<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\GravityForms;

use GeneroWP\MCP\Integrations\GravityForms\GetFormEntriesAbility;
use WP_UnitTestCase;

class GetFormEntriesAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_execute_returns_error_without_gravity_forms(): void
    {
        if (class_exists('GFAPI')) {
            $this->markTestSkipped('Gravity Forms is active.');
        }

        $result = GetFormEntriesAbility::execute(['form_id' => 1]);

        $this->assertWPError($result);
        $this->assertSame('gravity_forms_not_active', $result->get_error_code());
    }

    public function test_permission_denied_for_editor(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $result = GetFormEntriesAbility::checkPermission();

        $this->assertWPError($result);
    }

    public function test_permission_granted_for_administrator(): void
    {
        $this->assertTrue(GetFormEntriesAbility::checkPermission());
    }
}
