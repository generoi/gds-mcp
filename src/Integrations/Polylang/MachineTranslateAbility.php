<?php

namespace GeneroWP\MCP\Integrations\Polylang;

use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Concerns\PolylangAware;
use WP_Error;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Data;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Factory;
use WP_Syntex\Polylang_Pro\Modules\Machine_Translation\Processor;

/**
 * Machine-translate a post using Polylang Pro's configured translation service (e.g. DeepL).
 * Creates or updates a translation with machine-translated content.
 */
final class MachineTranslateAbility
{
    use PolylangAware;

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/translations-machine', [
            'label' => 'Machine Translate',
            'description' => self::buildDescription(),
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'post_id' => [
                        'type' => 'integer',
                        'description' => 'The source post ID to translate. Required unless string_group is provided.',
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'Target language slug (e.g. fi, en, sv).',
                    ],
                    'string_group' => [
                        'type' => 'string',
                        'description' => 'Translate registered Polylang strings instead of a post. Pass the group name (e.g. "WordPress", "ACF") or empty string for all groups. Use gds/strings-list to see available groups.',
                    ],
                ],
                'required' => ['language'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'source_id' => ['type' => 'integer'],
                    'translation_id' => ['type' => 'integer'],
                    'language' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'url' => ['type' => 'string'],
                    'service' => ['type' => 'string'],
                ],
            ],
            'permission_callback' => [self::class, 'checkPermission'],
            'execute_callback' => [self::class, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => false,
                    'destructive' => false,
                    'idempotent' => false,
                ],
            ],
        ]);
    }

    public static function checkPermission(?array $input = []): bool|WP_Error
    {
        if (! is_user_logged_in()) {
            return new WP_Error('authentication_required', 'User must be authenticated.');
        }

        if (! current_user_can('edit_posts')) {
            return new WP_Error('insufficient_capability', 'You do not have permission to create translations.');
        }

        $postId = $input['post_id'] ?? 0;
        if ($postId && ! current_user_can('read_post', $postId)) {
            return new WP_Error('insufficient_capability', 'You do not have permission to read the source post.');
        }

        return true;
    }

    public static function execute(?array $input = []): array|WP_Error
    {
        if (! self::polylangAvailable()) {
            return new WP_Error('polylang_not_active', 'Polylang is not active.');
        }

        if (! class_exists(Factory::class)) {
            return new WP_Error('machine_translation_not_available', 'Polylang Pro machine translation module is not available.');
        }

        $language = $input['language'] ?? '';

        // Validate target language.
        $targetLang = \PLL()->model->get_language($language);
        if (! $targetLang) {
            $validLanguages = array_column(self::getAllLanguages(), 'slug');

            return new WP_Error('invalid_language', sprintf(
                'Invalid language "%s". Valid languages: %s',
                $language,
                implode(', ', $validLanguages)
            ));
        }

        // Check machine translation is enabled and service is active.
        $factory = new Factory(\PLL()->model);

        if (! $factory->is_enabled()) {
            return new WP_Error('machine_translation_disabled', 'Machine translation is disabled in Polylang settings.');
        }

        $service = $factory->get_active_service();
        if (! $service) {
            return new WP_Error('no_translation_service', 'No machine translation service is configured. Set up DeepL in Polylang settings.');
        }

        // Dispatch to the right translation type.
        if (isset($input['string_group'])) {
            return self::translateStrings($input['string_group'], $targetLang, $service, $language);
        }

        $postId = $input['post_id'] ?? 0;
        if (! $postId) {
            return new WP_Error('missing_input', 'Provide post_id or string_group. For terms, use gds/translations-create-term instead.');
        }

        return self::translatePost($postId, $targetLang, $service, $language);
    }

    private static function translatePost(int $postId, object $targetLang, object $service, string $language): array|WP_Error
    {
        $post = get_post($postId);
        if (! $post) {
            return new WP_Error('post_not_found', 'Source post not found.');
        }

        $container = new \PLL_Export_Container(Data::class);
        $exporter = new \PLL_Export_Data_From_Posts(\PLL()->model);
        $exporter->send_to_export($container, [$postId], $targetLang);

        $processor = new Processor(\PLL(), $service->get_client());
        $result = $processor->translate($container);

        if ($result->has_errors()) {
            return new WP_Error('translation_failed', implode('; ', $result->get_error_messages()));
        }

        $result = $processor->save($container);

        if ($result->has_errors()) {
            return new WP_Error('save_failed', implode('; ', $result->get_error_messages()));
        }

        $translations = pll_get_post_translations($postId);
        $translationId = $translations[$language] ?? 0;
        $translatedPost = $translationId ? get_post($translationId) : null;

        return [
            'type' => 'post',
            'source_id' => $postId,
            'translation_id' => $translationId,
            'language' => $language,
            'title' => $translatedPost ? $translatedPost->post_title : '',
            'status' => $translatedPost ? $translatedPost->post_status : '',
            'url' => $translatedPost ? get_permalink($translatedPost) : '',
            'service' => $service->get_name(),
        ];
    }

    private static function translateStrings(string $group, object $targetLang, object $service, string $language): array|WP_Error
    {
        $sources = \PLL_Admin_Strings::get_strings();

        if ($group !== '') {
            $sources = array_filter($sources, fn ($s) => ($s['context'] ?? '') === $group);
        }

        if (empty($sources)) {
            return new WP_Error('no_strings', sprintf('No registered strings found for group "%s".', $group));
        }

        $container = new \PLL_Export_Container(Data::class);
        $exporter = new \PLL_Export_Data_From_Strings(\PLL()->model);
        $exporter->send_to_export($container, $sources, $targetLang, true);

        $processor = new Processor(\PLL(), $service->get_client());
        $result = $processor->translate($container);

        if ($result->has_errors()) {
            return new WP_Error('translation_failed', implode('; ', $result->get_error_messages()));
        }

        $result = $processor->save($container);

        if ($result->has_errors()) {
            return new WP_Error('save_failed', implode('; ', $result->get_error_messages()));
        }

        return [
            'type' => 'strings',
            'group' => $group ?: '(all)',
            'language' => $language,
            'count' => count($sources),
            'service' => $service->get_name(),
        ];
    }

    private static function buildDescription(): string
    {
        $desc = 'Machine-translate content using Polylang Pro\'s configured translation service (e.g. DeepL). '
            .'Supports posts (provide post_id) and registered string translations (provide string_group). '
            .'For posts: translates title, content, excerpt, and meta. For strings: translates all strings in the group.';

        if (class_exists(Factory::class)) {
            $factory = new Factory(\PLL()->model);
            $service = $factory->get_active_service();
            if ($service) {
                $desc .= sprintf(' Active service: %s.', $service->get_name());
            }
        }

        return $desc;
    }
}
