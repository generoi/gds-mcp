<?php

namespace GeneroWP\MCP\Integrations\Polylang;

use GeneroWP\MCP\Abilities\GenericPostTypeAbility;
use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Concerns\PolylangAware;
use GeneroWP\MCP\Concerns\PostCopying;
use GeneroWP\MCP\Concerns\RestDelegation;
use GeneroWP\MCP\Undo\Reversible;
use WP_Error;

final class CreateTranslationAbility
{
    use PolylangAware;
    use PostCopying;
    use RestDelegation;
    use Reversible;

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/translations-create', [
            'label' => 'Create Translation',
            'description' => 'Create a translated post linked via Polylang. Copies source content, author, featured image, meta, and taxonomy terms. Pass `seo` to set the translation\'s SEO title and description in the same call. '
                .'Use this when you have an existing post and want to fork it into another language — the new post is automatically linked so the language switcher works.'
                ."\n\nIf you already created the translations separately (via gds/content-create with different `lang` values), use gds/translations-link to connect them instead."
                ."\n\nRelated: gds/translations-machine (machine translation), gds/translations-create-term (for taxonomy terms).",
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'source_id' => [
                        'type' => 'integer',
                        'description' => 'The post ID to create a translation of.',
                    ],
                    'lang' => [
                        'type' => 'string',
                        'description' => 'Target language slug (e.g. fi, en, sv).',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Translated title. Defaults to source title.',
                    ],
                    'content' => [
                        'type' => 'string',
                        'description' => 'Translated content (raw block markup). Defaults to source content.',
                    ],
                    'excerpt' => [
                        'type' => 'string',
                        'description' => 'Translated excerpt. Defaults to source excerpt.',
                    ],
                    'status' => [
                        'type' => 'string',
                        'description' => 'Status for the new post.',
                        'default' => 'draft',
                        'enum' => ['draft', 'publish', 'pending', 'private'],
                    ],
                    'slug' => [
                        'type' => 'string',
                        'description' => 'URL slug for the new post. Auto-generated if omitted.',
                    ],
                    'author' => [
                        'type' => 'integer',
                        'description' => 'Author user ID. Defaults to the source post\'s author.',
                    ],
                    'featured_media' => [
                        'type' => 'integer',
                        'description' => 'Featured image attachment ID. Defaults to the source image, or its translation in the target language when media is translated.',
                    ],
                    'seo' => [
                        'type' => 'object',
                        'description' => 'Yoast SEO fields for the translation, same shape as gds/seo-update `fields` (title, description, …). Ignored when Yoast is not active.',
                    ],
                    'quiet' => [
                        'type' => 'boolean',
                        'description' => 'Return only id, status, modified, link, edit_url and preview_url. Default: true. Pass false to get the whole post back.',
                        'default' => true,
                    ],
                ],
                'required' => ['source_id', 'lang'],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object', 'additionalProperties' => true],
            'permission_callback' => '__return_true',
            'execute_callback' => [new self, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => false,
                    'destructive' => false,
                    'idempotent' => false,
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
        if (! self::polylangAvailable()) {
            return new WP_Error('polylang_not_active', 'Polylang is not active.');
        }

        $sourceId = (int) ($input['source_id'] ?? 0);
        $language = $input['lang'] ?? '';

        $source = get_post($sourceId);
        if (! $source) {
            return new WP_Error('source_not_found', 'Source post not found.');
        }

        if (! current_user_can('edit_post', $sourceId)) {
            return new WP_Error('forbidden', 'You do not have permission to read this post.', ['status' => 403]);
        }

        $typeObj = get_post_type_object($source->post_type);
        if (! $typeObj || ! current_user_can($typeObj->cap->create_posts)) {
            return new WP_Error('forbidden', 'You do not have permission to create posts of this type.', ['status' => 403]);
        }

        $langError = self::validateLanguage($language);
        if ($langError) {
            return $langError;
        }

        // Check if translation already exists.
        $existingTranslations = pll_get_post_translations($sourceId);
        if (! empty($existingTranslations[$language])) {
            $existingId = $existingTranslations[$language];

            return new WP_Error('translation_exists', sprintf(
                'A %s translation already exists (ID: %d).',
                $language,
                $existingId
            ), ['existing_id' => $existingId]);
        }

        // Build the new post data, defaulting to source content.
        $postData = [
            'post_type' => $source->post_type,
            'post_title' => $input['title'] ?? $source->post_title,
            'post_content' => $input['content'] ?? $source->post_content,
            'post_excerpt' => $input['excerpt'] ?? $source->post_excerpt,
            'post_status' => $input['status'] ?? 'draft',
            'post_password' => $source->post_password,
            'comment_status' => $source->comment_status,
            'ping_status' => $source->ping_status,
        ];

        if (in_array($postData['post_status'], ['publish', 'private', 'future'], true) && ! current_user_can($typeObj->cap->publish_posts)) {
            return new WP_Error('forbidden', 'You do not have permission to publish posts of this type.', ['status' => 403]);
        }

        // Keep the source's author, unless that would hand the caller a post
        // they may not edit.
        $mayAssignAuthor = current_user_can($typeObj->cap->edit_others_posts);
        if (isset($input['author']) && (int) $input['author'] !== get_current_user_id() && ! $mayAssignAuthor) {
            return new WP_Error('forbidden', 'You do not have permission to create posts as another user.', ['status' => 403]);
        }
        $postData['post_author'] = (int) ($input['author'] ?? ($mayAssignAuthor ? $source->post_author : get_current_user_id()));

        if (isset($input['slug'])) {
            $postData['post_name'] = $input['slug'];
        }

        // Resolve parent hierarchy.
        if ($source->post_parent) {
            $parentTranslations = pll_get_post_translations($source->post_parent);
            if (! empty($parentTranslations[$language])) {
                $postData['post_parent'] = $parentTranslations[$language];
            } else {
                $postData['post_parent'] = 0;
            }
        }

        // wp_insert_post() unslashes its input; slash so serialized block
        // attributes keep their backslashes.
        $newId = wp_insert_post(wp_slash($postData), true);

        if (is_wp_error($newId)) {
            return $newId;
        }

        // Set language and link translations.
        pll_set_post_language($newId, $language);

        $translations = pll_get_post_translations($sourceId);
        $translations[$language] = $newId;
        pll_save_post_translations($translations);

        self::copyPostMeta($sourceId, $newId, duplicate: false);
        self::copyPostTaxonomies($sourceId, $newId, $source->post_type);

        $thumbnailId = (int) ($input['featured_media'] ?? get_post_thumbnail_id($sourceId));
        if (! isset($input['featured_media']) && $thumbnailId) {
            $thumbnailId = (int) (pll_get_post($thumbnailId, $language) ?: $thumbnailId);
        }
        if ($thumbnailId) {
            set_post_thumbnail($newId, $thumbnailId);
        }

        $seoError = null;
        if (! empty($input['seo']) && is_array($input['seo'])) {
            $seoResult = GenericPostTypeAbility::updateSeo($newId, $input['seo']);
            $seoError = is_wp_error($seoResult) ? $seoResult->get_error_message() : null;
        }

        // Return canonical REST response
        $route = self::getRestRoute($source->post_type);
        if ($route) {
            $response = self::restGet("{$route}/{$newId}");
            if (! self::isRestError($response)) {
                $result = self::restResponseData($response);
                if ($seoError) {
                    $result['seo_error'] = $seoError;
                }
                if ($input['quiet'] ?? true) {
                    $result = GenericPostTypeAbility::summary(GenericPostTypeAbility::enrichPostUrls($result));
                }

                return $this->reversible($result, 'trash', ['id' => $newId], 'Delete the created translation');
            }
        }

        $result = ['id' => $newId, 'source_id' => $sourceId];

        return $this->reversible($result, 'trash', ['id' => $newId], 'Delete the created translation');
    }
}
