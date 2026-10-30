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

    public function test_flush_without_cachetags_says_no_page_cache_was_purged(): void
    {
        if (function_exists('app')) {
            $this->markTestSkipped('sage-cachetags may be bound here.');
        }
        wp_cache_set('probe', 'cached');

        $result = (new ClearCacheAbility)->execute(['type' => 'flush']);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('no page cache was purged', $result['message']);
        $this->assertFalse(wp_cache_get('probe'), 'On a single site the object cache is flushed.');
    }

    public function test_execute_tags_requires_tags(): void
    {
        if (! function_exists('app')) {
            $this->markTestSkipped('sage-cachetags is not active.');
        }

        $result = (new ClearCacheAbility)->execute(['type' => 'tags']);

        $this->assertWPError($result);
        $this->assertSame('missing_tags', $result->get_error_code());
    }
}
