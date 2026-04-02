<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\GravityForms;

use GeneroWP\MCP\Integrations\GravityForms\GetFormAbility;
use WP_UnitTestCase;

class GetFormAbilityTest extends WP_UnitTestCase
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

        $result = GetFormAbility::execute(['form_id' => 1]);

        $this->assertWPError($result);
        $this->assertSame('gravity_forms_not_active', $result->get_error_code());
    }

    public function test_permission_denied_for_guest(): void
    {
        wp_set_current_user(0);

        $result = GetFormAbility::checkPermission();

        $this->assertWPError($result);
        $this->assertSame('authentication_required', $result->get_error_code());
    }

    public function test_permission_granted_for_administrator(): void
    {
        $this->assertTrue(GetFormAbility::checkPermission());
    }
}
