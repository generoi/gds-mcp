<?php

namespace GeneroWP\MCP\Abilities;

/**
 * Centralised, least-privilege capability policy for gds/* abilities.
 *
 * Most abilities register with `permission_callback => '__return_true'`, which
 * delegates all authorization to the single chat-level gate in gds-assistant
 * (`edit_posts` by default). That lets an Author/Editor perform admin-domain
 * operations — editing/deleting Gravity Forms and their feeds, taxonomy terms,
 * menus, redirects, caches, translations, reading submissions (PII) — that
 * wp-admin gates behind `manage_options` and friends.
 *
 * This maps each ability to the WordPress capability its admin equivalent
 * needs and injects it at registration via `wp_register_ability_args`. It only
 * replaces the default `__return_true`; abilities that already declare a
 * stricter callback (e.g. mail-send → manage_options) are left untouched, so
 * the policy can only tighten, never loosen. Content operations stay at the
 * editor baseline — managing content is the AI's intended job — while
 * admin-domain tools require the matching admin capability.
 *
 * Override any mapping with the `gds-mcp/ability_capability` filter.
 */
final class CapabilityPolicy
{
    public static function register(): void
    {
        // Priority 20 so it runs after peer bridges (e.g. WooCommerce at 10),
        // and only ever touches the gds/ namespace.
        add_filter('wp_register_ability_args', [self::class, 'applyCapability'], 20, 2);
    }

    /**
     * @param  array  $args  Ability registration args.
     * @param  string  $name  Ability name, e.g. `gds/forms-update`.
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public static function applyCapability(array $args, string $name): array
    {
        if (! str_starts_with($name, 'gds/')) {
            return $args;
        }

        // Only replace the permissive default — never loosen a real check.
        $current = $args['permission_callback'] ?? null;
        if ($current !== null && $current !== '__return_true') {
            return $args;
        }

        $cap = self::capabilityFor($name);
        if ($cap === null || $cap === '') {
            return $args;
        }

        $args['permission_callback'] = static fn () => current_user_can($cap);

        return $args;
    }

    /**
     * Resolve the required capability for an ability.
     *
     * @return string|null The required capability, or null to leave the
     *                     ability's existing permission_callback in place.
     */
    public static function capabilityFor(string $name): ?string
    {
        $cap = self::map()[$name] ?? null;

        return apply_filters('gds-mcp/ability_capability', $cap, $name);
    }

    /**
     * Capability overrides for abilities that have NO authorization of their
     * own. Deliberately narrow: most write abilities already enforce caps
     * internally (returning `forbidden`) or via their REST controller, and
     * reads are intentionally public. The Gravity Forms abilities are the
     * exception — they call GFAPI directly with `permission_callback =>
     * __return_true` and no internal check, so an editor could edit/delete
     * forms and feeds (breaking integrations) or read submissions (PII).
     *
     * @return array<string, string>
     */
    private static function map(): array
    {
        $manage = 'manage_options';

        return [
            'gds/forms-list' => $manage,
            'gds/forms-read' => $manage,
            'gds/forms-create' => $manage,
            'gds/forms-update' => $manage,
            'gds/forms-entries' => $manage,
            'gds/feeds-list' => $manage,
            'gds/feeds-read' => $manage,
            'gds/feeds-create' => $manage,
            'gds/feeds-update' => $manage,
            'gds/feeds-delete' => $manage,
            'gds/feeds-duplicate' => $manage,
        ];
    }
}
