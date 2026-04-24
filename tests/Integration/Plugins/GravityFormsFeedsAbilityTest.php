<?php

namespace GeneroWP\MCP\Tests\Integration\Plugins;

use GeneroWP\MCP\Tests\AbilityTestCase;

/**
 * Integration tests for gds/feeds-* abilities.
 *
 * Feeds are stored in {$wpdb->prefix}gf_addon_feed and don't require the
 * addon class to be loaded for CRUD — encryption is skipped for unknown
 * addon slugs. Tests use a fake 'test-addon' slug throughout.
 */
class GravityFormsFeedsAbilityTest extends AbilityTestCase
{
    /** @var int[] Form IDs created during the test, deleted in tearDown */
    private array $createdFormIds = [];

    /** @var int[] Feed IDs created during the test, deleted in tearDown */
    private array $createdFeedIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists('GFAPI')) {
            $this->markTestSkipped('Gravity Forms is not active.');
        }

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFeedIds as $id) {
            \GFAPI::delete_feed($id);
        }
        foreach ($this->createdFormIds as $id) {
            \GFAPI::delete_form($id);
        }
        $this->createdFeedIds = [];
        $this->createdFormIds = [];

        parent::tearDown();
    }

    private function createForm(string $title = 'Feed Test Form'): int
    {
        $formId = \GFAPI::add_form([
            'title' => $title.' '.uniqid(),
            'fields' => [
                ['id' => 1, 'type' => 'email', 'label' => 'Email'],
            ],
        ]);
        $this->assertIsInt($formId, 'Failed to create form for feed test.');
        $this->createdFormIds[] = $formId;

        return $formId;
    }

    private function trackFeed(int $id): int
    {
        $this->createdFeedIds[] = $id;

        return $id;
    }

    public function test_abilities_registered(): void
    {
        $this->assertAbilityRegistered('gds/feeds-list');
        $this->assertAbilityRegistered('gds/feeds-read');
        $this->assertAbilityRegistered('gds/feeds-create');
        $this->assertAbilityRegistered('gds/feeds-update');
        $this->assertAbilityRegistered('gds/feeds-delete');
        $this->assertAbilityRegistered('gds/feeds-duplicate');
    }

    public function test_feeds_create_persists_feed(): void
    {
        $formId = $this->createForm();

        $result = $this->assertAbilitySuccess('gds/feeds-create', [
            'form_id' => $formId,
            'addon_slug' => 'test-addon',
            'meta' => [
                'feed_name' => 'My feed',
                'list_id' => 42,
            ],
        ]);
        $this->trackFeed((int) $result['id']);

        $this->assertSame($formId, (int) $result['form_id']);
        $this->assertSame('test-addon', $result['addon_slug']);
        $this->assertSame('My feed', $result['meta']['feed_name']);
        $this->assertSame(42, (int) $result['meta']['list_id']);
    }

    public function test_feeds_create_honors_is_active_flag(): void
    {
        $formId = $this->createForm();

        $result = $this->assertAbilitySuccess('gds/feeds-create', [
            'form_id' => $formId,
            'addon_slug' => 'test-addon',
            'meta' => ['feed_name' => 'Disabled'],
            'is_active' => false,
        ]);
        $this->trackFeed((int) $result['id']);

        $this->assertSame(0, (int) $result['is_active']);
    }

    public function test_feeds_read_returns_created_feed(): void
    {
        $formId = $this->createForm();
        $created = $this->assertAbilitySuccess('gds/feeds-create', [
            'form_id' => $formId,
            'addon_slug' => 'test-addon',
            'meta' => ['feed_name' => 'Readable'],
        ]);
        $id = $this->trackFeed((int) $created['id']);

        $result = $this->assertAbilitySuccess('gds/feeds-read', ['id' => $id]);
        $this->assertSame($id, (int) $result['id']);
        $this->assertSame('Readable', $result['meta']['feed_name']);
    }

    public function test_feeds_list_filters_by_form(): void
    {
        // Two forms, one feed each. Listing by form_id A must only return A's feed.
        $formA = $this->createForm('Form A');
        $formB = $this->createForm('Form B');

        $feedA = $this->trackFeed((int) $this->assertAbilitySuccess('gds/feeds-create', [
            'form_id' => $formA,
            'addon_slug' => 'test-addon',
            'meta' => ['feed_name' => 'A feed'],
        ])['id']);
        $feedB = $this->trackFeed((int) $this->assertAbilitySuccess('gds/feeds-create', [
            'form_id' => $formB,
            'addon_slug' => 'test-addon',
            'meta' => ['feed_name' => 'B feed'],
        ])['id']);

        $listed = $this->assertAbilitySuccess('gds/feeds-list', ['form_id' => $formA]);
        $ids = array_column($listed, 'id');
        $this->assertContains((string) $feedA, array_map('strval', $ids));
        $this->assertNotContains((string) $feedB, array_map('strval', $ids));
    }

    public function test_feeds_list_includes_inactive_by_default(): void
    {
        // GFAPI::get_feeds defaults is_active=true; the ability must override
        // that so "list all feeds" returns both active and inactive.
        $formId = $this->createForm();

        $active = $this->trackFeed((int) $this->assertAbilitySuccess('gds/feeds-create', [
            'form_id' => $formId,
            'addon_slug' => 'test-addon',
            'meta' => ['feed_name' => 'active'],
        ])['id']);
        $inactive = $this->trackFeed((int) $this->assertAbilitySuccess('gds/feeds-create', [
            'form_id' => $formId,
            'addon_slug' => 'test-addon',
            'meta' => ['feed_name' => 'inactive'],
            'is_active' => false,
        ])['id']);

        $listed = $this->assertAbilitySuccess('gds/feeds-list', ['form_id' => $formId]);
        $ids = array_map('intval', array_column($listed, 'id'));
        $this->assertContains($active, $ids);
        $this->assertContains($inactive, $ids, 'list should include inactive feeds when is_active is omitted');
    }

    public function test_feeds_list_returns_empty_array_when_no_feeds(): void
    {
        // Regression: GFAPI::get_feeds returns WP_Error('not_found') when nothing
        // matches. The ability must translate that into an empty array so
        // callers can `foreach` without a type check.
        $formId = $this->createForm();

        $listed = $this->assertAbilitySuccess('gds/feeds-list', ['form_id' => $formId]);
        $this->assertSame([], $listed);
    }

    public function test_feeds_update_replaces_meta(): void
    {
        $formId = $this->createForm();
        $created = $this->assertAbilitySuccess('gds/feeds-create', [
            'form_id' => $formId,
            'addon_slug' => 'test-addon',
            'meta' => ['feed_name' => 'Original', 'list_id' => 1],
        ]);
        $id = $this->trackFeed((int) $created['id']);

        $updated = $this->assertAbilitySuccess('gds/feeds-update', [
            'id' => $id,
            'meta' => ['feed_name' => 'Renamed', 'list_id' => 99],
        ]);

        $this->assertSame('Renamed', $updated['meta']['feed_name']);
        $this->assertSame(99, (int) $updated['meta']['list_id']);
    }

    public function test_feeds_update_toggles_is_active(): void
    {
        $formId = $this->createForm();
        $created = $this->assertAbilitySuccess('gds/feeds-create', [
            'form_id' => $formId,
            'addon_slug' => 'test-addon',
            'meta' => ['feed_name' => 'Toggle me'],
        ]);
        $id = $this->trackFeed((int) $created['id']);
        $this->assertSame(1, (int) $created['is_active']);

        $disabled = $this->assertAbilitySuccess('gds/feeds-update', [
            'id' => $id,
            'is_active' => false,
        ]);
        $this->assertSame(0, (int) $disabled['is_active']);
        $this->assertSame('Toggle me', $disabled['meta']['feed_name'], 'meta must survive an is_active-only update');

        $enabled = $this->assertAbilitySuccess('gds/feeds-update', [
            'id' => $id,
            'is_active' => true,
        ]);
        $this->assertSame(1, (int) $enabled['is_active']);
    }

    public function test_feeds_update_moves_feed_to_different_form(): void
    {
        $formA = $this->createForm('Move From');
        $formB = $this->createForm('Move To');

        $created = $this->assertAbilitySuccess('gds/feeds-create', [
            'form_id' => $formA,
            'addon_slug' => 'test-addon',
            'meta' => ['feed_name' => 'Mover'],
        ]);
        $id = $this->trackFeed((int) $created['id']);

        $moved = $this->assertAbilitySuccess('gds/feeds-update', [
            'id' => $id,
            'form_id' => $formB,
        ]);

        $this->assertSame($formB, (int) $moved['form_id']);
        $this->assertSame('Mover', $moved['meta']['feed_name'], 'moving form must not wipe meta');
    }

    public function test_feeds_delete_removes_feed(): void
    {
        $formId = $this->createForm();
        $created = $this->assertAbilitySuccess('gds/feeds-create', [
            'form_id' => $formId,
            'addon_slug' => 'test-addon',
            'meta' => ['feed_name' => 'Doomed'],
        ]);
        $id = (int) $created['id'];

        $result = $this->assertAbilitySuccess('gds/feeds-delete', ['id' => $id]);
        $this->assertTrue($result['deleted']);

        // Confirm it's actually gone.
        $after = \GFAPI::get_feed($id);
        $this->assertTrue(is_wp_error($after), 'feed should no longer exist after delete');
    }

    public function test_feeds_duplicate_clones_onto_target_forms(): void
    {
        // The core use case: duplicate an ActiveCampaign feed across three
        // region-specific forms. Meta and addon_slug must carry over; each
        // copy gets its own id and form_id.
        $sourceForm = $this->createForm('Source Form');
        $targetA = $this->createForm('Target A');
        $targetB = $this->createForm('Target B');

        $source = $this->assertAbilitySuccess('gds/feeds-create', [
            'form_id' => $sourceForm,
            'addon_slug' => 'test-addon',
            'meta' => [
                'feed_name' => 'Clone me',
                'list_id' => 123,
                'mappedFields' => ['first_name' => '1.3', 'email' => '2'],
            ],
        ]);
        $sourceId = $this->trackFeed((int) $source['id']);

        $result = $this->assertAbilitySuccess('gds/feeds-duplicate', [
            'id' => $sourceId,
            'target_form_ids' => [$targetA, $targetB],
        ]);

        $this->assertSame($sourceId, (int) $result['source_id']);
        $this->assertCount(2, $result['created']);
        foreach ($result['created'] as $clone) {
            $this->trackFeed((int) $clone['id']);
        }

        // First clone landed on targetA.
        $this->assertSame($targetA, (int) $result['created'][0]['form_id']);
        $this->assertSame('Clone me', $result['created'][0]['meta']['feed_name']);
        $this->assertSame(123, (int) $result['created'][0]['meta']['list_id']);
        $this->assertSame('test-addon', $result['created'][0]['addon_slug']);

        // Second clone on targetB.
        $this->assertSame($targetB, (int) $result['created'][1]['form_id']);
        $this->assertSame('Clone me', $result['created'][1]['meta']['feed_name']);

        // Source + clones all have distinct ids.
        $allIds = array_merge(
            [$sourceId],
            array_map(fn ($c) => (int) $c['id'], $result['created']),
        );
        $this->assertCount(3, array_unique($allIds));

        // Source feed itself is untouched.
        $sourceAfter = \GFAPI::get_feed($sourceId);
        $this->assertNotTrue(is_wp_error($sourceAfter));
        $this->assertSame($sourceForm, (int) $sourceAfter['form_id']);
    }

    public function test_feeds_duplicate_preserves_is_active_false(): void
    {
        // If the source feed is disabled, clones should also land disabled
        // so they don't silently start firing on new forms.
        $sourceForm = $this->createForm();
        $target = $this->createForm();

        $source = $this->assertAbilitySuccess('gds/feeds-create', [
            'form_id' => $sourceForm,
            'addon_slug' => 'test-addon',
            'meta' => ['feed_name' => 'Disabled source'],
            'is_active' => false,
        ]);
        $sourceId = $this->trackFeed((int) $source['id']);

        $result = $this->assertAbilitySuccess('gds/feeds-duplicate', [
            'id' => $sourceId,
            'target_form_ids' => [$target],
        ]);
        $this->trackFeed((int) $result['created'][0]['id']);

        $this->assertSame(0, (int) $result['created'][0]['is_active']);
    }

    public function test_feeds_update_requires_id(): void
    {
        $this->assertAbilityError('gds/feeds-update', [
            'meta' => ['feed_name' => 'No id'],
        ]);
    }

    public function test_feeds_update_rejects_unknown_feed(): void
    {
        $this->assertAbilityError('gds/feeds-update', [
            'id' => 99999999,
            'meta' => ['feed_name' => 'ghost'],
        ]);
    }

    public function test_feeds_read_rejects_unknown_feed(): void
    {
        $this->assertAbilityError('gds/feeds-read', ['id' => 99999999]);
    }

    public function test_feeds_delete_rejects_unknown_feed(): void
    {
        $this->assertAbilityError('gds/feeds-delete', ['id' => 99999999]);
    }

    public function test_feeds_create_rejects_unknown_form(): void
    {
        $this->assertAbilityError('gds/feeds-create', [
            'form_id' => 99999999,
            'addon_slug' => 'test-addon',
            'meta' => ['feed_name' => 'orphan'],
        ]);
    }
}
