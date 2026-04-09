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

        $result = ListPostsAbility::instance()->execute(['post_type' => 'page']);

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

        $result = ListPostsAbility::instance()->execute([
            'post_type' => 'page',
            'search' => 'Unique Findable',
        ]);

        $this->assertCount(1, $result['posts']);
    }

    public function test_execute_respects_pagination(): void
    {
        $this->createPosts(5, [
            'post_type' => 'page',
            'post_status' => 'publish',
        ]);

        $result = ListPostsAbility::instance()->execute([
            'post_type' => 'page',
            'per_page' => 2,
            'page' => 1,
        ]);

        $this->assertCount(2, $result['posts']);
        $this->assertGreaterThanOrEqual(3, $result['pages']);
    }

    public function test_execute_returns_rest_fields_plus_polylang(): void
    {
        $this->createPost([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Structure Test',
        ]);

        $result = ListPostsAbility::instance()->execute(['post_type' => 'page', 'search' => 'Structure Test']);

        $this->assertNotEmpty($result['posts']);
        $post = $result['posts'][0];
        // REST API core fields
        $this->assertArrayHasKey('id', $post);
        $this->assertArrayHasKey('type', $post);
        $this->assertArrayHasKey('title', $post);
        $this->assertArrayHasKey('status', $post);
        $this->assertArrayHasKey('link', $post);
    }

    public function test_execute_fields_filter(): void
    {
        $this->createPost([
            'post_type' => 'page',
            'post_status' => 'publish',
        ]);

        $result = ListPostsAbility::instance()->execute([
            'post_type' => 'page',
            '_fields' => 'id,title,link',
        ]);

        $this->assertNotEmpty($result['posts']);
        $post = $result['posts'][0];
        $this->assertArrayHasKey('id', $post);
        $this->assertArrayHasKey('title', $post);
        $this->assertArrayHasKey('link', $post);
        $this->assertArrayNotHasKey('status', $post);
    }

    public function test_invalid_post_type_returns_error(): void
    {
        $result = ListPostsAbility::instance()->execute(['post_type' => 'nonexistent_type']);

        $this->assertWPError($result);
    }
}
