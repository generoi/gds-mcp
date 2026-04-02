<?php

namespace GeneroWP\MCP\Integrations\GravityForms;

use WP_Error;

final class GetFormEntriesAbility
{
    public static function register(): void
    {
        wp_register_ability('gds/forms/entries', [
            'label' => 'Get Gravity Form Entries',
            'description' => 'Query form submission entries with filtering by date, status, and field values. Returns entry data with field labels mapped to values.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'form_id' => [
                        'type' => 'integer',
                        'description' => 'The Gravity Form ID to query entries for.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Entry status filter.',
                        'default' => 'active',
                        'enum' => ['active', 'spam', 'trash'],
                    ],
                    'start_date' => [
                        'type' => 'string',
                        'description' => 'Filter entries created on or after this date (YYYY-MM-DD).',
                    ],
                    'end_date' => [
                        'type' => 'string',
                        'description' => 'Filter entries created on or before this date (YYYY-MM-DD).',
                    ],
                    'search' => [
                        'type' => 'string',
                        'description' => 'Search across all fields for this value.',
                    ],
                    'page_size' => [
                        'type' => 'integer',
                        'description' => 'Number of entries per page.',
                        'default' => 20,
                        'minimum' => 1,
                        'maximum' => 100,
                    ],
                    'offset' => [
                        'type' => 'integer',
                        'description' => 'Number of entries to skip.',
                        'default' => 0,
                    ],
                ],
                'required' => ['form_id'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'entries' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'date_created' => ['type' => 'string'],
                                'status' => ['type' => 'string'],
                                'is_read' => ['type' => 'boolean'],
                                'fields' => ['type' => 'object'],
                            ],
                        ],
                    ],
                    'total' => ['type' => 'integer'],
                    'page_size' => ['type' => 'integer'],
                    'offset' => ['type' => 'integer'],
                ],
            ],
            'permission_callback' => [self::class, 'checkPermission'],
            'execute_callback' => [self::class, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => true,
                    'destructive' => false,
                    'idempotent' => true,
                ],
            ],
        ]);
    }

    public static function checkPermission(?array $input = []): bool|WP_Error
    {
        if (! is_user_logged_in()) {
            return new WP_Error('authentication_required', 'User must be authenticated.');
        }

        if (! current_user_can('gform_full_access') && ! current_user_can('manage_options')) {
            return new WP_Error('insufficient_capability', 'You do not have permission to view form entries.');
        }

        return true;
    }

    public static function execute(?array $input = []): array|WP_Error
    {
        if (! class_exists('GFAPI')) {
            return new WP_Error('gravity_forms_not_active', 'Gravity Forms is not active.');
        }

        $formId = $input['form_id'] ?? 0;
        $form = \GFAPI::get_form($formId);

        if (! $form) {
            return new WP_Error('form_not_found', sprintf('Form %d not found.', $formId));
        }

        // Build field label map for readable output.
        $fieldLabels = [];
        foreach ($form['fields'] ?? [] as $field) {
            $fieldLabels[(string) $field->id] = $field->label;

            // Map sub-field inputs (e.g. name.3, name.6 for first/last).
            if (! empty($field->inputs)) {
                foreach ($field->inputs as $fieldInput) {
                    $fieldLabels[(string) $fieldInput['id']] = $fieldInput['label'] ?? $field->label;
                }
            }
        }

        // Build search criteria.
        $searchCriteria = [
            'status' => $input['status'] ?? 'active',
        ];

        if (! empty($input['start_date'])) {
            $searchCriteria['start_date'] = $input['start_date'];
        }

        if (! empty($input['end_date'])) {
            $searchCriteria['end_date'] = $input['end_date'];
        }

        if (! empty($input['search'])) {
            $searchCriteria['field_filters'][] = [
                'key' => 0,
                'value' => $input['search'],
                'operator' => 'contains',
            ];
        }

        $pageSize = min($input['page_size'] ?? 20, 100);
        $paging = [
            'offset' => $input['offset'] ?? 0,
            'page_size' => $pageSize,
        ];

        $totalCount = 0;
        $entries = \GFAPI::get_entries($formId, $searchCriteria, null, $paging, $totalCount);

        if (is_wp_error($entries)) {
            return $entries;
        }

        // Map entries to readable format with field labels.
        $result = array_map(function ($entry) use ($fieldLabels) {
            $fields = [];
            foreach ($fieldLabels as $fieldId => $label) {
                $value = $entry[$fieldId] ?? '';
                if ($value !== '') {
                    $fields[$label] = $value;
                }
            }

            return [
                'id' => (int) $entry['id'],
                'date_created' => $entry['date_created'] ?? '',
                'status' => $entry['status'] ?? '',
                'is_read' => ! empty($entry['is_read']),
                'fields' => $fields,
            ];
        }, $entries);

        return [
            'entries' => $result,
            'total' => $totalCount,
            'page_size' => $pageSize,
            'offset' => $input['offset'] ?? 0,
        ];
    }
}
