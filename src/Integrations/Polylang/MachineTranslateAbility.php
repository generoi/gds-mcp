<?php

namespace GeneroWP\MCP\Integrations\Polylang;

use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Concerns\PolylangAware;
use GeneroWP\MCP\Undo\Reversible;
use GeneroWP\MCP\Undo\Snapshot;
use PLL_MO;
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
    use Reversible;

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/translations-machine', [
            'label' => 'Machine Translate',
            'description' => self::buildDescription(),
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => [
                        'type' => 'integer',
                        'description' => 'The source post ID to translate. Required unless string_group is provided.',
                    ],
                    'lang' => [
                        'type' => 'string',
                        'description' => 'Target language slug (e.g. fi, en, sv).',
                    ],
                    'string_group' => [
                        'type' => 'string',
                        'description' => 'Translate registered Polylang strings instead of a post. Pass the group name (e.g. "WordPress", "ACF") or empty string for all groups. Use gds/strings-list to see available groups.',
                    ],
                ],
                'required' => ['lang'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'source_id' => ['type' => 'integer'],
                    'translation_id' => ['type' => 'integer'],
                    'lang' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'status' => ['type' => 'string'],
                    'url' => ['type' => 'string'],
                    'service' => ['type' => 'string'],
                ],
            ],
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

        if (! class_exists(Factory::class)) {
            return new WP_Error('machine_translation_not_available', 'Polylang Pro machine translation module is not available.');
        }

        $language = $input['lang'] ?? '';

        // Validate target language.
        $targetLang = \PLL()->model->get_language($language);
        if (! $targetLang) {
            return self::validateLanguage($language) ?? new WP_Error('invalid_language', "Invalid language: {$language}");
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
            if (! current_user_can('manage_options')) {
                return new WP_Error('forbidden', 'You do not have permission to manage string translations.', ['status' => 403]);
            }

            return $this->translateStrings($input['string_group'], $targetLang, $service, $language);
        }

        $postId = (int) ($input['id'] ?? 0);
        if (! $postId) {
            return new WP_Error('missing_input', 'Provide post_id or string_group. For terms, use gds/translations-create-term instead.');
        }

        if (! current_user_can('edit_post', $postId)) {
            return new WP_Error('forbidden', 'You do not have permission to translate this post.', ['status' => 403]);
        }

        return $this->translatePost($postId, $targetLang, $service, $language);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private function translatePost(int $postId, object $targetLang, object $service, string $language): array|WP_Error
    {
        $polylang = \PLL();
        $post = get_post($postId);
        if (! $post) {
            return new WP_Error('post_not_found', 'Source post not found.');
        }

        // Polylang's post-translation save path calls get_default_post_to_edit(),
        // which lives in wp-admin/includes/post.php and is not loaded in the
        // REST/MCP/CLI context this ability runs in.
        if (! function_exists('get_default_post_to_edit')) {
            require_once ABSPATH.'wp-admin/includes/post.php';
        }

        // Determine the undo branch BEFORE translating: if a translation in the
        // target language already exists it will be OVERWRITTEN (revert it via a
        // full post snapshot); otherwise a new post is created (undo by trashing
        // it). DeepL credits consumed by the translation are NOT refundable.
        $existingId = pll_get_post_translations($postId)[$language] ?? null;
        $beforeSnapshot = $existingId ? Snapshot::postFields((int) $existingId) : null;

        $container = new \PLL_Export_Container(Data::class);
        $exporter = new \PLL_Export_Data_From_Posts($polylang->model);
        // send_to_export() expects WP_Post[], not IDs. Passing an int ID makes
        // Polylang's ACF block dispatcher call `new Blocks($post->ID)` where
        // `$post` is the int and `->ID` resolves to null, throwing a TypeError.
        //
        // When a translation already exists, Polylang's default export skips the
        // source post (it treats "already translated" as "nothing to export").
        // That breaks re-runs and overwrites — include_translated_items keeps the
        // source post in the export so block attributes and content are re-sent.
        $exporter->send_to_export($container, [$post], $targetLang, [
            'include_translated_items' => true,
        ]);

        // Processor::__construct() takes PLL_Base by reference; assign PLL() first.
        $processor = new Processor($polylang, $service->get_client());
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
        $title = $translatedPost ? $translatedPost->post_title : '';

        $response = [
            'type' => 'post',
            'source_id' => $postId,
            'translation_id' => $translationId,
            'lang' => $language,
            'title' => $title,
            'status' => $translatedPost ? $translatedPost->post_status : '',
            'url' => $translatedPost ? get_permalink($translatedPost) : '',
            'service' => $service->get_name(),
        ];

        if ($existingId) {
            // An existing translation was overwritten — revert it to the
            // captured prior state.
            return $this->reversible(
                $response,
                'restore-post',
                $beforeSnapshot,
                sprintf('Revert the machine translation of "%s"', $title),
            );
        }

        // A new translation post was created — undo by trashing it.
        return $this->reversible(
            $response,
            'trash',
            ['id' => (int) $translationId],
            'Delete the machine-created translation',
        );
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    private function translateStrings(string $group, object $targetLang, object $service, string $language): array|WP_Error
    {
        $polylang = \PLL();
        $sources = \PLL_Admin_Strings::get_strings();

        if ($group !== '') {
            $sources = array_filter($sources, fn ($s) => ($s['context'] ?? '') === $group);
        }

        if (empty($sources)) {
            return new WP_Error('no_strings', sprintf('No registered strings found for group "%s".', $group));
        }

        // Capture each string's prior translation in the target language BEFORE
        // translating, mirroring UpdateStringTranslationAbility (PLL_MO import +
        // translate). On undo each prior value is re-applied; an empty string
        // clears the entry when there was no prior translation. DeepL credits
        // consumed by the translation are NOT refundable by undo.
        $undoItems = [];
        if (class_exists('PLL_MO')) {
            $mo = new PLL_MO;
            $mo->import_from_db($targetLang);
            foreach ($sources as $source) {
                $src = (string) ($source['string'] ?? '');
                if ($src === '') {
                    continue;
                }
                $prev = $mo->translate($src);
                $prev = ($prev !== $src && $prev !== '') ? $prev : '';
                $undoItems[] = [
                    'kind' => 'restore-string',
                    'data' => ['string' => $src, 'lang' => $language, 'translation' => (string) $prev],
                ];
            }
        }

        $container = new \PLL_Export_Container(Data::class);
        $exporter = new \PLL_Export_Data_From_Strings($polylang->model);
        $exporter->send_to_export($container, $sources, $targetLang, true);

        // Processor::__construct() takes PLL_Base by reference; assign PLL() first.
        $processor = new Processor($polylang, $service->get_client());
        $result = $processor->translate($container);

        if ($result->has_errors()) {
            return new WP_Error('translation_failed', implode('; ', $result->get_error_messages()));
        }

        $result = $processor->save($container);

        if ($result->has_errors()) {
            return new WP_Error('save_failed', implode('; ', $result->get_error_messages()));
        }

        $response = [
            'type' => 'strings',
            'group' => $group ?: '(all)',
            'lang' => $language,
            'count' => count($sources),
            'service' => $service->get_name(),
        ];

        return $this->reversible($response, 'bulk', ['items' => $undoItems], 'Revert the machine-translated strings');
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
