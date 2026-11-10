<?php

namespace GeneroWP\MCP\Integrations\Core;

/**
 * Put WordPress core's own abilities (core/get-site-info, core/get-user-info,
 * core/get-environment-info) on the default MCP server.
 *
 * WordPress 7.0 registers them without any MCP flag, so the adapter hides
 * them. WordPress 7.1 sets the high-level `meta.public`, which MCP Adapter
 * 0.6+ inherits, but older adapters only read `meta.mcp.public`. This sets
 * `meta.mcp.public` from `meta.public` when core set it, and to true when it
 * did not. An explicit `meta.mcp.public` from the registrant is left alone.
 *
 * Exposure does not widen access: each ability keeps its own
 * permission_callback (site and environment info need `manage_options`; user
 * info returns only the caller's own profile), behind the MCP endpoint's
 * floor. Filter `gds-mcp/expose_core_ability` to keep one off the server.
 */
final class AbilitiesBridge
{
    public static function register(): void
    {
        add_filter('wp_register_ability_args', [self::class, 'markCoreAbilitiesPublic'], 10, 2);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function markCoreAbilitiesPublic(array $args, string $name): array
    {
        if (! str_starts_with($name, 'core/') || isset($args['meta']['mcp']['public'])) {
            return $args;
        }

        $public = (bool) ($args['meta']['public'] ?? true);
        $args['meta']['mcp']['public'] = (bool) apply_filters('gds-mcp/expose_core_ability', $public, $name);

        return $args;
    }
}
