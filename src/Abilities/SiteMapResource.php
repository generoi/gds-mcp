<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Concerns\PolylangAware;
use WP_Error;

final class SiteMapResource
{
    use PolylangAware;

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/site-map', [
            'label' => 'Site Map',
            'description' => 'Read-only site structure showing the primary navigation menu tree and any published pages not in the menu. Use this to understand the site hierarchy before creating or linking content.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => new \stdClass,
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'menu' => ['type' => ['object', 'null']],
                    'disconnected' => ['type' => 'array'],
                ],
            ],
            'permission_callback' => [self::class, 'checkPermission'],
            'execute_callback' => [self::class, 'execute'],
            'meta' => [
                'uri' => 'site://pages',
                'mimeType' => 'application/json',
                'mcp' => [
                    'type' => 'resource',
                    'public' => true,
                ],
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

        if (! current_user_can('edit_posts')) {
            return new WP_Error('insufficient_capability', 'You do not have permission to view the site map.');
        }

        return true;
    }

    public static function execute(?array $input = []): array
    {
        $menu = self::getPrimaryMenu();
        $menuPageIds = [];

        if ($menu) {
            $menuPageIds = self::collectMenuPageIds($menu['items']);
        }

        $disconnected = self::getDisconnectedPages($menuPageIds);

        return [
            'menu' => $menu,
            'disconnected' => $disconnected,
        ];
    }

    /**
     * Get the primary navigation menu with hierarchical items.
     */
    private static function getPrimaryMenu(): ?array
    {
        $locations = get_nav_menu_locations();

        // Try primary_navigation (Sage default), then primary, then main.
        $locationSlugs = ['primary_navigation', 'primary', 'main'];

        // If Polylang is active, also try language-suffixed locations.
        if (self::polylangAvailable() && function_exists('pll_current_language')) {
            $lang = pll_current_language();
            if ($lang) {
                $defaultLang = pll_default_language();
                if ($lang !== $defaultLang) {
                    // Prepend language-specific locations.
                    $langSlugs = array_map(fn ($s) => $s.'___'.$lang, $locationSlugs);
                    $locationSlugs = array_merge($langSlugs, $locationSlugs);
                }
            }
        }

        $menuId = null;
        foreach ($locationSlugs as $slug) {
            if (! empty($locations[$slug])) {
                $menuId = $locations[$slug];
                break;
            }
        }

        if (! $menuId) {
            // Fall back to first available menu.
            $menus = wp_get_nav_menus();
            if (empty($menus)) {
                return null;
            }
            $menuId = $menus[0]->term_id;
        }

        $menuObj = wp_get_nav_menu_object($menuId);
        if (! $menuObj) {
            return null;
        }

        $menuItems = wp_get_nav_menu_items($menuId) ?: [];
        $tree = self::buildMenuTree($menuItems);

        return [
            'name' => $menuObj->name,
            'items' => $tree,
        ];
    }

    /**
     * Build a hierarchical tree from flat menu items.
     */
    private static function buildMenuTree(array $items, int $parentId = 0): array
    {
        $tree = [];

        foreach ($items as $item) {
            if ((int) $item->menu_item_parent !== $parentId) {
                continue;
            }

            $node = [
                'title' => $item->title,
                'url' => $item->url,
                'type' => $item->type,
                'object' => $item->object,
            ];

            if ($item->type === 'post_type') {
                $node['post_id'] = (int) $item->object_id;
            }

            $children = self::buildMenuTree($items, (int) $item->ID);
            if ($children) {
                $node['children'] = $children;
            }

            $tree[] = $node;
        }

        return $tree;
    }

    /**
     * Collect all page post IDs referenced in the menu tree.
     */
    private static function collectMenuPageIds(array $items): array
    {
        $ids = [];

        foreach ($items as $item) {
            if (! empty($item['post_id'])) {
                $ids[] = $item['post_id'];
            }
            if (! empty($item['children'])) {
                $ids = array_merge($ids, self::collectMenuPageIds($item['children']));
            }
        }

        return $ids;
    }

    /**
     * Get published pages not present in the menu.
     */
    private static function getDisconnectedPages(array $excludeIds): array
    {
        $args = [
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => 100,
            'orderby' => 'title',
            'order' => 'ASC',
            'lang' => '', // Disable Polylang auto-filtering.
        ];

        if ($excludeIds) {
            $args['post__not_in'] = $excludeIds;
        }

        $pages = get_posts($args);

        return array_map(fn ($page) => [
            'post_id' => $page->ID,
            'title' => $page->post_title,
            'slug' => $page->post_name,
            'url' => get_permalink($page),
            'parent_id' => $page->post_parent ?: null,
            'language' => self::getPostLanguage($page->ID),
        ], $pages);
    }
}
