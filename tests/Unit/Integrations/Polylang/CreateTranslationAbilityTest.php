<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Polylang;

use GeneroWP\MCP\Integrations\Polylang\CreateTranslationAbility;
use WP_UnitTestCase;

class CreateTranslationAbilityTest extends WP_UnitTestCase
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

        $postId = self::factory()->post->create();

        $result = CreateTranslationAbility::execute([
            'source_post_id' => $postId,
            'language' => 'en',
        ]);

        $this->assertWPError($result);
        $this->assertSame('polylang_not_active', $result->get_error_code());
    }

    public function test_execute_returns_error_for_missing_source(): void
    {
        if (! function_exists('pll_get_post_language')) {
            $this->markTestSkipped('Polylang is not active.');
        }

        $result = CreateTranslationAbility::execute([
            'source_post_id' => 999999,
            'language' => 'en',
        ]);

        $this->assertWPError($result);
        $this->assertSame('source_not_found', $result->get_error_code());
    }

    public function test_permission_denied_for_guest(): void
    {
        wp_set_current_user(0);

        $result = CreateTranslationAbility::checkPermission([
            'source_post_id' => 1,
            'language' => 'en',
        ]);

        $this->assertWPError($result);
        $this->assertSame('authentication_required', $result->get_error_code());
    }

    public function test_permission_denied_for_subscriber(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $result = CreateTranslationAbility::checkPermission([
            'source_post_id' => 1,
            'language' => 'en',
        ]);

        $this->assertWPError($result);
        $this->assertSame('insufficient_capability', $result->get_error_code());
    }
}
