<?php

namespace GeneroWP\MCP\Integrations\GravityForms;

use WP_Error;

final class ListFormsAbility
{
    public static function register(): void
    {
        wp_register_ability('gds/forms/list', [
            'label' => 'List Gravity Forms',
            'description' => 'List all Gravity Forms with their ID, title, status, and entry count.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'active' => [
                        'type' => 'boolean',
                        'description' => 'Filter by active status.',
                        'default' => true,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'forms' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'title' => ['type' => 'string'],
                                'is_active' => ['type' => 'boolean'],
                                'date_created' => ['type' => 'string'],
                                'entry_count' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'total' => ['type' => 'integer'],
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

        $active = $input['active'] ?? true;
        $forms = \GFAPI::get_forms($active, false, 'title', 'ASC');

        $result = array_map(function ($form) {
            $formId = (int) $form['id'];

            return [
                'id' => $formId,
                'title' => $form['title'] ?? '',
                'is_active' => ! empty($form['is_active']),
                'date_created' => $form['date_created'] ?? '',
                'entry_count' => \GFAPI::count_entries($formId),
            ];
        }, $forms);

        return [
            'forms' => $result,
            'total' => count($result),
        ];
    }
}
