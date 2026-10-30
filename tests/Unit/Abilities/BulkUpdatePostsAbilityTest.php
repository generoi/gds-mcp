<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\BulkUpdatePostsAbility;
use WP_UnitTestCase;

class BulkUpdatePostsAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    public function test_error_without_updates(): void
    {
        $result = (new BulkUpdatePostsAbility)->execute([
            'post_type' => 'post',
        ]);

        $this->assertWPError($result);
        $this->assertSame('no_updates', $result->get_error_code());
    }

    public function test_error_without_filter(): void
    {
        $result = (new BulkUpdatePostsAbility)->execute([
            'set_status' => 'draft',
        ]);

        $this->assertWPError($result);
        $this->assertSame('missing_filter', $result->get_error_code());
    }

    public function test_dry_run_does_not_modify(): void
    {
        $postId = self::factory()->post->create([
            'post_status' => 'publish',
            'post_title' => 'Stay Published',
        ]);

        $result = (new BulkUpdatePostsAbility)->execute([
            'post_ids' => [$postId],
            'set_status' => 'draft',
            'dry_run' => true,
        ]);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['matched']);
        $this->assertSame('publish', get_post_status($postId));
    }

    public function test_updates_status_by_ids(): void
    {
        $id1 = self::factory()->post->create(['post_status' => 'publish']);
        $id2 = self::factory()->post->create(['post_status' => 'publish']);

        $result = (new BulkUpdatePostsAbility)->execute([
            'post_ids' => [$id1, $id2],
            'set_status' => 'draft',
        ]);

        $this->assertSame(2, $result['updated']);
        $this->assertSame('draft', get_post_status($id1));
        $this->assertSame('draft', get_post_status($id2));
    }

    public function test_updates_by_query(): void
    {
        $id1 = self::factory()->post->create(['post_status' => 'draft', 'post_type' => 'post']);
        $id2 = self::factory()->post->create(['post_status' => 'draft', 'post_type' => 'post']);
        // This one shouldn't match (different status).
        self::factory()->post->create(['post_status' => 'publish', 'post_type' => 'post']);

        $result = (new BulkUpdatePostsAbility)->execute([
            'post_type' => 'post',
            'status' => 'draft',
            'set_status' => 'pending',
        ]);

        $this->assertSame(2, $result['updated']);
        $this->assertSame('pending', get_post_status($id1));
        $this->assertSame('pending', get_post_status($id2));
    }

    public function test_sets_meta(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);

        $result = (new BulkUpdatePostsAbility)->execute([
            'post_ids' => [$postId],
            'set_meta' => ['featured' => 'yes', 'priority' => '1'],
        ]);

        $this->assertSame(1, $result['updated']);
        $this->assertSame('yes', get_post_meta($postId, 'featured', true));
        $this->assertSame('1', get_post_meta($postId, 'priority', true));
    }

    public function test_deletes_meta(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        update_post_meta($postId, 'obsolete', 'value');

        $result = (new BulkUpdatePostsAbility)->execute([
            'post_ids' => [$postId],
            'delete_meta' => ['obsolete'],
        ]);

        $this->assertSame(1, $result['updated']);
        $this->assertEmpty(get_post_meta($postId, 'obsolete', true));
    }

    public function test_contributor_cannot_publish(): void
    {
        $contributor = self::factory()->user->create(['role' => 'contributor']);
        wp_set_current_user($contributor);
        $id1 = self::factory()->post->create(['post_author' => $contributor, 'post_status' => 'draft']);
        $id2 = self::factory()->post->create(['post_author' => $contributor, 'post_status' => 'draft']);

        $result = (new BulkUpdatePostsAbility)->execute([
            'post_ids' => [$id1, $id2],
            'set_status' => 'publish',
        ]);

        $this->assertWPError($result);
        $this->assertSame('forbidden', $result->get_error_code());
        $this->assertSame('draft', get_post_status($id1));
        $this->assertSame('draft', get_post_status($id2));

        $result = (new BulkUpdatePostsAbility)->execute([
            'post_ids' => [$id1],
            'set_status' => 'pending',
        ]);
        $this->assertSame(1, $result['updated']);
    }

    public function test_meta_writes_respect_meta_capabilities(): void
    {
        register_post_meta('post', 'locked', ['auth_callback' => '__return_false']);
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        update_post_meta($postId, 'locked_delete', 'stays');
        register_post_meta('post', 'locked_delete', ['auth_callback' => '__return_false']);

        (new BulkUpdatePostsAbility)->execute([
            'post_ids' => [$postId],
            'set_meta' => ['locked' => 'nope', 'open' => 'yes'],
            'delete_meta' => ['locked_delete'],
        ]);

        $this->assertSame('', get_post_meta($postId, 'locked', true));
        $this->assertSame('yes', get_post_meta($postId, 'open', true));
        $this->assertSame('stays', get_post_meta($postId, 'locked_delete', true));

        unregister_post_meta('post', 'locked');
        unregister_post_meta('post', 'locked_delete');
    }

    public function test_trash_needs_delete_post_and_goes_through_the_trash(): void
    {
        $locked = self::factory()->post->create(['post_status' => 'publish']);
        $published = self::factory()->post->create(['post_status' => 'publish', 'post_name' => 'kept-slug']);
        // Editable, but not deletable.
        add_filter('map_meta_cap', static fn ($caps, $cap, $userId, $args) => $cap === 'delete_post' && (int) ($args[0] ?? 0) === $locked ? ['do_not_allow'] : $caps, 10, 4);

        $result = (new BulkUpdatePostsAbility)->execute(['post_ids' => [$locked, $published], 'set_status' => 'trash']);

        $this->assertSame(1, $result['updated']);
        $this->assertSame('publish', get_post_status($locked));
        $this->assertSame($locked, $result['errors'][0]['id']);
        $this->assertSame('trash', get_post_status($published));
        $this->assertSame('publish', get_post_meta($published, '_wp_trash_meta_status', true), 'A real trash, so untrash can restore it.');

        $undo = apply_filters('gds-mcp/restore_snapshot', null, $result['_undo']);
        $this->assertNotWPError($undo);
        clean_post_cache($published);
        $this->assertSame('publish', get_post_status($published), 'Undo puts the post back as published, not as a draft.');
        $this->assertSame('kept-slug', get_post($published)->post_name);
    }

    public function test_a_refused_meta_write_is_an_error_not_an_unchanged_value(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        // Something refuses this one key.
        add_filter('update_post_metadata', fn ($check, $id, $key) => $key === 'refused' ? false : $check, 10, 3);

        $result = (new BulkUpdatePostsAbility)->execute(['post_ids' => [$postId], 'set_meta' => ['refused' => 'x', 'kept' => 'y']]);

        $this->assertSame(1, $result['updated'], 'The other key was written, so this is not a failed run.');
        $this->assertStringContainsString('"refused"', $result['errors'][0]['error']);
    }

    public function test_a_value_the_sanitizer_makes_equal_is_not_an_error(): void
    {
        register_post_meta('post', 'shouty', ['sanitize_callback' => fn ($value) => strtoupper((string) $value)]);
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        update_post_meta($postId, 'shouty', 'LOUD');
        update_post_meta($postId, 'other', 'old');

        $result = (new BulkUpdatePostsAbility)->execute(['post_ids' => [$postId], 'set_meta' => ['shouty' => 'loud', 'other' => 'new']]);
        unregister_post_meta('post', 'shouty');

        $this->assertArrayNotHasKey('errors', $result, 'Stored as LOUD already: unchanged, not refused.');
    }

    public function test_counts_only_posts_where_something_was_written(): void
    {
        $same = self::factory()->post->create(['post_status' => 'draft']);
        $changed = self::factory()->post->create(['post_status' => 'publish']);
        update_post_meta($same, 'flag', 'on');

        $result = (new BulkUpdatePostsAbility)->execute(['post_ids' => [$same, $changed], 'set_status' => 'draft', 'set_meta' => ['flag' => 'on']]);

        // $same already had both values, so nothing was written to it.
        $this->assertSame(1, $result['updated']);
        $this->assertCount(1, $result['_undo']['data']['items']);
    }

    public function test_set_meta_preserves_backslashes(): void
    {
        $json = '{"label":"a \"quoted\" <word>"}';
        $postId = self::factory()->post->create(['post_status' => 'publish']);

        (new BulkUpdatePostsAbility)->execute([
            'post_ids' => [$postId],
            'set_meta' => ['json_field' => $json],
        ]);

        $this->assertSame($json, get_post_meta($postId, 'json_field', true));
    }

    public function test_respects_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::factory()->post->create(['post_status' => 'publish', 'post_type' => 'post']);
        }

        $result = (new BulkUpdatePostsAbility)->execute([
            'post_type' => 'post',
            'set_status' => 'draft',
            'limit' => 2,
        ]);

        $this->assertSame(2, $result['matched']);
        $this->assertSame(2, $result['updated']);
    }

    public function test_a_negative_or_zero_limit_does_not_lift_the_cap(): void
    {
        for ($i = 0; $i < 3; $i++) {
            self::factory()->post->create(['post_status' => 'publish', 'post_type' => 'post']);
        }

        foreach ([-1, 0] as $limit) {
            $result = (new BulkUpdatePostsAbility)->execute(['post_type' => 'post', 'set_meta' => ['flag' => (string) $limit], 'limit' => $limit, 'dry_run' => true]);

            $this->assertSame(1, $result['matched'], "limit {$limit}");
        }
    }
}
