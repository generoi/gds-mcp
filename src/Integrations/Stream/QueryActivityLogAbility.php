<?php

namespace GeneroWP\MCP\Integrations\Stream;

use WP_Error;

/**
 * Query the Stream activity log for recent changes, user actions, etc.
 */
final class QueryActivityLogAbility
{
    public static function register(): void
    {
        wp_register_ability('gds/activity/query', [
            'label' => 'Query Activity Log',
            'description' => 'Query the Stream activity log to see recent changes: who edited what, when content was published, plugin activations, login activity, etc.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'search' => [
                        'type' => 'string',
                        'description' => 'Search term to filter log entries.',
                    ],
                    'connector' => [
                        'type' => 'string',
                        'description' => 'Filter by connector (e.g. posts, comments, media, users, plugins, widgets, menus, taxonomies, settings).',
                    ],
                    'context' => [
                        'type' => 'string',
                        'description' => 'Filter by context (e.g. page, post, product, plugin, theme).',
                    ],
                    'action' => [
                        'type' => 'string',
                        'description' => 'Filter by action (e.g. created, updated, deleted, trashed, published, activated).',
                    ],
                    'user_id' => [
                        'type' => 'integer',
                        'description' => 'Filter by user ID.',
                    ],
                    'object_id' => [
                        'type' => 'integer',
                        'description' => 'Filter by object ID (e.g. post ID).',
                    ],
                    'date_from' => [
                        'type' => 'string',
                        'description' => 'Filter entries from this date (YYYY-MM-DD).',
                    ],
                    'date_to' => [
                        'type' => 'string',
                        'description' => 'Filter entries up to this date (YYYY-MM-DD).',
                    ],
                    'per_page' => [
                        'type' => 'integer',
                        'description' => 'Number of entries per page.',
                        'default' => 20,
                        'minimum' => 1,
                        'maximum' => 100,
                    ],
                    'paged' => [
                        'type' => 'integer',
                        'description' => 'Page number.',
                        'default' => 1,
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'entries' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'date' => ['type' => 'string'],
                                'summary' => ['type' => 'string'],
                                'user' => ['type' => 'string'],
                                'connector' => ['type' => 'string'],
                                'context' => ['type' => 'string'],
                                'action' => ['type' => 'string'],
                                'object_id' => ['type' => 'integer'],
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

        if (! current_user_can('manage_options')) {
            return new WP_Error('insufficient_capability', 'You do not have permission to view the activity log.');
        }

        return true;
    }

    public static function execute(?array $input = []): array|WP_Error
    {
        if (! class_exists('WP_Stream\Plugin')) {
            return new WP_Error('stream_not_active', 'Stream plugin is not active.');
        }

        $args = [
            'records_per_page' => min($input['per_page'] ?? 20, 100),
            'paged' => $input['paged'] ?? 1,
        ];

        if (! empty($input['search'])) {
            $args['search'] = $input['search'];
        }
        if (! empty($input['connector'])) {
            $args['connector'] = $input['connector'];
        }
        if (! empty($input['context'])) {
            $args['context'] = $input['context'];
        }
        if (! empty($input['action'])) {
            $args['action'] = $input['action'];
        }
        if (! empty($input['user_id'])) {
            $args['user_id'] = $input['user_id'];
        }
        if (! empty($input['object_id'])) {
            $args['object_id'] = $input['object_id'];
        }
        if (! empty($input['date_from'])) {
            $args['date_from'] = $input['date_from'];
        }
        if (! empty($input['date_to'])) {
            $args['date_to'] = $input['date_to'];
        }

        $db = wp_stream_get_instance()->db;
        $records = $db->get_records($args);
        $total = $db->get_found_records_count();

        $entries = array_map(function ($record) {
            $user = get_userdata($record->user_id);

            return [
                'id' => (int) $record->ID,
                'date' => $record->created,
                'summary' => $record->summary,
                'user' => $user ? $user->display_name : sprintf('User #%d', $record->user_id),
                'connector' => $record->connector,
                'context' => $record->context,
                'action' => $record->action,
                'object_id' => (int) $record->object_id,
            ];
        }, $records);

        return [
            'entries' => $entries,
            'total' => (int) $total,
        ];
    }
}
