<?php

namespace GeneroWP\MCP\Integrations\GravityForms;

use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Concerns\RestDelegation;
use WP_Error;

/**
 * REST-delegated abilities for Gravity Forms.
 *
 * Registers CRUD abilities for forms and entries via GF REST API v2.
 */
final class GravityFormsAbility
{
    use RestDelegation;

    public static function register(): void
    {
        $instance = new self;

        HelpAbility::registerAbility('gds/forms-list', [
            'label' => 'List Forms',
            'description' => 'List Gravity Forms. Delegates to GF REST API v2.',
            'category' => 'gds-content',
            'input_schema' => self::getRestInputSchema('/gf/v2/forms'),
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
            'description' => 'Create a new Gravity Form.',
            'category' => 'gds-content',
            'input_schema' => self::getRestInputSchema('/gf/v2/forms', method: 'POST'),
            'output_schema' => ['type' => 'object', 'additionalProperties' => true],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'createForm'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
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

    public function listForms(mixed $input = []): array|WP_Error
    {
        $response = self::restGet('/gf/v2/forms', (array) ($input ?? []));

        return self::isRestError($response)
            ? self::restErrorToWpError($response)
            : (array) $response->get_data();
    }

    public function readForm(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $id = $input['id'] ?? 0;
        unset($input['id']);

        $response = self::restGet("/gf/v2/forms/{$id}", $input);

        return self::isRestError($response)
            ? self::restErrorToWpError($response)
            : (array) $response->get_data();
    }

    public function createForm(mixed $input = []): array|WP_Error
    {
        $response = self::restPost('/gf/v2/forms', (array) ($input ?? []));

        return self::isRestError($response)
            ? self::restErrorToWpError($response)
            : (array) $response->get_data();
    }

    public function listEntries(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $formId = $input['form_id'] ?? 0;
        unset($input['form_id']);

        $response = self::restGet("/gf/v2/forms/{$formId}/entries", $input);

        return self::isRestError($response)
            ? self::restErrorToWpError($response)
            : (array) $response->get_data();
    }
}
