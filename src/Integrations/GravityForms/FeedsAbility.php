<?php

namespace GeneroWP\MCP\Integrations\GravityForms;

use GeneroWP\MCP\Abilities\HelpAbility;
use WP_Error;

/**
 * Abilities for managing Gravity Forms addon feeds (ActiveCampaign, Webhooks, etc.).
 *
 * All methods use GFAPI directly. Feeds live in {$wpdb->prefix}gf_addon_feed
 * with a single JSON `meta` column whose shape is addon-specific.
 */
final class FeedsAbility
{
    public static function register(): void
    {
        $instance = new self;

        HelpAbility::registerAbility('gds/feeds-list', [
            'label' => 'List Form Feeds',
            'description' => 'List Gravity Forms addon feeds (e.g. ActiveCampaign, Webhooks). Optionally filter by form_id, addon_slug, or is_active.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'form_id' => ['type' => 'integer', 'description' => 'Limit to feeds belonging to this form.'],
                    'addon_slug' => ['type' => 'string', 'description' => 'Limit to feeds for this addon (e.g. gravityformsactivecampaign, gravityformswebhooks).'],
                    'is_active' => ['type' => ['boolean', 'null'], 'description' => 'Filter by active state. Omit or null to include both.'],
                ],
                'additionalProperties' => true,
            ],
            'output_schema' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'listFeeds'],
            'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        HelpAbility::registerAbility('gds/feeds-read', [
            'label' => 'Read Form Feed',
            'description' => 'Read a single Gravity Forms feed by id.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer', 'description' => 'The feed ID.']],
                'required' => ['id'],
                'additionalProperties' => true,
            ],
            'output_schema' => ['type' => 'object', 'additionalProperties' => true],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'readFeed'],
            'meta' => ['annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        HelpAbility::registerAbility('gds/feeds-create', [
            'label' => 'Create Form Feed',
            'description' => 'Create a new addon feed for a form. Supply form_id, addon_slug, and the addon-specific meta payload.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'form_id' => ['type' => 'integer', 'description' => 'The form this feed belongs to.'],
                    'addon_slug' => ['type' => 'string', 'description' => 'The addon slug (e.g. gravityformsactivecampaign, gravityformswebhooks).'],
                    'meta' => ['type' => 'object', 'description' => 'Addon-specific feed configuration.', 'additionalProperties' => true],
                    'is_active' => ['type' => 'boolean', 'description' => 'Whether the feed is active. Defaults to true.'],
                    'feed_order' => ['type' => 'integer', 'description' => 'Execution order among feeds for the same form.'],
                ],
                'required' => ['form_id', 'addon_slug', 'meta'],
                'additionalProperties' => true,
            ],
            'output_schema' => ['type' => 'object', 'additionalProperties' => true],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'createFeed'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
        ]);

        HelpAbility::registerAbility('gds/feeds-update', [
            'label' => 'Update Form Feed',
            'description' => 'Update an existing addon feed. Supply id plus any of meta, is_active, feed_order, form_id. Meta is a full replacement of the feed meta object.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'The feed ID to update.'],
                    'meta' => ['type' => 'object', 'description' => 'Replacement meta payload (full replacement, not merge).', 'additionalProperties' => true],
                    'is_active' => ['type' => 'boolean'],
                    'feed_order' => ['type' => 'integer'],
                    'form_id' => ['type' => 'integer', 'description' => 'Move the feed to a different form.'],
                ],
                'required' => ['id'],
                'additionalProperties' => true,
            ],
            'output_schema' => ['type' => 'object', 'additionalProperties' => true],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'updateFeed'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true]],
        ]);

        HelpAbility::registerAbility('gds/feeds-delete', [
            'label' => 'Delete Form Feed',
            'description' => 'Delete a Gravity Forms feed by id.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer', 'description' => 'The feed ID to delete.']],
                'required' => ['id'],
                'additionalProperties' => true,
            ],
            'output_schema' => ['type' => 'object', 'additionalProperties' => true],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'deleteFeed'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => true, 'idempotent' => true]],
        ]);

        HelpAbility::registerAbility('gds/feeds-duplicate', [
            'label' => 'Duplicate Form Feed',
            'description' => 'Copy an existing feed onto one or more target forms. Preserves meta and addon_slug; generates a new feed id per target.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer', 'description' => 'The source feed ID.'],
                    'target_form_ids' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'description' => 'One or more form IDs to copy the feed onto.',
                    ],
                ],
                'required' => ['id', 'target_form_ids'],
                'additionalProperties' => true,
            ],
            'output_schema' => ['type' => 'object', 'additionalProperties' => true],
            'permission_callback' => '__return_true',
            'execute_callback' => [$instance, 'duplicateFeed'],
            'meta' => ['annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
        ]);
    }

    public function listFeeds(mixed $input = []): array|WP_Error
    {
        if (! class_exists('GFAPI')) {
            return new WP_Error('gf_not_available', 'Gravity Forms is not active.');
        }

        $input = (array) ($input ?? []);
        $formId = isset($input['form_id']) ? (int) $input['form_id'] : null;
        $addonSlug = $input['addon_slug'] ?? null;
        // is_active defaults to null (include both) instead of GFAPI's default of true.
        $isActive = array_key_exists('is_active', $input) ? $input['is_active'] : null;

        $feeds = \GFAPI::get_feeds(null, $formId, $addonSlug, $isActive);

        // get_feeds returns WP_Error('not_found') on empty result — treat that as [].
        if (is_wp_error($feeds)) {
            if ($feeds->get_error_code() === 'not_found') {
                return [];
            }

            return $feeds;
        }

        return json_decode(json_encode($feeds), true) ?: [];
    }

    public function readFeed(mixed $input = []): array|WP_Error
    {
        if (! class_exists('GFAPI')) {
            return new WP_Error('gf_not_available', 'Gravity Forms is not active.');
        }

        $input = (array) ($input ?? []);
        $id = (int) ($input['id'] ?? 0);

        if (! $id) {
            return new WP_Error('missing_id', 'Feed id is required.');
        }

        $feed = \GFAPI::get_feed($id);
        if (is_wp_error($feed)) {
            return $feed;
        }
        if (! $feed) {
            return new WP_Error('feed_not_found', "Feed {$id} not found.");
        }

        return json_decode(json_encode($feed), true) ?: [];
    }

    public function createFeed(mixed $input = []): array|WP_Error
    {
        if (! class_exists('GFAPI')) {
            return new WP_Error('gf_not_available', 'Gravity Forms is not active.');
        }

        $input = json_decode(json_encode($input ?? []), true) ?: [];
        $formId = (int) ($input['form_id'] ?? 0);
        $addonSlug = (string) ($input['addon_slug'] ?? '');
        $meta = $input['meta'] ?? [];

        if (! $formId) {
            return new WP_Error('missing_form_id', 'form_id is required.');
        }
        if ($addonSlug === '') {
            return new WP_Error('missing_addon_slug', 'addon_slug is required.');
        }
        if (! is_array($meta)) {
            return new WP_Error('invalid_meta', 'meta must be an object/array.');
        }

        $result = \GFAPI::add_feed($formId, $meta, $addonSlug);
        if (is_wp_error($result)) {
            return $result;
        }

        $feedId = (int) $result;

        // Apply optional is_active/feed_order via update_feed_property.
        if (array_key_exists('is_active', $input)) {
            \GFAPI::update_feed_property($feedId, 'is_active', (bool) $input['is_active'] ? 1 : 0);
        }
        if (array_key_exists('feed_order', $input)) {
            \GFAPI::update_feed_property($feedId, 'feed_order', (int) $input['feed_order']);
        }

        $saved = \GFAPI::get_feed($feedId);
        if (is_wp_error($saved)) {
            return $saved;
        }

        return json_decode(json_encode($saved), true) ?: [];
    }

    public function updateFeed(mixed $input = []): array|WP_Error
    {
        if (! class_exists('GFAPI')) {
            return new WP_Error('gf_not_available', 'Gravity Forms is not active.');
        }

        $input = json_decode(json_encode($input ?? []), true) ?: [];
        $id = (int) ($input['id'] ?? 0);

        if (! $id) {
            return new WP_Error('missing_id', 'Feed id is required.');
        }

        $existing = \GFAPI::get_feed($id);
        if (is_wp_error($existing)) {
            return $existing;
        }
        if (! $existing) {
            return new WP_Error('feed_not_found', "Feed {$id} not found.");
        }

        if (array_key_exists('meta', $input)) {
            $meta = $input['meta'];
            if (! is_array($meta)) {
                return new WP_Error('invalid_meta', 'meta must be an object/array.');
            }
            // GFAPI::update_feed's form_id arg is used to filter the lookup
            // ("feed X belonging to form Y") rather than as a new form_id —
            // passing the target form id to move a feed just makes the lookup
            // fail. Always update meta without form_id, then move separately.
            $result = \GFAPI::update_feed($id, $meta);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        if (array_key_exists('form_id', $input)) {
            \GFAPI::update_feed_property($id, 'form_id', (int) $input['form_id']);
        }
        if (array_key_exists('is_active', $input)) {
            \GFAPI::update_feed_property($id, 'is_active', (bool) $input['is_active'] ? 1 : 0);
        }
        if (array_key_exists('feed_order', $input)) {
            \GFAPI::update_feed_property($id, 'feed_order', (int) $input['feed_order']);
        }

        $saved = \GFAPI::get_feed($id);
        if (is_wp_error($saved)) {
            return $saved;
        }

        return json_decode(json_encode($saved), true) ?: [];
    }

    public function deleteFeed(mixed $input = []): array|WP_Error
    {
        if (! class_exists('GFAPI')) {
            return new WP_Error('gf_not_available', 'Gravity Forms is not active.');
        }

        $input = (array) ($input ?? []);
        $id = (int) ($input['id'] ?? 0);

        if (! $id) {
            return new WP_Error('missing_id', 'Feed id is required.');
        }

        $result = \GFAPI::delete_feed($id);
        if (is_wp_error($result)) {
            return $result;
        }

        return ['deleted' => true, 'id' => $id];
    }

    public function duplicateFeed(mixed $input = []): array|WP_Error
    {
        if (! class_exists('GFAPI')) {
            return new WP_Error('gf_not_available', 'Gravity Forms is not active.');
        }

        $input = json_decode(json_encode($input ?? []), true) ?: [];
        $sourceId = (int) ($input['id'] ?? 0);
        $targetFormIds = $input['target_form_ids'] ?? [];

        if (! $sourceId) {
            return new WP_Error('missing_id', 'Source feed id is required.');
        }
        if (! is_array($targetFormIds) || empty($targetFormIds)) {
            return new WP_Error('missing_target_form_ids', 'target_form_ids must be a non-empty array.');
        }

        $source = \GFAPI::get_feed($sourceId);
        if (is_wp_error($source)) {
            return $source;
        }
        if (! $source) {
            return new WP_Error('feed_not_found', "Feed {$sourceId} not found.");
        }

        $created = [];
        foreach ($targetFormIds as $targetFormId) {
            $targetFormId = (int) $targetFormId;
            $newId = \GFAPI::add_feed($targetFormId, $source['meta'], $source['addon_slug']);
            if (is_wp_error($newId)) {
                // Return early with partial results so the caller sees what succeeded.
                return new WP_Error(
                    $newId->get_error_code(),
                    sprintf('Failed duplicating feed %d onto form %d: %s', $sourceId, $targetFormId, $newId->get_error_message()),
                    ['created' => $created],
                );
            }

            // Preserve is_active / feed_order from source.
            if (isset($source['is_active'])) {
                \GFAPI::update_feed_property((int) $newId, 'is_active', (int) $source['is_active']);
            }
            if (isset($source['feed_order'])) {
                \GFAPI::update_feed_property((int) $newId, 'feed_order', (int) $source['feed_order']);
            }

            $saved = \GFAPI::get_feed((int) $newId);
            $created[] = is_wp_error($saved) ? ['id' => (int) $newId, 'form_id' => $targetFormId] : json_decode(json_encode($saved), true);
        }

        return ['source_id' => $sourceId, 'created' => $created];
    }
}
