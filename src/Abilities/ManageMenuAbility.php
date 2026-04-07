<?php

namespace GeneroWP\MCP\Abilities;

use WP_Error;

final class ManageMenuAbility
{
    public static function register(): void
    {
        HelpAbility::registerAbility('gds/menus-manage', [
            'label' => 'Manage Menus',
            'description' => 'Create, update, or delete navigation menus. Use gds/menus-list to discover existing menus.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'action' => [
                        'type' => 'string',
                        'description' => 'Action to perform.',
                        'enum' => ['create', 'update', 'delete'],
                    ],
                    'menu_id' => [
                        'type' => 'integer',
                        'description' => 'Menu term ID (required for update and delete).',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'Menu name (required for create, optional for update).',
                    ],
                    'locations' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Theme menu locations to assign (e.g. ["primary_navigation"]). Use gds/menus-list to see available locations.',
                    ],
                ],
                'required' => ['action'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'menu_id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'action' => ['type' => 'string'],
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
        $action = $input['action'] ?? '';

        return match ($action) {
            'create' => self::create($input),
            'update' => self::update($input),
            'delete' => self::delete($input),
            default => new WP_Error('invalid_action', 'Action must be create, update, or delete.'),
        };
    }

    private static function create(array $input): array|WP_Error
    {
        $name = $input['name'] ?? '';
        if (empty($name)) {
            return new WP_Error('missing_name', 'Menu name is required for create.');
        }

        $menuId = wp_create_nav_menu($name);
        if (is_wp_error($menuId)) {
            return $menuId;
        }

        if (! empty($input['locations'])) {
            self::assignLocations($menuId, $input['locations']);
        }

        return [
            'menu_id' => $menuId,
            'name' => $name,
            'action' => 'created',
        ];
    }

    private static function update(array $input): array|WP_Error
    {
        $menuId = $input['menu_id'] ?? 0;
        $menu = wp_get_nav_menu_object($menuId);
        if (! $menu) {
            return new WP_Error('menu_not_found', sprintf('Menu %d not found.', $menuId));
        }

        $name = $input['name'] ?? $menu->name;
        $result = wp_update_nav_menu_object($menuId, ['menu-name' => $name]);
        if (is_wp_error($result)) {
            return $result;
        }

        if (isset($input['locations'])) {
            self::assignLocations($menuId, $input['locations']);
        }

        return [
            'menu_id' => $menuId,
            'name' => $name,
            'action' => 'updated',
        ];
    }

    private static function delete(array $input): array|WP_Error
    {
        $menuId = $input['menu_id'] ?? 0;
        $menu = wp_get_nav_menu_object($menuId);
        if (! $menu) {
            return new WP_Error('menu_not_found', sprintf('Menu %d not found.', $menuId));
        }

        $name = $menu->name;
        $result = wp_delete_nav_menu($menuId);
        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'menu_id' => $menuId,
            'name' => $name,
            'action' => 'deleted',
        ];
    }

    private static function assignLocations(int $menuId, array $locations): void
    {
        $current = get_nav_menu_locations();
        foreach ($locations as $location) {
            $current[$location] = $menuId;
        }
        set_theme_mod('nav_menu_locations', $current);
    }
}
