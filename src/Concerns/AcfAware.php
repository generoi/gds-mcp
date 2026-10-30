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
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, string> Field key → why it was not saved.
     */
    private static function updateAcfFields(int $postId, array $fields): array
    {
        if (! self::acfAvailable()) {
            return array_fill_keys(array_map('strval', array_keys($fields)), 'ACF is not active.');
        }

        $errors = [];
        foreach ($fields as $key => $value) {
            // Only allow registered ACF field keys/names — reject arbitrary meta.
            $fieldObj = acf_get_field($key);
            if (! $fieldObj) {
                $errors[(string) $key] = 'Not a registered ACF field key or name.';

                continue;
            }
            // update_field() is also false when the value was already stored.
            if (! update_field($fieldObj['key'], $value, $postId) && acf_get_value($postId, $fieldObj) != $value) {
                $errors[(string) $key] = 'ACF did not save the value.';
            }
        }

        return $errors;
    }
}
