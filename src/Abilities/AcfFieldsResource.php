<?php

namespace GeneroWP\MCP\Abilities;

use WP_Error;

final class AcfFieldsResource
{
    public static function register(): void
    {
        HelpAbility::registerAbility('gds/acf-fields', [
            'label' => 'ACF Field Groups',
            'description' => 'Read-only index of ACF field groups with their fields, types, and post type assignments. Use this to discover available fields before reading or updating post data with gds/posts-read or gds/posts-update.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'default' => [],
                'properties' => [],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'field_groups' => [
                        'type' => 'array',
                        'items' => ['type' => 'object'],
                    ],
                ],
            ],
            'permission_callback' => '__return_true',
            'execute_callback' => [new self, 'execute'],
            'meta' => [
                // Provide uri/mimeType/annotations in BOTH locations for adapter
                // cross-compatibility: WordPress/mcp-adapter >= 0.5.0 reads meta.mcp.*
                // (canonical), while < 0.5.0 reads only top-level meta.*. Keeping both
                // works on every adapter version without triggering 0.5.0 deprecation
                // notices (the mcp.* copy is matched first). The top-level annotations
                // copy is also what RegisterAbilityAsMcpTool still reads in 0.5.0.
                'uri' => 'acf://fields',
                'mimeType' => 'application/json',
                'mcp' => [
                    'type' => 'resource',
                    'public' => true,
                    'uri' => 'acf://fields',
                    'mimeType' => 'application/json',
                    'annotations' => [
                        'readonly' => true,
                        'destructive' => false,
                        'idempotent' => true,
                    ],
                ],
                'annotations' => [
                    'readonly' => true,
                    'destructive' => false,
                    'idempotent' => true,
                ],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function execute(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        if (! function_exists('acf_get_field_groups') || ! function_exists('acf_get_fields')) {
            return new WP_Error('acf_not_active', 'ACF Pro is not active.');
        }

        $groups = acf_get_field_groups();
        $result = [];

        foreach ($groups as $group) {
            if (! $group['active']) {
                continue;
            }

            $fields = acf_get_fields($group['key']);
            $postTypes = self::extractPostTypes($group['location']);

            $result[] = [
                'key' => $group['key'],
                'title' => $group['title'],
                'post_types' => $postTypes,
                'position' => $group['position'] ?? 'normal',
                'fields' => array_map([self::class, 'formatField'], $fields ?: []),
            ];
        }

        return ['field_groups' => $result];
    }

    /**
     * Format a single ACF field for the API response.
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed>
     */
    private static function formatField(array $field): array
    {
        $item = [
            'key' => $field['key'],
            'name' => $field['name'],
            'label' => $field['label'],
            'type' => $field['type'],
            'required' => ! empty($field['required']),
        ];

        if (! empty($field['instructions'])) {
            $item['instructions'] = $field['instructions'];
        }

        if (! empty($field['default_value'])) {
            $item['default_value'] = $field['default_value'];
        }

        if (! empty($field['choices'])) {
            $item['choices'] = $field['choices'];
        }

        // Relationship / post_object constraints.
        if (! empty($field['post_type'])) {
            $item['post_type'] = $field['post_type'];
        }

        // Taxonomy constraints.
        if (! empty($field['taxonomy'])) {
            $item['taxonomy'] = $field['taxonomy'];
        }

        // Return format (url, array, id, etc.).
        if (! empty($field['return_format'])) {
            $item['return_format'] = $field['return_format'];
        }

        // Min/max for relationship, repeater, etc.
        if (isset($field['min'])) {
            $item['min'] = $field['min'];
        }
        if (isset($field['max'])) {
            $item['max'] = $field['max'];
        }

        // Sub-fields for group/repeater/flexible content.
        if (! empty($field['sub_fields'])) {
            $item['sub_fields'] = array_map([self::class, 'formatField'], $field['sub_fields']);
        }

        // Layouts for flexible content.
        if (! empty($field['layouts'])) {
            $item['layouts'] = array_map(function ($layout) {
                return [
                    'key' => $layout['key'],
                    'name' => $layout['name'],
                    'label' => $layout['label'],
                    'sub_fields' => array_map([self::class, 'formatField'], $layout['sub_fields'] ?? []),
                ];
            }, $field['layouts']);
        }

        return $item;
    }

    /**
     * Extract post type names from ACF location rules.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $locationGroups
     * @return array<int, string>
     */
    private static function extractPostTypes(array $locationGroups): array
    {
        $postTypes = [];

        foreach ($locationGroups as $group) {
            foreach ($group as $rule) {
                if (($rule['param'] ?? '') === 'post_type' && ($rule['operator'] ?? '') === '==') {
                    $postTypes[] = $rule['value'];
                }
            }
        }

        return array_unique($postTypes);
    }
}
