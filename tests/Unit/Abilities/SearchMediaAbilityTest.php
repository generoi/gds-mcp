<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\SearchMediaAbility;
use WP_UnitTestCase;

class SearchMediaAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_execute_returns_media_structure(): void
    {
        $result = SearchMediaAbility::execute([]);

        $this->assertArrayHasKey('media', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('pages', $result);
    }

    public function test_permission_denied_for_subscriber(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $result = SearchMediaAbility::checkPermission();
        $this->assertWPError($result);
    }
}
