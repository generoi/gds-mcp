<?php

namespace GeneroWP\MCP\Tests\Integration;

use GeneroWP\MCP\Abilities\ListPostsAbility;
use GeneroWP\MCP\Tests\McpIntegrationTestCase;

/**
 * Integration tests for ListPostsAbility.
 *
 * Tests the ability through REST delegation to verify that rest_do_request()
 * works correctly in all contexts (HTTP, CLI, STDIO, tests).
 */
class McpListPostsTest extends McpIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_delegates_to_rest_api(): void
    {
        $this->createPosts(3, [
            'post_type' => 'page',
            'post_status' => 'publish',
        ]);

        $result = ListPostsAbility::instance()->execute(['post_type' => 'page']);

        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(3, $result['total']);

        // Verify REST API response shape (not our custom mapping)
        $post = $result['posts'][0];
        $this->assertArrayHasKey('id', $post);
        $this->assertArrayHasKey('title', $post);
        $this->assertIsArray($post['title']); // REST returns {rendered: "..."}
        $this->assertArrayHasKey('rendered', $post['title']);
    }

    public function test_rest_fields_parameter(): void
    {
        $this->createPost([
            'post_type' => 'page',
            'post_status' => 'publish',
        ]);

        $result = ListPostsAbility::instance()->execute([
            'post_type' => 'page',
            '_fields' => 'id,title,link',
        ]);

        $post = $result['posts'][0];
        $this->assertArrayHasKey('id', $post);
        $this->assertArrayHasKey('title', $post);
        $this->assertArrayHasKey('link', $post);
        // REST API's _fields should exclude other fields
        $this->assertArrayNotHasKey('content', $post);
        $this->assertArrayNotHasKey('excerpt', $post);
    }

    public function test_rest_do_request_works_in_cli_context(): void
    {
        // This test verifies that rest_do_request() (used internally by the ability)
        // works even though we're not in an HTTP context.
        $this->createPost([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'CLI Context Test',
        ]);

        // Call ability which internally uses rest_do_request()
        $result = ListPostsAbility::instance()->execute([
            'post_type' => 'page',
            'search' => 'CLI Context Test',
        ]);

        $this->assertIsArray($result);
        $this->assertSame(1, $result['total']);
    }

    public function test_direct_rest_endpoint_matches_ability(): void
    {
        $this->createPosts(2, [
            'post_type' => 'page',
            'post_status' => 'publish',
        ]);

        // Call REST API directly
        $restResponse = $this->restGet('/wp/v2/pages', [
            'per_page' => 2,
            'orderby' => 'title',
            'order' => 'asc',
        ]);

        // Call ability
        $abilityResult = ListPostsAbility::instance()->execute([
            'post_type' => 'page',
            'per_page' => 2,
        ]);

        // Both should return the same post IDs
        $restIds = array_map(fn ($p) => $p['id'], $restResponse->get_data());
        $abilityIds = array_map(fn ($p) => $p['id'], $abilityResult['posts']);

        $this->assertSame($restIds, $abilityIds);
    }

    public function test_invalid_post_type_returns_error(): void
    {
        $result = ListPostsAbility::instance()->execute(['post_type' => 'nonexistent_type']);

        $this->assertWPError($result);
        $this->assertSame('invalid_post_type', $result->get_error_code());
    }
}
