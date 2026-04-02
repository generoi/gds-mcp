<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\ListPostTypesAbility;
use WP_UnitTestCase;

class ListPostTypesAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_execute_returns_post_types(): void
    {
        $result = ListPostTypesAbility::execute([]);

        $this->assertArrayHasKey('post_types', $result);
        $names = array_column($result['post_types'], 'name');
        $this->assertContains('post', $names);
        $this->assertContains('page', $names);
    }

    public function test_execute_includes_required_fields(): void
    {
        $result = ListPostTypesAbility::execute([]);
        $pt = $result['post_types'][0];

        $this->assertArrayHasKey('name', $pt);
        $this->assertArrayHasKey('label', $pt);
        $this->assertArrayHasKey('public', $pt);
        $this->assertArrayHasKey('count', $pt);
    }

    public function test_permission_denied_for_guest(): void
    {
        wp_set_current_user(0);
        $result = ListPostTypesAbility::checkPermission();
        $this->assertWPError($result);
    }
}
