<?php

namespace GeneroWP\MCP\Integrations\Polylang;

use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Concerns\RestDelegation;
use WP_Error;

/**
 * List Polylang languages via /pll/v1/languages REST endpoint.
 */
final class ListLanguagesAbility
{
    use RestDelegation;

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public static function register(): void
    {
        $ability = self::instance();

        HelpAbility::registerAbility('gds/languages-list', [
            'label' => 'List Languages',
            'description' => 'List all Polylang languages. Delegates to /pll/v1/languages REST endpoint.',
            'category' => 'gds-content',
            'input_schema' => self::getRestInputSchema('/pll/v1/languages'),
            'output_schema' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
            'permission_callback' => '__return_true',
            'execute_callback' => [$ability, 'execute'],
            'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);
    }

    public function execute(mixed $input = []): array|WP_Error
    {
        $response = self::restGet('/pll/v1/languages', is_array($input) ? $input : []);

        return self::isRestError($response)
            ? self::restErrorToWpError($response)
            : array_map(fn ($item) => (array) $item, $response->get_data());
    }
}
