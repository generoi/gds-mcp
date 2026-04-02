<?php

namespace GeneroWP\MCP\Integrations\Polylang;

use GeneroWP\MCP\Concerns\PolylangAware;
use PLL_MO;
use WP_Error;

final class ListStringTranslationsAbility
{
    use PolylangAware;

    public static function register(): void
    {
        wp_register_ability('gds/strings/list', [
            'label' => 'List String Translations',
            'description' => 'List all registered Polylang string translations with their translations in each language. Useful for auditing missing translations or finding strings to update (e.g. button labels, widget titles, theme strings).',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'group' => [
                        'type' => 'string',
                        'description' => 'Filter by string group/context (e.g. "Polylang", "Widget", a plugin name).',
                    ],
                    'search' => [
                        'type' => 'string',
                        'description' => 'Search in string name, value, or translations.',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'groups' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                    'strings' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'string' => ['type' => 'string'],
                                'group' => ['type' => 'string'],
                                'multiline' => ['type' => 'boolean'],
                                'translations' => ['type' => 'object'],
                            ],
                        ],
                    ],
                    'total' => ['type' => 'integer'],
                ],
            ],
            'permission_callback' => [self::class, 'checkPermission'],
            'execute_callback' => [self::class, 'execute'],
            'meta' => [
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

        if (! current_user_can('read')) {
            return new WP_Error('insufficient_capability', 'You do not have permission to view string translations.');
        }

        return true;
    }

    public static function execute(?array $input = []): array|WP_Error
    {
        if (! self::polylangAvailable() || ! class_exists('PLL_Admin_Strings')) {
            return new WP_Error('polylang_not_active', 'Polylang is not active.');
        }

        // Get all registered strings.
        $registeredStrings = \PLL_Admin_Strings::get_strings();

        // Load translations for each language.
        $languages = self::getAllLanguages();
        $moObjects = [];
        foreach ($languages as $lang) {
            $langObject = \PLL()->model->get_language($lang['slug']);
            if ($langObject) {
                $mo = new PLL_MO;
                $mo->import_from_db($langObject);
                $moObjects[$lang['slug']] = $mo;
            }
        }

        $group = $input['group'] ?? null;
        $search = $input['search'] ?? null;
        $groups = [];
        $strings = [];

        foreach ($registeredStrings as $registered) {
            $context = $registered['context'] ?? 'Polylang';
            $groups[$context] = true;

            // Filter by group.
            if ($group && $context !== $group) {
                continue;
            }

            $stringValue = $registered['string'] ?? '';
            $stringName = $registered['name'] ?? '';

            // Build translations map.
            $translations = [];
            foreach ($languages as $lang) {
                $slug = $lang['slug'];
                if (isset($moObjects[$slug])) {
                    $translated = $moObjects[$slug]->translate($stringValue);
                    $translations[$slug] = ($translated !== $stringValue && $translated !== '') ? $translated : null;
                } else {
                    $translations[$slug] = null;
                }
            }

            // Filter by search.
            if ($search) {
                $haystack = strtolower($stringName.' '.$stringValue.' '.implode(' ', array_filter($translations)));
                if (! str_contains($haystack, strtolower($search))) {
                    continue;
                }
            }

            $strings[] = [
                'name' => $stringName,
                'string' => $stringValue,
                'group' => $context,
                'multiline' => ! empty($registered['multiline']),
                'translations' => $translations,
            ];
        }

        return [
            'groups' => array_keys($groups),
            'strings' => $strings,
            'total' => count($strings),
        ];
    }
}
