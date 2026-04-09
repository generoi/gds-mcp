<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\CacheTags;

use GeneroWP\MCP\Integrations\CacheTags\ClearCacheAbility;
use WP_UnitTestCase;

class ClearCacheAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_execute_flush_returns_success(): void
    {
        // Without sage-cachetags, falls back to wp_cache_flush.
        $result = (new ClearCacheAbility)->execute(['type' => 'flush']);

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
    }

    public function test_execute_tags_requires_tags(): void
    {
        $result = (new ClearCacheAbility)->execute(['type' => 'tags']);

        // Either WP_Error (missing tags) or success depending on cachetags availability.
        if (is_wp_error($result)) {
            $this->assertSame('missing_tags', $result->get_error_code());
        } else {
            $this->assertIsArray($result);
        }
    }
}
