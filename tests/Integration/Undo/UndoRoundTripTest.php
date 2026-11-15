<?php

namespace GeneroWP\MCP\Tests\Integration\Undo;

use GeneroWP\MCP\Tests\AbilityTestCase;

/**
 * End-to-end undo: drive the REAL abilities (through their REST-base inputs and
 * output validation), then replay the captured `_undo` through the
 * gds-mcp/restore_snapshot filter exactly as gds-assistant would, after the
 * JSON round trip of its audit log.
 *
 * The round trips compare the whole {@see WorldState} before the write and
 * after the undo: an undo that leaves anything behind fails, whatever it
 * reports. Where an exact undo is impossible the ability must not offer one,
 * or the undo must refuse.
 *
 * This is also the layer that catches bugs the RestoreSnapshotTest can't: that
 * one feeds restore handlers the real taxonomy slug directly, whereas abilities
 * receive a REST base (e.g. "categories", not "category").
 */
class UndoRoundTripTest extends AbilityTestCase
{
    /**
     * Writes that offer no undo, and why. Every other write ability must
     * have a round trip below.
     */
    private const NO_UNDO = [
        'gds/mail-send' => 'A sent email cannot be unsent.',
        'gds/cache-clear' => 'Purging a cache loses nothing; it refills.',
    ];

    /** Round trips that need a commercial plugin this suite may not have. */
    private const NOT_RUNNABLE_HERE = [
        'gds/translations-machine' => 'Needs Polylang Pro and a machine translation service.',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        register_nav_menus(['primary' => 'Primary', 'footer' => 'Footer']);
    }

    private function restore(array $result): mixed
    {
        $undo = $result['_undo'] ?? null;
        $this->assertIsArray($undo, 'Ability result must carry an _undo envelope.');
        $this->assertNotEmpty($undo['kind'], 'The _undo envelope must have a kind.');

        // gds-assistant stores the envelope as JSON on the audit log row.
        $undo = json_decode((string) wp_json_encode($undo), true);

        return apply_filters('gds-mcp/restore_snapshot', null, $undo);
    }

    /**
     * Run an ability, undo it, and assert the world is as it was.
     *
     * @param  callable(array<string, mixed>): list<int>|null  $created  The posts the undo moves to the trash, left out of the comparison.
     * @return array<string, mixed> The ability's result.
     */
    private function assertRoundTrip(string $ability, array $input, ?callable $created = null): array
    {
        $before = WorldState::capture();
        $result = $this->assertAbilitySuccess($ability, $input);
        $this->assertNotSame([], WorldState::diff($before, WorldState::capture()), "{$ability} changed nothing, so the round trip proves nothing.");

        $restored = $this->restore($result);
        $this->assertNotWPError($restored, "Undoing {$ability} failed: ".($restored instanceof \WP_Error ? $restored->get_error_message() : ''));
        $this->assertEmpty($restored['partial'] ?? null, "Undoing {$ability} was only partial.");
        $this->assertEmpty($restored['caveats'] ?? null, "Undoing {$ability} came with caveats: ".implode(' ', (array) ($restored['caveats'] ?? [])));

        $ignored = $created ? $created($result) : [];
        foreach ($ignored as $id) {
            $this->assertContains(get_post_status($id), ['trash', false], "Undoing {$ability} left post {$id} outside the trash.");
        }
        $this->assertSame(
            [],
            WorldState::diff(WorldState::without($before, $ignored), WorldState::without(WorldState::capture(), $ignored)),
            "Undoing {$ability} did not put everything back.",
        );

        return $result;
    }

    private function assertUndoRefused(array $result, string $message = ''): \WP_Error
    {
        $before = WorldState::capture();
        $restored = $this->restore($result);
        $this->assertWPError($restored, $message ?: 'The undo should have been refused.');
        $this->assertSame([], WorldState::diff($before, WorldState::capture()), 'A refused undo must change nothing.');

        return $restored;
    }

    /**
     * A small site: a category tree, a front page and posts page, a sticky
     * post, posts with meta, and a menu in a theme location linking pages,
     * categories and a URL, with a sub-item.
     *
     * @return array<string, int>
     */
    private function site(): array
    {
        $ids = [];
        $ids['parent'] = self::factory()->category->create(['name' => 'Parent']);
        $ids['child'] = self::factory()->category->create(['name' => 'Child', 'parent' => $ids['parent']]);
        $ids['grandchild'] = self::factory()->category->create(['name' => 'Grandchild', 'parent' => $ids['child']]);
        $ids['other'] = self::factory()->category->create(['name' => 'Other']);
        $ids['tag'] = self::factory()->tag->create(['name' => 'Tagged']);
        add_term_meta($ids['child'], 'colour', 'blue');

        $ids['front'] = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Home', 'post_status' => 'publish']);
        $ids['blog'] = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'Blog', 'post_status' => 'publish']);
        $ids['about'] = self::factory()->post->create(['post_type' => 'page', 'post_title' => 'About', 'post_status' => 'publish', 'post_parent' => $ids['front']]);
        update_option('show_on_front', 'page');
        update_option('page_on_front', $ids['front']);
        update_option('page_for_posts', $ids['blog']);

        $ids['post'] = self::factory()->post->create([
            'post_title' => 'Original',
            'post_content' => "<!-- wp:paragraph -->\n<p>Hello world</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Heading</h2>\n<!-- /wp:heading -->",
            'post_excerpt' => 'Excerpt',
            'post_status' => 'publish',
        ]);
        wp_set_object_terms($ids['post'], [$ids['child'], $ids['other']], 'category');
        wp_set_object_terms($ids['post'], [$ids['tag']], 'post_tag');
        add_post_meta($ids['post'], 'subtitle', 'Sub');
        add_post_meta($ids['post'], 'list', ['a' => 1]);

        // Its only category is the child: deleting that gives it the default.
        $ids['only_child'] = self::factory()->post->create(['post_title' => 'Only child', 'post_status' => 'publish']);
        wp_set_object_terms($ids['only_child'], [$ids['child']], 'category');

        $ids['sticky'] = self::factory()->post->create(['post_title' => 'Sticky', 'post_status' => 'publish']);
        stick_post($ids['sticky']);

        $ids['menu'] = wp_create_nav_menu('Main '.uniqid());
        $ids['footer_menu'] = wp_create_nav_menu('Footer '.uniqid());
        $ids['item_home'] = $this->menuItem($ids['menu'], ['menu-item-object-id' => $ids['front'], 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-position' => 1]);
        $ids['item_child'] = $this->menuItem($ids['menu'], ['menu-item-object-id' => $ids['child'], 'menu-item-object' => 'category', 'menu-item-type' => 'taxonomy', 'menu-item-position' => 2]);
        $ids['item_sub'] = $this->menuItem($ids['menu'], ['menu-item-object-id' => $ids['about'], 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-parent-id' => $ids['item_child'], 'menu-item-position' => 3]);
        $ids['item_url'] = $this->menuItem($ids['menu'], ['menu-item-title' => 'Docs', 'menu-item-url' => 'https://example.com/docs', 'menu-item-type' => 'custom', 'menu-item-position' => 4]);
        $ids['item_footer'] = $this->menuItem($ids['footer_menu'], ['menu-item-title' => 'Legal', 'menu-item-url' => 'https://example.com/legal', 'menu-item-type' => 'custom', 'menu-item-position' => 1]);
        set_theme_mod('nav_menu_locations', ['primary' => $ids['menu'], 'footer' => $ids['footer_menu']]);

        return $ids;
    }

    private function menuItem(int $menuId, array $args): int
    {
        $id = wp_update_nav_menu_item($menuId, 0, $args + ['menu-item-status' => 'publish']);
        $this->assertIsInt($id);

        return $id;
    }

    // ── Content ─────────────────────────────────────────────────────

    public function test_content_create_round_trip(): void
    {
        $site = $this->site();

        $this->assertRoundTrip('gds/content-create', [
            'type' => 'posts',
            'title' => 'New post',
            'content' => '<p>Body</p>',
            'status' => 'publish',
            'categories' => [$site['child']],
            'tags' => [$site['tag']],
            'sticky' => true,
        ], fn ($result) => [(int) $result['id']]);
    }

    public function test_content_create_undo_is_refused_once_the_page_is_the_homepage(): void
    {
        $result = $this->assertAbilitySuccess('gds/content-create', ['type' => 'pages', 'title' => 'New home', 'status' => 'publish']);
        update_option('show_on_front', 'page');
        update_option('page_on_front', (int) $result['id']);

        $this->assertUndoRefused($result);
        $this->assertSame('publish', get_post_status((int) $result['id']));
    }

    public function test_content_update_round_trip(): void
    {
        $site = $this->site();

        $this->assertRoundTrip('gds/content-update', [
            'type' => 'posts',
            'id' => $site['post'],
            'title' => 'Edited',
            'content' => '<p>Replaced</p>',
            'excerpt' => 'New excerpt',
            'slug' => 'edited-slug',
            'status' => 'draft',
            'sticky' => true,
            'categories' => [$site['parent']],
            'tags' => [],
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ]);
    }

    public function test_content_update_of_a_page_round_trip(): void
    {
        $site = $this->site();

        $this->assertRoundTrip('gds/content-update', [
            'type' => 'pages',
            'id' => $site['about'],
            'title' => 'About us',
            'parent' => 0,
            'menu_order' => 5,
            'template' => '',
        ]);
    }

    public function test_content_update_undo_is_refused_when_the_post_changed_since(): void
    {
        $site = $this->site();
        $result = $this->assertAbilitySuccess('gds/content-update', ['type' => 'posts', 'id' => $site['post'], 'title' => 'Edited']);

        wp_update_post(['ID' => $site['post'], 'post_content' => 'Someone else wrote this']);

        $error = $this->assertUndoRefused($result);
        $this->assertStringContainsString('changed', $error->get_error_message());
        $this->assertSame('Someone else wrote this', get_post($site['post'])->post_content);
    }

    public function test_content_update_undo_after_undoing_a_later_edit(): void
    {
        $site = $this->site();
        $before = WorldState::capture();
        $first = $this->assertAbilitySuccess('gds/content-update', ['type' => 'posts', 'id' => $site['post'], 'title' => 'First']);
        $second = $this->assertAbilitySuccess('gds/content-update', ['type' => 'posts', 'id' => $site['post'], 'title' => 'Second']);

        // Undone newest first, as the assistant lists them.
        $this->assertNotWPError($this->restore($second));
        $this->assertNotWPError($this->restore($first));
        $this->assertSame([], WorldState::diff($before, WorldState::capture()));
    }

    public function test_content_update_undo_refuses_a_slug_taken_since(): void
    {
        $site = $this->site();
        $result = $this->assertAbilitySuccess('gds/content-update', ['type' => 'posts', 'id' => $site['post'], 'slug' => 'renamed']);
        self::factory()->post->create(['post_name' => 'original', 'post_status' => 'publish']);

        $error = $this->assertUndoRefused($result);
        $this->assertStringContainsString('original', $error->get_error_message());
    }

    public function test_content_update_lang_round_trip(): void
    {
        $this->requirePolylang();
        $site = $this->site();
        pll_set_post_language($site['post'], 'en');

        $this->assertRoundTrip('gds/content-update', ['type' => 'posts', 'id' => $site['post'], 'lang' => 'fi']);
    }

    public function test_content_delete_round_trip_for_a_sticky_post(): void
    {
        $site = $this->site();

        $this->assertRoundTrip('gds/content-delete', ['type' => 'posts', 'id' => $site['sticky']]);
    }

    public function test_content_delete_round_trip_for_the_front_page(): void
    {
        $site = $this->site();

        $this->assertRoundTrip('gds/content-delete', ['type' => 'pages', 'id' => $site['front']]);
    }

    public function test_content_delete_round_trip_for_the_posts_page(): void
    {
        $site = $this->site();

        $this->assertRoundTrip('gds/content-delete', ['type' => 'pages', 'id' => $site['blog']]);
    }

    public function test_content_delete_undo_is_refused_when_the_front_page_changed_since(): void
    {
        $site = $this->site();
        $result = $this->assertAbilitySuccess('gds/content-delete', ['type' => 'pages', 'id' => $site['front']]);
        update_option('show_on_front', 'page');
        update_option('page_on_front', $site['about']);

        $error = $this->assertUndoRefused($result);
        $this->assertStringContainsString('homepage', $error->get_error_message());
        $this->assertSame('trash', get_post_status($site['front']));
    }

    public function test_content_delete_never_deletes_a_font_family_permanently(): void
    {
        $id = self::factory()->post->create(['post_type' => 'wp_font_family', 'post_title' => 'Inter', 'post_name' => 'inter', 'post_status' => 'publish', 'post_content' => wp_json_encode(['name' => 'Inter', 'slug' => 'inter', 'fontFamily' => 'Inter'])]);

        $this->assertAbilityError('gds/content-delete', ['type' => 'font-families', 'id' => $id], 'trash_not_supported');
        $this->assertNotNull(get_post($id), 'The font family is still there.');
    }

    public function test_posts_bulk_update_round_trip(): void
    {
        $site = $this->site();
        $draft = self::factory()->post->create(['post_status' => 'draft', 'post_title' => 'Draft one']);
        update_post_meta($site['post'], 'flag', 'old');

        $this->assertRoundTrip('gds/posts-bulk-update', [
            'post_ids' => [$site['post'], $draft],
            'set_status' => 'publish',
            'set_meta' => ['flag' => 'new', 'added' => 'x'],
            'delete_meta' => ['subtitle'],
        ]);
    }

    public function test_posts_bulk_update_trash_round_trip(): void
    {
        $site = $this->site();

        $this->assertRoundTrip('gds/posts-bulk-update', [
            'post_ids' => [$site['front'], $site['blog'], $site['sticky']],
            'set_status' => 'trash',
        ]);
    }

    public function test_posts_bulk_update_undo_is_refused_when_one_post_changed_since(): void
    {
        $site = $this->site();
        $result = $this->assertAbilitySuccess('gds/posts-bulk-update', [
            'post_ids' => [$site['post'], $site['sticky']],
            'set_meta' => ['flag' => 'new'],
        ]);
        update_post_meta($site['sticky'], 'flag', 'edited by hand');

        $error = $this->assertUndoRefused($result);
        $this->assertStringContainsString((string) $site['sticky'], $error->get_error_message());
    }

    public function test_posts_duplicate_round_trip(): void
    {
        $site = $this->site();

        $this->assertRoundTrip('gds/posts-duplicate', ['id' => $site['post']], fn ($result) => [(int) $result['id']]);
    }

    public function test_revisions_restore_round_trip(): void
    {
        $site = $this->site();
        wp_update_post(['ID' => $site['post'], 'post_title' => 'Second version', 'post_content' => 'Second body']);
        $revisions = wp_get_post_revisions($site['post']);
        $oldest = end($revisions);
        $this->assertInstanceOf(\WP_Post::class, $oldest);
        wp_update_post(['ID' => $site['post'], 'post_title' => 'Third version', 'post_content' => 'Third body']);

        $this->assertRoundTrip('gds/revisions-restore', ['id' => $oldest->ID]);
    }

    public function test_blocks_patch_round_trip(): void
    {
        $site = $this->site();

        $this->assertRoundTrip('gds/blocks-patch', ['id' => $site['post'], 'block_name' => 'core/heading', 'attrs' => ['level' => 3]]);
    }

    public function test_blocks_patch_undo_keeps_a_later_title_change(): void
    {
        $site = $this->site();
        $result = $this->assertAbilitySuccess('gds/blocks-patch', ['id' => $site['post'], 'block_name' => 'core/heading', 'attrs' => ['level' => 3]]);
        wp_update_post(['ID' => $site['post'], 'post_title' => 'Retitled since']);

        $this->assertNotWPError($this->restore($result));
        $post = get_post($site['post']);
        $this->assertSame('Retitled since', $post->post_title, 'Undoing a block change touches only the content.');
        $this->assertStringNotContainsString('"level":3', $post->post_content);
    }

    public function test_blocks_patch_undo_is_refused_when_the_content_changed_since(): void
    {
        $site = $this->site();
        $result = $this->assertAbilitySuccess('gds/blocks-patch', ['id' => $site['post'], 'block_name' => 'core/heading', 'attrs' => ['level' => 3]]);
        wp_update_post(['ID' => $site['post'], 'post_content' => 'Rewritten since']);

        $this->assertUndoRefused($result);
    }

    public function test_content_replace_round_trip(): void
    {
        $site = $this->site();

        $this->assertRoundTrip('gds/content-replace', ['id' => $site['post'], 'search' => 'Hello', 'replace' => 'Goodbye']);
    }

    public function test_content_replace_across_posts_round_trip(): void
    {
        $site = $this->site();
        $other = self::factory()->post->create(['post_content' => '<p>Hello again</p>', 'post_status' => 'publish']);

        $this->assertRoundTrip('gds/content-replace', ['ids' => [$site['post'], $other], 'search' => 'Hello', 'replace' => 'Goodbye']);
    }

    public function test_media_upload_round_trip(): void
    {
        $this->assertRoundTrip('gds/media-upload', [
            'base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8D4HwAFBQIAX8jx0gAAAABJRU5ErkJggg==',
            'filename' => 'round-trip.png',
        ]);
    }

    // ── Terms ───────────────────────────────────────────────────────

    public function test_terms_create_round_trip(): void
    {
        $site = $this->site();

        $this->assertRoundTrip('gds/terms-create', ['taxonomy' => 'categories', 'name' => 'Created', 'parent' => $site['parent'], 'description' => 'New']);
    }

    public function test_terms_create_undo_is_refused_once_the_term_is_used(): void
    {
        $site = $this->site();
        $result = $this->assertAbilitySuccess('gds/terms-create', ['taxonomy' => 'categories', 'name' => 'Created']);
        wp_set_object_terms($site['post'], [(int) $result['id']], 'category', true);

        $this->assertUndoRefused($result);
    }

    public function test_terms_update_round_trip(): void
    {
        $site = $this->site();

        $this->assertRoundTrip('gds/terms-update', [
            'taxonomy' => 'categories',
            'id' => $site['child'],
            'name' => 'Renamed',
            'slug' => 'renamed',
            'description' => 'Changed',
            'parent' => 0,
        ]);
    }

    public function test_terms_update_undo_is_refused_when_the_term_changed_since(): void
    {
        $site = $this->site();
        $result = $this->assertAbilitySuccess('gds/terms-update', ['taxonomy' => 'categories', 'id' => $site['child'], 'name' => 'Renamed']);
        wp_update_term($site['child'], 'category', ['description' => 'Edited by hand']);

        $this->assertUndoRefused($result);
    }

    public function test_terms_create_round_trip_for_a_menu_in_a_theme_location(): void
    {
        $this->site();

        $this->assertRoundTrip('gds/terms-create', ['taxonomy' => 'menus', 'name' => 'New menu', 'locations' => ['primary']]);
    }

    public function test_terms_update_round_trip_for_a_menu(): void
    {
        $site = $this->site();

        $this->assertRoundTrip('gds/terms-update', ['taxonomy' => 'menus', 'id' => $site['footer_menu'], 'name' => 'Renamed menu', 'locations' => ['primary'], 'auto_add' => true]);
    }

    public function test_terms_delete_round_trip_for_a_category_in_use(): void
    {
        // Children, a menu item linking to it, a post whose only category
        // it was (it gets the default), a post with another category too.
        $site = $this->site();

        $this->assertRoundTrip('gds/terms-delete', ['taxonomy' => 'categories', 'id' => $site['child']]);
    }

    public function test_terms_delete_round_trip_for_a_tag(): void
    {
        $site = $this->site();

        $this->assertRoundTrip('gds/terms-delete', ['taxonomy' => 'tags', 'id' => $site['tag']]);
    }

    public function test_terms_delete_round_trip_for_a_menu(): void
    {
        $site = $this->site();

        $this->assertRoundTrip('gds/terms-delete', ['taxonomy' => 'menus', 'id' => $site['menu']]);
    }

    public function test_terms_delete_undo_after_a_later_term_took_the_id_is_not_offered_as_exact(): void
    {
        global $wpdb;
        $site = $this->site();
        $result = $this->assertAbilitySuccess('gds/terms-delete', ['taxonomy' => 'categories', 'id' => $site['other']]);
        $wpdb->insert($wpdb->terms, ['term_id' => $site['other'], 'name' => 'Squatter', 'slug' => 'squatter']);

        $restored = $this->restore($result);
        $this->assertNotWPError($restored);
        $this->assertNotEmpty($restored['caveats'] ?? [], 'A term back under a new id says so.');
    }

    // ── Menus ───────────────────────────────────────────────────────

    public function test_nav_menu_items_create_round_trip(): void
    {
        $site = $this->site();

        $this->assertRoundTrip('gds/nav-menu-items-create', [
            'menu_id' => $site['menu'],
            'title' => 'Blog link',
            'linked' => ['kind' => 'post', 'post_id' => $site['blog']],
            'position' => 2,
        ], fn ($result) => [(int) $result['id']]);
    }

    public function test_nav_menu_items_update_round_trip(): void
    {
        $site = $this->site();

        $this->assertRoundTrip('gds/nav-menu-items-update', [
            'id' => $site['item_url'],
            'title' => 'Documentation',
            'linked' => ['kind' => 'url', 'url' => 'https://example.com/documentation'],
        ]);
    }

    public function test_nav_menu_items_delete_round_trip(): void
    {
        $site = $this->site();

        $this->assertRoundTrip('gds/nav-menu-items-delete', ['id' => $site['item_child']]);
    }

    public function test_nav_menu_items_move_round_trip(): void
    {
        $site = $this->site();

        $this->assertRoundTrip('gds/nav-menu-items-move', ['id' => $site['item_url'], 'menu_id' => $site['footer_menu'], 'position' => 1]);
    }

    public function test_nav_menu_items_move_undo_keeps_a_later_rename_of_another_item(): void
    {
        $site = $this->site();
        $result = $this->assertAbilitySuccess('gds/nav-menu-items-move', ['id' => $site['item_url'], 'position' => 1]);
        wp_update_post(['ID' => $site['item_home'], 'post_title' => 'Start']);

        $this->assertNotWPError($this->restore($result));
        $this->assertSame('Start', get_post($site['item_home'])->post_title, 'Only the orders it shifted are put back.');
    }

    public function test_nav_menu_items_reorder_round_trip(): void
    {
        $site = $this->site();

        $this->assertRoundTrip('gds/nav-menu-items-reorder', [
            'menu_id' => $site['menu'],
            'parent_item_id' => 0,
            'item_ids_in_order' => [$site['item_url'], $site['item_child'], $site['item_home']],
        ]);
    }

    // ── Gravity Forms ───────────────────────────────────────────────

    private function requireGravityForms(): void
    {
        if (! class_exists('GFAPI')) {
            $this->markTestSkipped('Gravity Forms is not active.');
        }
    }

    private function form(): int
    {
        $id = \GFAPI::add_form(['title' => 'Contact '.uniqid(), 'fields' => [['id' => 1, 'type' => 'email', 'label' => 'Email']]]);
        $this->assertIsInt($id);

        return $id;
    }

    public function test_forms_create_round_trip(): void
    {
        $this->requireGravityForms();

        $this->assertRoundTrip('gds/forms-create', ['title' => 'Signup '.uniqid(), 'fields' => [['id' => 1, 'type' => 'text', 'label' => 'Name']]]);
    }

    public function test_forms_create_undo_is_refused_once_the_form_has_entries(): void
    {
        $this->requireGravityForms();
        $result = $this->assertAbilitySuccess('gds/forms-create', ['title' => 'Signup '.uniqid(), 'fields' => [['id' => 1, 'type' => 'text', 'label' => 'Name']]]);
        \GFAPI::add_entry(['form_id' => (int) $result['id'], '1' => 'Ada']);

        $error = $this->assertUndoRefused($result);
        $this->assertStringContainsString('entr', $error->get_error_message());
    }

    public function test_forms_update_round_trip(): void
    {
        $this->requireGravityForms();
        $id = $this->form();
        \GFAPI::add_entry(['form_id' => $id, '1' => 'a@example.com']);

        $this->assertRoundTrip('gds/forms-update', [
            'id' => $id,
            'title' => 'Renamed '.uniqid(),
            'fields' => [['id' => 1, 'type' => 'email', 'label' => 'Email'], ['id' => 2, 'type' => 'text', 'label' => 'Name']],
        ]);
    }

    public function test_forms_update_undo_is_refused_when_it_would_drop_a_field_with_entries(): void
    {
        $this->requireGravityForms();
        $id = $this->form();
        $result = $this->assertAbilitySuccess('gds/forms-update', [
            'id' => $id,
            'fields' => [['id' => 1, 'type' => 'email', 'label' => 'Email'], ['id' => 2, 'type' => 'text', 'label' => 'Name']],
        ]);
        \GFAPI::add_entry(['form_id' => $id, '1' => 'a@example.com', '2' => 'Ada']);

        $this->assertUndoRefused($result);
    }

    public function test_feeds_create_round_trip(): void
    {
        $this->requireGravityForms();
        $form = $this->form();

        $this->assertRoundTrip('gds/feeds-create', ['form_id' => $form, 'addon_slug' => 'test-addon', 'meta' => ['feed_name' => 'CRM']]);
    }

    public function test_feeds_update_round_trip(): void
    {
        $this->requireGravityForms();
        $form = $this->form();
        $other = $this->form();
        $feed = \GFAPI::add_feed($form, ['feed_name' => 'CRM', 'list' => 1], 'test-addon');

        $this->assertRoundTrip('gds/feeds-update', ['id' => (int) $feed, 'form_id' => $other, 'is_active' => false, 'meta' => ['feed_name' => 'Renamed']]);
    }

    public function test_feeds_delete_round_trip(): void
    {
        $this->requireGravityForms();
        $form = $this->form();
        $feed = \GFAPI::add_feed($form, ['feed_name' => 'CRM'], 'test-addon');
        \GFAPI::update_feed_property((int) $feed, 'feed_order', 3);

        $this->assertRoundTrip('gds/feeds-delete', ['id' => (int) $feed]);
    }

    public function test_feeds_duplicate_round_trip(): void
    {
        $this->requireGravityForms();
        $form = $this->form();
        $feed = \GFAPI::add_feed($form, ['feed_name' => 'CRM'], 'test-addon');

        $this->assertRoundTrip('gds/feeds-duplicate', ['id' => (int) $feed, 'target_form_ids' => [$this->form(), $this->form()]]);
    }

    // ── Polylang ────────────────────────────────────────────────────

    private function requirePolylang(): void
    {
        if (! function_exists('pll_set_post_language')) {
            $this->markTestSkipped('Polylang is not active.');
        }
        // WP_UnitTestCase rollback removes language terms; Polylang's cache
        // may still list them.
        $model = PLL()->model;
        $model->clean_languages_cache();
        foreach ([['name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'term_group' => 0], ['name' => 'Finnish', 'slug' => 'fi', 'locale' => 'fi', 'term_group' => 1]] as $lang) {
            if (! $model->get_language($lang['slug'])) {
                $model->add_language($lang);
            }
        }
        $model->clean_languages_cache();
        // PLL_MO keeps string translations in a static cache by language
        // slug, which outlives the rolled-back rows of earlier tests.
        if (class_exists('PLL_MO')) {
            new \PLL_MO;
            $cache = (new \ReflectionProperty(\PLL_MO::class, 'cache'))->getValue();
            if (is_object($cache) && method_exists($cache, 'clean')) {
                $cache->clean();
            }
        }
    }

    public function test_translations_create_round_trip(): void
    {
        $this->requirePolylang();
        $site = $this->site();
        pll_set_post_language($site['post'], 'en');

        $this->assertRoundTrip('gds/translations-create', ['source_id' => $site['post'], 'lang' => 'fi'], fn ($result) => [(int) $result['id']]);
    }

    public function test_translations_create_works_again_after_its_undo(): void
    {
        $this->requirePolylang();
        $site = $this->site();
        pll_set_post_language($site['post'], 'en');
        $first = $this->assertAbilitySuccess('gds/translations-create', ['source_id' => $site['post'], 'lang' => 'fi']);
        $this->assertStringNotContainsString('Delete', $first['_undo']['label'], 'The undo trashes the copy, so it does not say it deletes it.');
        $this->assertNotWPError($this->restore($first));

        $this->assertAbilitySuccess('gds/translations-create', ['source_id' => $site['post'], 'lang' => 'fi']);
    }

    public function test_translations_create_term_round_trip(): void
    {
        $this->requirePolylang();
        $site = $this->site();
        pll_set_term_language($site['other'], 'en');

        $this->assertRoundTrip('gds/translations-create-term', ['source_id' => $site['other'], 'taxonomy' => 'category', 'lang' => 'fi', 'name' => 'Muu']);
    }

    public function test_translations_link_round_trip(): void
    {
        $this->requirePolylang();
        $site = $this->site();
        pll_set_post_language($site['post'], 'en');
        $fi = self::factory()->post->create(['post_title' => 'Alkuperäinen']);
        pll_set_post_language($fi, 'fi');

        $this->assertRoundTrip('gds/translations-link', ['translations' => ['en' => $site['post'], 'fi' => $fi]]);
    }

    public function test_strings_update_round_trip(): void
    {
        $this->requirePolylang();
        // The string table lives on a polylang_mo post per language.
        $this->assertRoundTrip('gds/strings-update', ['string' => 'Read more', 'lang' => 'fi', 'translation' => 'Lue lisää']);
    }

    public function test_strings_update_round_trip_over_an_existing_translation(): void
    {
        $this->requirePolylang();
        $this->assertAbilitySuccess('gds/strings-update', ['string' => 'Read more', 'lang' => 'fi', 'translation' => 'Lue lisää']);

        $this->assertRoundTrip('gds/strings-update', ['string' => 'Read more', 'lang' => 'fi', 'translation' => 'Lisää']);
    }

    public function test_strings_update_undo_is_refused_once_the_translation_changed_again(): void
    {
        $this->requirePolylang();
        $first = $this->assertAbilitySuccess('gds/strings-update', ['string' => 'Read more', 'lang' => 'fi', 'translation' => 'Lue lisää']);
        $this->assertAbilitySuccess('gds/strings-update', ['string' => 'Read more', 'lang' => 'fi', 'translation' => 'Lisää']);

        $this->assertUndoRefused($first);
    }

    // ── SEO and redirects ───────────────────────────────────────────

    public function test_seo_update_post_round_trip(): void
    {
        if (! defined('WPSEO_VERSION')) {
            $this->markTestSkipped('Yoast SEO is not active.');
        }
        $site = $this->site();
        update_post_meta($site['post'], '_yoast_wpseo_title', 'Old title');

        $this->assertRoundTrip('gds/seo-update', ['object_type' => 'post', 'id' => $site['post'], 'fields' => ['title' => 'New title', 'description' => 'New description']]);
    }

    public function test_seo_update_term_round_trip(): void
    {
        if (! defined('WPSEO_VERSION')) {
            $this->markTestSkipped('Yoast SEO is not active.');
        }
        $site = $this->site();

        $this->assertRoundTrip('gds/seo-update', ['object_type' => 'term', 'id' => $site['child'], 'fields' => ['title' => 'Category title']]);
    }

    public function test_redirects_manage_create_round_trip(): void
    {
        if (! wp_get_ability('gds/redirects-manage')) {
            $this->markTestSkipped('No redirect plugin is active.');
        }

        // A Safe Redirect Manager rule is a post; the undo trashes it.
        $this->assertRoundTrip('gds/redirects-manage', ['action' => 'create', 'from' => '/old-'.uniqid(), 'to' => '/new'], fn ($result) => array_filter([(int) ($result['_undo']['data']['post_id'] ?? 0)]));
    }

    // ── Coverage ────────────────────────────────────────────────────

    public function test_every_write_ability_has_a_round_trip_or_says_why_not(): void
    {
        preg_match_all("#assertRoundTrip\\('(gds/[a-z-]+)'#", (string) file_get_contents(__FILE__), $m);
        $tested = array_unique($m[1]);

        $missing = [];
        foreach (wp_get_abilities() as $ability) {
            $name = $ability->get_name();
            if (! str_starts_with($name, 'gds/') || ! empty($ability->get_meta()['annotations']['readonly'])) {
                continue;
            }
            if (! in_array($name, $tested, true) && ! isset(self::NO_UNDO[$name]) && ! isset(self::NOT_RUNNABLE_HERE[$name])) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing, 'Write abilities without an undo round trip here.');
    }
}
