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

        $result = (new CreateTranslationAbility)->execute([
            'source_id' => $postId,
            'lang' => 'en',
        ]);

        $this->assertWPError($result);
        $this->assertSame('polylang_not_active', $result->get_error_code());
    }

    public function test_execute_returns_error_for_missing_source(): void
    {
        if (! function_exists('pll_get_post_language')) {
            $this->markTestSkipped('Polylang is not active.');
        }

        $result = (new CreateTranslationAbility)->execute([
            'source_id' => 999999,
            'lang' => 'en',
        ]);

        $this->assertWPError($result);
        $this->assertSame('source_not_found', $result->get_error_code());
    }
}
