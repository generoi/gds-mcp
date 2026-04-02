<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\ManageRevisionsAbility;
use GeneroWP\MCP\Tests\TestCase;

class ManageRevisionsAbilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
    }

    public function test_list_revisions(): void
    {
        $postId = $this->createPost([
            'post_status' => 'publish',
            'post_content' => 'Version 1',
        ]);

        // Create a revision by updating.
        wp_update_post([
            'ID' => $postId,
            'post_content' => 'Version 2',
        ]);

        $result = ManageRevisionsAbility::execute([
            'action' => 'list',
            'post_id' => $postId,
        ]);

        $this->assertIsArray($result);
        $this->assertSame($postId, $result['post_id']);
        $this->assertArrayHasKey('revisions', $result);
        $this->assertNotEmpty($result['revisions']);

        $rev = $result['revisions'][0];
        $this->assertArrayHasKey('id', $rev);
        $this->assertArrayHasKey('date', $rev);
        $this->assertArrayHasKey('author', $rev);
        $this->assertArrayHasKey('excerpt', $rev);
    }

    public function test_view_revision(): void
    {
        $postId = $this->createPost([
            'post_status' => 'publish',
            'post_content' => 'Original content',
        ]);

        wp_update_post([
            'ID' => $postId,
            'post_content' => 'Updated content',
        ]);

        $revisions = wp_get_post_revisions($postId);
        $revisionId = array_key_first($revisions);

        $result = ManageRevisionsAbility::execute([
            'action' => 'view',
            'revision_id' => $revisionId,
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('revision', $result);
        $this->assertSame($revisionId, $result['revision']['id']);
        $this->assertSame($postId, $result['revision']['parent_id']);
        $this->assertArrayHasKey('content', $result['revision']);
    }

    public function test_restore_revision(): void
    {
        $postId = $this->createPost([
            'post_status' => 'publish',
            'post_content' => 'Version 1',
        ]);

        // Create two revisions so we have a history.
        wp_update_post(['ID' => $postId, 'post_content' => 'Version 2']);
        wp_update_post(['ID' => $postId, 'post_content' => 'Version 3']);

        $revisions = wp_get_post_revisions($postId, ['order' => 'ASC']);
        if (count($revisions) < 2) {
            $this->markTestSkipped('WP_POST_REVISIONS may be disabled.');
        }

        // Find the revision with "Version 2".
        $targetRevision = null;
        foreach ($revisions as $rev) {
            if ($rev->post_content === 'Version 2') {
                $targetRevision = $rev;
                break;
            }
        }
        $this->assertNotNull($targetRevision, 'Should find Version 2 revision');

        $result = ManageRevisionsAbility::execute([
            'action' => 'restore',
            'revision_id' => $targetRevision->ID,
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('restored', $result);
        $this->assertSame($postId, $result['restored']['post_id']);

        // Post should now have Version 2's content.
        clean_post_cache($postId);
        $restored = get_post($postId);
        $this->assertSame('Version 2', $restored->post_content);
    }

    public function test_list_returns_error_for_missing_post(): void
    {
        $result = ManageRevisionsAbility::execute([
            'action' => 'list',
            'post_id' => 999999,
        ]);

        $this->assertWPError($result);
        $this->assertSame('post_not_found', $result->get_error_code());
    }

    public function test_view_returns_error_for_invalid_revision(): void
    {
        $result = ManageRevisionsAbility::execute([
            'action' => 'view',
            'revision_id' => 999999,
        ]);

        $this->assertWPError($result);
        $this->assertSame('revision_not_found', $result->get_error_code());
    }

    public function test_list_requires_post_id(): void
    {
        $result = ManageRevisionsAbility::execute(['action' => 'list']);

        $this->assertWPError($result);
        $this->assertSame('missing_post_id', $result->get_error_code());
    }

    public function test_permission_denied_for_guest(): void
    {
        wp_set_current_user(0);
        $result = ManageRevisionsAbility::checkPermission(['action' => 'list', 'post_id' => 1]);
        $this->assertWPError($result);
    }
}
