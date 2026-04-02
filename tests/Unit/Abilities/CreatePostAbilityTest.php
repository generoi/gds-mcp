<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\CreatePostAbility;
use WP_UnitTestCase;

class CreatePostAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_execute_creates_post(): void
    {
        $result = CreatePostAbility::execute([
            'post_title' => 'Test Created Post',
            'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
            'post_type' => 'post',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('Test Created Post', $result['title']);
        $this->assertSame('draft', $result['status']);
        $this->assertSame('post', $result['post_type']);
        $this->assertGreaterThan(0, $result['id']);
    }

    public function test_execute_creates_page_with_status(): void
    {
        $result = CreatePostAbility::execute([
            'post_title' => 'Published Page',
            'post_type' => 'page',
            'post_status' => 'publish',
        ]);

        $this->assertSame('publish', $result['status']);
        $this->assertSame('page', $result['post_type']);
    }

    public function test_execute_sets_meta(): void
    {
        $result = CreatePostAbility::execute([
            'post_title' => 'Post With Meta',
            'meta' => ['custom_key' => 'custom_value'],
        ]);

        $this->assertSame('custom_value', get_post_meta($result['id'], 'custom_key', true));
    }

    public function test_permission_denied_for_guest(): void
    {
        wp_set_current_user(0);
        $result = CreatePostAbility::checkPermission();
        $this->assertWPError($result);
    }
}
