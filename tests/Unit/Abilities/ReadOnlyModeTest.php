<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Abilities\ReadOnlyMode;
use GeneroWP\MCP\Undo\RestoreSnapshot;
use WP_Ability;
use WP_Error;
use WP_UnitTestCase;

class ReadOnlyModeTest extends WP_UnitTestCase
{
    public function tear_down(): void
    {
        remove_filter('gds-mcp/read_only', '__return_true');
        parent::tear_down();
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function ability(array $meta, string $name = 'gds-test/probe'): WP_Ability
    {
        return new WP_Ability($name, [
            'label' => 'Probe',
            'description' => 'Probe',
            'category' => 'gds-content',
            'execute_callback' => '__return_true',
            'permission_callback' => '__return_true',
            'meta' => $meta,
        ]);
    }

    public function test_keeps_readonly_abilities(): void
    {
        $this->assertTrue(ReadOnlyMode::allows($this->ability([
            'mcp' => ['public' => true],
            'annotations' => ['readonly' => true],
        ])));
    }

    public function test_drops_abilities_that_write(): void
    {
        $this->assertFalse(ReadOnlyMode::allows($this->ability([
            'mcp' => ['public' => true],
            'annotations' => ['readonly' => false],
        ])));
    }

    public function test_drops_abilities_without_annotation(): void
    {
        $this->assertFalse(ReadOnlyMode::allows($this->ability(['show_in_rest' => true])));
    }

    // A server can list an ability by name without mcp.public.
    public function test_drops_unexposed_abilities_that_write(): void
    {
        $this->assertFalse(ReadOnlyMode::allows($this->ability([
            'annotations' => ['readonly' => false],
        ])));
    }

    public function test_keeps_the_adapters_dispatchers(): void
    {
        $this->assertTrue(ReadOnlyMode::allows($this->ability([
            'annotations' => ['readonly' => false],
        ], 'mcp-adapter/execute-ability')));
    }

    public function test_off_by_default(): void
    {
        $this->assertFalse(ReadOnlyMode::enabled());
    }

    public function test_every_gds_write_ability_would_be_dropped(): void
    {
        foreach (['gds/content-create', 'gds/content-update', 'gds/content-delete', 'gds/mail-send'] as $name) {
            $ability = wp_get_ability($name);
            $this->assertNotNull($ability, "{$name} should be registered");
            $this->assertFalse(ReadOnlyMode::allows($ability), "{$name} should be dropped");
        }

        foreach (['gds/help', 'gds/content-list', 'gds/content-read'] as $name) {
            $ability = wp_get_ability($name);
            $this->assertNotNull($ability, "{$name} should be registered");
            $this->assertTrue(ReadOnlyMode::allows($ability), "{$name} should stay");
        }
    }

    // A write ability registered after the sweep stays listed but can't run.
    public function test_late_write_abilities_are_denied(): void
    {
        add_filter('gds-mcp/read_only', '__return_true');

        $write = ReadOnlyMode::denyWrites(['permission_callback' => '__return_true', 'meta' => []], 'late/write');
        $this->assertInstanceOf(WP_Error::class, ($write['permission_callback'])());

        $read = ReadOnlyMode::denyWrites([
            'permission_callback' => '__return_true',
            'meta' => ['annotations' => ['readonly' => true]],
        ], 'late/read');
        $this->assertSame('__return_true', $read['permission_callback']);
    }

    public function test_undo_is_refused(): void
    {
        add_filter('gds-mcp/read_only', '__return_true');

        $result = RestoreSnapshot::handle(null, ['kind' => 'trash', 'data' => ['post_id' => 1]]);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('gds_mcp_read_only', $result->get_error_code());
    }

    public function test_help_skips_unregistered_abilities(): void
    {
        // wp_register_ability() only accepts registrations during this action.
        $GLOBALS['wp_current_filter'][] = 'wp_abilities_api_init';
        try {
            HelpAbility::registerAbility('gds/zz-probe', [
                'label' => 'Probe',
                'description' => 'Probe',
                'category' => 'gds-content',
                'execute_callback' => '__return_true',
                'permission_callback' => '__return_true',
            ]);
        } finally {
            array_pop($GLOBALS['wp_current_filter']);
        }

        $names = static fn (): array => array_merge(...array_map(
            static fn (array $group): array => array_column($group['abilities'], 'name'),
            HelpAbility::execute()['groups']
        ));

        try {
            $this->assertContains('gds/zz-probe', $names());
            wp_unregister_ability('gds/zz-probe');
            $this->assertNotContains('gds/zz-probe', $names());
        } finally {
            if (wp_has_ability('gds/zz-probe')) {
                wp_unregister_ability('gds/zz-probe');
            }
        }
    }
}
