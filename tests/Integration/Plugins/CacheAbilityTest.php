<?php

namespace GeneroWP\MCP\Tests\Integration\Plugins;

use Genero\Sage\CacheTags\CacheTags;
use GeneroWP\MCP\Tests\AbilityTestCase;

/**
 * Integration tests for gds/cache-clear through the Abilities API.
 */
class CacheAbilityTest extends AbilityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(CacheTags::class)) {
            $this->markTestSkipped('sage-cachetags is not active.');
        }

        // Cache clearing requires manage_options (admin).
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_cache_clear_registered(): void
    {
        $this->assertAbilityRegistered('gds/cache-clear');
    }

    public function test_flush_cache(): void
    {
        $result = $this->assertAbilitySuccess('gds/cache-clear', [
            'type' => 'flush',
        ]);

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertTrue($result['success']);
    }

    public function test_purge_by_tags_requires_tags(): void
    {
        $this->assertAbilityError('gds/cache-clear', [
            'type' => 'tags',
        ], 'missing_tags');
    }
}
