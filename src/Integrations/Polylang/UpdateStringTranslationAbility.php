<?php

namespace GeneroWP\MCP\Integrations\Polylang;

use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Concerns\PolylangAware;
use PLL_MO;
use WP_Error;

final class UpdateStringTranslationAbility
{
    use PolylangAware;

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/strings/update', [
            'label' => 'Update String Translation',
            'description' => 'Update the translation of a registered Polylang string for a specific language. Use gds/strings/list first to find the exact string value to translate.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'string' => [
                        'type' => 'string',
                        'description' => 'The original (source) string value to translate. Must match exactly.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'Target language slug (e.g. fi, en, sv).',
                    ],
                    'translation' => [
                        'type' => 'string',
                        'description' => 'The translated string value.',
                    ],
                ],
                'required' => ['string', 'language', 'translation'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'string' => ['type' => 'string'],
                    'language' => ['type' => 'string'],
                    'translation' => ['type' => 'string'],
                    'previous' => ['type' => ['string', 'null']],
                ],
            ],
            'permission_callback' => [self::class, 'checkPermission'],
            'execute_callback' => [self::class, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => false,
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

        if (! current_user_can('manage_options')) {
            return new WP_Error('insufficient_capability', 'You do not have permission to update string translations.');
        }

        return true;
    }

    public static function execute(?array $input = []): array|WP_Error
    {
        if (! self::polylangAvailable() || ! class_exists('PLL_MO')) {
            return new WP_Error('polylang_not_active', 'Polylang is not active.');
        }

        $string = $input['string'] ?? '';
        $language = $input['language'] ?? '';
        $translation = $input['translation'] ?? '';

        if (empty($string) || empty($language) || empty($translation)) {
            return new WP_Error('missing_fields', 'string, language, and translation are all required.');
        }

        // Validate language.
        $langObject = \PLL()->model->get_language($language);
        if (! $langObject) {
            $validLanguages = array_column(self::getAllLanguages(), 'slug');

            return new WP_Error('invalid_language', sprintf(
                'Invalid language "%s". Valid languages: %s',
                $language,
                implode(', ', $validLanguages)
            ));
        }

        // Load existing translations for this language.
        $mo = new PLL_MO;
        $mo->import_from_db($langObject);

        // Get previous translation.
        $previous = $mo->translate($string);
        $previous = ($previous !== $string && $previous !== '') ? $previous : null;

        // Add/update the translation entry.
        $mo->add_entry($mo->make_entry($string, $translation));

        // Save back to database.
        $mo->export_to_db($langObject);

        return [
            'string' => $string,
            'language' => $language,
            'translation' => $translation,
            'previous' => $previous,
        ];
    }
}
