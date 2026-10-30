<?php

namespace GeneroWP\MCP\Integrations\Yoast;

use GeneroWP\MCP\Abilities\HelpAbility;
use GeneroWP\MCP\Undo\Reversible;
use GeneroWP\MCP\Undo\Snapshot;
use GeneroWP\MCP\Undo\Snapshots\PartialPostSnapshot;
use WP_Error;
use WP_Post;
use WP_Term;

/**
 * Read and update Yoast SEO fields — search appearance, robots and social
 * sharing (Open Graph / X) — for a post or a term.
 */
final class SeoAbility
{
    use Reversible;

    public static function register(): void
    {
        $target = [
            'object_type' => [
                'type' => 'string',
                'description' => 'Whether `id` is a post (any post type) or a taxonomy term.',
                'enum' => ['post', 'term'],
            ],
            'id' => [
                'type' => 'integer',
                'description' => 'Post ID or term ID.',
            ],
        ];

        HelpAbility::registerAbility('gds/seo-read', [
            'label' => 'Read SEO',
            'description' => 'Read the Yoast SEO fields of a post or term: SEO title, meta description, focus keyphrase, canonical, robots index setting, cornerstone, breadcrumb title, primary category, and the social sharing (Open Graph / X) title, description and image. `fields` holds what is stored (empty means Yoast falls back to its templates); `resolved` holds what Yoast actually outputs.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => $target,
                'required' => ['object_type', 'id'],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object', 'additionalProperties' => true],
            'permission_callback' => '__return_true',
            'execute_callback' => [new self, 'read'],
            'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        HelpAbility::registerAbility('gds/seo-update', [
            'label' => 'Update SEO',
            'description' => 'Update Yoast SEO fields of a post or term. Only the fields given are changed; pass an empty string (or 0 for ids) to clear a field so Yoast falls back to its default. Social images take a media library attachment ID. Titles and descriptions may use Yoast variables such as %%title%% and %%sep%%.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => $target + [
                    'fields' => [
                        'type' => 'object',
                        'description' => 'SEO fields to change.',
                        'properties' => [
                            'title' => ['type' => 'string', 'description' => 'SEO title (the <title> tag).'],
                            'description' => ['type' => 'string', 'description' => 'Meta description.'],
                            'focus_keyphrase' => ['type' => 'string'],
                            'canonical' => ['type' => 'string', 'description' => 'Absolute canonical URL.'],
                            'breadcrumb_title' => ['type' => 'string'],
                            'robots_index' => [
                                'type' => 'string',
                                'enum' => ['default', 'index', 'noindex'],
                                'description' => 'Whether search engines may index it. `default` follows the post type / taxonomy setting.',
                            ],
                            'cornerstone' => ['type' => 'boolean'],
                            'opengraph_title' => ['type' => 'string', 'description' => 'Social share title (Facebook, LinkedIn, …).'],
                            'opengraph_description' => ['type' => 'string'],
                            'opengraph_image_id' => ['type' => 'integer', 'description' => 'Attachment ID of the social share image.'],
                            'twitter_title' => ['type' => 'string', 'description' => 'X (Twitter) title. Falls back to the Open Graph title.'],
                            'twitter_description' => ['type' => 'string'],
                            'twitter_image_id' => ['type' => 'integer'],
                            'primary_category' => ['type' => 'integer', 'description' => 'Posts only: ID of an assigned category to use as primary.'],
                        ],
                        'additionalProperties' => false,
                    ],
                ],
                'required' => ['object_type', 'id', 'fields'],
                'additionalProperties' => false,
            ],
            'output_schema' => ['type' => 'object', 'additionalProperties' => true],
            'permission_callback' => '__return_true',
            'execute_callback' => [new self, 'update'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
        ]);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function read(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $object = self::resolveObject($input);
        if (is_wp_error($object)) {
            return $object;
        }

        return self::describe($object);
    }

    /**
     * @return array<string, mixed>|WP_Error
     */
    public function update(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);
        $object = self::resolveObject($input);
        if (is_wp_error($object)) {
            return $object;
        }

        $fields = (array) ($input['fields'] ?? []);
        if ($fields === []) {
            return new WP_Error('no_fields', 'Provide at least one SEO field to update.');
        }

        $blocked = SeoFields::blockedAdvancedFields($fields);
        if ($blocked !== []) {
            return new WP_Error('forbidden', 'You do not have permission to change '.implode(', ', $blocked).'.', ['status' => 403]);
        }

        if ($object instanceof WP_Post) {
            $stored = SeoFields::toStored('post', $fields, $object);
            if (is_wp_error($stored)) {
                return $stored;
            }

            $metaKeys = array_map(static fn ($key) => '_yoast_wpseo_'.$key, array_keys($stored));
            $captured = Snapshot::partialPost($object->ID, $metaKeys);
            // Empty status: undoing SEO must not revert a publish or untrash.
            $before = $captured ? new PartialPostSnapshot($object->ID, '', $captured->metaPrev, $captured->serialized) : null;

            SeoFields::writePost($object->ID, $stored);

            $result = self::describe(get_post($object->ID));

            return $before
                ? $this->reversible($result, 'restore-post-partial', $before, "Restore the SEO fields of post {$object->ID}")
                : $result;
        }

        $stored = SeoFields::toStored('term', $fields);
        if (is_wp_error($stored)) {
            return $stored;
        }

        $prev = SeoFields::termValues($object, array_keys($stored));
        SeoFields::writeTerm($object, $stored);

        return $this->reversible(
            self::describe(get_term($object->term_id, $object->taxonomy)),
            'restore-term-seo',
            ['term_id' => $object->term_id, 'taxonomy' => $object->taxonomy, 'prev' => $prev],
            "Restore the SEO fields of term {$object->term_id}",
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function resolveObject(array $input): WP_Post|WP_Term|WP_Error
    {
        $id = (int) ($input['id'] ?? 0);

        if (($input['object_type'] ?? '') === 'term') {
            $term = get_term($id);
            if (! $term instanceof WP_Term) {
                return new WP_Error('not_found', "Term {$id} not found.", ['status' => 404]);
            }
            if (! current_user_can('edit_term', $term->term_id)) {
                return new WP_Error('forbidden', 'You do not have permission to edit this term.', ['status' => 403]);
            }

            return $term;
        }

        $post = get_post($id);
        if (! $post instanceof WP_Post) {
            return new WP_Error('not_found', "Post {$id} not found.", ['status' => 404]);
        }
        // Yoast hides its post meta from REST for users who can't edit the post.
        if (! current_user_can('edit_post', $post->ID)) {
            return new WP_Error('forbidden', 'You do not have permission to edit this post.', ['status' => 403]);
        }

        return $post;
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(WP_Post|WP_Term $object): array
    {
        if ($object instanceof WP_Post) {
            return [
                'object_type' => 'post',
                'id' => $object->ID,
                'post_type' => $object->post_type,
                'fields' => SeoFields::readPost($object),
                'resolved' => SeoFields::resolved('post', $object->ID),
            ];
        }

        return [
            'object_type' => 'term',
            'id' => $object->term_id,
            'taxonomy' => $object->taxonomy,
            'fields' => SeoFields::readTerm($object),
            'resolved' => SeoFields::resolved('term', $object->term_id),
        ];
    }
}
