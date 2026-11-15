<?php

namespace GeneroWP\MCP\Abilities;

use WP_Ability;

/**
 * Read-only mode: unregister every MCP-public ability that is not annotated
 * `readonly => true`, so write tools are gone from the MCP server, core's
 * /wp-abilities/v1 routes and gds-assistant at once. An ability with no
 * annotation counts as one that writes.
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
     * Whether the ability may stay registered in read-only mode. Abilities
     * that are not MCP-public are left alone: the adapter's own
     * mcp-adapter/execute-ability runs only MCP-public ones.
     */
    public static function allows(WP_Ability $ability): bool
    {
        $meta = $ability->get_meta();

        if (! ($meta['mcp']['public'] ?? false)) {
            return true;
        }

        return ($meta['annotations']['readonly'] ?? null) === true;
    }
}
