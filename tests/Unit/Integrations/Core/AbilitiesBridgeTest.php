<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Core;

use GeneroWP\MCP\Integrations\Core\AbilitiesBridge;
use WP_UnitTestCase;

class AbilitiesBridgeTest extends WP_UnitTestCase
{
    protected function tearDown(): void
    {
        remove_all_filters('gds-mcp/expose_core_ability');
        parent::tearDown();
    }

    public function test_core_abilities_without_a_public_flag_are_exposed(): void
    {
        // WordPress 7.0 registers core abilities with show_in_rest only.
        $args = AbilitiesBridge::markCoreAbilitiesPublic(['meta' => ['show_in_rest' => true]], 'core/get-site-info');

        $this->assertTrue($args['meta']['mcp']['public']);
        $this->assertArrayNotHasKey('public', $args['meta'], 'REST exposure is core\'s call, not ours.');
    }

    public function test_core_public_flag_is_mirrored_for_older_adapters(): void
    {
        $args = AbilitiesBridge::markCoreAbilitiesPublic(['meta' => ['public' => false]], 'core/get-environment-info');

        $this->assertFalse($args['meta']['mcp']['public'], 'An ability core keeps private stays private.');
    }

    public function test_an_explicit_mcp_flag_is_left_alone(): void
    {
        $args = AbilitiesBridge::markCoreAbilitiesPublic(['meta' => ['mcp' => ['public' => false]]], 'core/get-user-info');

        $this->assertFalse($args['meta']['mcp']['public']);
    }

    public function test_other_namespaces_are_ignored(): void
    {
        $args = AbilitiesBridge::markCoreAbilitiesPublic(['meta' => []], 'acme/do-something');

        $this->assertArrayNotHasKey('mcp', $args['meta']);
    }

    public function test_projects_can_opt_an_ability_out(): void
    {
        add_filter('gds-mcp/expose_core_ability', fn ($expose, $name) => $name !== 'core/get-environment-info' && $expose, 10, 2);

        $this->assertFalse(AbilitiesBridge::markCoreAbilitiesPublic([], 'core/get-environment-info')['meta']['mcp']['public']);
        $this->assertTrue(AbilitiesBridge::markCoreAbilitiesPublic([], 'core/get-site-info')['meta']['mcp']['public']);
    }
}
