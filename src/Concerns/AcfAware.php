<?php

namespace GeneroWP\MCP\Concerns;

trait AcfAware
{
    /**
     * Field types ACF stores as sent, so a stored value can tell a refused
     * write from an unchanged one.
     *
     * @var list<string>
     */
    private static array $comparableAcfTypes = ['text', 'textarea', 'number', 'range', 'email', 'url', 'password', 'wysiwyg', 'oembed', 'color_picker', 'radio', 'button_group'];

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
            $comparable = in_array($fieldObj['type'] ?? '', self::$comparableAcfTypes, true);
            $stored = $comparable ? acf_get_value($postId, $fieldObj) : null;
            // update_field() ends in update_metadata(), which unslashes.
            $saved = update_field($fieldObj['key'], wp_slash($value), $postId);
            // It is also false when the value was already stored. A refusal
            // leaves the stored value as it was and unlike the one sent; one
            // that ACF reshaped or sanitized on the way in is not a refusal.
            // Groups, repeaters, dates are stored in another shape than sent,
            // so only plain fields are judged.
            if (! $saved && $comparable) {
                $after = acf_get_value($postId, $fieldObj);
                if ($after === $stored && $after != $value) {
                    $errors[(string) $key] = 'ACF did not save the value.';
                }
            }
        }

        return $errors;
    }
}
