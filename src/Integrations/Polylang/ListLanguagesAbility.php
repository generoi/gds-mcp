<?php

namespace GeneroWP\MCP\Integrations\Polylang;

use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Concerns\PolylangAware;
use WP_Error;

final class ListLanguagesAbility
{
    use PolylangAware;

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/languages-list', [
            'label' => 'List Languages',
            'description' => 'List all Polylang languages with their slug, locale, active status, and post counts. Includes both enabled and disabled languages — disabled languages are only visible to admins for editing purposes.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'include_disabled' => [
                        'type' => 'boolean',
                        'description' => 'Include disabled (admin-only) languages. Default true.',
                        'default' => true,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'default' => ['type' => 'string'],
                    'languages' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'slug' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'locale' => ['type' => 'string'],
                                'active' => ['type' => 'boolean'],
                                'is_default' => ['type' => 'boolean'],
                                'order' => ['type' => 'integer'],
                                'flag' => ['type' => 'string'],
                                'post_count' => ['type' => 'integer'],
                            ],
                        ],
                    ],
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
            return new WP_Error('insufficient_capability', 'You do not have permission to list languages.');
        }

        return true;
    }

    public static function execute(?array $input = []): array|WP_Error
    {
        if (! self::polylangAvailable() || ! function_exists('PLL') || ! PLL()) {
            return new WP_Error('polylang_not_active', 'Polylang is not active.');
        }

        $includeDisabled = $input['include_disabled'] ?? true;
        $defaultSlug = pll_default_language();
        $allLanguages = PLL()->model->get_languages_list();

        $languages = [];
        foreach ($allLanguages as $lang) {
            if (! $includeDisabled && empty($lang->active)) {
                continue;
            }

            $postCount = 0;
            if (function_exists('pll_count_posts')) {
                $postCount = (int) pll_count_posts($lang->slug);
            }

            $languages[] = [
                'slug' => $lang->slug,
                'name' => $lang->name,
                'locale' => $lang->locale,
                'active' => ! empty($lang->active),
                'is_default' => $lang->slug === $defaultSlug,
                'order' => (int) $lang->term_group,
                'flag' => $lang->flag_url ?? '',
                'post_count' => $postCount,
            ];
        }

        // Sort by order (term_group).
        usort($languages, fn ($a, $b) => $a['order'] <=> $b['order']);

        return [
            'default' => $defaultSlug,
            'languages' => $languages,
        ];
    }
}
