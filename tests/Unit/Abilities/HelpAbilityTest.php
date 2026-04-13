<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\HelpAbility;
use WP_UnitTestCase;

class HelpAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_execute_returns_grouped_abilities(): void
    {
        $result = (new HelpAbility)->execute([]);

        $this->assertArrayHasKey('groups', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertGreaterThan(0, $result['total']);

        // Groups should be arrays with name and abilities.
        $group = $result['groups'][0];
        $this->assertArrayHasKey('name', $group);
        $this->assertArrayHasKey('abilities', $group);
    }

    public function test_groups_contain_expected_resources(): void
    {
        $result = (new HelpAbility)->execute([]);
        $groupNames = array_column($result['groups'], 'name');

        // Core groups should always be present.
        $this->assertContains('content', $groupNames);
        $this->assertContains('terms', $groupNames);
        $this->assertContains('blocks', $groupNames);
    }

    public function test_abilities_have_required_fields(): void
    {
        $result = (new HelpAbility)->execute([]);

        foreach ($result['groups'] as $group) {
            foreach ($group['abilities'] as $ability) {
                $this->assertArrayHasKey('name', $ability);
                $this->assertArrayHasKey('label', $ability);
                $this->assertArrayHasKey('description', $ability);
                $this->assertArrayHasKey('type', $ability, "Ability {$ability['name']} missing type");
                $this->assertContains($ability['type'], ['tool', 'resource']);
            }
        }
    }

    public function test_resources_include_uri(): void
    {
        $result = (new HelpAbility)->execute([]);

        $resources = [];
        foreach ($result['groups'] as $group) {
            foreach ($group['abilities'] as $ability) {
                if ($ability['type'] === 'resource') {
                    $resources[] = $ability;
                }
            }
        }

        foreach ($resources as $resource) {
            $this->assertNotNull($resource['uri'], "Resource {$resource['name']} should have a URI");
        }
    }
}
