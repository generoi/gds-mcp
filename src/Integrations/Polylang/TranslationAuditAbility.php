<?php

namespace GeneroWP\MCP\Integrations\Polylang;

use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Concerns\PolylangAware;
use WP_Error;
use WP_Query;

final class TranslationAuditAbility
{
    use PolylangAware;

    private const PER_PAGE = 200;

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/translations-audit', [
            'label' => 'Translation Audit',
            'description' => 'Audit all content for missing translations. Reports which posts are untranslated, partially translated, or fully translated across all languages. Paged: summary counts cover the current page; use page/total_pages to walk the rest.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_type' => [
                        'type' => 'string',
                        'description' => 'Post type to audit. Omit to audit all public post types.',
                    ],
                    'lang' => [
                        'type' => 'string',
                        'description' => 'Filter to a specific target language (e.g. "en" to find content missing English translations).',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Post status to audit.',
                        'default' => 'publish',
                    ],
                    'per_page' => [
                        'type' => 'integer',
                        'description' => 'Posts to audit per page (default 200, max 500).',
                        'default' => self::PER_PAGE,
                    ],
                    'page' => [
                        'type' => 'integer',
                        'description' => 'Page number (1-based). See total_pages in the output.',
                        'default' => 1,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'total' => ['type' => 'integer'],
                    'page' => ['type' => 'integer'],
                    'per_page' => ['type' => 'integer'],
                    'total_pages' => ['type' => 'integer'],
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
            'permission_callback' => [self::class, 'permissionCheck'],
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

    /**
     * Published content is visible to any editor; other statuses (drafts,
     * private, trash) may belong to other users.
     */
    public static function permissionCheck(mixed $input = []): bool
    {
        $status = ((array) ($input ?? []))['status'] ?? 'publish';

        return current_user_can($status === 'publish' ? 'edit_posts' : 'edit_others_posts');
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function execute(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        if (! self::polylangAvailable()) {
            return new WP_Error('polylang_not_active', 'Polylang is not active.');
        }

        $languages = self::getAllLanguages();
        $languageSlugs = array_column($languages, 'slug');
        $targetLanguage = $input['lang'] ?? null;
        $status = $input['status'] ?? 'publish';
        $perPage = max(1, min(500, (int) ($input['per_page'] ?? self::PER_PAGE)));
        $page = max(1, (int) ($input['page'] ?? 1));

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

        $query = new WP_Query([
            'post_type' => $postTypes,
            'post_status' => $status,
            'posts_per_page' => $perPage,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'lang' => '', // Disable Polylang's automatic language filtering.
        ]);

        $postsByType = [];
        foreach ($query->posts as $postId) {
            $postsByType[get_post_type($postId)][] = $postId;
        }

        foreach ($postsByType as $postType => $postIds) {
            $typeMissing = [];
            $typeTotal = 0;

            foreach ($postIds as $postId) {
                $translations = pll_get_post_translations($postId);

                // Report each translation group once, at its lowest-id member
                // with the same status — stable across pages.
                foreach ($translations as $siblingId) {
                    if ($siblingId < $postId && get_post_status($siblingId) === get_post_status($postId)) {
                        continue 2;
                    }
                }
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

                // `empty()` covered the zero case; `count > 0` is redundant.
                if (empty($missingLangs)) {
                    $fullyTranslated++;
                } elseif (count($missingLangs) < count($languageSlugs)) {
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
            'total' => (int) $query->found_posts,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) $query->max_num_pages,
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
