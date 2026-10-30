<?php

namespace GeneroWP\MCP\Tests\Unit\Integrations\Acf;

use GeneroWP\MCP\Abilities\AcfFieldsResource;
use GeneroWP\MCP\Abilities\GenericPostTypeAbility;
use WP_UnitTestCase;

/**
 * Tests ACF integration with real ACF plugin.
 */
class AcfIntegrationTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        if (! function_exists('acf_get_field_groups') || ! function_exists('acf_add_local_field_group')) {
            $this->markTestSkipped('ACF is not active.');
        }
    }

    public function test_acf_fields_resource_returns_field_groups(): void
    {
        // Register a test field group.
        acf_add_local_field_group([
            'key' => 'group_test_mcp',
            'title' => 'MCP Test Fields',
            'fields' => [
                [
                    'key' => 'field_test_text',
                    'label' => 'Test Text',
                    'name' => 'test_text',
                    'type' => 'text',
                ],
                [
                    'key' => 'field_test_select',
                    'label' => 'Test Select',
                    'name' => 'test_select',
                    'type' => 'select',
                    'choices' => ['a' => 'Option A', 'b' => 'Option B'],
                ],
            ],
            'location' => [
                [['param' => 'post_type', 'operator' => '==', 'value' => 'post']],
            ],
            'active' => true,
        ]);

        $result = (new AcfFieldsResource)->execute([]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('field_groups', $result);

        // Find our test group.
        $testGroup = null;
        foreach ($result['field_groups'] as $group) {
            if ($group['key'] === 'group_test_mcp') {
                $testGroup = $group;
                break;
            }
        }

        $this->assertNotNull($testGroup, 'Test field group should be in results');
        $this->assertSame('MCP Test Fields', $testGroup['title']);
        $this->assertContains('post', $testGroup['post_types']);
        $this->assertCount(2, $testGroup['fields']);

        // Check field structure.
        $textField = $testGroup['fields'][0];
        $this->assertSame('test_text', $textField['name']);
        $this->assertSame('text', $textField['type']);
        $this->assertSame('Test Text', $textField['label']);

        $selectField = $testGroup['fields'][1];
        $this->assertSame('select', $selectField['type']);
        $this->assertArrayHasKey('choices', $selectField);
    }

    public function test_read_post_includes_acf_fields(): void
    {
        // Register a test field group for posts.
        acf_add_local_field_group([
            'key' => 'group_test_read',
            'title' => 'Read Test',
            'fields' => [
                [
                    'key' => 'field_read_test',
                    'label' => 'Read Test Field',
                    'name' => 'read_test_field',
                    'type' => 'text',
                ],
            ],
            'location' => [
                [['param' => 'post_type', 'operator' => '==', 'value' => 'post']],
            ],
            'active' => true,
        ]);

        $postId = self::factory()->post->create(['post_status' => 'publish']);
        update_field('read_test_field', 'hello from acf', $postId);

        $result = (new GenericPostTypeAbility)->executeRead(['type' => 'posts', 'id' => $postId]);

        $this->assertIsArray($result);
        // ACF fields are exposed via the REST API's acf field
        $this->assertArrayHasKey('acf', $result);
    }

    public function test_update_acf_fields_via_update_field(): void
    {
        acf_add_local_field_group([
            'key' => 'group_test_update',
            'title' => 'Update Test',
            'fields' => [
                [
                    'key' => 'field_update_test',
                    'label' => 'Update Field',
                    'name' => 'update_test_field',
                    'type' => 'text',
                ],
            ],
            'location' => [
                [['param' => 'post_type', 'operator' => '==', 'value' => 'post']],
            ],
            'active' => true,
        ]);

        $postId = self::factory()->post->create(['post_status' => 'publish']);

        // Update via ACF API (as our AcfAware trait does).
        update_field('update_test_field', 'updated value', $postId);

        // Verify via get_field.
        $this->assertSame('updated value', get_field('update_test_field', $postId));
    }

    public function test_content_update_reports_fields_it_did_not_save(): void
    {
        acf_add_local_field_group([
            'key' => 'group_test_report',
            'title' => 'Report Test',
            'fields' => [['key' => 'field_report_test', 'label' => 'Report', 'name' => 'report_test_field', 'type' => 'text']],
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
            'active' => true,
        ]);
        $postId = self::factory()->post->create(['post_status' => 'publish']);

        $result = (new GenericPostTypeAbility)->executeUpdate([
            'type' => 'posts',
            'id' => $postId,
            'fields' => ['report_test_field' => 'saved', 'not_a_field' => 'x'],
        ]);

        $this->assertSame('saved', get_field('report_test_field', $postId));
        $this->assertSame(['not_a_field'], array_keys($result['fields_errors']));
        $this->assertSame('', get_post_meta($postId, 'not_a_field', true));
    }

    public function test_content_update_reports_a_refused_write_and_keeps_backslashes(): void
    {
        acf_add_local_field_group([
            'key' => 'group_test_refused',
            'title' => 'Refused Test',
            'fields' => [
                ['key' => 'field_refused', 'label' => 'Refused', 'name' => 'refused_field', 'type' => 'text'],
                ['key' => 'field_path', 'label' => 'Path', 'name' => 'path_field', 'type' => 'text'],
                ['key' => 'field_box', 'label' => 'Box', 'name' => 'box_field', 'type' => 'group', 'sub_fields' => [
                    ['key' => 'field_box_inner', 'label' => 'Inner', 'name' => 'inner', 'type' => 'text'],
                ]],
            ],
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
            'active' => true,
        ]);
        $postId = self::factory()->post->create(['post_status' => 'publish']);
        update_field('field_box', ['field_box_inner' => 'same'], $postId);
        // Something refuses this one write.
        add_filter('acf/pre_update_value', fn ($check, $value, $id, $field) => $field['key'] === 'field_refused' ? false : $check, 10, 4);

        $result = (new GenericPostTypeAbility)->executeUpdate([
            'type' => 'posts',
            'id' => $postId,
            'fields' => ['refused_field' => 'nope', 'path_field' => 'C:\Temp', 'box_field' => ['inner' => 'same']],
        ]);

        $this->assertSame(['refused_field'], array_keys($result['fields_errors'] ?? []), 'Only the refused plain field is reported, not the unchanged group.');
        $this->assertSame('C:\Temp', get_field('path_field', $postId));
    }

    public function test_content_update_filters_html_for_users_without_unfiltered_html(): void
    {
        acf_add_local_field_group([
            'key' => 'group_test_kses',
            'title' => 'Kses Test',
            'fields' => [['key' => 'field_kses', 'label' => 'Kses', 'name' => 'kses_field', 'type' => 'wysiwyg']],
            'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
            'active' => true,
        ]);
        $author = self::factory()->user->create(['role' => 'author']);
        wp_set_current_user($author);
        $this->assertFalse(current_user_can('unfiltered_html'));
        $postId = self::factory()->post->create(['post_status' => 'publish', 'post_author' => $author]);

        (new GenericPostTypeAbility)->executeUpdate([
            'type' => 'posts',
            'id' => $postId,
            'fields' => ['kses_field' => '<p>Hi</p><script>alert(1)</script>'],
        ]);

        $this->assertSame('<p>Hi</p>alert(1)', get_post_meta($postId, 'kses_field', true));

        // null still deletes the value.
        (new GenericPostTypeAbility)->executeUpdate(['type' => 'posts', 'id' => $postId, 'fields' => ['kses_field' => null]]);

        $this->assertSame([], get_post_meta($postId, 'kses_field'));
    }
}
