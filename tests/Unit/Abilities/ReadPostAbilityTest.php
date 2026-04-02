<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\ReadPostAbility;
use WP_UnitTestCase;

class ReadPostAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_execute_returns_post_data(): void
    {
        $postId = self::factory()->post->create([
            'post_title' => 'Test Post',
            'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
            'post_excerpt' => 'Test excerpt',
            'post_status' => 'publish',
            'post_name' => 'test-post',
        ]);

        $result = ReadPostAbility::execute(['post_id' => $postId]);

        $this->assertIsArray($result);
        $this->assertSame($postId, $result['id']);
        $this->assertSame('post', $result['post_type']);
        $this->assertSame('Test Post', $result['title']);
        $this->assertStringContainsString('Hello', $result['content']);
        $this->assertSame('Test excerpt', $result['excerpt']);
        $this->assertSame('publish', $result['status']);
        $this->assertSame('test-post', $result['slug']);
        $this->assertSame(0, $result['parent_id']);
        $this->assertArrayHasKey('url', $result);
        $this->assertArrayHasKey('modified', $result);
    }

    public function test_execute_returns_error_for_missing_post(): void
    {
        $result = ReadPostAbility::execute(['post_id' => 999999]);

        $this->assertWPError($result);
        $this->assertSame('post_not_found', $result->get_error_code());
    }

    public function test_execute_includes_language_fields(): void
    {
        $postId = self::factory()->post->create();

        $result = ReadPostAbility::execute(['post_id' => $postId]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('language', $result);
        $this->assertArrayHasKey('translations', $result);
    }

    public function test_execute_includes_parent_id(): void
    {
        $parentId = self::factory()->post->create(['post_type' => 'page']);
        $childId = self::factory()->post->create([
            'post_type' => 'page',
            'post_parent' => $parentId,
        ]);

        $result = ReadPostAbility::execute(['post_id' => $childId]);

        $this->assertSame($parentId, $result['parent_id']);
    }

    public function test_permission_denied_for_guest(): void
    {
        wp_set_current_user(0);

        $result = ReadPostAbility::checkPermission(['post_id' => 1]);

        $this->assertWPError($result);
        $this->assertSame('authentication_required', $result->get_error_code());
    }
}
