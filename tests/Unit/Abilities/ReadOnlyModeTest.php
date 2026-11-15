<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Abilities\ReadOnlyMode;
use WP_Ability;
use WP_UnitTestCase;

class ReadOnlyModeTest extends WP_UnitTestCase
{
    /**
     * @param  array<string, mixed>  $meta
     */
    private function ability(array $meta): WP_Ability
    {
        return new WP_Ability('gds-test/probe', [
            'label' => 'Probe',
            'description' => 'Probe',
            'category' => 'gds-content',
            'execute_callback' => '__return_true',
            'permission_callback' => '__return_true',
            'meta' => $meta,
        ]);
    }

    public function test_keeps_readonly_public_abilities(): void
    {
        $this->assertTrue(ReadOnlyMode::allows($this->ability([
            'mcp' => ['public' => true],
            'annotations' => ['readonly' => true],
        ])));
    }

    public function test_drops_public_abilities_that_write(): void
    {
        $this->assertFalse(ReadOnlyMode::allows($this->ability([
            'mcp' => ['public' => true],
            'annotations' => ['readonly' => false],
        ])));
    }

    public function test_drops_public_abilities_without_annotation(): void
    {
        $this->assertFalse(ReadOnlyMode::allows($this->ability([
            'mcp' => ['public' => true],
        ])));
    }

    public function test_leaves_non_public_abilities_alone(): void
    {
        $this->assertTrue(ReadOnlyMode::allows($this->ability([
            'annotations' => ['readonly' => false],
        ])));
    }

    public function test_off_by_default(): void
    {
        $this->assertFalse(ReadOnlyMode::enabled());
    }

    public function test_every_gds_write_ability_would_be_dropped(): void
    {
        $writes = ['gds/content-create', 'gds/content-update', 'gds/content-delete', 'gds/mail-send'];

        foreach ($writes as $name) {
            $ability = wp_get_ability($name);
            $this->assertNotNull($ability, "{$name} should be registered");
            $this->assertFalse(ReadOnlyMode::allows($ability), "{$name} should be dropped");
        }

        foreach (['gds/help', 'gds/content-list', 'gds/content-read'] as $name) {
            $this->assertTrue(ReadOnlyMode::allows(wp_get_ability($name)), "{$name} should stay");
        }
    }

    public function test_help_skips_unregistered_abilities(): void
    {
        // wp_register_ability() only accepts registrations during this action.
        $GLOBALS['wp_current_filter'][] = 'wp_abilities_api_init';
        HelpAbility::registerAbility('gds/zz-probe', [
            'label' => 'Probe',
            'description' => 'Probe',
            'category' => 'gds-content',
            'execute_callback' => '__return_true',
            'permission_callback' => '__return_true',
        ]);
        array_pop($GLOBALS['wp_current_filter']);

        $names = static fn (): array => array_merge(...array_map(
            static fn (array $group): array => array_column($group['abilities'], 'name'),
            HelpAbility::execute()['groups']
        ));

        $this->assertContains('gds/zz-probe', $names());

        wp_unregister_ability('gds/zz-probe');

        $this->assertNotContains('gds/zz-probe', $names());
    }
}
