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

        $result = (new DuplicatePostAbility)->execute(['id' => $sourceId]);

        $this->assertArrayHasKey('id', $result);
        $this->assertNotSame($sourceId, $result['id']);
        $this->assertSame('Original (Copy)', $result['title']['rendered']);
        $this->assertSame('draft', $result['status']);

        $duplicate = get_post($result['id']);
        $this->assertStringContainsString('Hello', $duplicate->post_content);
    }

    public function test_custom_title(): void
    {
        $sourceId = self::factory()->post->create(['title' => 'Original']);

        $result = (new DuplicatePostAbility)->execute([
            'id' => $sourceId,
            'title' => 'Custom Title',
        ]);

        $this->assertSame('Custom Title', $result['title']['rendered']);
    }

    public function test_custom_status(): void
    {
        $sourceId = self::factory()->post->create(['title' => 'Original']);

        $result = (new DuplicatePostAbility)->execute([
            'id' => $sourceId,
            'status' => 'publish',
        ]);

        $this->assertSame('publish', $result['status']);
    }

    public function test_copies_meta(): void
    {
        $sourceId = self::factory()->post->create(['title' => 'With Meta']);
        update_post_meta($sourceId, 'custom_field', 'custom_value');
        update_post_meta($sourceId, '_wp_page_template', 'template-wide.php');
        update_post_meta($sourceId, '_yoast_wpseo_title', 'SEO title');
        update_post_meta($sourceId, '_custom_acf_ref', 'field_abc123');
        update_post_meta($sourceId, '_edit_lock', '123:1');
        update_post_meta($sourceId, '_wp_old_slug', 'old-slug');
        update_post_meta($sourceId, '_pll_internal', 'x');
        update_post_meta($sourceId, '_wp_trash_meta_status', 'publish');

        $result = (new DuplicatePostAbility)->execute(['id' => $sourceId]);

        $this->assertSame('custom_value', get_post_meta($result['id'], 'custom_field', true));
        // Underscore meta that describes the content is copied…
        $this->assertSame('template-wide.php', get_post_meta($result['id'], '_wp_page_template', true));
        $this->assertSame('SEO title', get_post_meta($result['id'], '_yoast_wpseo_title', true));
        $this->assertSame('field_abc123', get_post_meta($result['id'], '_custom_acf_ref', true));
        // …per-post bookkeeping is not.
        foreach (['_edit_lock', '_wp_old_slug', '_pll_internal', '_wp_trash_meta_status'] as $key) {
            $this->assertSame('', get_post_meta($result['id'], $key, true), $key);
        }
    }

    public function test_copies_taxonomy_terms(): void
    {
        register_taxonomy('test_tag', 'post');
        $sourceId = self::factory()->post->create(['title' => 'Tagged']);
        wp_set_object_terms($sourceId, ['alpha', 'beta'], 'test_tag');

        $result = (new DuplicatePostAbility)->execute(['id' => $sourceId]);

        $terms = get_the_terms($result['id'], 'test_tag');
        $slugs = array_column($terms, 'slug');
        sort($slugs);
        $this->assertSame(['alpha', 'beta'], $slugs);
    }

    public function test_copies_featured_image(): void
    {
        $sourceId = self::factory()->post->create(['title' => 'With Thumb']);
        $attachmentId = self::factory()->attachment->create_upload_object(
            DIR_TESTDATA.'/images/canola.jpg',
            $sourceId
        );
        set_post_thumbnail($sourceId, $attachmentId);

        $result = (new DuplicatePostAbility)->execute(['id' => $sourceId]);

        $this->assertSame($attachmentId, (int) get_post_thumbnail_id($result['id']));
    }

    public function test_preserves_backslashes_in_content_and_meta(): void
    {
        $content = '<!-- wp:paragraph {"placeholder":"say \"hi\" \u003cb\u003e"} --><p>C:\temp</p><!-- /wp:paragraph -->';
        $json = '{"label":"a \"quoted\" \u003cword\u003e"}';
        $sourceId = self::factory()->post->create(['post_content' => wp_slash($content)]);
        update_post_meta($sourceId, 'json_field', wp_slash($json));

        $result = (new DuplicatePostAbility)->execute(['id' => $sourceId]);

        $this->assertSame($content, get_post($result['id'])->post_content);
        $this->assertSame($json, get_post_meta($result['id'], 'json_field', true));
    }

    public function test_contributor_cannot_publish_a_duplicate(): void
    {
        $contributor = self::factory()->user->create(['role' => 'contributor']);
        wp_set_current_user($contributor);
        $sourceId = self::factory()->post->create(['post_author' => $contributor, 'post_status' => 'draft']);

        foreach (['publish', 'private'] as $status) {
            $result = (new DuplicatePostAbility)->execute(['id' => $sourceId, 'status' => $status]);
            $this->assertWPError($result, $status);
            $this->assertSame('forbidden', $result->get_error_code());
        }

        $result = (new DuplicatePostAbility)->execute(['id' => $sourceId, 'status' => 'pending']);
        $this->assertSame('pending', get_post_status($result['id']));
    }

    public function test_error_for_nonexistent_post(): void
    {
        $result = (new DuplicatePostAbility)->execute(['id' => 999999]);
        $this->assertWPError($result);
        $this->assertSame('post_not_found', $result->get_error_code());
    }
}
