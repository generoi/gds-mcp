<?php

namespace GeneroWP\MCP\Abilities;

use WP_Error;

final class RemoveMenuItemAbility
{
    public static function register(): void
    {
        HelpAbility::registerAbility('gds/menus-remove-item', [
            'label' => 'Remove Menu Item',
            'description' => 'Remove an item from a navigation menu.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'menu_item_id' => [
                        'type' => 'integer',
                        'description' => 'The menu item ID to remove (from gds/menus-get).',
                    ],
                ],
                'required' => ['menu_item_id'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'deleted' => ['type' => 'boolean'],
                    'menu_item_id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                ],
            ],
            'permission_callback' => [self::class, 'checkPermission'],
            'execute_callback' => [self::class, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => false,
                    'destructive' => true,
                    'idempotent' => true,
                ],
            ],
        ]);
    }

    public static function checkPermission(mixed $input = []): bool|WP_Error
    {
        $input = is_array($input) ? $input : [];
        if (! is_user_logged_in()) {
            return new WP_Error('authentication_required', 'User must be authenticated.');
        }

        if (! current_user_can('edit_theme_options')) {
            return new WP_Error('insufficient_capability', 'You do not have permission to manage menus.');
        }

        return true;
    }

    public static function execute(mixed $input = []): array|WP_Error
    {
        $input = is_array($input) ? $input : [];
        $menuItemId = (int) ($input['menu_item_id'] ?? 0);

        $menuItem = get_post($menuItemId);
        if (! $menuItem || $menuItem->post_type !== 'nav_menu_item') {
            return new WP_Error('menu_item_not_found', 'Menu item not found.');
        }

        $title = $menuItem->post_title;

        $result = wp_delete_post($menuItemId, true);

        if (! $result) {
            return new WP_Error('delete_failed', 'Failed to remove menu item.');
        }

        return [
            'deleted' => true,
            'menu_item_id' => $menuItemId,
            'title' => $title,
        ];
    }
}
