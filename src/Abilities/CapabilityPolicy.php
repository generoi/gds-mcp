<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\OAuth\Server;

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
        // The rest need the floor every MCP caller already meets (edit_posts
        // unless filtered): show_in_rest also puts them on core's
        // /wp-abilities/v1 run route, which checks only this callback, so a
        // public default would let anonymous visitors run them.
        $cap = self::map()[$name] ?? Server::capability();

        return apply_filters('gds-mcp/ability_capability', $cap, $name);
    }

    /**
     * Capability overrides for gds/* abilities; any other gds/* ability needs
     * the MCP floor, {@see Server::capability()}. REST-delegated CRUD is left to its REST controller for the
     * per-object checks (post types and taxonomies carry their own caps). The
     * Gravity Forms abilities are the
     * exception — they call GFAPI directly with `permission_callback =>
     * __return_true` and no internal check, so an editor could edit/delete
     * forms and feeds (breaking integrations) or read submissions (PII).
     *
     * The nav-menu abilities are the other exception. Delete, move and reorder
     * call wp_delete_post()/wp_update_post() directly and check nothing of
     * their own; create renumbers menu_order across the menu before handing
     * over to WP_REST_Menu_Items_Controller, so an unauthorized caller writes
     * first and is rolled back afterwards. Core registers `nav_menu_item` as
     * an `edit_theme_options` object — the same capability the controller
     * enforces — so gating the whole set here changes nothing for a caller who
     * is allowed, and stops one who is not before any write.
     *
     * The rest carry the coarse capability their execute callback needs
     * anyway, so a caller who can never succeed is refused at the permission
     * check (and the MCP client sees the tool as unavailable) rather than
     * partway through. Per-object checks (`edit_post` on the target, the post
     * type's `publish_posts`, …) stay in the execute callbacks.
     *
     * @return array<string, string>
     */
    private static function map(): array
    {
        $manage = 'manage_options';
        $theme = 'edit_theme_options';
        $edit = 'edit_posts';

        return [
            'gds/posts-duplicate' => $edit,
            'gds/posts-bulk-update' => $edit,
            'gds/blocks-patch' => $edit,
            'gds/content-replace' => $edit,
            'gds/revisions-restore' => $edit,
            'gds/translations-create' => $edit,
            'gds/translations-link' => $edit,
            'gds/translations-machine' => $edit,
            'gds/seo-update' => $edit,
            'gds/media-upload' => 'upload_files',
            'gds/cache-clear' => $manage,
            'gds/strings-update' => $manage,
            'gds/activity-query' => $manage,
            'gds/redirects-manage' => $manage,
            'gds/nav-menu-items-create' => $theme,
            'gds/nav-menu-items-update' => $theme,
            'gds/nav-menu-items-delete' => $theme,
            'gds/nav-menu-items-move' => $theme,
            'gds/nav-menu-items-reorder' => $theme,
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
