<?php

namespace GeneroWP\MCP\Integrations\GravityForms;

use WP_Error;

final class GetFormAbility
{
    public static function register(): void
    {
        wp_register_ability('gds/get-gravity-form', [
            'label' => 'Get Gravity Form',
            'description' => 'Get a specific Gravity Form with all its fields, confirmations, and notifications.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'form_id' => [
                        'type' => 'integer',
                        'description' => 'The Gravity Form ID.',
                    ],
                ],
                'required' => ['form_id'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'is_active' => ['type' => 'boolean'],
                    'date_created' => ['type' => 'string'],
                    'entry_count' => ['type' => 'integer'],
                    'fields' => ['type' => 'array'],
                    'confirmations' => ['type' => 'object'],
                    'notifications' => ['type' => 'object'],
                    'button' => ['type' => 'object'],
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
            return new WP_Error('insufficient_capability', 'You do not have permission to view forms.');
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

        $fields = array_map(function ($field) {
            $data = [
                'id' => (int) $field->id,
                'type' => $field->type,
                'label' => $field->label,
                'isRequired' => ! empty($field->isRequired),
            ];

            foreach (['placeholder', 'description', 'defaultValue', 'choices'] as $key) {
                if (! empty($field->$key)) {
                    $data[$key] = $field->$key;
                }
            }

            return $data;
        }, $form['fields'] ?? []);

        return [
            'id' => (int) $form['id'],
            'title' => $form['title'] ?? '',
            'description' => $form['description'] ?? '',
            'is_active' => ! empty($form['is_active']),
            'date_created' => $form['date_created'] ?? '',
            'entry_count' => \GFAPI::count_entries($formId),
            'fields' => $fields,
            'confirmations' => $form['confirmations'] ?? [],
            'notifications' => $form['notifications'] ?? [],
            'button' => $form['button'] ?? [],
        ];
    }
}
