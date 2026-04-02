<?php

namespace GeneroWP\MCP\Integrations\GravityForms;

use GeneroWP\MCP\Abilities\HelpAbility;
use WP_Error;

final class CreateGravityFormAbility
{
    public static function register(): void
    {
        HelpAbility::registerAbility('gds/forms/create', [
            'label' => 'Create Gravity Form',
            'description' => 'Create a new Gravity Form. Fields are passed as raw Gravity Forms field arrays (type, label, isRequired, placeholder, choices, size, etc.) and are converted internally by GF. Field IDs are auto-assigned if omitted.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => [
                        'type' => 'string',
                        'description' => 'Form title.',
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Form description.',
                    ],
                    'submit_text' => [
                        'type' => 'string',
                        'description' => 'Submit button text.',
                        'default' => 'Submit',
                    ],
                    'fields' => [
                        'type' => 'array',
                        'description' => 'Array of Gravity Forms field arrays. Each must have "type" and "label". Common types: text, email, textarea, select, checkbox, radio, phone, date, name, website, hidden, fileupload, number. Supports all native GF field properties (isRequired, placeholder, choices, size, defaultValue, description, etc.). Field IDs are auto-assigned if omitted.',
                        'items' => [
                            'type' => 'object',
                        ],
                    ],
                    'notification_email' => [
                        'type' => 'string',
                        'description' => 'Email address for form submission notifications. Defaults to admin email.',
                    ],
                    'confirmation_message' => [
                        'type' => 'string',
                        'description' => 'Message shown after successful submission.',
                        'default' => 'Thank you for your submission.',
                    ],
                ],
                'required' => ['title', 'fields'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'form_id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'edit_url' => ['type' => 'string'],
                    'field_count' => ['type' => 'integer'],
                ],
            ],
            'permission_callback' => [self::class, 'checkPermission'],
            'execute_callback' => [self::class, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => false,
                    'destructive' => false,
                    'idempotent' => false,
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
            return new WP_Error('insufficient_capability', 'You do not have permission to create forms.');
        }

        return true;
    }

    public static function execute(?array $input = []): array|WP_Error
    {
        if (! class_exists('GFAPI')) {
            return new WP_Error('gravity_forms_not_active', 'Gravity Forms is not active.');
        }

        // Auto-assign field IDs if not provided.
        $fields = $input['fields'] ?? [];
        foreach ($fields as $index => &$field) {
            if (empty($field['id'])) {
                $field['id'] = $index + 1;
            }
        }
        unset($field);

        $form = [
            'title' => $input['title'],
            'description' => $input['description'] ?? '',
            'labelPlacement' => 'top_label',
            'descriptionPlacement' => 'below',
            'button' => [
                'type' => 'text',
                'text' => $input['submit_text'] ?? 'Submit',
            ],
            'fields' => $fields,
            'confirmations' => [
                'default' => [
                    'id' => 'default',
                    'name' => 'Default Confirmation',
                    'isDefault' => true,
                    'type' => 'message',
                    'message' => $input['confirmation_message'] ?? 'Thank you for your submission.',
                ],
            ],
            'notifications' => [
                'admin' => [
                    'id' => 'admin',
                    'isActive' => true,
                    'name' => 'Admin Notification',
                    'event' => 'form_submission',
                    'to' => $input['notification_email'] ?? '{admin_email}',
                    'subject' => sprintf('New submission: %s', $input['title']),
                    'message' => '{all_fields}',
                ],
            ],
        ];

        $result = \GFAPI::add_form($form);

        if (is_wp_error($result)) {
            return $result;
        }

        return [
            'form_id' => $result,
            'title' => $input['title'],
            'edit_url' => admin_url(sprintf('admin.php?page=gf_edit_forms&id=%d', $result)),
            'field_count' => count($fields),
        ];
    }
}
