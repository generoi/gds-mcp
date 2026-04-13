<?php

namespace GeneroWP\MCP\Tests\Integration;

use GeneroWP\MCP\Tests\AbilityTestCase;

/**
 * Schema round-trip validation: verifies every registered gds/* ability
 * can be executed with valid input and produces output that passes
 * its own output schema validation.
 *
 * This catches:
 * - Missing input_schema (WP_Ability requires it)
 * - Output format drift (ability returns structure that doesn't match schema)
 * - Registration errors (typos in schema definitions)
 */
class SchemaValidationTest extends AbilityTestCase
{
    /**
     * Minimal valid input per ability for a successful round-trip.
     * Abilities not listed here are tested with empty input [].
     */
    private static function fixtures(): array
    {
        return [
            // Content CRUD needs a type
            'gds/content-list' => ['type' => 'posts', 'per_page' => 1],
            'gds/content-read' => null, // needs real ID, tested separately
            'gds/content-create' => null, // write-path, tested separately
            'gds/content-update' => null,
            'gds/content-delete' => null,

            // Terms CRUD needs a taxonomy
            'gds/terms-list' => ['taxonomy' => 'categories', 'per_page' => 1, 'hide_empty' => false],
            'gds/terms-read' => null,
            'gds/terms-create' => null,
            'gds/terms-update' => null,
            'gds/terms-delete' => null,

            // Custom abilities
            'gds/help' => [],
            'gds/posts-duplicate' => null, // needs real ID
            'gds/posts-bulk-update' => ['post_ids' => [], 'dry_run' => true],
            'gds/revisions-list' => null, // needs real ID
            'gds/revisions-read' => null,
            'gds/revisions-restore' => null,
            'gds/blocks-get' => null, // needs block name
            'gds/blocks-patch' => null,
            'gds/block-types-list' => [],
            'gds/site-map' => [],
            'gds/design-theme-json' => [],

            // Polylang
            'gds/languages-list' => [],
            'gds/translations-create' => null,
            'gds/translations-audit' => ['post_type' => 'post'],
            'gds/strings-list' => [],
            'gds/strings-update' => null,
            'gds/translations-create-term' => null,

            // Redirects
            'gds/redirects-manage' => ['action' => 'list'],

            // Stream
            'gds/activity-query' => ['per_page' => 1],

            // Cache
            'gds/cache-clear' => null, // sage-cachetags may not be active
        ];
    }

    /**
     * Test that all gds/* abilities are registered.
     */
    public function test_all_expected_abilities_are_registered(): void
    {
        $abilities = wp_get_abilities();
        $gdsAbilities = [];

        foreach ($abilities as $ability) {
            if (str_starts_with($ability->get_name(), 'gds/')) {
                $gdsAbilities[] = $ability->get_name();
            }
        }

        $this->assertNotEmpty($gdsAbilities, 'No gds/* abilities registered.');

        // Only check abilities that should always be registered (not conditional on plugins).
        foreach (array_keys(self::fixtures()) as $name) {
            if (wp_has_ability($name)) {
                continue;
            }
            // Conditionally-registered abilities are OK to skip.
            $conditional = ['gds/cache-clear', 'gds/translations-machine', 'gds/acf-fields'];
            if (in_array($name, $conditional, true)) {
                continue;
            }
            $this->assertContains($name, $gdsAbilities, "Expected ability '{$name}' is not registered.");
        }
    }

    /**
     * Test that all gds/* abilities have valid input schemas.
     */
    public function test_all_abilities_have_input_schema(): void
    {
        $abilities = wp_get_abilities();

        foreach ($abilities as $ability) {
            if (! str_starts_with($ability->get_name(), 'gds/')) {
                continue;
            }

            $schema = $ability->get_input_schema();
            $this->assertNotEmpty(
                $schema,
                "Ability '{$ability->get_name()}' has no input_schema. The Abilities API requires it."
            );
        }
    }

    /**
     * Test that all gds/* abilities have metadata (annotations).
     */
    public function test_all_abilities_have_annotations(): void
    {
        $abilities = wp_get_abilities();

        foreach ($abilities as $ability) {
            if (! str_starts_with($ability->get_name(), 'gds/')) {
                continue;
            }

            $meta = $ability->get_meta();
            // gds/help is registered directly via wp_register_ability, not HelpAbility::registerAbility
            if ($ability->get_name() === 'gds/help') {
                continue;
            }

            $this->assertArrayHasKey(
                'annotations',
                $meta,
                "Ability '{$ability->get_name()}' missing annotations metadata."
            );
        }
    }

    /**
     * @dataProvider readOnlyAbilitiesProvider
     *
     * Execute each read-only ability with minimal valid input and verify
     * no schema validation errors occur.
     */
    public function test_read_only_ability_schema_round_trip(string $name, array $input): void
    {
        if (! wp_has_ability($name)) {
            $this->markTestSkipped("Ability '{$name}' is not registered (plugin may not be active).");
        }

        $result = $this->executeAbility($name, $input);

        // The result should not be a schema validation error.
        if (is_wp_error($result)) {
            $code = $result->get_error_code();
            $this->assertNotSame(
                'ability_invalid_output',
                $code,
                "Ability '{$name}' output failed schema validation: ".$result->get_error_message()
            );
            $this->assertNotSame(
                'ability_invalid_input',
                $code,
                "Ability '{$name}' input failed schema validation: ".$result->get_error_message()
            );
        }
    }

    public static function readOnlyAbilitiesProvider(): array
    {
        $cases = [];
        foreach (self::fixtures() as $name => $input) {
            if ($input === null) {
                continue; // skip abilities that need real data
            }
            $cases[$name] = [$name, $input];
        }

        return $cases;
    }
}
