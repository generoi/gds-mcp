<?php

namespace GeneroWP\MCP\Tests\Integration\Plugins;

use GeneroWP\MCP\Integrations\GravityForms\GravityFormsAbility;
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

    public function test_forms_html_is_filtered_for_admins_without_unfiltered_html(): void
    {
        add_filter('map_meta_cap', fn ($caps, $cap) => $cap === 'unfiltered_html' ? ['do_not_allow'] : $caps, 10, 2);
        $script = '<script>alert(1)</script>';

        $created = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'Kses Test '.uniqid(),
            'description' => 'Intro'.$script,
            'fields' => [['type' => 'html', 'label' => 'Block', 'content' => 'Hi'.$script]],
            'confirmations' => [['name' => 'Main', 'message' => 'Thanks'.$script, 'isDefault' => true, 'type' => 'message']],
        ]);
        $id = $this->trackForm((int) $created['id']);
        $updated = $this->assertAbilitySuccess('gds/forms-update', [
            'id' => $id,
            'notifications' => [['name' => 'Admin', 'to' => 'a@example.com', 'subject' => 'New entry', 'message' => 'New'.$script]],
        ]);

        $form = \GFAPI::get_form($id);
        $this->assertStringNotContainsString('<script>', $form['description']);
        $this->assertStringNotContainsString('<script>', $form['fields'][0]->content);
        $this->assertStringNotContainsString('<script>', reset($form['confirmations'])['message']);
        $this->assertStringNotContainsString('<script>', reset($form['notifications'])['message']);
        $this->assertSame('Block', $updated['fields'][0]['label']);
    }

    public function test_forms_settings_and_redirects_are_sanitized(): void
    {
        $created = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'Sanitize <b>Test</b> '.uniqid(),
            'button' => ['type' => 'text', 'text' => 'Send <img src=x onerror=alert(1)>'],
            'fields' => [['type' => 'email', 'label' => 'Email']],
            'confirmations' => [
                ['name' => 'Script', 'type' => 'redirect', 'url' => 'javascript://x%0aalert(document.cookie)', 'isDefault' => true],
                ['name' => 'Hidden', 'type' => 'redirect', 'url' => "\x01javascript:alert(1)"],
                ['name' => 'Fine', 'type' => 'redirect', 'url' => 'https://example.com/thanks'],
                ['name' => 'Tag', 'type' => 'redirect', 'url' => '{embed_url}?sent=1'],
                // GF would redirect to http:///thanks.
                ['name' => 'Path', 'type' => 'redirect', 'url' => '/thanks'],
            ],
        ]);
        $id = $this->trackForm((int) $created['id']);

        $form = \GFAPI::get_form($id);
        $this->assertStringNotContainsString('<b>', $form['title']);
        $this->assertStringNotContainsString('<img', $form['button']['text']);
        $urls = array_column($form['confirmations'], 'url', 'name');
        $this->assertSame('', $urls['Script']);
        $this->assertSame('', $urls['Hidden']);
        $this->assertSame('https://example.com/thanks', $urls['Fine']);
        $this->assertSame('{embed_url}?sent=1', $urls['Tag']);
        $this->assertSame('', $urls['Path']);
    }

    public function test_notification_fallback_sanitizes_conditional_logic_and_routing(): void
    {
        $method = new \ReflectionMethod(GravityFormsAbility::class, 'sanitizeNotification');
        $rule = ['fieldId' => '1<b>x</b>', 'operator' => 'bogus', 'value' => 'a<script>alert(1)</script>'];

        $clean = $method->invoke(null, [
            'name' => 'Admin <b>x</b>',
            'conditionalLogic' => ['actionType' => 'run', 'logicType' => 'some', 'rules' => [$rule]],
            'routing' => [$rule + ['email' => 'a@example.com']],
        ]);

        $this->assertSame('Admin x', $clean['name']);
        $this->assertSame('show', $clean['conditionalLogic']['actionType']);
        $this->assertSame('all', $clean['conditionalLogic']['logicType']);
        foreach ([$clean['conditionalLogic']['rules'][0], $clean['routing'][0]] as $cleanRule) {
            $this->assertSame('1x', $cleanRule['fieldId']);
            $this->assertSame('is', $cleanRule['operator']);
            $this->assertStringNotContainsString('<script>', $cleanRule['value']);
        }
        $this->assertSame('a@example.com', $clean['routing'][0]['email']);
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
        $this->assertTrue(array_is_list($result), 'forms-list must encode as a JSON array, matching its schema.');

        $listed = array_values(array_filter($result, fn ($form) => $form['id'] === $id));
        $this->assertCount(1, $listed);
        $this->assertSame(1, $listed[0]['field_count']);
        $this->assertArrayNotHasKey('fields', $listed[0], 'Summaries by default.');

        $full = $this->assertAbilitySuccess('gds/forms-list', ['full' => true]);
        $fullForm = array_values(array_filter($full, fn ($form) => (int) $form['id'] === $id))[0];
        $this->assertArrayHasKey('fields', $fullForm);
    }

    public function test_forms_entries_are_paged(): void
    {
        $created = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'Entries Test '.uniqid(),
            'fields' => [['id' => 1, 'type' => 'text', 'label' => 'Name']],
        ]);
        $id = $this->trackForm((int) $created['id']);
        foreach (['a', 'b', 'c'] as $name) {
            \GFAPI::add_entry(['form_id' => $id, '1' => $name]);
        }

        $result = $this->assertAbilitySuccess('gds/forms-entries', ['form_id' => $id, 'per_page' => 2, 'page' => 2]);

        $this->assertSame(3, $result['total']);
        $this->assertSame(2, $result['total_pages']);
        $this->assertCount(1, $result['entries']);
    }

    public function test_forms_entries_require_a_form(): void
    {
        $this->assertAbilityError('gds/forms-entries', ['form_id' => 0]);
        $this->assertAbilityError('gds/forms-entries', ['form_id' => 999999], 'form_not_found');
    }

    public function test_forms_update_blocks_field_removal_when_entries_exist(): void
    {
        $created = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'Destructive Removal '.uniqid(),
            'fields' => [
                ['id' => 1, 'type' => 'email', 'label' => 'Email'],
                ['id' => 2, 'type' => 'text', 'label' => 'Company'],
            ],
        ]);
        $id = $this->trackForm((int) $created['id']);

        // A real submission ties data to field 2.
        $entryId = \GFAPI::add_entry(['form_id' => $id, '1' => 'a@example.com', '2' => 'Acme']);
        $this->assertNotWPError($entryId);

        // Dropping field 2 would orphan its submitted value — refuse it.
        $this->assertAbilityError('gds/forms-update', [
            'id' => $id,
            'fields' => [
                ['id' => 1, 'type' => 'email', 'label' => 'Email'],
            ],
        ], 'destructive_field_change');

        // The form must be left untouched — both fields still present.
        $fresh = \GFAPI::get_form($id);
        $this->assertCount(2, $fresh['fields'], 'A refused update must not modify the form.');
    }

    public function test_forms_update_blocks_field_type_change_when_entries_exist(): void
    {
        $created = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'Destructive Retype '.uniqid(),
            'fields' => [
                ['id' => 1, 'type' => 'text', 'label' => 'Email'],
            ],
        ]);
        $id = $this->trackForm((int) $created['id']);
        $this->assertNotWPError(\GFAPI::add_entry(['form_id' => $id, '1' => 'plain text']));

        // text → email is the exact case from the bug report.
        $this->assertAbilityError('gds/forms-update', [
            'id' => $id,
            'fields' => [
                ['id' => 1, 'type' => 'email', 'label' => 'Email'],
            ],
        ], 'destructive_field_change');
    }

    public function test_forms_update_allows_destructive_change_with_confirm_flag(): void
    {
        $created = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'Confirmed Removal '.uniqid(),
            'fields' => [
                ['id' => 1, 'type' => 'email', 'label' => 'Email'],
                ['id' => 2, 'type' => 'text', 'label' => 'Company'],
            ],
        ]);
        $id = $this->trackForm((int) $created['id']);
        $this->assertNotWPError(\GFAPI::add_entry(['form_id' => $id, '1' => 'a@example.com', '2' => 'Acme']));

        $updated = $this->assertAbilitySuccess('gds/forms-update', [
            'id' => $id,
            'confirm_destructive' => true,
            'fields' => [
                ['id' => 1, 'type' => 'email', 'label' => 'Email'],
            ],
        ]);

        $this->assertCount(1, $updated['fields'], 'With confirmation the field removal must go through.');
        // The guard flag must not leak onto the persisted form object.
        $this->assertArrayNotHasKey('confirm_destructive', $updated);
    }

    public function test_forms_update_allows_field_removal_without_entries(): void
    {
        // No submissions → nothing to orphan → the guard stays out of the way.
        $created = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'Safe Removal '.uniqid(),
            'fields' => [
                ['id' => 1, 'type' => 'email', 'label' => 'Email'],
                ['id' => 2, 'type' => 'text', 'label' => 'Company'],
            ],
        ]);
        $id = $this->trackForm((int) $created['id']);

        $updated = $this->assertAbilitySuccess('gds/forms-update', [
            'id' => $id,
            'fields' => [
                ['id' => 1, 'type' => 'email', 'label' => 'Email'],
            ],
        ]);
        $this->assertCount(1, $updated['fields']);
    }

    public function test_forms_update_label_change_allowed_with_entries(): void
    {
        // Relabeling keeps id + type, so submissions stay intact → allowed.
        $created = $this->assertAbilitySuccess('gds/forms-create', [
            'title' => 'Relabel With Entries '.uniqid(),
            'fields' => [['id' => 1, 'type' => 'email', 'label' => 'Email']],
        ]);
        $id = $this->trackForm((int) $created['id']);
        $this->assertNotWPError(\GFAPI::add_entry(['form_id' => $id, '1' => 'a@example.com']));

        $updated = $this->assertAbilitySuccess('gds/forms-update', [
            'id' => $id,
            'fields' => [['id' => 1, 'type' => 'email', 'label' => 'Sähköposti']],
        ]);
        $this->assertSame('Sähköposti', $updated['fields'][0]['label']);
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
