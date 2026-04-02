<?php

namespace GeneroWP\MCP\Abilities;

use WP_Error;

final class AddMenuItemAbility
{
    public static function register(): void
    {
        wp_register_ability('gds/add-menu-item', [
            'label' => 'Add Menu Item',
            'description' => 'Add an item to a navigation menu. Can link to a page, post, custom URL, or any post type. IMPORTANT: With Polylang, each language has its own menu. Always use gds/list-menus first to find the correct language\'s menu ID, and link to pages in the matching language.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'menu_id' => [
                        'type' => 'integer',
                        'description' => 'The menu term ID to add the item to.',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Menu item display title.',
                    ],
                    'type' => [
                        'type' => 'string',
                        'description' => 'Item type: post_type (link to a post/page), custom (custom URL), taxonomy (link to a term archive).',
                        'default' => 'post_type',
                        'enum' => ['post_type', 'custom', 'taxonomy'],
                    ],
                    'object' => [
                        'type' => 'string',
                        'description' => 'Object type: page, post, product, category, etc. Required for post_type and taxonomy types.',
                    ],
                    'object_id' => [
                        'type' => 'integer',
                        'description' => 'The post ID or term ID to link to. Required for post_type and taxonomy types.',
                    ],
                    'url' => [
                        'type' => 'string',
                        'description' => 'Custom URL. Required for custom type.',
                    ],
                    'parent_id' => [
                        'type' => 'integer',
                        'description' => 'Parent menu item ID for nested items.',
                        'default' => 0,
                    ],
                    'position' => [
                        'type' => 'integer',
                        'description' => 'Menu order position.',
                        'default' => 0,
                    ],
                ],
                'required' => ['menu_id', 'title'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'url' => ['type' => 'string'],
                    'menu_id' => ['type' => 'integer'],
                ],
            ],
            'permission_callback' => [self::class, 'checkPermission'],
            'execute_callback' => [self::class, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => false,
                    'destructive' => false,
                    'idempotent' => false,
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

        $itemData = [
            'menu-item-title' => $input['title'],
            'menu-item-status' => 'publish',
            'menu-item-type' => $input['type'] ?? 'post_type',
            'menu-item-parent-id' => $input['parent_id'] ?? 0,
            'menu-item-position' => $input['position'] ?? 0,
        ];

        $type = $input['type'] ?? 'post_type';

        if ($type === 'custom') {
            $url = $input['url'] ?? '#';
            if ($url !== '#') {
                $scheme = wp_parse_url($url, PHP_URL_SCHEME);
                if ($scheme && ! in_array($scheme, ['http', 'https'], true)) {
                    return new WP_Error('invalid_url', 'Custom menu URLs must use http or https.');
                }
                $url = esc_url_raw($url);
            }
            $itemData['menu-item-url'] = $url;
        } else {
            $itemData['menu-item-object'] = $input['object'] ?? 'page';
            $itemData['menu-item-object-id'] = $input['object_id'] ?? 0;
        }

        $result = wp_update_nav_menu_item($menuId, 0, $itemData);

        if (is_wp_error($result)) {
            return $result;
        }

        $item = wp_setup_nav_menu_item(get_post($result));

        return [
            'id' => $result,
            'title' => $item->title,
            'url' => $item->url,
            'menu_id' => $menuId,
        ];
    }
}
