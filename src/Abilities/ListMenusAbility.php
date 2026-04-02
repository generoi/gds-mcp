<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Concerns\PolylangAware;
use WP_Error;

final class ListMenusAbility
{
    use PolylangAware;

    public static function register(): void
    {
        wp_register_ability('gds/list-menus', [
            'label' => 'List Menus',
            'description' => 'List all navigation menus with their language, locations, and item count. With Polylang, each language has its own menu — always verify you are editing the correct language\'s menu before adding items.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'locations' => ['type' => 'object', 'description' => 'Registered menu locations with labels.'],
                    'menus' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'name' => ['type' => 'string'],
                                'slug' => ['type' => 'string'],
                                'item_count' => ['type' => 'integer'],
                                'locations' => ['type' => 'array'],
                                'language' => ['type' => ['string', 'null']],
                            ],
                        ],
                    ],
                ],
            ],
            'permission_callback' => [self::class, 'checkPermission'],
            'execute_callback' => [self::class, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => true,
                    'destructive' => false,
                    'idempotent' => true,
                ],
            ],
        ]);
    }

    public static function checkPermission(?array $input = []): bool|WP_Error
    {
        if (! is_user_logged_in()) {
            return new WP_Error('authentication_required', 'User must be authenticated.');
        }

        if (! current_user_can('edit_theme_options')) {
            return new WP_Error('insufficient_capability', 'You do not have permission to manage menus.');
        }

        return true;
    }

    public static function execute(?array $input = []): array
    {
        // Get registered locations.
        $registeredLocations = get_registered_nav_menus();

        // Get assigned locations.
        $assignedLocations = get_nav_menu_locations();

        // Build reverse lookup: menu_id => [location_slugs].
        $menuLocations = [];
        foreach ($assignedLocations as $location => $menuId) {
            $menuLocations[$menuId][] = $location;
        }

        $menus = wp_get_nav_menus();
        $result = [];

        foreach ($menus as $menu) {
            $locations = $menuLocations[$menu->term_id] ?? [];
            $language = self::detectMenuLanguage($menu->term_id, $locations);

            $result[] = [
                'id' => $menu->term_id,
                'name' => $menu->name,
                'slug' => $menu->slug,
                'item_count' => $menu->count,
                'locations' => $locations,
                'language' => $language,
            ];
        }

        return [
            'locations' => $registeredLocations,
            'menus' => $result,
        ];
    }

    /**
     * Detect menu language from Polylang location suffix or term language.
     *
     * Polylang assigns language-specific locations with a ___lang suffix:
     *   primary_navigation       -> default language (fi)
     *   primary_navigation___en  -> English
     *   primary_navigation___sv  -> Swedish
     */
    private static function detectMenuLanguage(int $termId, array $locations): ?string
    {
        if (! self::polylangAvailable()) {
            return null;
        }

        // Try Polylang term language first.
        if (function_exists('pll_get_term_language')) {
            $lang = pll_get_term_language($termId);
            if ($lang) {
                return $lang;
            }
        }

        // Fall back to detecting language from location suffix.
        $defaultLang = function_exists('pll_default_language') ? pll_default_language() : null;

        foreach ($locations as $location) {
            if (preg_match('/___([a-z]{2,})$/', $location, $matches)) {
                return $matches[1];
            }
        }

        // No suffix means default language.
        if (! empty($locations) && $defaultLang) {
            return $defaultLang;
        }

        return null;
    }
}
