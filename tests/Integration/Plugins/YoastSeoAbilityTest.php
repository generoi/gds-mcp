<?php

namespace GeneroWP\MCP\Tests\Integration\Plugins;

use GeneroWP\MCP\Tests\AbilityTestCase;

/**
 * Integration tests for gds/seo-read and gds/seo-update through the Abilities API.
 */
class YoastSeoAbilityTest extends AbilityTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! defined('WPSEO_VERSION')) {
            $this->markTestSkipped('Yoast SEO is not active.');
        }
    }

    private function undo(array $result): mixed
    {
        $undo = $result['_undo'] ?? null;
        $this->assertIsArray($undo, 'Ability result must carry an _undo envelope.');

        return apply_filters('gds-mcp/restore_snapshot', null, ['kind' => $undo['kind'], 'data' => $undo['data']]);
    }

    public function test_abilities_registered(): void
    {
        $this->assertAbilityRegistered('gds/seo-read');
        $this->assertAbilityRegistered('gds/seo-update');
    }

    public function test_update_and_read_post_seo_and_social_fields(): void
    {
        $postId = self::factory()->post->create(['post_title' => 'SEO post']);
        $imageId = self::factory()->attachment->create_upload_object(DIR_TESTDATA.'/images/canola.jpg', $postId);

        $result = $this->assertAbilitySuccess('gds/seo-update', [
            'object_type' => 'post',
            'id' => $postId,
            'fields' => [
                'title' => 'Custom %%sep%% title',
                'description' => 'A meta description.',
                'focus_keyphrase' => 'seo',
                'robots_index' => 'noindex',
                'opengraph_title' => 'Share title',
                'opengraph_description' => 'Share description',
                'opengraph_image_id' => $imageId,
            ],
        ]);

        $this->assertSame('Custom %%sep%% title', $result['fields']['title']);
        $this->assertSame('noindex', $result['fields']['robots_index']);
        $this->assertSame($imageId, $result['fields']['opengraph_image_id']);
        $this->assertSame('1', get_post_meta($postId, '_yoast_wpseo_meta-robots-noindex', true));
        $this->assertSame(wp_get_attachment_url($imageId), get_post_meta($postId, '_yoast_wpseo_opengraph-image', true));

        $read = $this->assertAbilitySuccess('gds/seo-read', ['object_type' => 'post', 'id' => $postId]);
        $this->assertSame('Share title', $read['fields']['opengraph_title']);
        $this->assertSame('A meta description.', $read['fields']['description']);
        $this->assertArrayHasKey('resolved', $read);
    }

    public function test_empty_value_clears_a_post_field(): void
    {
        $postId = self::factory()->post->create();
        update_post_meta($postId, '_yoast_wpseo_metadesc', 'Old description');

        $this->assertAbilitySuccess('gds/seo-update', [
            'object_type' => 'post',
            'id' => $postId,
            'fields' => ['description' => ''],
        ]);

        $this->assertSame([], get_post_meta($postId, '_yoast_wpseo_metadesc'));
    }

    public function test_post_update_is_undoable(): void
    {
        $postId = self::factory()->post->create();
        update_post_meta($postId, '_yoast_wpseo_title', 'Before');

        $result = $this->assertAbilitySuccess('gds/seo-update', [
            'object_type' => 'post',
            'id' => $postId,
            'fields' => ['title' => 'After', 'twitter_title' => 'X title'],
        ]);
        $this->assertSame('After', get_post_meta($postId, '_yoast_wpseo_title', true));

        $this->assertIsArray($this->undo($result));
        $this->assertSame('Before', get_post_meta($postId, '_yoast_wpseo_title', true));
        $this->assertSame([], get_post_meta($postId, '_yoast_wpseo_twitter-title'));
    }

    public function test_primary_category_must_be_assigned(): void
    {
        $assigned = self::factory()->category->create();
        $other = self::factory()->category->create();
        $postId = self::factory()->post->create(['post_category' => [$assigned]]);

        $this->assertAbilityError('gds/seo-update', [
            'object_type' => 'post',
            'id' => $postId,
            'fields' => ['primary_category' => $other],
        ], 'invalid_primary_category');

        $result = $this->assertAbilitySuccess('gds/seo-update', [
            'object_type' => 'post',
            'id' => $postId,
            'fields' => ['primary_category' => $assigned],
        ]);
        $this->assertSame($assigned, $result['fields']['primary_category']);
    }

    public function test_update_read_and_undo_term_seo(): void
    {
        $termId = self::factory()->category->create(['name' => 'SEO category']);

        $result = $this->assertAbilitySuccess('gds/seo-update', [
            'object_type' => 'term',
            'id' => $termId,
            'fields' => [
                'title' => 'Category SEO title',
                'description' => 'Category description',
                'robots_index' => 'noindex',
                'twitter_description' => 'X description',
            ],
        ]);

        $this->assertSame('category', $result['taxonomy']);
        $this->assertSame('Category SEO title', $result['fields']['title']);
        $this->assertSame('noindex', $result['fields']['robots_index']);

        $stored = \WPSEO_Taxonomy_Meta::get_term_meta($termId, 'category');
        $this->assertSame('Category description', $stored['wpseo_desc']);

        $read = $this->assertAbilitySuccess('gds/seo-read', ['object_type' => 'term', 'id' => $termId]);
        $this->assertSame('X description', $read['fields']['twitter_description']);

        $this->assertIsArray($this->undo($result));
        $this->assertSame('', (string) \WPSEO_Taxonomy_Meta::get_term_meta($termId, 'category', 'title'));
    }

    public function test_rejects_fields_that_do_not_exist_for_terms(): void
    {
        $termId = self::factory()->category->create();

        $this->assertAbilityError('gds/seo-update', [
            'object_type' => 'term',
            'id' => $termId,
            'fields' => ['primary_category' => 1],
        ], 'invalid_field');
    }

    public function test_requires_edit_permission(): void
    {
        $postId = self::factory()->post->create();
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        $this->assertAbilityError('gds/seo-update', [
            'object_type' => 'post',
            'id' => $postId,
            'fields' => ['title' => 'Nope'],
        ]);
    }

    public function test_keeps_yoast_variables_intact(): void
    {
        $postId = self::factory()->post->create();

        $result = $this->assertAbilitySuccess('gds/seo-update', [
            'object_type' => 'post',
            'id' => $postId,
            'fields' => ['title' => '%%category%% %%sep%% %%date%% %%cf_price%%'],
        ]);

        $this->assertSame('%%category%% %%sep%% %%date%% %%cf_price%%', $result['fields']['title']);
    }

    public function test_resolved_reflects_the_write(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        $this->assertAbilitySuccess('gds/seo-read', ['object_type' => 'post', 'id' => $postId]);

        $result = $this->assertAbilitySuccess('gds/seo-update', [
            'object_type' => 'post',
            'id' => $postId,
            'fields' => ['title' => 'Fresh resolved title'],
        ]);

        $this->assertSame('Fresh resolved title', $result['resolved']['title'] ?? null);
    }

    public function test_admin_can_set_advanced_fields_when_advanced_meta_is_disabled(): void
    {
        \WPSEO_Options::set('disableadvanced_meta', true);
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $postId = self::factory()->post->create();

        $result = $this->assertAbilitySuccess('gds/seo-update', [
            'object_type' => 'post',
            'id' => $postId,
            'fields' => ['robots_index' => 'noindex', 'canonical' => 'https://example.com/canonical/'],
        ]);

        $this->assertSame('noindex', $result['fields']['robots_index']);
    }

    public function test_author_cannot_set_advanced_fields_when_advanced_meta_is_disabled(): void
    {
        \WPSEO_Options::set('disableadvanced_meta', true);
        $authorId = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($authorId);
        $postId = self::factory()->post->create(['post_author' => $authorId]);

        $this->assertAbilityError('gds/seo-update', [
            'object_type' => 'post',
            'id' => $postId,
            'fields' => ['robots_index' => 'noindex'],
        ], 'forbidden');
    }

    public function test_undo_does_not_revert_post_status(): void
    {
        $postId = self::factory()->post->create(['post_status' => 'draft']);

        $result = $this->assertAbilitySuccess('gds/seo-update', [
            'object_type' => 'post',
            'id' => $postId,
            'fields' => ['title' => 'Draft SEO'],
        ]);
        wp_publish_post($postId);

        $this->assertIsArray($this->undo($result));
        $this->assertSame('publish', get_post_status($postId));
        $this->assertSame([], get_post_meta($postId, '_yoast_wpseo_title'));
    }

    public function test_term_undo_keeps_later_changes_to_other_fields(): void
    {
        $termId = self::factory()->category->create();

        $first = $this->assertAbilitySuccess('gds/seo-update', [
            'object_type' => 'term',
            'id' => $termId,
            'fields' => ['title' => 'First title'],
        ]);
        $this->assertAbilitySuccess('gds/seo-update', [
            'object_type' => 'term',
            'id' => $termId,
            'fields' => ['opengraph_title' => 'Later share title'],
        ]);

        $this->assertIsArray($this->undo($first));
        $this->assertSame('', (string) \WPSEO_Taxonomy_Meta::get_term_meta($termId, 'category', 'title'));
        $this->assertSame('Later share title', \WPSEO_Taxonomy_Meta::get_term_meta($termId, 'category', 'opengraph-title'));
    }

    public function test_rejects_non_http_canonical(): void
    {
        $postId = self::factory()->post->create();
        update_post_meta($postId, '_yoast_wpseo_canonical', 'https://example.com/keep/');

        $this->assertAbilityError('gds/seo-update', [
            'object_type' => 'post',
            'id' => $postId,
            'fields' => ['canonical' => 'javascript://x'],
        ], 'invalid_canonical');
        $this->assertSame('https://example.com/keep/', get_post_meta($postId, '_yoast_wpseo_canonical', true));
    }
}
