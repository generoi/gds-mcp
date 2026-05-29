<?php

namespace GeneroWP\MCP\Integrations\GravityForms;

use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Concerns\RestDelegation;
use GeneroWP\MCP\Undo\Reversible;
use WP_Error;

/**
 * REST-delegated abilities for Gravity Forms.
 *
 * Registers CRUD abilities for forms and entries via GF REST API v2.
 */
final class GravityFormsAbility
{
    use RestDelegation;
    use Reversible;

    public static function register(): void
    {
        $instance = new self;

        HelpAbility::registerAbility('gds/forms-list', [
            'label' => 'List Forms',
            'description' => 'List Gravity Forms. Defaults to active, non-trashed forms — pass `include_inactive` and/or `include_trashed` to widen the listing when the user is hunting for a form they "remember was here" (e.g. a disabled or trashed contact form).',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'include_inactive' => ['type' => 'boolean', 'description' => 'Include inactive forms (status off). Default false.'],
                    'include_trashed' => ['type' => 'boolean', 'description' => 'Include trashed forms. Default false.'],
                ],
                'additionalProperties' => true,
            ],
            'output_schema' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'listForms'],
            'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        HelpAbility::registerAbility('gds/forms-read', [
            'label' => 'Read Form',
            'description' => 'Read a Gravity Form with fields and settings.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer', 'description' => 'The form ID.']],
                'required' => ['id'],
                'additionalProperties' => true,
            ],
            'output_schema' => ['type' => 'object', 'additionalProperties' => true],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'readForm'],
            'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        HelpAbility::registerAbility('gds/forms-create', [
            'label' => 'Create Form',
            'description' => 'Create a Gravity Form with fields and email notifications. Always include title, fields array, and notifications array.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'Form title'],
                    'description' => ['type' => 'string', 'description' => 'Form description'],
                    'fields' => [
                        'type' => 'array',
                        'description' => 'Form fields. Each needs type and label.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => ['type' => 'string', 'description' => 'Field type: text, email, phone, textarea, select, checkbox, radio, number, date, name, address'],
                                'label' => ['type' => 'string', 'description' => 'Field label'],
                                'isRequired' => ['type' => 'boolean', 'description' => 'Whether field is required'],
                                'placeholder' => ['type' => 'string'],
                                'choices' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string'], 'value' => ['type' => 'string']]]],
                            ],
                            'required' => ['type', 'label'],
                        ],
                    ],
                    'notifications' => [
                        'type' => 'array',
                        'description' => 'Email notifications sent on submission.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string', 'description' => 'Notification name'],
                                'to' => ['type' => 'string', 'description' => 'Email address to send to'],
                                'subject' => ['type' => 'string', 'description' => 'Email subject line'],
                                'isActive' => ['type' => 'boolean'],
                            ],
                            'required' => ['name', 'to', 'subject'],
                        ],
                    ],
                    'confirmations' => [
                        'type' => 'array',
                        'description' => 'Confirmation messages shown after submission.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'message' => ['type' => 'string'],
                                'isDefault' => ['type' => 'boolean'],
                            ],
                        ],
                    ],
                ],
                'required' => ['title', 'fields'],
            ],
            'output_schema' => ['type' => 'object', 'additionalProperties' => true],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'createForm'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
        ]);

        HelpAbility::registerAbility('gds/forms-update', [
            'label' => 'Update Form',
            'description' => 'Update an existing Gravity Form. Reads the current form first, merges your changes, then writes the result. Supply only the properties you want to change alongside the required id.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'The form ID to update.'],
                    'title' => ['type' => 'string', 'description' => 'Form title'],
                    'description' => ['type' => 'string', 'description' => 'Form description'],
                    'fields' => [
                        'type' => 'array',
                        'description' => 'Full replacement of the fields array. Each field needs type, label, and id.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer', 'description' => 'Field ID (auto-assigned if omitted)'],
                                'type' => ['type' => 'string', 'description' => 'Field type: text, email, phone, textarea, select, checkbox, radio, number, date, name, address'],
                                'label' => ['type' => 'string', 'description' => 'Field label'],
                                'isRequired' => ['type' => 'boolean'],
                                'placeholder' => ['type' => 'string'],
                                'choices' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string'], 'value' => ['type' => 'string']]]],
                            ],
                            'required' => ['type', 'label'],
                        ],
                    ],
                    'notifications' => [
                        'type' => 'array',
                        'description' => 'Full replacement of email notifications.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'to' => ['type' => 'string'],
                                'subject' => ['type' => 'string'],
                                'message' => ['type' => 'string'],
                                'isActive' => ['type' => 'boolean'],
                            ],
                            'required' => ['name', 'to', 'subject'],
                        ],
                    ],
                    'confirmations' => [
                        'type' => 'array',
                        'description' => 'Full replacement of confirmation messages.',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'message' => ['type' => 'string'],
                                'isDefault' => ['type' => 'boolean'],
                            ],
                        ],
                    ],
                    'confirm_destructive' => [
                        'type' => 'boolean',
                        'description' => 'Set true ONLY to confirm intentional removal of an existing field or a change to its type. Either permanently orphans the submissions stored for that field (Gravity Forms has no field-level revisions). Leave unset for normal edits — the call is refused if it would destroy submitted data without this flag.',
                    ],
                ],
                'required' => ['id'],
                'additionalProperties' => true,
            ],
            'output_schema' => ['type' => 'object', 'additionalProperties' => true],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'updateForm'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
        ]);

        HelpAbility::registerAbility('gds/forms-entries', [
            'label' => 'List Form Entries',
            'description' => 'List entries (submissions) for a Gravity Form.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'form_id' => ['type' => 'integer', 'description' => 'The form ID.'],
                ],
                'required' => ['form_id'],
                'additionalProperties' => true,
            ],
            'output_schema' => ['type' => 'object', 'additionalProperties' => true],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'listEntries'],
            'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>|WP_Error
     */
    public function listForms(mixed $input = []): array|WP_Error
    {
        if (! class_exists('GFAPI')) {
            return new WP_Error('gf_not_available', 'Gravity Forms is not active.');
        }

        $input = (array) ($input ?? []);
        // GFAPI::get_forms($active, $trash):
        //   $active = null  → ignore active filter (returns active + inactive)
        //   $active = true  → active only
        //   $trash  = true  → trashed only
        //   $trash  = false → not trashed
        //   $trash  = null  → ignore trash filter (both)
        $includeInactive = ! empty($input['include_inactive']);
        $includeTrashed = ! empty($input['include_trashed']);

        $active = $includeInactive ? null : true;
        $trash = $includeTrashed ? null : false;

        $forms = \GFAPI::get_forms($active, $trash);

        $result = [];
        foreach ($forms as $form) {
            $result[(int) $form['id']] = json_decode(json_encode($form), true);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function readForm(mixed $input = []): array|WP_Error
    {
        if (! class_exists('GFAPI')) {
            return new WP_Error('gf_not_available', 'Gravity Forms is not active.');
        }

        $input = (array) ($input ?? []);
        $id = (int) ($input['id'] ?? 0);

        $form = \GFAPI::get_form($id);
        if (! $form) {
            return new WP_Error('form_not_found', "Form {$id} not found.");
        }

        return json_decode(json_encode($form), true) ?: [];
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function createForm(mixed $input = []): array|WP_Error
    {
        if (! class_exists('GFAPI')) {
            return new WP_Error('gf_not_available', 'Gravity Forms is not active.');
        }

        // Deep-convert to arrays (GFAPI expects arrays, not stdClass)
        $input = json_decode(json_encode($input ?? []), true) ?: [];
        $input = self::normalizeFormPayload($input);

        // Bypass the GF REST API (which expects the body as a JSON-encoded string
        // and returns cryptic "no route"/"must be sent as a JSON string" errors
        // depending on the request wrapper) and call GFAPI directly.
        $result = \GFAPI::add_form($input);
        if (is_wp_error($result)) {
            return $result;
        }
        if (! $result) {
            return new WP_Error('create_failed', 'Failed to create form.');
        }

        $formId = (int) $result;

        $saved = json_decode(json_encode(\GFAPI::get_form($formId)), true) ?: [];

        // Undo a create by deleting the new form.
        return $this->reversible($saved, 'delete-form', ['id' => $formId], "Delete the created form \"{$saved['title']}\"");
    }

    /**
     * Auto-assign field/notification/confirmation IDs and set GF-required defaults.
     *
     * Shared by createForm() and updateForm() so the two paths produce identical output.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function normalizeFormPayload(array $input): array
    {
        // Auto-assign field IDs — GF requires each field to have a unique id.
        if (! empty($input['fields']) && is_array($input['fields'])) {
            $maxId = 0;
            foreach ($input['fields'] as $field) {
                $maxId = max($maxId, (int) ($field['id'] ?? 0));
            }
            foreach ($input['fields'] as $i => &$field) {
                if (empty($field['id'])) {
                    $field['id'] = ++$maxId;
                }
            }
            unset($field);
        }

        // Auto-assign notification IDs and set required GF fields.
        if (! empty($input['notifications']) && is_array($input['notifications'])) {
            $keyed = [];
            foreach ($input['notifications'] as $notif) {
                $nid = $notif['id'] ?? wp_generate_uuid4();
                $notif['id'] = $nid;
                // GF requires toType='email' for custom email addresses
                if (! empty($notif['to']) && ! isset($notif['toType'])) {
                    $notif['toType'] = 'email';
                }
                $notif['event'] ??= 'form_submission';
                $notif['message'] ??= '{all_fields}';
                $keyed[$nid] = $notif;
            }
            $input['notifications'] = $keyed;
        }

        // Auto-assign confirmation IDs (same pattern as notifications).
        if (! empty($input['confirmations']) && is_array($input['confirmations'])) {
            $keyed = [];
            foreach ($input['confirmations'] as $conf) {
                $cid = $conf['id'] ?? wp_generate_uuid4();
                $conf['id'] = $cid;
                $keyed[$cid] = $conf;
            }
            $input['confirmations'] = $keyed;
        }

        return $input;
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function updateForm(mixed $input = []): array|WP_Error
    {
        if (! class_exists('GFAPI')) {
            return new WP_Error('gf_not_available', 'Gravity Forms is not active.');
        }

        $input = json_decode(json_encode($input ?? []), true) ?: [];
        $id = (int) ($input['id'] ?? 0);

        if (! $id) {
            return new WP_Error('missing_id', 'Form id is required.');
        }

        // Read the current form so callers can send partial updates.
        // GFAPI::get_form() returns GF_Field objects in fields[]; JSON round-trip
        // normalises everything to plain arrays before merging with caller input.
        $raw = \GFAPI::get_form($id);
        if (! $raw || is_wp_error($raw)) {
            return new WP_Error('form_not_found', "Form {$id} not found.");
        }
        $current = json_decode(json_encode($raw), true);

        unset($input['id']);

        // `confirm_destructive` is a guard signal, not a form property — capture
        // it then strip it so it isn't merged into and written onto the form.
        $confirmDestructive = ! empty($input['confirm_destructive']);
        unset($input['confirm_destructive']);

        // Guard irreversible field changes. A full fields[] replacement that
        // removes a field or changes its type permanently orphans that field's
        // submitted entries (GF keys entry values by field id and has no form
        // revisions). Refuse unless the caller explicitly confirms.
        if (array_key_exists('fields', $input) && ! $confirmDestructive) {
            $destructive = self::destructiveFieldChanges(
                $current['fields'] ?? [],
                is_array($input['fields'] ?? null) ? $input['fields'] : [],
            );

            if (! empty($destructive)) {
                $entryCount = (int) \GFAPI::count_entries($id);
                if ($entryCount > 0) {
                    return new WP_Error(
                        'destructive_field_change',
                        self::destructiveChangeMessage($id, $entryCount, $destructive),
                        ['status' => 409, 'fields' => array_values($destructive), 'entry_count' => $entryCount],
                    );
                }
            }
        }

        // Top-level replacement, not recursive merge: if the caller supplies a key
        // (fields, notifications, confirmations, title, description) it fully
        // replaces the current value. Omitted keys stay untouched.
        //
        // array_replace_recursive would recurse into the numerically-indexed
        // fields array and only replace entries at the same index, leaving
        // extras behind — that was the "leftover fields can't be deleted" bug.
        $merged = array_merge($current, $input);
        $merged = self::normalizeFormPayload($merged);

        // Use GFAPI directly — REST PUT requires GF_Field objects to already be
        // instantiated, but after the json_decode round-trip they're plain arrays.
        $result = \GFAPI::update_form($merged, $id);
        if (is_wp_error($result)) {
            return $result;
        }
        if ($result === false) {
            return new WP_Error('update_failed', "Failed to update form {$id}.");
        }

        // Return the saved form, with the full prior form as an undo snapshot
        // ($current was read before the merge, so capture is free).
        $saved = json_decode(json_encode(\GFAPI::get_form($id)), true) ?: [];

        // Make consecutive undo entries for the same form distinguishable —
        // two updates on a single form would otherwise both read "Revert form
        // 'X' to its previous state", leaving the user no way to tell which
        // snapshot is which when undoing from the chat UI.
        $deltaSummary = self::summarizeUpdateDelta($current, $saved);
        $label = "Revert form \"{$saved['title']}\" to its previous state";
        if ($deltaSummary !== '') {
            $label .= " ({$deltaSummary})";
        }

        return $this->reversible($saved, 'restore-form', ['id' => $id, 'form' => $current], $label);
    }

    /**
     * Short human-readable summary of what changed in a forms-update, used to
     * disambiguate audit-log labels when the user updates the same form
     * multiple times. Picks the most informative dimension(s) — field count
     * changes lead; otherwise list which top-level keys differ.
     *
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $before
     */
    private static function summarizeUpdateDelta(array $before, array $after): string
    {
        $bits = [];

        $bf = count(is_array($before['fields'] ?? null) ? $before['fields'] : []);
        $af = count(is_array($after['fields'] ?? null) ? $after['fields'] : []);
        if ($bf !== $af) {
            $bits[] = "fields: {$bf} → {$af}";
        }

        $watched = ['title', 'description', 'button', 'confirmations', 'notifications'];
        $changed = [];
        foreach ($watched as $key) {
            $b = $before[$key] ?? null;
            $a = $after[$key] ?? null;
            if ($b !== $a && json_encode($b) !== json_encode($a)) {
                $changed[] = $key;
            }
        }
        if ($changed) {
            $bits[] = 'changed: '.implode(', ', $changed);
        }

        // Same field count + same watched keys: at least timestamp it so two
        // identical-shape edits still produce different labels.
        if (! $bits) {
            $bits[] = 'saved '.gmdate('H:i:s').' UTC';
        }

        return implode(', ', $bits);
    }

    /**
     * Compare current vs incoming fields (both numerically indexed) and return
     * the fields that would lose submitted data: removed entirely, or whose
     * type changes. Matched by field id; an incoming field without an id can't
     * be matched to an existing one, so omitting/renumbering ids reads as a
     * removal — which is exactly the destructive case we want to catch.
     *
     * @param  array<string, mixed>  $newFields
     * @param  array<string, mixed>  $oldFields
     * @return array<int, array{id: int, label: string, reason: string}> Keyed by old field id.
     */
    private static function destructiveFieldChanges(array $oldFields, array $newFields): array
    {
        $newById = [];
        foreach ($newFields as $field) {
            $fid = (int) ($field['id'] ?? 0);
            if ($fid) {
                $newById[$fid] = $field;
            }
        }

        $changes = [];
        foreach ($oldFields as $field) {
            $fid = (int) ($field['id'] ?? 0);
            if (! $fid) {
                continue;
            }
            $label = (string) ($field['label'] ?? '');
            $label = $label !== '' ? $label : "Field {$fid}";

            if (! isset($newById[$fid])) {
                $changes[$fid] = ['id' => $fid, 'label' => $label, 'reason' => 'removed'];

                continue;
            }

            $oldType = (string) ($field['type'] ?? '');
            $newType = (string) ($newById[$fid]['type'] ?? '');
            if ($oldType !== '' && $newType !== '' && $oldType !== $newType) {
                $changes[$fid] = [
                    'id' => $fid,
                    'label' => $label,
                    'reason' => "type changed from {$oldType} to {$newType}",
                ];
            }
        }

        return $changes;
    }

    /**
     * Build the human-readable refusal explaining which fields would lose data.
     *
     * @param  array<int, array{id: int, label: string, reason: string}>  $changes
     */
    private static function destructiveChangeMessage(int $formId, int $entryCount, array $changes): string
    {
        $lines = [];
        foreach ($changes as $change) {
            $lines[] = "- Field {$change['id']} \"{$change['label']}\": {$change['reason']}";
        }
        $list = implode("\n", $lines);
        $entries = $entryCount === 1 ? '1 entry' : "{$entryCount} entries";

        return "This update to form {$formId} removes or retypes fields that hold submitted data. "
            ."The form has {$entries}, and these changes permanently orphan the affected submissions "
            ."(Gravity Forms has no field-level revisions):\n{$list}\n\n"
            .'To preserve submissions, keep each existing field\'s original id and type and change only labels or settings. '
            .'If the loss is intentional, re-run with "confirm_destructive": true.';
    }

    /**
     * @return array<int, array<string, mixed>>|WP_Error
     */
    public function listEntries(mixed $input = []): array|WP_Error
    {
        if (! class_exists('GFAPI')) {
            return new WP_Error('gf_not_available', 'Gravity Forms is not active.');
        }

        $input = (array) ($input ?? []);
        $formId = (int) ($input['form_id'] ?? 0);

        $entries = \GFAPI::get_entries(
            $formId,
            $input['search_criteria'] ?? [],
            $input['sorting'] ?? null,
            $input['paging'] ?? null,
        );

        if (is_wp_error($entries)) {
            return $entries;
        }

        return json_decode(json_encode($entries), true) ?: [];
    }
}
