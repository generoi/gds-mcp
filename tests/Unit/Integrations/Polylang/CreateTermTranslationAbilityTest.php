<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Polylang;

use GeneroWP\MCP\Integrations\Polylang\CreateTermTranslationAbility;
use WP_UnitTestCase;

class CreateTermTranslationAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_execute_returns_error_without_polylang(): void
    {
        if (function_exists('pll_set_term_language')) {
            $this->markTestSkipped('Polylang is active.');
        }

        $term = wp_insert_term('Test', 'category');
        $result = (new CreateTermTranslationAbility)->execute([
            'source_id' => $term['term_id'],
            'taxonomy' => 'category',
            'lang' => 'en',
        ]);

        $this->assertWPError($result);
        $this->assertSame('polylang_not_active', $result->get_error_code());
    }
}
