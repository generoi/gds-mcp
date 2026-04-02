<?php

namespace GeneroWP\MCP\Concerns;

trait AcfAware
{
    private static function acfAvailable(): bool
    {
        return function_exists('get_field_objects') && function_exists('update_field');
    }

    /**
     * Update ACF fields on a post using update_field() so ACF hooks fire
     * (bidirectional relationships, validation, etc.).
     */
    private static function updateAcfFields(int $postId, array $fields): void
    {
        if (! self::acfAvailable()) {
            return;
        }

        foreach ($fields as $key => $value) {
            update_field($key, $value, $postId);
        }
    }

    /**
     * Get structured ACF field data for a post.
     *
     * Returns field objects with label, type, and formatted value — richer
     * than raw post meta. Excludes internal/system fields.
     */
    private static function getAcfFields(int $postId): ?array
    {
        if (! self::acfAvailable()) {
            return null;
        }

        $fieldObjects = get_field_objects($postId);
        if (! $fieldObjects) {
            return null;
        }

        $result = [];
        foreach ($fieldObjects as $name => $field) {
            $item = [
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => $field['type'],
                'value' => $field['value'],
            ];

            // Include choices for select/checkbox/radio fields.
            if (! empty($field['choices'])) {
                $item['choices'] = $field['choices'];
            }

            // For relationship/post_object fields, include the post type constraint.
            if (! empty($field['post_type'])) {
                $item['post_type'] = $field['post_type'];
            }

            // For taxonomy fields, include the taxonomy.
            if (! empty($field['taxonomy'])) {
                $item['taxonomy'] = $field['taxonomy'];
            }

            $result[$name] = $item;
        }

        return $result;
    }
}
