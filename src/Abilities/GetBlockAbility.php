<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Concerns\BlockExamples;
use WP_Block_Type;
use WP_Block_Type_Registry;
use WP_Error;

final class GetBlockAbility
{
    use BlockExamples;

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/blocks-get', [
            'label' => 'Get Block',
            'description' => 'Get full details for a block: attributes, supports, styles, and example markup from the demo page or published posts. Read blocks://catalog first to discover available blocks.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => [
                        'type' => 'string',
                        'description' => 'Block name to look up (e.g. "gds/card", "core/heading").',
                    ],
                    'include_examples' => [
                        'type' => 'boolean',
                        'description' => 'Include example markup extracted from the block demo page.',
                        'default' => false,
                    ],
                    'search_posts' => [
                        'type' => 'boolean',
                        'description' => 'Search published posts/pages for real-world usage examples of the block.',
                        'default' => false,
                    ],
                    'search_post_type' => [
                        'type' => 'string',
                        'description' => 'Limit search_posts to a specific post type (e.g. "page", "product", "wp_template_part"). Omit to search all published post types.',
                    ],
                    'style' => [
                        'type' => 'string',
                        'description' => 'Filter examples to a specific block style (e.g. "curved-top", "landscape"). Matches is-style-{name} in className.',
                    ],
                    'max_examples' => [
                        'type' => 'integer',
                        'description' => 'Max demo page examples to return (default 10, max 100).',
                        'default' => 10,
                    ],
                    'max_post_examples' => [
                        'type' => 'integer',
                        'description' => 'Max post examples to return (default 10, max 100).',
                        'default' => 10,
                    ],
                ],
                'required' => ['name'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'block' => ['type' => 'object'],
                    'demo_page' => [
                        'type' => ['object', 'null'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'title' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [new self, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => true,
                    'destructive' => false,
                    'idempotent' => true,
                ],
            ],
        ]);
    }

    public function execute(mixed $input = []): array|WP_Error
    {
        $input = is_array($input) ? $input : [];
        $name = $input['name'] ?? null;
        $includeExamples = $input['include_examples'] ?? false;
        $searchPosts = $input['search_posts'] ?? false;
        $searchPostType = $input['search_post_type'] ?? null;
        $style = $input['style'] ?? null;
        $maxExamples = min((int) ($input['max_examples'] ?? 10), 100);
        $maxPostExamples = min((int) ($input['max_post_examples'] ?? 10), 100);

        if (! $name) {
            return new WP_Error('missing_name', 'Block name is required. Use the blocks://catalog resource to browse all blocks.');
        }

        $registry = WP_Block_Type_Registry::get_instance();
        $blockType = $registry->get_registered($name);
        if (! $blockType) {
            return new WP_Error('block_not_found', "Block '{$name}' is not registered.");
        }

        $detail = self::formatBlockDetail($blockType);

        $demoPage = null;
        if ($includeExamples) {
            [$demoPage, $examples] = self::loadDemoExamples($maxExamples, $style);
            if (isset($examples[$name])) {
                $detail['examples'] = $examples[$name];
            }
        }

        if ($searchPosts) {
            $postExamples = self::searchPostsForBlock($name, $searchPostType, $maxPostExamples, $style);
            if ($postExamples) {
                $detail['post_examples'] = $postExamples;
            }
        }

        return [
            'block' => $detail,
            'demo_page' => $demoPage,
        ];
    }

    private static function formatBlockDetail(WP_Block_Type $blockType): array
    {
        $detail = [
            'name' => $blockType->name,
            'title' => $blockType->title,
            'description' => $blockType->description,
            'category' => $blockType->category,
        ];

        if ($blockType->keywords) {
            $detail['keywords'] = $blockType->keywords;
        }

        if ($blockType->parent) {
            $detail['parent'] = $blockType->parent;
        }

        if ($blockType->allowed_blocks) {
            $detail['allowed_blocks'] = $blockType->allowed_blocks;
        }

        if ($blockType->is_dynamic()) {
            $detail['is_dynamic'] = true;
        }

        // Attributes with types and defaults.
        $attributes = [];
        foreach (($blockType->attributes ?? []) as $key => $schema) {
            $attr = ['type' => $schema['type'] ?? 'string'];
            if (array_key_exists('default', $schema)) {
                $attr['default'] = $schema['default'];
            }
            if (isset($schema['enum'])) {
                $attr['enum'] = $schema['enum'];
            }
            $attributes[$key] = $attr;
        }
        if ($attributes) {
            $detail['attributes'] = $attributes;
        }

        // Supports.
        if ($blockType->supports) {
            $detail['supports'] = $blockType->supports;
        }

        // Styles (merged from block.json and WP_Block_Styles_Registry).
        $styles = self::getBlockStyles($blockType);
        if ($styles) {
            $detail['styles'] = $styles;
        }

        // Variations (PHP-registered ones; JS-only variations won't appear here).
        if ($blockType->variations) {
            $detail['variations'] = array_map(
                fn ($v) => array_filter([
                    'name' => $v['name'] ?? null,
                    'title' => $v['title'] ?? null,
                    'description' => $v['description'] ?? null,
                    'scope' => $v['scope'] ?? null,
                ]),
                $blockType->variations
            );
        }

        // Context.
        if ($blockType->uses_context) {
            $detail['uses_context'] = $blockType->uses_context;
        }
        if ($blockType->provides_context) {
            $detail['provides_context'] = $blockType->provides_context;
        }

        // Example from block.json.
        if ($blockType->example) {
            $detail['example'] = $blockType->example;
        }

        return $detail;
    }

    /**
     * Get all styles for a block, merging block.json styles with WP_Block_Styles_Registry.
     */
    private static function getBlockStyles(WP_Block_Type $blockType): array
    {
        $styles = [];

        foreach (($blockType->styles ?? []) as $style) {
            $styles[$style['name']] = [
                'name' => $style['name'],
                'label' => $style['label'] ?? $style['name'],
                'isDefault' => $style['isDefault'] ?? false,
            ];
        }

        $registered = \WP_Block_Styles_Registry::get_instance()
            ->get_registered_styles_for_block($blockType->name);
        foreach ($registered as $name => $style) {
            if (! isset($styles[$name])) {
                $styles[$name] = [
                    'name' => $name,
                    'label' => $style['label'] ?? $name,
                    'isDefault' => false,
                ];
            }
        }

        return array_values($styles);
    }
}
