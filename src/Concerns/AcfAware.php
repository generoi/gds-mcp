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
            // Only allow registered ACF field keys/names — reject arbitrary meta.
            $fieldObj = acf_get_field($key);
            if (! $fieldObj) {
                continue;
            }
            update_field($fieldObj['key'], $value, $postId);
        }
    }
}
