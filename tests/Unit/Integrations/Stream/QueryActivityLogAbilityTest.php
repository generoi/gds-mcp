<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Stream;

use GeneroWP\MCP\Integrations\Stream\QueryActivityLogAbility;
use WP_UnitTestCase;

class QueryActivityLogAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_execute_returns_error_without_stream(): void
    {
        if (class_exists('WP_Stream\Plugin')) {
            $this->markTestSkipped('Stream is active.');
        }

        $result = QueryActivityLogAbility::execute([]);
        $this->assertWPError($result);
        $this->assertSame('stream_not_active', $result->get_error_code());
    }

    public function test_permission_denied_for_editor(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $result = QueryActivityLogAbility::checkPermission();
        $this->assertWPError($result);
    }

    public function test_permission_granted_for_admin(): void
    {
        $this->assertTrue(QueryActivityLogAbility::checkPermission());
    }
}
