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
}
