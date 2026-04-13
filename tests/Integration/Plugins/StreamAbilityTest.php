<?php

namespace GeneroWP\MCP\Tests\Integration\Plugins;

use GeneroWP\MCP\Tests\AbilityTestCase;

/**
 * Integration tests for gds/activity-query through the Abilities API.
 */
class StreamAbilityTest extends AbilityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(\WP_Stream\Plugin::class)) {
            $this->markTestSkipped('Stream is not active.');
        }

        // Activity log requires manage_options (admin).
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_activity_query_registered(): void
    {
        $this->assertAbilityRegistered('gds/activity-query');
    }

    public function test_query_activity_log(): void
    {
        $result = $this->assertAbilitySuccess('gds/activity-query', [
            'per_page' => 5,
        ]);

        $this->assertArrayHasKey('entries', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertIsArray($result['entries']);
    }

    public function test_query_with_connector_filter(): void
    {
        $result = $this->assertAbilitySuccess('gds/activity-query', [
            'connector' => 'posts',
            'per_page' => 5,
        ]);

        $this->assertArrayHasKey('entries', $result);
    }
}
