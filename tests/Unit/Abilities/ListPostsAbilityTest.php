<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\ListPostsAbility;
use GeneroWP\MCP\Tests\TestCase;

class ListPostsAbilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_execute_returns_posts(): void
    {
        $this->createPosts(3, [
            'post_type' => 'page',
            'post_status' => 'publish',
        ]);

        $result = ListPostsAbility::execute(['post_type' => 'page']);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('posts', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('pages', $result);
        $this->assertGreaterThanOrEqual(3, $result['total']);
    }

    public function test_execute_filters_by_search(): void
    {
        $this->createPost([
            'post_title' => 'Unique Findable Title',
            'post_type' => 'page',
            'post_status' => 'publish',
        ]);
        $this->createPost([
            'post_title' => 'Other Page',
            'post_type' => 'page',
            'post_status' => 'publish',
        ]);

        $result = ListPostsAbility::execute([
            'post_type' => 'page',
            'search' => 'Unique Findable',
        ]);

        $this->assertCount(1, $result['posts']);
        $this->assertSame('Unique Findable Title', $result['posts'][0]['title']);
    }

    public function test_execute_respects_pagination(): void
    {
        $this->createPosts(5, [
            'post_type' => 'page',
            'post_status' => 'publish',
        ]);

        $result = ListPostsAbility::execute([
            'post_type' => 'page',
            'per_page' => 2,
            'page' => 1,
        ]);

        $this->assertCount(2, $result['posts']);
        $this->assertGreaterThanOrEqual(3, $result['pages']);
    }

    public function test_execute_caps_per_page_at_100(): void
    {
        $result = ListPostsAbility::execute([
            'post_type' => 'page',
            'per_page' => 500,
        ]);

        $this->assertIsArray($result);
    }

    public function test_execute_returns_post_structure(): void
    {
        $this->createPost([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Structure Test',
        ]);

        $result = ListPostsAbility::execute(['post_type' => 'page', 'search' => 'Structure Test']);

        $this->assertNotEmpty($result['posts']);
        $post = $result['posts'][0];
        $this->assertArrayHasKey('id', $post);
        $this->assertArrayHasKey('post_type', $post);
        $this->assertArrayHasKey('title', $post);
        $this->assertArrayHasKey('status', $post);
        $this->assertArrayHasKey('language', $post);
        $this->assertArrayHasKey('translations', $post);
        $this->assertArrayHasKey('parent_id', $post);
        $this->assertArrayHasKey('url', $post);
    }

    public function test_permission_denied_for_guest(): void
    {
        wp_set_current_user(0);

        $result = ListPostsAbility::checkPermission();

        $this->assertWPError($result);
        $this->assertSame('authentication_required', $result->get_error_code());
    }
}
