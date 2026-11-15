<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Concerns\PolylangAware;

final class SiteMapResource
{
    use PolylangAware;

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/site-map', [
            'label' => 'Site Map',
            'description' => 'Read-only site structure showing the primary navigation menu tree and the published pages not in the menu (up to 100, by title; disconnected_total and truncated say whether there are more). On a multilingual site both come from one language: the default one unless lang is given. Use this to understand the site hierarchy before creating or linking content.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'default' => [],
                'properties' => [
                    'lang' => ['type' => 'string', 'description' => 'Polylang language slug whose menu and pages to show. Default: the current, else the default language.'],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'language' => ['type' => ['string', 'null']],
                    'menu' => ['type' => ['object', 'null']],
                    'disconnected' => ['type' => 'array'],
                    'disconnected_total' => ['type' => 'integer'],
                    'truncated' => ['type' => 'boolean'],
                ],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [new self, 'execute'],
            'meta' => [
                // Provide uri/mimeType/annotations in BOTH locations for adapter
                // cross-compatibility: WordPress/mcp-adapter >= 0.5.0 reads meta.mcp.*
                // (canonical), while < 0.5.0 reads only top-level meta.*. Keeping both
                // works on every adapter version without triggering 0.5.0 deprecation
                // notices (the mcp.* copy is matched first). The top-level annotations
                // copy is also what RegisterAbilityAsMcpTool still reads in 0.5.0.
                'uri' => 'site://pages',
                'mimeType' => 'application/json',
                'mcp' => [
                    'type' => 'resource',
                    'public' => true,
                    'uri' => 'site://pages',
                    'mimeType' => 'application/json',
                    'annotations' => [
                        'readonly' => true,
                        'destructive' => false,
                        'idempotent' => true,
                    ],
                ],
                'annotations' => [
                    'readonly' => true,
                    'destructive' => false,
                    'idempotent' => true,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>|\WP_Error
     */
    public function execute(mixed $input = []): array|\WP_Error
    {
        $input = (array) ($input ?? []);

        $lang = null;
        if (self::polylangAvailable() && function_exists('pll_current_language')) {
            if (! empty($input['lang'])) {
                $lang = (string) $input['lang'];
                if ($error = self::validateLanguage($lang)) {
                    return $error;
                }
            } else {
                $lang = (pll_current_language() ?: pll_default_language()) ?: null;
            }
        }

        $menu = self::getPrimaryMenu($lang);
        $menuPageIds = [];

        if ($menu) {
            $menuPageIds = self::collectMenuPageIds($menu['items']);
        }

        [$disconnected, $total] = self::getDisconnectedPages($menuPageIds, $lang);

        return [
            'language' => $lang,
            'menu' => $menu,
            'disconnected' => $disconnected,
            'disconnected_total' => $total,
            'truncated' => $total > count($disconnected),
        ];
    }

    /**
     * Get the primary navigation menu with hierarchical items.
     *
     * @return array<string, mixed>
     */
    private static function getPrimaryMenu(?string $lang): ?array
    {
        $locations = get_nav_menu_locations();

        // Try primary_navigation (Sage default), then primary, then main.
        $locationSlugs = ['primary_navigation', 'primary', 'main'];

        $menuId = null;
        if ($lang) {
            // Polylang keeps each language's menu per location in its options.
            $options = function_exists('PLL') && PLL() ? PLL()->options : [];
            $pllMenus = $options['nav_menus'][get_stylesheet()] ?? [];
            foreach ($locationSlugs as $slug) {
                if (! empty($pllMenus[$slug][$lang]) && wp_get_nav_menu_object((int) $pllMenus[$slug][$lang])) {
                    $menuId = (int) $pllMenus[$slug][$lang];
                    break;
                }
            }

            if ($lang !== pll_default_language()) {
                // Prepend language-specific locations.
                $langSlugs = array_map(fn ($s) => $s.'___'.$lang, $locationSlugs);
                $locationSlugs = array_merge($langSlugs, $locationSlugs);
            }
        }

        foreach ($locationSlugs as $slug) {
            if (! $menuId && ! empty($locations[$slug])) {
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
     *
     * @param  array<int, \stdClass>  $items  Menu items after wp_setup_nav_menu_item — carries dynamic $menu_item_parent / $title / $url / $type / $object / $object_id / $ID props that don't exist on WP_Post.
     * @return list<array<string, mixed>>
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
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, int>
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
     * Get published pages not present in the menu, in the menu's language.
     *
     * @param  array<int, int>  $excludeIds
     * @return array{0: list<array<string, mixed>>, 1: int} The first 100 pages, and how many there are.
     */
    private static function getDisconnectedPages(array $excludeIds, ?string $lang): array
    {
        $args = [
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => 100,
            'orderby' => 'title',
            'order' => 'ASC',
            // Disable Polylang auto-filtering; the language is filtered below.
            'lang' => '',
        ];

        // Pages in the menu's language, and pages that have no language.
        if ($lang) {
            $args['tax_query'] = [
                'relation' => 'OR',
                ['taxonomy' => 'language', 'field' => 'slug', 'terms' => [$lang]],
                ['taxonomy' => 'language', 'operator' => 'NOT EXISTS'],
            ];
        }

        if ($excludeIds) {
            $args['post__not_in'] = $excludeIds;
        }

        $query = new \WP_Query($args);
        $pages = $query->posts;

        return [array_map(fn ($page) => [
            'post_id' => $page->ID,
            'title' => $page->post_title,
            'slug' => $page->post_name,
            'url' => get_permalink($page),
            'parent_id' => $page->post_parent ?: null,
            'language' => self::getPostLanguage($page->ID),
        ], $pages), (int) $query->found_posts];
    }
}
