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
                // Provide the resource URI/mimeType in BOTH locations for adapter
                // cross-compatibility: WordPress/mcp-adapter >= 0.5.0 reads meta.mcp.uri
                // (canonical), while < 0.5.0 reads only top-level meta.uri. Keeping both
                // works on every adapter version without triggering 0.5.0 deprecation
                // notices (the mcp.* copy is matched first).
                'uri' => 'blocks://catalog',
                'mimeType' => 'application/json',
                'mcp' => [
                    'type' => 'resource',
                    'public' => true,
                    'uri' => 'blocks://catalog',
                    'mimeType' => 'application/json',
                ],
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(mixed $input = []): array
    {
        $response = self::restGet('/wp/v2/block-types', is_array($input) ? $input : []);

        if (self::isRestError($response)) {
            return [];
        }

        return self::restResponseData($response);
    }
}
