<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Acf;

use GeneroWP\MCP\Abilities\AcfFieldsResource;
use WP_UnitTestCase;

class AcfFieldsResourceTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_execute_returns_error_without_acf(): void
    {
        if (function_exists('acf_get_field_groups')) {
            $this->markTestSkipped('ACF is active.');
        }

        $result = AcfFieldsResource::execute([]);
        $this->assertWPError($result);
        $this->assertSame('acf_not_active', $result->get_error_code());
    }

    public function test_permission_denied_for_subscriber(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $result = AcfFieldsResource::checkPermission();
        $this->assertWPError($result);
    }

    public function test_permission_granted_for_editor(): void
    {
        $this->assertTrue(AcfFieldsResource::checkPermission());
    }
}
