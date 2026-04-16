<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Concerns\RestDelegation;
use WP_Error;

/**
 * Dedicated ability for nav_menu_item CRUD + menu-specific operations.
 *
 * Why this is separate from GenericPostTypeAbility:
 *
 *  1. Type overload. `nav_menu_item` posts have a `type` field in their REST
 *     schema (post_type | taxonomy | custom | post_type_archive) that collides
 *     with the generic tool's own `type` parameter (the post-type slug being
 *     acted on). When the LLM sent `type: "menu-items"` for routing, the
 *     generic tool stripped it before forwarding to REST; REST saw no `type`
 *     and defaulted to `custom`, silently discarding the provided `object_id`.
 *
 *  2. Cascading order math. Inserting/moving/deleting a menu item requires
 *     shifting `menu_order` on siblings. In the generic tool, that meant
 *     23+ individual `content-update` calls to do one logical "insert at
 *     position X" operation.
 *
 * This ability hides the REST type/object/object_id tangle behind a
 * discriminated-union `linked` object and offers atomic `-move` and
 * `-reorder` operations that do the sibling shifts server-side.
 */
final class NavMenuItemsAbility
{
    use RestDelegation;

    private const ROUTE = '/wp/v2/menu-items';

    public static function register(): void
    {
        $instance = new self;

        self::registerList($instance);
        self::registerRead($instance);
        self::registerCreate($instance);
        self::registerUpdate($instance);
        self::registerDelete($instance);
        self::registerMove($instance);
        self::registerReorder($instance);
    }

    // ── Registration ────────────────────────────────────────────────────────

    private static function registerList(self $instance): void
    {
        HelpAbility::registerAbility('gds/nav-menu-items-list', [
            'label' => 'List Menu Items',
            'description' => 'List items in a nav menu, as a tree or flat list. Filter by parent_item_id to get direct children of a specific dropdown. Each item includes its `linked` info (the page/term/url it points to) and `position` (1-indexed rank among its siblings).',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'menu_id' => ['type' => 'integer', 'description' => 'The nav_menu term ID. Use gds/terms-list?taxonomy=nav_menu to discover menus.'],
                    'parent_item_id' => ['type' => 'integer', 'description' => 'Optional: filter to direct children of this menu item. 0 = only top-level items.'],
                    'tree' => ['type' => 'boolean', 'description' => 'Return nested tree (default true) vs flat list.', 'default' => true],
                ],
                'required' => ['menu_id'],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'executeList'],
            'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);
    }

    private static function registerRead(self $instance): void
    {
        HelpAbility::registerAbility('gds/nav-menu-items-read', [
            'label' => 'Read Menu Item',
            'description' => 'Read a single menu item by its post ID. Returns full linkage (what page/term/url it points to), parent, position within siblings, menu, and CSS classes.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Menu item post ID.'],
                ],
                'required' => ['id'],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'executeRead'],
            'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);
    }

    private static function registerCreate(self $instance): void
    {
        HelpAbility::registerAbility('gds/nav-menu-items-create', [
            'label' => 'Create Menu Item',
            'description' => 'Create a new menu item. Set `linked` to specify what it points to:'
                ."\n  - {kind: \"post\", post_id: 123}   — link to a post/page"
                ."\n  - {kind: \"taxonomy\", taxonomy: \"category\", term_id: 4} — link to a term archive"
                ."\n  - {kind: \"archive\", post_type: \"post\"} — link to a post-type archive"
                ."\n  - {kind: \"url\", url: \"https://…\"} — plain URL link"
                ."\nUse `position` (1-indexed within the parent\'s children) to insert at a specific spot; "
                .'siblings are shifted down automatically. Omit for append.'
                ."\n\nMultilingual note: menu items have NO `lang` field — they inherit language from the menu they belong to. "
                .'Each language has its own menu (discover them via gds/terms-list taxonomy=nav_menu). To add the same link to several languages, call this tool once per language menu with that language\'s translated page as `linked.post_id`.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'menu_id' => ['type' => 'integer', 'description' => 'The nav_menu term ID.'],
                    'title' => ['type' => 'string', 'description' => 'Menu label displayed to users.'],
                    'linked' => self::linkedSchema(),
                    'parent_item_id' => ['type' => 'integer', 'description' => 'Parent menu item post ID (the dropdown it belongs to). 0 = top level.', 'default' => 0],
                    'position' => ['type' => 'integer', 'description' => '1-indexed position within siblings under parent_item_id. Omit to append.'],
                    'description' => ['type' => 'string'],
                    'target' => ['type' => 'string', 'enum' => ['', '_blank'], 'description' => 'Link target. Use "_blank" to open in a new tab.'],
                    'css_classes' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'status' => ['type' => 'string', 'enum' => ['publish', 'draft'], 'default' => 'publish'],
                ],
                'required' => ['menu_id', 'title', 'linked'],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'executeCreate'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
        ]);
    }

    private static function registerUpdate(self $instance): void
    {
        HelpAbility::registerAbility('gds/nav-menu-items-update', [
            'label' => 'Update Menu Item',
            'description' => 'Update fields of an existing menu item. For moving to a different menu/parent/position, use gds/nav-menu-items-move instead.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'linked' => self::linkedSchema(),
                    'description' => ['type' => 'string'],
                    'target' => ['type' => 'string', 'enum' => ['', '_blank']],
                    'css_classes' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'status' => ['type' => 'string', 'enum' => ['publish', 'draft']],
                ],
                'required' => ['id'],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'executeUpdate'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
        ]);
    }

    private static function registerDelete(self $instance): void
    {
        HelpAbility::registerAbility('gds/nav-menu-items-delete', [
            'label' => 'Delete Menu Item',
            'description' => 'Permanently delete a menu item. Sibling items below it shift up to close the gap.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
                'required' => ['id'],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'executeDelete'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => false]],
        ]);
    }

    private static function registerMove(self $instance): void
    {
        HelpAbility::registerAbility('gds/nav-menu-items-move', [
            'label' => 'Move Menu Item',
            'description' => 'Atomically move a menu item to a new menu, parent, and/or position. Siblings in both old and new locations are re-numbered in one operation.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'Menu item post ID to move.'],
                    'menu_id' => ['type' => 'integer', 'description' => 'Destination menu ID. Omit to keep current menu.'],
                    'parent_item_id' => ['type' => 'integer', 'description' => 'Destination parent menu item ID (0 = top level). Omit to keep current parent.'],
                    'position' => ['type' => 'integer', 'description' => '1-indexed position among siblings at the destination.'],
                ],
                'required' => ['id', 'position'],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'executeMove'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
        ]);
    }

    private static function registerReorder(self $instance): void
    {
        HelpAbility::registerAbility('gds/nav-menu-items-reorder', [
            'label' => 'Reorder Menu Items',
            'description' => 'Bulk set the exact order of all children under a given parent in a menu. Pass item IDs in the desired order. Only touches items already under the specified parent — use gds/nav-menu-items-move to reparent.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'menu_id' => ['type' => 'integer'],
                    'parent_item_id' => ['type' => 'integer', 'default' => 0],
                    'item_ids_in_order' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'All sibling item IDs in the desired final order. Must cover every current child of the parent.',
                    ],
                ],
                'required' => ['menu_id', 'item_ids_in_order'],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'executeReorder'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
        ]);
    }

    /** The `linked` discriminated-union schema shared between create and update. */
    private static function linkedSchema(): array
    {
        return [
            'type' => 'object',
            'description' => 'What the menu item points to. kind determines which other fields are required.',
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => ['post', 'taxonomy', 'archive', 'url']],
                'post_id' => ['type' => 'integer', 'description' => 'For kind=post: the target post/page ID.'],
                'post_type' => ['type' => 'string', 'description' => 'For kind=post: the post type slug (auto-detected from post_id if omitted). For kind=archive: the post type whose archive this links to.'],
                'taxonomy' => ['type' => 'string', 'description' => 'For kind=taxonomy: the taxonomy slug (e.g. "category", "post_tag").'],
                'term_id' => ['type' => 'integer', 'description' => 'For kind=taxonomy: the term ID.'],
                'url' => ['type' => 'string', 'description' => 'For kind=url: the full URL.'],
            ],
            'required' => ['kind'],
        ];
    }

    // ── Execute methods ─────────────────────────────────────────────────────

    public function executeList(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $menuId = (int) ($input['menu_id'] ?? 0);
        if ($menuId <= 0) {
            return new WP_Error('missing_menu_id', 'menu_id is required.');
        }

        $tree = $input['tree'] ?? true;
        $parentFilter = array_key_exists('parent_item_id', $input) ? (int) $input['parent_item_id'] : null;

        $items = wp_get_nav_menu_items($menuId);
        if ($items === false) {
            return new WP_Error('menu_not_found', "No menu found with ID {$menuId}.");
        }

        $transformed = array_map([self::class, 'transformItem'], $items);

        if ($parentFilter !== null) {
            $transformed = array_values(array_filter($transformed, fn ($i) => $i['parent_item_id'] === $parentFilter));
        }

        return [
            'items' => $tree ? self::buildTree($transformed) : $transformed,
            'total' => count($transformed),
        ];
    }

    public function executeRead(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            return new WP_Error('missing_id', 'id is required.');
        }

        $post = get_post($id);
        if (! $post || $post->post_type !== 'nav_menu_item') {
            return new WP_Error('item_not_found', "No menu item found with ID {$id}.");
        }

        $items = wp_setup_nav_menu_item(clone $post);

        return self::transformItem($items);
    }

    public function executeCreate(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $menuId = (int) ($input['menu_id'] ?? 0);
        $parentItemId = (int) ($input['parent_item_id'] ?? 0);
        $position = isset($input['position']) ? (int) $input['position'] : null;

        if ($menuId <= 0) {
            return new WP_Error('missing_menu_id', 'menu_id is required.');
        }
        if (empty($input['title'])) {
            return new WP_Error('missing_title', 'title is required.');
        }
        if (empty($input['linked']) || ! is_array($input['linked'])) {
            return new WP_Error('missing_linked', 'linked is required.');
        }

        $restFields = self::linkedToRestFields($input['linked']);
        if ($restFields instanceof WP_Error) {
            return $restFields;
        }

        $targetOrder = self::computeTargetOrder($menuId, $parentItemId, $position);
        // Bump siblings globally to open the slot at target_order.
        self::shiftOrder($menuId, $targetOrder, +1);

        $payload = array_merge(
            [
                'title' => $input['title'],
                'status' => $input['status'] ?? 'publish',
                'menus' => $menuId,
                'parent' => $parentItemId,
                'menu_order' => $targetOrder,
            ],
            $restFields,
            self::optionalFieldsFromInput($input),
        );

        $response = self::restPost(self::ROUTE, $payload);
        if (self::isRestError($response)) {
            // Rollback the sibling shift so we don't leave a gap.
            self::shiftOrder($menuId, $targetOrder + 1, -1);

            return self::restErrorToWpError($response);
        }

        return self::transformItem(self::restResponseData($response));
    }

    public function executeUpdate(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            return new WP_Error('missing_id', 'id is required.');
        }

        $payload = self::optionalFieldsFromInput($input);

        if (isset($input['title'])) {
            $payload['title'] = $input['title'];
        }
        if (isset($input['status'])) {
            $payload['status'] = $input['status'];
        }

        if (isset($input['linked']) && is_array($input['linked'])) {
            $restFields = self::linkedToRestFields($input['linked']);
            if ($restFields instanceof WP_Error) {
                return $restFields;
            }
            $payload = array_merge($payload, $restFields);
        }

        $response = self::restPost(self::ROUTE.'/'.$id, $payload);
        if (self::isRestError($response)) {
            return self::restErrorToWpError($response);
        }

        return self::transformItem(self::restResponseData($response));
    }

    public function executeDelete(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            return new WP_Error('missing_id', 'id is required.');
        }

        $post = get_post($id);
        if (! $post || $post->post_type !== 'nav_menu_item') {
            return new WP_Error('item_not_found', "No menu item found with ID {$id}.");
        }

        $menuId = self::getMenuIdForItem($id);
        $itemOrder = (int) $post->menu_order;

        $deleted = wp_delete_post($id, true);
        if (! $deleted) {
            return new WP_Error('delete_failed', "Could not delete menu item {$id}.");
        }

        // Close the gap by shifting everything after it up by one.
        if ($menuId > 0) {
            self::shiftOrder($menuId, $itemOrder + 1, -1);
        }

        return ['deleted' => $id];
    }

    public function executeMove(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $id = (int) ($input['id'] ?? 0);
        $position = isset($input['position']) ? (int) $input['position'] : null;

        if ($id <= 0) {
            return new WP_Error('missing_id', 'id is required.');
        }
        if ($position === null || $position < 1) {
            return new WP_Error('invalid_position', 'position must be a positive integer (1-indexed).');
        }

        $post = get_post($id);
        if (! $post || $post->post_type !== 'nav_menu_item') {
            return new WP_Error('item_not_found', "No menu item found with ID {$id}.");
        }

        $currentMenuId = self::getMenuIdForItem($id);
        $currentParentId = (int) get_post_meta($id, '_menu_item_menu_item_parent', true);
        $currentOrder = (int) $post->menu_order;

        $newMenuId = (int) ($input['menu_id'] ?? $currentMenuId);
        $newParentId = array_key_exists('parent_item_id', $input) ? (int) $input['parent_item_id'] : $currentParentId;

        // Step 1: close the gap at the old location (move item out of the way first).
        self::shiftOrder($currentMenuId, $currentOrder + 1, -1);

        // Step 2: if menu changed, move the post to the new menu taxonomy term.
        if ($newMenuId !== $currentMenuId) {
            wp_set_object_terms($id, $newMenuId, 'nav_menu');
        }

        // Step 3: update parent meta.
        if ($newParentId !== $currentParentId) {
            update_post_meta($id, '_menu_item_menu_item_parent', (string) $newParentId);
        }

        // Step 4: compute the target order at destination (excluding self since it's not among siblings yet).
        $targetOrder = self::computeTargetOrder($newMenuId, $newParentId, $position, excludeId: $id);

        // Step 5: open the slot in the destination.
        self::shiftOrder($newMenuId, $targetOrder, +1);

        // Step 6: write the new menu_order on the item itself.
        wp_update_post(['ID' => $id, 'menu_order' => $targetOrder]);

        return self::transformItem(wp_setup_nav_menu_item(clone get_post($id)));
    }

    public function executeReorder(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $menuId = (int) ($input['menu_id'] ?? 0);
        $parentItemId = (int) ($input['parent_item_id'] ?? 0);
        $itemIds = array_map('intval', (array) ($input['item_ids_in_order'] ?? []));

        if ($menuId <= 0) {
            return new WP_Error('missing_menu_id', 'menu_id is required.');
        }
        if (! $itemIds) {
            return new WP_Error('missing_item_ids', 'item_ids_in_order must be a non-empty array.');
        }

        $siblings = self::getSiblings($menuId, $parentItemId);
        $siblingIds = array_map(fn ($s) => (int) $s->ID, $siblings);

        sort($siblingIds);
        $providedSorted = $itemIds;
        sort($providedSorted);
        if ($siblingIds !== $providedSorted) {
            return new WP_Error(
                'incomplete_reorder',
                'item_ids_in_order must exactly match the current children of parent_item_id. '
                .'Expected IDs: '.implode(',', $siblingIds).'. Got: '.implode(',', $providedSorted).'.',
            );
        }

        // Preserve existing menu_order values (they may be sparse); re-assign by provided order.
        $orders = array_map(fn ($s) => (int) $s->menu_order, $siblings);
        sort($orders);

        foreach ($itemIds as $i => $itemId) {
            wp_update_post(['ID' => $itemId, 'menu_order' => $orders[$i]]);
        }

        return ['reordered' => $itemIds];
    }

    // ── Linkage helpers ─────────────────────────────────────────────────────

    /** Translate our `linked` discriminated union into WP REST type/object/object_id/url. */
    private static function linkedToRestFields(array $linked): array|WP_Error
    {
        $kind = $linked['kind'] ?? '';

        return match ($kind) {
            'post' => self::linkedFromPost($linked),
            'taxonomy' => self::linkedFromTaxonomy($linked),
            'archive' => self::linkedFromArchive($linked),
            'url' => self::linkedFromUrl($linked),
            default => new WP_Error(
                'invalid_linked_kind',
                'linked.kind must be one of: "post", "taxonomy", "archive", "url". Got: '.var_export($kind, true),
            ),
        };
    }

    private static function linkedFromPost(array $linked): array|WP_Error
    {
        $postId = (int) ($linked['post_id'] ?? 0);
        if ($postId <= 0) {
            return new WP_Error('missing_post_id', 'linked.kind="post" requires linked.post_id.');
        }
        $post = get_post($postId);
        if (! $post) {
            return new WP_Error('post_not_found', "No post found with ID {$postId}.");
        }
        $postType = $linked['post_type'] ?? $post->post_type;

        return [
            'type' => 'post_type',
            'object' => $postType,
            'object_id' => $postId,
        ];
    }

    private static function linkedFromTaxonomy(array $linked): array|WP_Error
    {
        $taxonomy = $linked['taxonomy'] ?? '';
        $termId = (int) ($linked['term_id'] ?? 0);
        if (! $taxonomy || ! get_taxonomy($taxonomy)) {
            return new WP_Error('invalid_taxonomy', 'linked.kind="taxonomy" requires a valid linked.taxonomy.');
        }
        if ($termId <= 0) {
            return new WP_Error('missing_term_id', 'linked.kind="taxonomy" requires linked.term_id.');
        }
        if (! get_term($termId, $taxonomy)) {
            return new WP_Error('term_not_found', "No {$taxonomy} term found with ID {$termId}.");
        }

        return [
            'type' => 'taxonomy',
            'object' => $taxonomy,
            'object_id' => $termId,
        ];
    }

    private static function linkedFromArchive(array $linked): array|WP_Error
    {
        $postType = $linked['post_type'] ?? '';
        if (! $postType || ! get_post_type_object($postType)) {
            return new WP_Error('invalid_archive_post_type', 'linked.kind="archive" requires a valid linked.post_type.');
        }

        return [
            'type' => 'post_type_archive',
            'object' => $postType,
        ];
    }

    private static function linkedFromUrl(array $linked): array|WP_Error
    {
        $url = $linked['url'] ?? '';
        if (! $url) {
            return new WP_Error('missing_url', 'linked.kind="url" requires linked.url.');
        }

        return [
            'type' => 'custom',
            'url' => $url,
        ];
    }

    // ── Output shaping ──────────────────────────────────────────────────────

    /** Convert REST item / setup'd menu item object to our `linked` + flat response format. */
    private static function transformItem(mixed $item): array
    {
        $item = is_array($item) ? $item : json_decode(json_encode($item), true);

        $id = (int) ($item['ID'] ?? $item['id'] ?? 0);
        $title = $item['title'] ?? '';
        if (is_array($title)) {
            $title = $title['rendered'] ?? $title['raw'] ?? '';
        }

        $type = $item['type'] ?? $item['menu_item_type'] ?? 'custom';
        $object = $item['object'] ?? $item['menu_item_object'] ?? '';
        $objectId = (int) ($item['object_id'] ?? $item['menu_item_object_id'] ?? 0);
        $parentId = (int) ($item['parent'] ?? $item['menu_item_parent'] ?? 0);
        $menus = $item['menus'] ?? null;
        $menuId = is_array($menus) ? (int) ($menus[0] ?? 0) : (int) ($menus ?? 0);
        if ($menuId === 0 && $id > 0) {
            $menuId = self::getMenuIdForItem($id);
        }

        $linked = match ($type) {
            'post_type' => [
                'kind' => 'post',
                'post_id' => $objectId,
                'post_type' => $object,
            ],
            'taxonomy' => [
                'kind' => 'taxonomy',
                'taxonomy' => $object,
                'term_id' => $objectId,
            ],
            'post_type_archive' => [
                'kind' => 'archive',
                'post_type' => $object,
            ],
            default => [
                'kind' => 'url',
                'url' => $item['url'] ?? '',
            ],
        };

        return [
            'id' => $id,
            'title' => $title,
            'menu_id' => $menuId,
            'parent_item_id' => $parentId,
            'menu_order' => (int) ($item['menu_order'] ?? 0),
            'linked' => $linked,
            'url' => $item['url'] ?? '',
            'description' => $item['description'] ?? '',
            'target' => $item['target'] ?? '',
            'css_classes' => $item['classes'] ?? [],
        ];
    }

    /** Build a nested tree from a flat list, keyed by parent_item_id. */
    private static function buildTree(array $items): array
    {
        $byParent = [];
        foreach ($items as $item) {
            $byParent[$item['parent_item_id']][] = $item;
        }

        $attachChildren = function (array $item) use (&$attachChildren, $byParent) {
            $children = $byParent[$item['id']] ?? [];
            $item['children'] = array_map($attachChildren, $children);

            return $item;
        };

        $roots = $byParent[0] ?? [];

        return array_map($attachChildren, $roots);
    }

    // ── Order-math helpers ──────────────────────────────────────────────────

    /**
     * Compute the global menu_order to assign to a new/moved item so it lands
     * at `$position` (1-indexed) among its siblings.
     */
    private static function computeTargetOrder(int $menuId, int $parentItemId, ?int $position, int $excludeId = 0): int
    {
        $siblings = self::getSiblings($menuId, $parentItemId, $excludeId);

        if (! $siblings) {
            // First child of this parent — use max order in menu + 1 so it sorts after siblings of the parent.
            return self::maxMenuOrder($menuId) + 1;
        }

        if ($position === null || $position > count($siblings)) {
            // Append: just after the last existing sibling.
            return ((int) end($siblings)->menu_order) + 1;
        }

        $targetIndex = max(0, $position - 1);

        return (int) $siblings[$targetIndex]->menu_order;
    }

    /**
     * Get direct children of a parent menu item within a menu, sorted by menu_order.
     * Excludes $excludeId (used when moving an item to its own new location).
     *
     * @return \WP_Post[]
     */
    private static function getSiblings(int $menuId, int $parentItemId, int $excludeId = 0): array
    {
        $items = wp_get_nav_menu_items($menuId);
        if (! $items) {
            return [];
        }

        $siblings = array_values(array_filter($items, fn ($i) => (int) $i->menu_item_parent === $parentItemId && (int) $i->ID !== $excludeId));
        usort($siblings, fn ($a, $b) => $a->menu_order <=> $b->menu_order);

        return $siblings;
    }

    /**
     * Shift menu_order of all items in $menuId at or after $fromOrder by $delta.
     * Used to open a slot (+1) or close a gap (-1).
     *
     * Uses wp_update_post per item rather than a raw bulk UPDATE so that all
     * the usual WP cache/hook machinery runs and subsequent calls to
     * wp_get_nav_menu_items() see the current state.
     */
    private static function shiftOrder(int $menuId, int $fromOrder, int $delta): void
    {
        if ($delta === 0 || $menuId <= 0) {
            return;
        }

        $items = wp_get_nav_menu_items($menuId, ['update_post_term_cache' => false]);
        if (! $items) {
            return;
        }

        foreach ($items as $item) {
            if ((int) $item->menu_order >= $fromOrder) {
                wp_update_post([
                    'ID' => $item->ID,
                    'menu_order' => (int) $item->menu_order + $delta,
                ]);
            }
        }
    }

    private static function maxMenuOrder(int $menuId): int
    {
        $items = wp_get_nav_menu_items($menuId);
        if (! $items) {
            return 0;
        }

        return (int) max(array_map(fn ($i) => (int) $i->menu_order, $items));
    }

    private static function getMenuIdForItem(int $itemId): int
    {
        $terms = wp_get_object_terms($itemId, 'nav_menu', ['fields' => 'ids']);
        if (is_wp_error($terms) || ! $terms) {
            return 0;
        }

        return (int) $terms[0];
    }

    /** Extract optional passthrough fields from input. */
    private static function optionalFieldsFromInput(array $input): array
    {
        $out = [];
        if (isset($input['description'])) {
            $out['description'] = $input['description'];
        }
        if (isset($input['target'])) {
            $out['target'] = $input['target'];
        }
        if (isset($input['css_classes']) && is_array($input['css_classes'])) {
            $out['classes'] = $input['css_classes'];
        }

        return $out;
    }
}
