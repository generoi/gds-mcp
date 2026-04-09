<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Stream;

use GeneroWP\MCP\Integrations\Stream\QueryActivityLogAbility;
use WP_UnitTestCase;

/**
 * @group stream-integration
 */
class QueryActivityLogIntegrationTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        if (! class_exists('WP_Stream\Plugin') || ! function_exists('wp_stream_get_instance')) {
            $this->markTestSkipped('Stream plugin not active.');
        }

        // Ensure Stream tables exist.
        $instance = wp_stream_get_instance();
        if (isset($instance->install) && method_exists($instance->install, 'install')) {
            $instance->install->install(wp_stream_get_instance()->get_version());
        }
    }

    public function test_execute_returns_structure(): void
    {
        $result = (new QueryActivityLogAbility)->execute(['per_page' => 5]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('entries', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertIsArray($result['entries']);
    }

    public function test_execute_filters_by_connector(): void
    {
        $result = (new QueryActivityLogAbility)->execute([
            'connector' => 'posts',
            'per_page' => 5,
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('entries', $result);
    }

    public function test_execute_respects_per_page(): void
    {
        $result = (new QueryActivityLogAbility)->execute(['per_page' => 1]);

        $this->assertIsArray($result);
        $this->assertLessThanOrEqual(1, count($result['entries']));
    }
}
