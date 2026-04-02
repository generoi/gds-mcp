<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\GravityForms;

use GeneroWP\MCP\Integrations\GravityForms\CreateGravityFormAbility;
use WP_UnitTestCase;

class CreateGravityFormAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_execute_returns_error_without_gravity_forms(): void
    {
        if (class_exists('GFAPI')) {
            $this->markTestSkipped('Gravity Forms is active; cannot test missing GF behavior.');
        }

        $result = CreateGravityFormAbility::execute([
            'title' => 'Test Form',
            'fields' => [
                ['type' => 'text', 'label' => 'Name'],
            ],
        ]);

        $this->assertWPError($result);
        $this->assertSame('gravity_forms_not_active', $result->get_error_code());
    }

    public function test_permission_denied_for_guest(): void
    {
        wp_set_current_user(0);

        $result = CreateGravityFormAbility::checkPermission();

        $this->assertWPError($result);
        $this->assertSame('authentication_required', $result->get_error_code());
    }

    public function test_permission_denied_for_editor(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $result = CreateGravityFormAbility::checkPermission();

        $this->assertWPError($result);
        $this->assertSame('insufficient_capability', $result->get_error_code());
    }

    public function test_permission_granted_for_administrator(): void
    {
        $result = CreateGravityFormAbility::checkPermission();

        $this->assertTrue($result);
    }
}
