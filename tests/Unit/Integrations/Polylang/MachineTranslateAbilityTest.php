<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Polylang;

use GeneroWP\MCP\Integrations\Polylang\MachineTranslateAbility;
use WP_UnitTestCase;

class MachineTranslateAbilityTest extends WP_UnitTestCase
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

        $postId = self::factory()->post->create();
        $result = MachineTranslateAbility::execute([
            'post_id' => $postId,
            'language' => 'en',
        ]);

        $this->assertWPError($result);
    }

    public function test_permission_denied_for_subscriber(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));
        $result = MachineTranslateAbility::checkPermission();
        $this->assertWPError($result);
    }

    public function test_permission_granted_for_editor(): void
    {
        $this->assertTrue(MachineTranslateAbility::checkPermission());
    }
}
