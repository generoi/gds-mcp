<?php

namespace GeneroWP\MCP\Tests\Integration\Plugins;

use GeneroWP\MCP\Tests\AbilityTestCase;

/**
 * Integration tests for gds/forms-* abilities through the Abilities API.
 *
 * Requires Gravity Forms to be active. Each test creates its own form(s)
 * and cleans them up in tearDown() to avoid state leakage.
 */
class GravityFormsAbilityTest extends AbilityTestCase
{
    /** @var int[] Form IDs created during the test, deleted in tearDown */
    private array $createdFormIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists('GFAPI')) {
            $this->markTestSkipped('Gravity Forms is not active.');
        }

        // GF abilities need admin privileges.
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFormIds as $id) {
            \GFAPI::delete_form($id);
        }
        $this->createdFormIds = [];

        parent::tearDown();
    }

    private function trackForm(int $id): int
    {
        $this->createdFormIds[] = $id;

        return $id;
    }

    public function test_abilities_registered(): void
    {
        $this->assertAbilityRegistered('gds/forms-list');
        $this->assertAbilityRegistered('gds/forms-read');
        $this->assertAbilityRegistered('gds/forms-create');
        $this->assertAbilityRegistered('gds/forms-update');
        $this->assertAbilityRegistered('gds/forms-entries');
    }

    public function test_forms_create_returns_saved_form(): void
    {
        $result = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'Test Create Form '.uniqid(),
            'fields' => [
                ['type' => 'email', 'label' => 'Email', 'isRequired' => true],
                ['type' => 'text', 'label' => 'Company', 'isRequired' => true],
            ],
        ]);

        $this->assertArrayHasKey('id', $result, 'forms-create should return the saved form with an id.');
        $this->trackForm((int) $result['id']);

        $this->assertStringStartsWith('Test Create Form', $result['title'] ?? '');
        $this->assertIsArray($result['fields'] ?? null);
        $this->assertCount(2, $result['fields'], 'Created form should have exactly the 2 fields we sent.');

        $labels = array_column($result['fields'], 'label');
        $this->assertContains('Email', $labels);
        $this->assertContains('Company', $labels);
    }

    public function test_forms_create_persists_to_database(): void
    {
        $result = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'Persistence Check '.uniqid(),
            'fields' => [
                ['type' => 'email', 'label' => 'Email'],
            ],
        ]);

        $id = (int) $result['id'];
        $this->trackForm($id);

        // Fetch fresh from DB via GFAPI to verify the form actually persisted.
        $fromDb = \GFAPI::get_form($id);
        $this->assertNotFalse($fromDb);
        $this->assertSame($result['title'], $fromDb['title']);
        $this->assertCount(1, $fromDb['fields']);
    }

    public function test_forms_update_fields_is_full_replacement(): void
    {
        // Create a form with 4 fields.
        $created = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'Replace Test '.uniqid(),
            'fields' => [
                ['id' => 1, 'type' => 'email', 'label' => 'Email'],
                ['id' => 2, 'type' => 'text', 'label' => 'Company'],
                ['id' => 3, 'type' => 'text', 'label' => 'Job title'],
                ['id' => 4, 'type' => 'phone', 'label' => 'Phone'],
            ],
        ]);
        $id = $this->trackForm((int) $created['id']);
        $this->assertCount(4, $created['fields']);

        // Update with a smaller fields array — the 2 entries we omit must NOT linger.
        $updated = $this->assertAbilitySuccess('gds/forms-update', [
            'id' => $id,
            'fields' => [
                ['id' => 1, 'type' => 'email', 'label' => 'Email'],
                ['id' => 2, 'type' => 'text', 'label' => 'Company'],
            ],
        ]);

        $this->assertCount(
            2,
            $updated['fields'] ?? [],
            'forms-update should replace the fields array wholesale, not merge. '.
            'Found '.count($updated['fields'] ?? []).' fields after supplying only 2.',
        );

        $labels = array_column($updated['fields'], 'label');
        $this->assertSame(['Email', 'Company'], $labels);
    }

    public function test_forms_update_preserves_unchanged_keys(): void
    {
        // Create with title + fields, no description.
        $created = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'Preserve Test '.uniqid(),
            'description' => 'Original description',
            'fields' => [
                ['type' => 'email', 'label' => 'Email'],
                ['type' => 'text', 'label' => 'Company'],
            ],
        ]);
        $id = $this->trackForm((int) $created['id']);

        // Update only the title — fields and description must survive untouched.
        $updated = $this->assertAbilitySuccess('gds/forms-update', [
            'id' => $id,
            'title' => 'Updated Title',
        ]);

        $this->assertSame('Updated Title', $updated['title']);
        $this->assertSame('Original description', $updated['description']);
        $this->assertCount(2, $updated['fields']);
    }

    public function test_forms_update_field_label_change(): void
    {
        $created = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'Relabel Test '.uniqid(),
            'fields' => [
                ['id' => 1, 'type' => 'email', 'label' => 'Email'],
                ['id' => 2, 'type' => 'text', 'label' => 'Company'],
            ],
        ]);
        $id = $this->trackForm((int) $created['id']);

        // Supply full fields array with translated labels.
        $updated = $this->assertAbilitySuccess('gds/forms-update', [
            'id' => $id,
            'fields' => [
                ['id' => 1, 'type' => 'email', 'label' => 'Sähköposti', 'isRequired' => true],
                ['id' => 2, 'type' => 'text', 'label' => 'Yritys', 'isRequired' => true],
            ],
        ]);

        $labels = array_column($updated['fields'], 'label');
        $this->assertSame(['Sähköposti', 'Yritys'], $labels);
        $this->assertCount(2, $updated['fields']);
    }

    public function test_forms_update_confirmation_message(): void
    {
        // Edit an existing confirmation's message while keeping its id
        // (the real-world editing workflow: read, change a field, save).
        $created = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'Confirmation Message Edit '.uniqid(),
            'fields' => [['type' => 'email', 'label' => 'Email']],
            'confirmations' => [
                ['name' => 'Main', 'message' => 'Original message', 'isDefault' => true, 'type' => 'message'],
            ],
        ]);
        $id = $this->trackForm((int) $created['id']);

        $this->assertCount(1, $created['confirmations']);
        $confirmation = reset($created['confirmations']);
        $confirmationId = $confirmation['id'];

        $updated = $this->assertAbilitySuccess('gds/forms-update', [
            'id' => $id,
            'confirmations' => [
                [
                    'id' => $confirmationId,
                    'name' => 'Main',
                    'message' => 'Updated Jakobstad message',
                    'isDefault' => true,
                    'type' => 'message',
                ],
            ],
        ]);

        $this->assertCount(1, $updated['confirmations']);
        $this->assertArrayHasKey($confirmationId, $updated['confirmations']);
        $this->assertSame('Updated Jakobstad message', $updated['confirmations'][$confirmationId]['message']);
    }

    public function test_forms_update_notification_email(): void
    {
        // Change an existing notification's "to" address — the same edit
        // pattern a user doing form setup makes in the admin. Notification
        // id must survive so any webhooks/ESP bindings stay intact.
        $created = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'Notification Email Edit '.uniqid(),
            'fields' => [['type' => 'email', 'label' => 'Email']],
            'notifications' => [
                ['name' => 'Admin alert', 'to' => 'old@example.com', 'subject' => 'New entry'],
            ],
        ]);
        $id = $this->trackForm((int) $created['id']);

        $notification = reset($created['notifications']);
        $notificationId = $notification['id'];
        $this->assertSame('old@example.com', $notification['to']);

        $updated = $this->assertAbilitySuccess('gds/forms-update', [
            'id' => $id,
            'notifications' => [
                [
                    'id' => $notificationId,
                    'name' => 'Admin alert',
                    'to' => 'new@example.com',
                    'subject' => 'New entry',
                ],
            ],
        ]);

        $this->assertCount(1, $updated['notifications']);
        $this->assertArrayHasKey($notificationId, $updated['notifications']);
        $this->assertSame('new@example.com', $updated['notifications'][$notificationId]['to']);
    }

    public function test_forms_update_creates_new_notification(): void
    {
        // Start with one notification, add a second one. Both must end up
        // in the saved form with distinct ids.
        $created = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'Add Notification '.uniqid(),
            'fields' => [['type' => 'email', 'label' => 'Email']],
            'notifications' => [
                ['name' => 'Primary', 'to' => 'primary@example.com', 'subject' => 'Entry'],
            ],
        ]);
        $id = $this->trackForm((int) $created['id']);
        $existing = reset($created['notifications']);
        $existingId = $existing['id'];

        $updated = $this->assertAbilitySuccess('gds/forms-update', [
            'id' => $id,
            'notifications' => [
                [
                    'id' => $existingId,
                    'name' => 'Primary',
                    'to' => 'primary@example.com',
                    'subject' => 'Entry',
                ],
                // New notification — no id, createForm-style auto-assign.
                ['name' => 'Secondary', 'to' => 'cc@example.com', 'subject' => 'Entry CC'],
            ],
        ]);

        $this->assertCount(2, $updated['notifications']);
        $recipients = array_column($updated['notifications'], 'to');
        sort($recipients);
        $this->assertSame(['cc@example.com', 'primary@example.com'], $recipients);

        // Ids must all be distinct and the original one must still be present.
        $ids = array_column($updated['notifications'], 'id');
        $this->assertCount(2, array_unique($ids), 'notification ids must be unique');
        $this->assertContains($existingId, $ids, 'original notification id must be preserved');
    }

    public function test_forms_update_removes_notification(): void
    {
        // Start with two notifications, resave with only one — the other
        // must be dropped, not silently retained.
        $created = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'Remove Notification '.uniqid(),
            'fields' => [['type' => 'email', 'label' => 'Email']],
            'notifications' => [
                ['name' => 'Keep', 'to' => 'keep@example.com', 'subject' => 'Entry'],
                ['name' => 'Drop', 'to' => 'drop@example.com', 'subject' => 'Entry'],
            ],
        ]);
        $id = $this->trackForm((int) $created['id']);
        $this->assertCount(2, $created['notifications']);

        // Find the id of the "Keep" notification so we send the same one back.
        $keepId = null;
        foreach ($created['notifications'] as $notif) {
            if ($notif['name'] === 'Keep') {
                $keepId = $notif['id'];

                break;
            }
        }
        $this->assertNotNull($keepId);

        $updated = $this->assertAbilitySuccess('gds/forms-update', [
            'id' => $id,
            'notifications' => [
                [
                    'id' => $keepId,
                    'name' => 'Keep',
                    'to' => 'keep@example.com',
                    'subject' => 'Entry',
                ],
            ],
        ]);

        $this->assertCount(1, $updated['notifications']);
        $remaining = array_column($updated['notifications'], 'name');
        $this->assertSame(['Keep'], array_values($remaining));
        $this->assertNotContains('Drop', $remaining);
    }

    public function test_forms_update_replaces_confirmations(): void
    {
        // Regression: during the breakfast-event session the "contact form"
        // default confirmation stuck around even after the caller supplied a
        // new Jakobstad-specific message. Root cause: the recursive merge
        // kept the old confirmation keyed by its original UUID alongside the
        // new one. Verify the new confirmation fully replaces the default.
        $created = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'Confirmations Test '.uniqid(),
            'fields' => [['type' => 'email', 'label' => 'Email']],
        ]);
        $id = $this->trackForm((int) $created['id']);

        // GF auto-creates a default confirmation on form create.
        $this->assertNotEmpty($created['confirmations'] ?? []);

        $updated = $this->assertAbilitySuccess('gds/forms-update', [
            'id' => $id,
            'confirmations' => [
                ['name' => 'Jakobstad message', 'message' => 'See you in Jakobstad!', 'isDefault' => true, 'type' => 'message'],
            ],
        ]);

        $this->assertCount(
            1,
            $updated['confirmations'],
            'forms-update should replace the confirmations array; found '.count($updated['confirmations']).'.',
        );
        $messages = array_column($updated['confirmations'], 'message');
        $this->assertSame(['See you in Jakobstad!'], array_values($messages));
    }

    public function test_forms_update_replaces_notifications(): void
    {
        $created = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'Notifications Test '.uniqid(),
            'fields' => [['type' => 'email', 'label' => 'Email']],
            'notifications' => [
                ['name' => 'Initial', 'to' => 'a@example.com', 'subject' => 'Old'],
            ],
        ]);
        $id = $this->trackForm((int) $created['id']);
        $this->assertCount(1, $created['notifications']);

        $updated = $this->assertAbilitySuccess('gds/forms-update', [
            'id' => $id,
            'notifications' => [
                ['name' => 'Replacement', 'to' => 'b@example.com', 'subject' => 'New'],
            ],
        ]);

        $this->assertCount(1, $updated['notifications']);
        $names = array_column($updated['notifications'], 'name');
        $this->assertSame(['Replacement'], array_values($names));
    }

    public function test_forms_update_with_field_id_overwrites_that_slot(): void
    {
        // Regression: in the session, supplying `id: 6` in a new field
        // definition unpredictably interacted with the existing field at
        // id=6, sometimes dropping unrelated fields (e.g. Email at id=3).
        // With full array replacement, id:6 in the payload should overwrite
        // the id:6 slot and nothing else.
        $created = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'Id Overwrite Test '.uniqid(),
            'fields' => [
                ['id' => 3, 'type' => 'email', 'label' => 'Email'],
                ['id' => 4, 'type' => 'text', 'label' => 'Company'],
                ['id' => 6, 'type' => 'textarea', 'label' => 'Viesti'],
            ],
        ]);
        $id = $this->trackForm((int) $created['id']);

        // Replace id=6 with an Allergies field, keep the other two.
        $updated = $this->assertAbilitySuccess('gds/forms-update', [
            'id' => $id,
            'fields' => [
                ['id' => 3, 'type' => 'email', 'label' => 'Email'],
                ['id' => 4, 'type' => 'text', 'label' => 'Company'],
                ['id' => 6, 'type' => 'textarea', 'label' => 'Allergiat'],
            ],
        ]);

        $this->assertCount(3, $updated['fields']);
        $byId = [];
        foreach ($updated['fields'] as $field) {
            $byId[(int) $field['id']] = $field['label'];
        }
        $this->assertSame('Email', $byId[3] ?? null, 'Email at id=3 must survive');
        $this->assertSame('Company', $byId[4] ?? null, 'Company at id=4 must survive');
        $this->assertSame('Allergiat', $byId[6] ?? null, 'id=6 slot must be replaced with Allergiat');
    }

    public function test_forms_read_returns_created_form(): void
    {
        $created = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'Read Test '.uniqid(),
            'fields' => [['type' => 'email', 'label' => 'Email']],
        ]);
        $id = $this->trackForm((int) $created['id']);

        $result = $this->assertAbilitySuccess('gds/forms-read', ['id' => $id]);
        $this->assertSame($id, (int) $result['id']);
        $this->assertCount(1, $result['fields']);
    }

    public function test_forms_list_includes_created_form(): void
    {
        $created = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'List Test '.uniqid(),
            'fields' => [['type' => 'email', 'label' => 'Email']],
        ]);
        $id = $this->trackForm((int) $created['id']);

        $result = $this->assertAbilitySuccess('gds/forms-list');
        $this->assertArrayHasKey($id, $result, 'forms-list result should contain the created form id as a key.');
    }

    public function test_forms_update_requires_id(): void
    {
        $this->assertAbilityError('gds/forms-update', [
            'title' => 'No ID',
        ]);
    }

    public function test_forms_update_rejects_unknown_form(): void
    {
        $this->assertAbilityError('gds/forms-update', [
            'id' => 99999999,
            'title' => 'Should Fail',
        ]);
    }
}
