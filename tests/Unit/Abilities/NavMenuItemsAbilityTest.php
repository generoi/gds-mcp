<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\GenericPostTypeAbility;
use GeneroWP\MCP\Abilities\NavMenuItemsAbility;
use GeneroWP\MCP\Tests\TestCase;

class NavMenuItemsAbilityTest extends TestCase
{
    private NavMenuItemsAbility $ability;

    private int $menuId;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->ability = new NavMenuItemsAbility;
        $this->menuId = wp_create_nav_menu('Test Nav Menu');
    }

    // ── Create ──────────────────────────────────────────────────────────────

    public function test_create_item_linked_to_post_stores_post_type(): void
    {
        $pageId = $this->createPost(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Target']);

        $result = $this->ability->executeCreate([
            'menu_id' => $this->menuId,
            'title' => 'Link To Page',
            'linked' => ['kind' => 'post', 'post_id' => $pageId, 'post_type' => 'page'],
        ]);

        $this->assertIsArray($result);
        $this->assertSame('post', $result['linked']['kind']);
        $this->assertSame($pageId, $result['linked']['post_id']);
        $this->assertSame('page', $result['linked']['post_type']);

        // Verify the underlying meta is correct (this is what the original bug got wrong)
        $this->assertSame('post_type', get_post_meta($result['id'], '_menu_item_type', true));
        $this->assertSame('page', get_post_meta($result['id'], '_menu_item_object', true));
        $this->assertSame((string) $pageId, get_post_meta($result['id'], '_menu_item_object_id', true));
    }

    public function test_create_item_linked_to_taxonomy_term(): void
    {
        $term = wp_insert_term('Menu Term', 'category');

        $result = $this->ability->executeCreate([
            'menu_id' => $this->menuId,
            'title' => 'Link To Term',
            'linked' => ['kind' => 'taxonomy', 'taxonomy' => 'category', 'term_id' => $term['term_id']],
        ]);

        $this->assertIsArray($result);
        $this->assertSame('taxonomy', $result['linked']['kind']);
        $this->assertSame($term['term_id'], $result['linked']['term_id']);

        $this->assertSame('taxonomy', get_post_meta($result['id'], '_menu_item_type', true));
        $this->assertSame('category', get_post_meta($result['id'], '_menu_item_object', true));
    }

    public function test_create_item_linked_to_archive(): void
    {
        $result = $this->ability->executeCreate([
            'menu_id' => $this->menuId,
            'title' => 'Blog Archive',
            'linked' => ['kind' => 'archive', 'post_type' => 'post'],
        ]);

        $this->assertIsArray($result);
        $this->assertSame('archive', $result['linked']['kind']);
        $this->assertSame('post_type_archive', get_post_meta($result['id'], '_menu_item_type', true));
    }

    public function test_create_item_linked_to_url(): void
    {
        $result = $this->ability->executeCreate([
            'menu_id' => $this->menuId,
            'title' => 'External',
            'linked' => ['kind' => 'url', 'url' => 'https://example.com/external'],
        ]);

        $this->assertIsArray($result);
        $this->assertSame('url', $result['linked']['kind']);
        $this->assertSame('custom', get_post_meta($result['id'], '_menu_item_type', true));
        $this->assertSame('https://example.com/external', get_post_meta($result['id'], '_menu_item_url', true));
    }

    public function test_create_rejects_invalid_linked_kind(): void
    {
        $result = $this->ability->executeCreate([
            'menu_id' => $this->menuId,
            'title' => 'Bad',
            'linked' => ['kind' => 'whatever'],
        ]);

        $this->assertWPError($result);
        $this->assertSame('invalid_linked_kind', $result->get_error_code());
    }

    public function test_create_rejects_post_without_post_id(): void
    {
        $result = $this->ability->executeCreate([
            'menu_id' => $this->menuId,
            'title' => 'No post_id',
            'linked' => ['kind' => 'post'],
        ]);

        $this->assertWPError($result);
        $this->assertSame('missing_post_id', $result->get_error_code());
    }

    public function test_create_at_position_shifts_siblings(): void
    {
        // Build an existing menu with 3 items at positions 1, 2, 3
        $a = $this->insertItem('A');
        $b = $this->insertItem('B');
        $c = $this->insertItem('C');

        $pageId = $this->createPost(['post_type' => 'page', 'post_status' => 'publish']);

        $result = $this->ability->executeCreate([
            'menu_id' => $this->menuId,
            'title' => 'Inserted',
            'linked' => ['kind' => 'post', 'post_id' => $pageId, 'post_type' => 'page'],
            'position' => 2,
        ]);

        $this->assertIsArray($result);

        $orderedTitles = $this->orderedTitles();
        $this->assertSame(['A', 'Inserted', 'B', 'C'], $orderedTitles);
    }

    // ── Update ──────────────────────────────────────────────────────────────

    public function test_update_changes_linkage_from_url_to_post(): void
    {
        $itemId = $this->insertItem('Originally URL', ['menu-item-url' => 'https://old.example']);
        $pageId = $this->createPost(['post_type' => 'page', 'post_status' => 'publish']);

        $result = $this->ability->executeUpdate([
            'id' => $itemId,
            'linked' => ['kind' => 'post', 'post_id' => $pageId, 'post_type' => 'page'],
        ]);

        $this->assertIsArray($result);
        $this->assertSame('post_type', get_post_meta($itemId, '_menu_item_type', true));
        $this->assertSame((string) $pageId, get_post_meta($itemId, '_menu_item_object_id', true));
    }

    public function test_update_invalid_id_returns_error(): void
    {
        $result = $this->ability->executeUpdate(['id' => 99999999]);

        $this->assertWPError($result);
    }

    // ── Delete ──────────────────────────────────────────────────────────────

    public function test_delete_closes_gap(): void
    {
        $a = $this->insertItem('A');
        $b = $this->insertItem('B');
        $c = $this->insertItem('C');

        $result = $this->ability->executeDelete(['id' => $b]);

        $this->assertIsArray($result);
        $this->assertSame($b, $result['deleted']);
        $this->assertSame(['A', 'C'], $this->orderedTitles());

        // Verify orders are contiguous (no gap)
        $items = wp_get_nav_menu_items($this->menuId);
        usort($items, fn ($x, $y) => $x->menu_order <=> $y->menu_order);
        $this->assertSame(1, (int) $items[0]->menu_order);
        $this->assertSame(2, (int) $items[1]->menu_order);
    }

    // ── Move ────────────────────────────────────────────────────────────────

    public function test_move_within_same_parent_shifts_siblings(): void
    {
        $a = $this->insertItem('A');
        $b = $this->insertItem('B');
        $c = $this->insertItem('C');
        $d = $this->insertItem('D');

        // Move D to position 1
        $result = $this->ability->executeMove(['id' => $d, 'position' => 1]);

        $this->assertIsArray($result);
        $this->assertSame(['D', 'A', 'B', 'C'], $this->orderedTitles());
    }

    public function test_move_across_parents(): void
    {
        $parentA = $this->insertItem('Parent A');
        $parentB = $this->insertItem('Parent B');

        $child1 = $this->insertItem('Child of A', ['menu-item-parent-id' => $parentA]);
        $child2 = $this->insertItem('Another Child of A', ['menu-item-parent-id' => $parentA]);

        $result = $this->ability->executeMove([
            'id' => $child1,
            'parent_item_id' => $parentB,
            'position' => 1,
        ]);

        $this->assertIsArray($result);
        $this->assertSame($parentB, $result['parent_item_id']);

        // Verify parent meta was updated
        $this->assertSame((string) $parentB, get_post_meta($child1, '_menu_item_menu_item_parent', true));
    }

    // ── Reorder ─────────────────────────────────────────────────────────────

    public function test_reorder_applies_exact_order(): void
    {
        $a = $this->insertItem('A');
        $b = $this->insertItem('B');
        $c = $this->insertItem('C');

        $result = $this->ability->executeReorder([
            'menu_id' => $this->menuId,
            'item_ids_in_order' => [$c, $a, $b],
        ]);

        $this->assertIsArray($result);
        $this->assertSame(['C', 'A', 'B'], $this->orderedTitles());
    }

    public function test_reorder_rejects_incomplete_list(): void
    {
        $a = $this->insertItem('A');
        $b = $this->insertItem('B');
        $c = $this->insertItem('C');

        $result = $this->ability->executeReorder([
            'menu_id' => $this->menuId,
            'item_ids_in_order' => [$a, $b], // Missing C
        ]);

        $this->assertWPError($result);
        $this->assertSame('incomplete_reorder', $result->get_error_code());
    }

    // ── List + Read ─────────────────────────────────────────────────────────

    public function test_list_returns_tree(): void
    {
        $parent = $this->insertItem('Parent');
        $this->insertItem('Child', ['menu-item-parent-id' => $parent]);

        $result = $this->ability->executeList(['menu_id' => $this->menuId, 'tree' => true]);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result['items']);
        $root = $result['items'][0];
        $this->assertSame('Parent', $root['title']);
        $this->assertCount(1, $root['children']);
        $this->assertSame('Child', $root['children'][0]['title']);
    }

    public function test_read_returns_full_shape(): void
    {
        $pageId = $this->createPost(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Target']);
        $itemId = $this->insertItem('Menu Title', [
            'menu-item-object-id' => $pageId,
            'menu-item-object' => 'page',
            'menu-item-type' => 'post_type',
        ]);

        $result = $this->ability->executeRead(['id' => $itemId]);

        $this->assertIsArray($result);
        $this->assertSame('Menu Title', $result['title']);
        $this->assertSame('post', $result['linked']['kind']);
        $this->assertSame($pageId, $result['linked']['post_id']);
    }

    // ── Integration: generic tools refuse menu-items ────────────────────────

    public function test_generic_content_create_refuses_menu_items(): void
    {
        $generic = new GenericPostTypeAbility;
        $result = $generic->executeCreate(['type' => 'menu-items', 'title' => 'Should Fail']);

        $this->assertWPError($result);
        $this->assertSame('use_nav_menu_items_tools', $result->get_error_code());
    }

    public function test_generic_content_update_refuses_menu_items(): void
    {
        $generic = new GenericPostTypeAbility;
        $result = $generic->executeUpdate(['type' => 'menu-items', 'id' => 1]);

        $this->assertWPError($result);
        $this->assertSame('use_nav_menu_items_tools', $result->get_error_code());
    }

    // ── Gap-filling: create edge cases ──────────────────────────────────────

    public function test_create_without_position_appends(): void
    {
        $this->insertItem('A');
        $this->insertItem('B');
        $this->insertItem('C');

        $pageId = $this->createPost(['post_type' => 'page', 'post_status' => 'publish']);
        $result = $this->ability->executeCreate([
            'menu_id' => $this->menuId,
            'title' => 'Appended',
            'linked' => ['kind' => 'post', 'post_id' => $pageId, 'post_type' => 'page'],
            // no `position` → should append
        ]);

        $this->assertIsArray($result);
        $this->assertSame(['A', 'B', 'C', 'Appended'], $this->orderedTitles());
    }

    public function test_create_in_empty_menu_works(): void
    {
        $pageId = $this->createPost(['post_type' => 'page', 'post_status' => 'publish']);

        $result = $this->ability->executeCreate([
            'menu_id' => $this->menuId,
            'title' => 'First Item',
            'linked' => ['kind' => 'post', 'post_id' => $pageId, 'post_type' => 'page'],
        ]);

        $this->assertIsArray($result);
        $this->assertSame('First Item', $result['title']);
        $this->assertSame(['First Item'], $this->orderedTitles());
        // And the REST-layer type must be correct — regression guard against
        // the original gds/content-create bug.
        $this->assertSame('post_type', get_post_meta($result['id'], '_menu_item_type', true));
    }

    public function test_create_with_position_beyond_end_appends(): void
    {
        $this->insertItem('A');
        $this->insertItem('B');

        $pageId = $this->createPost(['post_type' => 'page', 'post_status' => 'publish']);
        $result = $this->ability->executeCreate([
            'menu_id' => $this->menuId,
            'title' => 'WayOut',
            'linked' => ['kind' => 'post', 'post_id' => $pageId, 'post_type' => 'page'],
            'position' => 999,
        ]);

        $this->assertIsArray($result);
        $this->assertSame(['A', 'B', 'WayOut'], $this->orderedTitles());
    }

    // ── Gap-filling: move edge cases ────────────────────────────────────────

    public function test_move_across_menus(): void
    {
        $otherMenuId = wp_create_nav_menu('Other Menu');

        $this->insertItem('A');
        $itemId = $this->insertItem('Travelling');
        $this->insertItem('C');

        $result = $this->ability->executeMove([
            'id' => $itemId,
            'menu_id' => $otherMenuId,
            'position' => 1,
        ]);

        $this->assertIsArray($result);
        $this->assertSame($otherMenuId, $result['menu_id']);

        // No longer in source menu.
        $this->assertSame(['A', 'C'], $this->orderedTitles());

        // Now in destination menu.
        $destItems = array_map(fn ($i) => $i->title, wp_get_nav_menu_items($otherMenuId) ?: []);
        $this->assertSame(['Travelling'], $destItems);

        // Nav-menu taxonomy term updated.
        $terms = wp_get_object_terms($itemId, 'nav_menu', ['fields' => 'ids']);
        $this->assertSame([$otherMenuId], array_map('intval', $terms));
    }

    public function test_move_child_to_top_level(): void
    {
        $parent = $this->insertItem('Parent');
        $childA = $this->insertItem('ChildA', ['menu-item-parent-id' => $parent]);
        $childB = $this->insertItem('ChildB', ['menu-item-parent-id' => $parent]);

        $result = $this->ability->executeMove([
            'id' => $childA,
            'parent_item_id' => 0,
            'position' => 1,
        ]);

        $this->assertIsArray($result);
        $this->assertSame(0, $result['parent_item_id']);
        $this->assertSame((string) 0, get_post_meta($childA, '_menu_item_menu_item_parent', true));

        // ChildA now top-level at position 1; Parent still top-level; ChildB still under Parent.
        $topLevel = $this->orderedTitles(0);
        $this->assertSame(['ChildA', 'Parent'], $topLevel);

        $childrenOfParent = $this->orderedTitles($parent);
        $this->assertSame(['ChildB'], $childrenOfParent);
    }

    // ── Gap-filling: update & delete edges ──────────────────────────────────

    public function test_update_with_invalid_linked_kind_returns_error(): void
    {
        $itemId = $this->insertItem('Original');

        $result = $this->ability->executeUpdate([
            'id' => $itemId,
            'linked' => ['kind' => 'nonsense'],
        ]);

        $this->assertWPError($result);
        $this->assertSame('invalid_linked_kind', $result->get_error_code());
    }

    public function test_delete_parent_leaves_children_orphaned(): void
    {
        // Document the current WP behaviour: deleting a parent does NOT cascade
        // to children. The child's _menu_item_menu_item_parent stays pointing
        // at the now-nonexistent post ID. Consumers should re-parent or delete
        // children explicitly first if that's undesirable.
        $parent = $this->insertItem('Parent');
        $child = $this->insertItem('Child', ['menu-item-parent-id' => $parent]);

        $this->ability->executeDelete(['id' => $parent]);

        $this->assertNull(get_post($parent));
        $childPost = get_post($child);
        $this->assertNotNull($childPost);
        $this->assertSame((string) $parent, get_post_meta($child, '_menu_item_menu_item_parent', true));
    }

    // ── Gap-filling: list & read edges ──────────────────────────────────────

    public function test_list_returns_flat_when_tree_false(): void
    {
        $parent = $this->insertItem('Parent');
        $this->insertItem('Child', ['menu-item-parent-id' => $parent]);
        $this->insertItem('Sibling');

        $result = $this->ability->executeList([
            'menu_id' => $this->menuId,
            'tree' => false,
        ]);

        $this->assertIsArray($result);
        $this->assertCount(3, $result['items']);
        // Flat items have no `children` key.
        foreach ($result['items'] as $item) {
            $this->assertArrayNotHasKey('children', $item);
        }
    }

    public function test_list_filters_by_parent_item_id(): void
    {
        $parent = $this->insertItem('Parent');
        $this->insertItem('Child', ['menu-item-parent-id' => $parent]);
        $this->insertItem('Sibling');

        $result = $this->ability->executeList([
            'menu_id' => $this->menuId,
            'parent_item_id' => $parent,
            'tree' => false,
        ]);

        $this->assertCount(1, $result['items']);
        $this->assertSame('Child', $result['items'][0]['title']);
    }

    public function test_read_non_menu_item_returns_error(): void
    {
        // Passing a regular post ID to `read` should fail cleanly.
        $pageId = $this->createPost(['post_type' => 'page', 'post_status' => 'publish']);

        $result = $this->ability->executeRead(['id' => $pageId]);

        $this->assertWPError($result);
        $this->assertSame('item_not_found', $result->get_error_code());
    }

    // ── Polylang: `lang` param on content-create/update ─────────────────────

    public function test_content_create_lang_param_assigns_polylang_language(): void
    {
        $this->skipIfPolylangNotReady();

        $generic = new GenericPostTypeAbility;
        $result = $generic->executeCreate([
            'type' => 'pages',
            'title' => 'Suomenkielinen sivu',
            'content' => 'sisältö',
            'status' => 'publish',
            'lang' => 'fi',
        ]);

        $this->assertIsArray($result, 'content-create should succeed: '.(is_wp_error($result) ? $result->get_error_message() : ''));
        $this->assertGreaterThan(0, $result['id'] ?? 0);
        $this->assertSame('fi', pll_get_post_language($result['id']));
    }

    public function test_content_create_with_invalid_lang_returns_error(): void
    {
        $this->skipIfPolylangNotReady();

        $generic = new GenericPostTypeAbility;
        $result = $generic->executeCreate([
            'type' => 'pages',
            'title' => 'Bad lang',
            'content' => 'x',
            'status' => 'publish',
            'lang' => 'xx',
        ]);

        $this->assertWPError($result);
        $this->assertSame('invalid_lang', $result->get_error_code());
    }

    public function test_content_update_lang_param_reassigns_language(): void
    {
        $this->skipIfPolylangNotReady();

        $generic = new GenericPostTypeAbility;
        $created = $generic->executeCreate([
            'type' => 'pages',
            'title' => 'Starts in EN',
            'content' => 'x',
            'status' => 'publish',
            'lang' => 'en',
        ]);
        $this->assertIsArray($created);
        $this->assertSame('en', pll_get_post_language($created['id']));

        $updated = $generic->executeUpdate([
            'type' => 'pages',
            'id' => $created['id'],
            'lang' => 'fi',
        ]);
        $this->assertIsArray($updated);
        $this->assertSame('fi', pll_get_post_language($created['id']));
    }

    private function skipIfPolylangNotReady(): void
    {
        if (! function_exists('pll_set_post_language') || ! function_exists('pll_languages_list')) {
            $this->markTestSkipped('Polylang not active in this environment.');
        }

        // WP_UnitTestCase rollback removes language terms; re-add them each test.
        $model = PLL()->model;
        $model->clean_languages_cache();
        foreach ([
            ['name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'term_group' => 0],
            ['name' => 'Finnish', 'slug' => 'fi', 'locale' => 'fi', 'term_group' => 1],
        ] as $lang) {
            if (! $model->get_language($lang['slug'])) {
                $model->add_language($lang);
            }
        }
        $model->update_default_lang('en');
        $model->clean_languages_cache();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function insertItem(string $title, array $overrides = []): int
    {
        return wp_update_nav_menu_item($this->menuId, 0, array_merge([
            'menu-item-title' => $title,
            'menu-item-type' => 'custom',
            'menu-item-url' => 'https://example.com/'.sanitize_title($title),
            'menu-item-status' => 'publish',
        ], $overrides));
    }

    /** @return string[] */
    private function orderedTitles(int $parentItemId = 0): array
    {
        $items = wp_get_nav_menu_items($this->menuId);
        $filtered = array_filter($items, fn ($i) => (int) $i->menu_item_parent === $parentItemId);
        usort($filtered, fn ($a, $b) => $a->menu_order <=> $b->menu_order);

        return array_map(fn ($i) => $i->title, array_values($filtered));
    }
}
