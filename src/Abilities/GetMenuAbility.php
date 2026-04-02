<?php

namespace GeneroWP\MCP\Abilities;

use WP_Error;

final class GetMenuAbility
{
    public static function register(): void
    {
        HelpAbility::registerAbility('gds/menus-get', [
            'label' => 'Get Menu',
            'description' => 'Get all items in a navigation menu with their hierarchy, links, and types.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'menu_id' => [
                        'type' => 'integer',
                        'description' => 'The menu term ID. Use gds/menus-list to find available menus.',
                    ],
                ],
                'required' => ['menu_id'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'title' => ['type' => 'string'],
                                'url' => ['type' => 'string'],
                                'type' => ['type' => 'string'],
                                'object' => ['type' => 'string'],
                                'object_id' => ['type' => 'integer'],
                                'parent_id' => ['type' => 'integer'],
                                'position' => ['type' => 'integer'],
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

    public static function execute(?array $input = []): array|WP_Error
    {
        $menuId = $input['menu_id'] ?? 0;
        $menu = wp_get_nav_menu_object($menuId);

        if (! $menu) {
            return new WP_Error('menu_not_found', sprintf('Menu %d not found.', $menuId));
        }

        $menuItems = wp_get_nav_menu_items($menuId) ?: [];

        $items = array_map(fn ($item) => [
            'id' => (int) $item->ID,
            'title' => $item->title,
            'url' => $item->url,
            'type' => $item->type,
            'object' => $item->object,
            'object_id' => (int) $item->object_id,
            'parent_id' => (int) $item->menu_item_parent,
            'position' => (int) $item->menu_order,
        ], $menuItems);

        return [
            'id' => $menu->term_id,
            'name' => $menu->name,
            'items' => $items,
        ];
    }
}
