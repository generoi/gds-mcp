<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\DuplicatePostAbility;
use WP_UnitTestCase;

class DuplicatePostAbilityTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_duplicates_post(): void
    {
        $sourceId = self::factory()->post->create([
            'post_title' => 'Original',
            'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
            'post_status' => 'publish',
        ]);

        $result = DuplicatePostAbility::execute(['post_id' => $sourceId]);

        $this->assertArrayHasKey('id', $result);
        $this->assertNotSame($sourceId, $result['id']);
        $this->assertSame($sourceId, $result['source_id']);
        $this->assertSame('Original (Copy)', $result['title']);
        $this->assertSame('draft', $result['status']);

        $duplicate = get_post($result['id']);
        $this->assertStringContainsString('Hello', $duplicate->post_content);
    }

    public function test_custom_title(): void
    {
        $sourceId = self::factory()->post->create(['post_title' => 'Original']);

        $result = DuplicatePostAbility::execute([
            'post_id' => $sourceId,
            'post_title' => 'Custom Title',
        ]);

        $this->assertSame('Custom Title', $result['title']);
    }

    public function test_custom_status(): void
    {
        $sourceId = self::factory()->post->create(['post_title' => 'Original']);

        $result = DuplicatePostAbility::execute([
            'post_id' => $sourceId,
            'post_status' => 'publish',
        ]);

        $this->assertSame('publish', $result['status']);
    }

    public function test_copies_meta(): void
    {
        $sourceId = self::factory()->post->create(['post_title' => 'With Meta']);
        update_post_meta($sourceId, 'custom_field', 'custom_value');
        update_post_meta($sourceId, '_private_field', 'hidden');

        $result = DuplicatePostAbility::execute(['post_id' => $sourceId]);

        $this->assertSame('custom_value', get_post_meta($result['id'], 'custom_field', true));
        // Private meta should not be copied.
        $this->assertEmpty(get_post_meta($result['id'], '_private_field', true));
    }

    public function test_copies_taxonomy_terms(): void
    {
        register_taxonomy('test_tag', 'post');
        $sourceId = self::factory()->post->create(['post_title' => 'Tagged']);
        wp_set_object_terms($sourceId, ['alpha', 'beta'], 'test_tag');

        $result = DuplicatePostAbility::execute(['post_id' => $sourceId]);

        $terms = get_the_terms($result['id'], 'test_tag');
        $slugs = array_column($terms, 'slug');
        sort($slugs);
        $this->assertSame(['alpha', 'beta'], $slugs);
    }

    public function test_copies_featured_image(): void
    {
        $sourceId = self::factory()->post->create(['post_title' => 'With Thumb']);
        $attachmentId = self::factory()->attachment->create_upload_object(
            DIR_TESTDATA.'/images/canola.jpg',
            $sourceId
        );
        set_post_thumbnail($sourceId, $attachmentId);

        $result = DuplicatePostAbility::execute(['post_id' => $sourceId]);

        $this->assertSame($attachmentId, (int) get_post_thumbnail_id($result['id']));
    }

    public function test_error_for_nonexistent_post(): void
    {
        $result = DuplicatePostAbility::execute(['post_id' => 999999]);
        $this->assertWPError($result);
        $this->assertSame('post_not_found', $result->get_error_code());
    }

    public function test_permission_denied_for_guest(): void
    {
        wp_set_current_user(0);
        $result = DuplicatePostAbility::checkPermission();
        $this->assertWPError($result);
    }
}
