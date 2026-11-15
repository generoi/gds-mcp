<?php

namespace GeneroWP\MCP\Abilities;

use WP_Ability;
use WP_Error;

/**
 * Read-only mode: unregister every ability not annotated `readonly => true`,
 * whichever server, route or assistant exposes it, so write tools are gone
 * from every MCP server, core's /wp-abilities/v1 routes and gds-assistant at
 * once. An ability with no annotation counts as one that writes.
 *
 * Off unless the site defines `GDS_MCP_READ_ONLY` as true, or the
 * `gds-mcp/read_only` filter says otherwise.
 */
final class ReadOnlyMode
{
    public static function register(): void
    {
        // Last, so abilities other integrations register on the same action
        // (WooCommerce at 11) are covered too.
        add_action('wp_abilities_api_init', [self::class, 'unregisterWriteAbilities'], PHP_INT_MAX);
        // A write ability registered after that sweep (a callback added later
        // at PHP_INT_MAX) stays listed, but can't run.
        add_filter('wp_register_ability_args', [self::class, 'denyWrites'], PHP_INT_MAX, 2);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function denyWrites(array $args, string $name): array
    {
        if (self::enabled() && ! self::isReadOnly($name, $args['meta'] ?? [])) {
            $args['permission_callback'] = static fn () => new WP_Error(
                'gds_mcp_read_only',
                __('This site is in read-only mode.', 'gds-mcp'),
                ['status' => 403]
            );
        }

        return $args;
    }

    public static function enabled(): bool
    {
        $enabled = defined('GDS_MCP_READ_ONLY') && GDS_MCP_READ_ONLY;

        return (bool) apply_filters('gds-mcp/read_only', $enabled);
    }

    public static function unregisterWriteAbilities(): void
    {
        if (! self::enabled()) {
            return;
        }

        foreach (wp_get_abilities() as $ability) {
            if (! self::allows($ability)) {
                wp_unregister_ability($ability->get_name());
            }
        }
    }

    /**
     * Whether the ability may stay registered in read-only mode.
     */
    public static function allows(WP_Ability $ability): bool
    {
        return self::isReadOnly($ability->get_name(), $ability->get_meta());
    }

    /**
     * Annotated `readonly => true`, or one of the MCP adapter's own
     * dispatchers (mcp-adapter/execute-ability is marked as writing, but only
     * runs abilities still registered, which are the read-only ones).
     *
     * @param  array<string, mixed>  $meta
     */
    private static function isReadOnly(string $name, array $meta): bool
    {
        return str_starts_with($name, 'mcp-adapter/')
            || ($meta['annotations']['readonly'] ?? null) === true;
    }
}
