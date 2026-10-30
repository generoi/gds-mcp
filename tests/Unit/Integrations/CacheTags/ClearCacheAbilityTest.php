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

        $this->assertWPError($result);
        $this->assertSame('page_cache_not_purged', $result->get_error_code());
        $this->assertFalse(wp_cache_get('probe'), 'On a single site the object cache is still flushed.');
    }

    public function test_execute_tags_requires_tags(): void
    {
        $result = (new ClearCacheAbility)->execute(['type' => 'tags']);

        // Without sage-cachetags nothing can be purged at all.
        $this->assertWPError($result);
        $this->assertContains($result->get_error_code(), ['missing_tags', 'page_cache_not_purged']);
    }
}
