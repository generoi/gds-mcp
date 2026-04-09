<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use GeneroWP\MCP\Abilities\ManageRevisionsAbility;
use GeneroWP\MCP\Tests\TestCase;

class ManageRevisionsAbilityTest extends TestCase
{
    private ManageRevisionsAbility $ability;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));
        $this->ability = ManageRevisionsAbility::instance();
    }

    public function test_list_revisions(): void
    {
        $postId = $this->createPost(['post_status' => 'publish', 'post_content' => 'V1']);
        wp_update_post(['ID' => $postId, 'post_content' => 'V2']);

        $result = $this->ability->listRevisions(['post_id' => $postId]);

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertArrayHasKey('id', $result[0]);
    }

    public function test_read_revision(): void
    {
        $postId = $this->createPost(['post_status' => 'publish', 'post_content' => 'Original']);
        wp_update_post(['ID' => $postId, 'post_content' => 'Updated']);

        $revisions = wp_get_post_revisions($postId);
        $revisionId = array_key_first($revisions);

        $result = $this->ability->readRevision(['post_id' => $postId, 'id' => $revisionId]);

        $this->assertIsArray($result);
        $this->assertSame($revisionId, $result['id']);
    }

    public function test_restore_revision(): void
    {
        $postId = $this->createPost(['post_status' => 'publish', 'post_content' => 'V1']);
        wp_update_post(['ID' => $postId, 'post_content' => 'V2']);
        wp_update_post(['ID' => $postId, 'post_content' => 'V3']);

        $revisions = wp_get_post_revisions($postId, ['order' => 'ASC']);
        if (count($revisions) < 2) {
            $this->markTestSkipped('WP_POST_REVISIONS may be disabled.');
        }

        $targetRevision = null;
        foreach ($revisions as $rev) {
            if ($rev->post_content === 'V2') {
                $targetRevision = $rev;
                break;
            }
        }
        $this->assertNotNull($targetRevision);

        $result = $this->ability->restoreRevision(['id' => $targetRevision->ID]);

        $this->assertIsArray($result);

        clean_post_cache($postId);
        $this->assertSame('V2', get_post($postId)->post_content);
    }

    public function test_list_error_for_missing_post(): void
    {
        $result = $this->ability->listRevisions(['post_id' => 999999]);
        $this->assertWPError($result);
    }

    public function test_restore_error_for_missing_revision(): void
    {
        $result = $this->ability->restoreRevision(['id' => 999999]);
        $this->assertWPError($result);
    }
}
