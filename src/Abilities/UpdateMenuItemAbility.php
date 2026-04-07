<?php

namespace GeneroWP\MCP\Abilities;

use WP_Error;

final class UpdateMenuItemAbility
{
    public static function register(): void
    {
        HelpAbility::registerAbility('gds/menus-update-item', [
            'label' => 'Update Menu Item',
            'description' => 'Update an existing menu item\'s title, URL, parent, or position. Use gds/menus-get to find item IDs.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'menu_item_id' => [
                        'type' => 'integer',
                        'description' => 'The menu item ID to update (from gds/menus-get).',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'New display title.',
                    ],
                    'url' => [
                        'type' => 'string',
                        'description' => 'New URL (only for custom type items).',
                    ],
                    'parent_id' => [
                        'type' => 'integer',
                        'description' => 'New parent menu item ID (0 for top-level).',
                    ],
                    'position' => [
                        'type' => 'integer',
                        'description' => 'New menu order position.',
                    ],
                ],
                'required' => ['menu_item_id'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'url' => ['type' => 'string'],
                    'parent_id' => ['type' => 'integer'],
                    'position' => ['type' => 'integer'],
                ],
            ],
            'permission_callback' => [self::class, 'checkPermission'],
            'execute_callback' => [self::class, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => false,
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
        $menuItemId = (int) ($input['menu_item_id'] ?? 0);

        $menuItem = get_post($menuItemId);
        if (! $menuItem || $menuItem->post_type !== 'nav_menu_item') {
            return new WP_Error('menu_item_not_found', 'Menu item not found.');
        }

        $terms = wp_get_object_terms($menuItemId, 'nav_menu');
        if (empty($terms) || is_wp_error($terms)) {
            return new WP_Error('menu_not_found', 'Could not determine which menu this item belongs to.');
        }
        $menuId = $terms[0]->term_id;

        $setupItem = wp_setup_nav_menu_item($menuItem);

        $itemData = [
            'menu-item-title' => $input['title'] ?? $setupItem->title,
            'menu-item-status' => 'publish',
            'menu-item-type' => $setupItem->type,
            'menu-item-object' => $setupItem->object,
            'menu-item-object-id' => $setupItem->object_id,
            'menu-item-url' => $setupItem->url,
            'menu-item-parent-id' => $input['parent_id'] ?? $setupItem->menu_item_parent,
            'menu-item-position' => $input['position'] ?? $setupItem->menu_order,
        ];

        if (isset($input['url']) && $setupItem->type === 'custom') {
            $url = $input['url'];
            if ($url !== '#') {
                $scheme = wp_parse_url($url, PHP_URL_SCHEME);
                if ($scheme && ! in_array($scheme, ['http', 'https'], true)) {
                    return new WP_Error('invalid_url', 'Custom menu URLs must use http or https.');
                }
                $url = esc_url_raw($url);
            }
            $itemData['menu-item-url'] = $url;
        }

        $result = wp_update_nav_menu_item($menuId, $menuItemId, $itemData);

        if (is_wp_error($result)) {
            return $result;
        }

        $updated = wp_setup_nav_menu_item(get_post($result));

        return [
            'id' => $result,
            'title' => $updated->title,
            'url' => $updated->url,
            'parent_id' => (int) $updated->menu_item_parent,
            'position' => (int) $updated->menu_order,
        ];
    }
}
