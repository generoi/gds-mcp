<?php

namespace GeneroWP\MCP\Integrations\Polylang;

use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Concerns\PolylangAware;
use WP_Error;
use WP_Query;

final class TranslationAuditAbility
{
    use PolylangAware;

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/translations-audit', [
            'label' => 'Translation Audit',
            'description' => 'Audit all content for missing translations. Reports which posts are untranslated, partially translated, or fully translated across all languages.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_type' => [
                        'type' => 'string',
                        'description' => 'Post type to audit. Omit to audit all public post types.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'Filter to a specific target language (e.g. "en" to find content missing English translations).',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Post status to audit.',
                        'default' => 'publish',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'summary' => [
                        'type' => 'object',
                        'properties' => [
                            'total_posts' => ['type' => 'integer'],
                            'fully_translated' => ['type' => 'integer'],
                            'partially_translated' => ['type' => 'integer'],
                            'untranslated' => ['type' => 'integer'],
                        ],
                    ],
                    'languages' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'slug' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'by_type' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'post_type' => ['type' => 'string'],
                                'total' => ['type' => 'integer'],
                                'missing' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'id' => ['type' => 'integer'],
                                            'title' => ['type' => 'string'],
                                            'source_language' => ['type' => 'string'],
                                            'missing_languages' => [
                                                'type' => 'array',
                                                'items' => ['type' => 'string'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
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
        if (! self::polylangAvailable()) {
            return new WP_Error('polylang_not_active', 'Polylang is not active.');
        }

        $languages = self::getAllLanguages();
        $languageSlugs = array_column($languages, 'slug');
        $targetLanguage = $input['language'] ?? null;
        $status = $input['status'] ?? 'publish';

        // Determine post types to audit.
        if (! empty($input['post_type'])) {
            $postTypes = [$input['post_type']];
        } else {
            $postTypes = get_post_types(['public' => true], 'names');
            $postTypes = array_values(array_diff($postTypes, ['attachment']));
        }

        $totalPosts = 0;
        $fullyTranslated = 0;
        $partiallyTranslated = 0;
        $untranslated = 0;
        $byType = [];

        foreach ($postTypes as $postType) {
            $query = new WP_Query([
                'post_type' => $postType,
                'post_status' => $status,
                'posts_per_page' => -1,
                'fields' => 'ids',
                'lang' => '', // Disable Polylang's automatic language filtering.
            ]);

            // Deduplicate: only process each translation group once.
            $processedGroups = [];
            $typeMissing = [];
            $typeTotal = 0;

            foreach ($query->posts as $postId) {
                $translations = pll_get_post_translations($postId);
                ksort($translations);
                $groupKey = implode('-', $translations);

                if (isset($processedGroups[$groupKey])) {
                    continue;
                }
                $processedGroups[$groupKey] = true;
                $typeTotal++;

                // Find missing languages.
                $missingLangs = [];
                foreach ($languageSlugs as $slug) {
                    if ($targetLanguage && $slug !== $targetLanguage) {
                        continue;
                    }
                    if (empty($translations[$slug])) {
                        $missingLangs[] = $slug;
                    }
                }

                if (empty($missingLangs)) {
                    $fullyTranslated++;
                } elseif (count($missingLangs) < count($targetLanguage ? [1] : $languageSlugs) - (count($translations) > 0 ? 0 : 1)) {
                    // Has some but not all translations.
                    $partiallyTranslated++;
                }

                if (! empty($missingLangs)) {
                    $sourcePost = get_post($postId);
                    $typeMissing[] = [
                        'id' => $postId,
                        'title' => $sourcePost ? $sourcePost->post_title : '(unknown)',
                        'source_language' => self::getPostLanguage($postId) ?? 'unknown',
                        'missing_languages' => $missingLangs,
                    ];
                }
            }

            $totalPosts += $typeTotal;

            if (! empty($typeMissing) || $typeTotal > 0) {
                $byType[] = [
                    'post_type' => $postType,
                    'total' => $typeTotal,
                    'missing' => $typeMissing,
                ];
            }
        }

        $untranslated = $totalPosts - $fullyTranslated - $partiallyTranslated;

        return [
            'summary' => [
                'total_posts' => $totalPosts,
                'fully_translated' => $fullyTranslated,
                'partially_translated' => $partiallyTranslated,
                'untranslated' => $untranslated,
            ],
            'languages' => $languages,
            'by_type' => $byType,
        ];
    }
}
