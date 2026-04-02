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
        $result = BulkUpdatePostsAbility::execute([
            'post_type' => 'post',
        ]);

        $this->assertWPError($result);
        $this->assertSame('no_updates', $result->get_error_code());
    }

    public function test_error_without_filter(): void
    {
        $result = BulkUpdatePostsAbility::execute([
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

        $result = BulkUpdatePostsAbility::execute([
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

        $result = BulkUpdatePostsAbility::execute([
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

        $result = BulkUpdatePostsAbility::execute([
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

        $result = BulkUpdatePostsAbility::execute([
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

        $result = BulkUpdatePostsAbility::execute([
            'post_ids' => [$postId],
            'delete_meta' => ['obsolete'],
        ]);

        $this->assertSame(1, $result['updated']);
        $this->assertEmpty(get_post_meta($postId, 'obsolete', true));
    }

    public function test_respects_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::factory()->post->create(['post_status' => 'publish', 'post_type' => 'post']);
        }

        $result = BulkUpdatePostsAbility::execute([
            'post_type' => 'post',
            'set_status' => 'draft',
            'limit' => 2,
        ]);

        $this->assertSame(2, $result['matched']);
        $this->assertSame(2, $result['updated']);
    }

    public function test_permission_denied_for_author(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'author']));
        $result = BulkUpdatePostsAbility::checkPermission();
        $this->assertWPError($result);
    }

    public function test_permission_denied_for_guest(): void
    {
        wp_set_current_user(0);
        $result = BulkUpdatePostsAbility::checkPermission();
        $this->assertWPError($result);
    }
}
