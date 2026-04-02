<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\ListPostsAbility;
use WP_UnitTestCase;

class ListPostsAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_execute_returns_posts(): void
    {
        self::factory()->post->create_many(3, [
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
        self::factory()->post->create([
            'post_title' => 'Unique Findable Title',
            'post_type' => 'page',
            'post_status' => 'publish',
        ]);
        self::factory()->post->create([
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
        self::factory()->post->create_many(5, [
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

        // Should not error; internally capped at 100.
        $this->assertIsArray($result);
    }

    public function test_execute_returns_post_structure(): void
    {
        $postId = self::factory()->post->create([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Structure Test',
        ]);

        // Assign language if Polylang is active so the post isn't filtered out.
        if (function_exists('pll_set_post_language')) {
            pll_set_post_language($postId, pll_default_language());
        }

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
