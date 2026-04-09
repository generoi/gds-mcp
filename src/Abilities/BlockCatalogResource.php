<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Concerns\RestDelegation;

/**
 * Block catalog — delegates to /wp/v2/block-types REST endpoint.
 *
 * Returns all registered blocks with attributes, supports, styles,
 * variations, keywords, parent/ancestor constraints, etc.
 */
final class BlockCatalogResource
{
    use RestDelegation;

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/block-types-list', [
            'label' => 'Block Catalog',
            'description' => 'List all registered block types with metadata. Delegates to /wp/v2/block-types. Use gds/blocks-get for real-world usage examples from published posts.',
            'category' => 'gds-content',
            'input_schema' => self::getRestInputSchema('/wp/v2/block-types'),
            'output_schema' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
            'permission_callback' => '__return_true',
            'execute_callback' => [new self, 'execute'],
            'meta' => [
                'uri' => 'blocks://catalog',
                'mimeType' => 'application/json',
                'mcp' => ['type' => 'resource', 'public' => true],
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);
    }

    public function execute(mixed $input = []): array
    {
        $response = self::restGet('/wp/v2/block-types', is_array($input) ? $input : []);

        if (self::isRestError($response)) {
            return [];
        }

        return array_map(fn ($item) => (array) $item, $response->get_data());
    }
}
