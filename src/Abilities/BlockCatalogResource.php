<?php

namespace GeneroWP\MCP\Abilities;

use WP_Block_Type;
use WP_Block_Type_Registry;
use WP_Error;

final class BlockCatalogResource
{
    public static function register(): void
    {
        HelpAbility::registerAbility('gds/blocks-catalog', [
            'label' => 'Block Catalog',
            'description' => 'Read-only index of all registered blocks with metadata (title, description, category, styles, allowed inner blocks, parent constraints). Use gds/blocks-get for full attribute details, example markup, and real-world usage search.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => new \stdClass,
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'blocks' => [
                        'type' => 'array',
                        'items' => ['type' => 'object'],
                    ],
                    'total' => ['type' => 'integer'],
                    'demo_page' => [
                        'type' => ['object', 'null'],
                    ],
                ],
            ],
            'permission_callback' => [self::class, 'checkPermission'],
            'execute_callback' => [self::class, 'execute'],
            'meta' => [
                'uri' => 'blocks://catalog',
                'mimeType' => 'application/json',
                'mcp' => [
                    'type' => 'resource',
                    'public' => true,
                ],
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

        if (! current_user_can('edit_posts')) {
            return new WP_Error('insufficient_capability', 'You do not have permission to read the block catalog.');
        }

        return true;
    }

    /**
     * Return the block catalog index. Use gds/blocks-get for full detail,
     * examples, and post search on individual blocks.
     */
    public static function execute(?array $input = []): array
    {
        $registry = WP_Block_Type_Registry::get_instance();
        $allBlocks = $registry->get_all_registered();

        $result = [];
        foreach ($allBlocks as $blockType) {
            $result[] = self::formatBlockSummary($blockType);
        }

        usort($result, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return [
            'blocks' => $result,
            'total' => count($result),
        ];
    }

    private static function formatBlockSummary(WP_Block_Type $blockType): array
    {
        $summary = [
            'name' => $blockType->name,
            'title' => $blockType->title,
            'description' => $blockType->description,
            'category' => $blockType->category,
        ];

        if ($blockType->keywords) {
            $summary['keywords'] = $blockType->keywords;
        }

        if ($blockType->parent) {
            $summary['parent'] = $blockType->parent;
        }

        if ($blockType->allowed_blocks) {
            $summary['allowed_blocks'] = $blockType->allowed_blocks;
        }

        if ($blockType->is_dynamic()) {
            $summary['is_dynamic'] = true;
        }

        $styles = self::getBlockStyles($blockType);
        if ($styles) {
            $summary['styles'] = $styles;
        }

        return $summary;
    }

    /**
     * Get all styles for a block, merging block.json styles with WP_Block_Styles_Registry.
     */
    private static function getBlockStyles(WP_Block_Type $blockType): array
    {
        $styles = [];

        foreach (($blockType->styles ?? []) as $style) {
            $styles[$style['name']] = $style['name'];
        }

        $registered = \WP_Block_Styles_Registry::get_instance()
            ->get_registered_styles_for_block($blockType->name);
        foreach ($registered as $name => $style) {
            $styles[$name] = $name;
        }

        return array_values($styles);
    }
}
