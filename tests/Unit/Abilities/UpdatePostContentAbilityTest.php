<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\UpdatePostContentAbility;
use WP_UnitTestCase;

class UpdatePostContentAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_execute_updates_title(): void
    {
        $postId = self::factory()->post->create(['post_title' => 'Old Title']);

        $result = UpdatePostContentAbility::execute([
            'post_id' => $postId,
            'post_title' => 'New Title',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('New Title', $result['title']);
        $this->assertSame('New Title', get_post($postId)->post_title);
    }

    public function test_execute_updates_content(): void
    {
        $postId = self::factory()->post->create(['post_content' => 'old content']);
        $newContent = '<!-- wp:paragraph --><p>New content</p><!-- /wp:paragraph -->';

        $result = UpdatePostContentAbility::execute([
            'post_id' => $postId,
            'post_content' => $newContent,
        ]);

        $this->assertIsArray($result);
        $this->assertSame($newContent, $result['content']);
    }

    public function test_execute_updates_status(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'draft']);

        $result = UpdatePostContentAbility::execute([
            'post_id' => $postId,
            'post_status' => 'publish',
        ]);

        $this->assertSame('publish', $result['status']);
    }

    public function test_execute_updates_multiple_fields(): void
    {
        $postId = self::factory()->post->create();

        $result = UpdatePostContentAbility::execute([
            'post_id' => $postId,
            'post_title' => 'Updated Title',
            'post_excerpt' => 'Updated excerpt',
            'post_name' => 'updated-slug',
        ]);

        $this->assertSame('Updated Title', $result['title']);
        $post = get_post($postId);
        $this->assertSame('Updated excerpt', $post->post_excerpt);
        $this->assertSame('updated-slug', $post->post_name);
    }

    public function test_execute_returns_error_for_missing_post(): void
    {
        $result = UpdatePostContentAbility::execute([
            'post_id' => 999999,
            'post_title' => 'New',
        ]);

        $this->assertWPError($result);
        $this->assertSame('post_not_found', $result->get_error_code());
    }

    public function test_execute_returns_error_when_no_fields_provided(): void
    {
        $postId = self::factory()->post->create();

        $result = UpdatePostContentAbility::execute(['post_id' => $postId]);

        $this->assertWPError($result);
        $this->assertSame('no_fields', $result->get_error_code());
    }

    public function test_permission_denied_for_guest(): void
    {
        wp_set_current_user(0);

        $result = UpdatePostContentAbility::checkPermission(['post_id' => 1]);

        $this->assertWPError($result);
        $this->assertSame('authentication_required', $result->get_error_code());
    }

    public function test_permission_accepts_non_array_input(): void
    {
        // WP core's invoke_callback can pass a string instead of array
        // when the MCP adapter transforms/flattens the input schema.
        $result = UpdatePostContentAbility::checkPermission('unexpected string');
        $this->assertTrue($result);

        $result = UpdatePostContentAbility::checkPermission(null);
        $this->assertTrue($result);
    }
}
