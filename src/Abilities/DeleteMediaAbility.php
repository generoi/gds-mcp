<?php

namespace GeneroWP\MCP\Abilities;

use WP_Error;

final class DeleteMediaAbility
{
    public static function register(): void
    {
        HelpAbility::registerAbility('gds/media-delete', [
            'label' => 'Delete Media',
            'description' => 'Permanently delete a media attachment and its associated files from the server.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'attachment_id' => [
                        'type' => 'integer',
                        'description' => 'The attachment ID to delete.',
                    ],
                ],
                'required' => ['attachment_id'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'deleted' => ['type' => 'boolean'],
                    'id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                ],
            ],
            'permission_callback' => [self::class, 'checkPermission'],
            'execute_callback' => [self::class, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => false,
                    'destructive' => true,
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

        $attachmentId = (int) ($input['attachment_id'] ?? 0);
        if ($attachmentId && ! current_user_can('delete_post', $attachmentId)) {
            return new WP_Error('insufficient_capability', 'You do not have permission to delete this attachment.');
        }

        return true;
    }

    public static function execute(?array $input = []): array|WP_Error
    {
        $attachmentId = (int) ($input['attachment_id'] ?? 0);

        $attachment = get_post($attachmentId);
        if (! $attachment || $attachment->post_type !== 'attachment') {
            return new WP_Error('attachment_not_found', 'Attachment not found.');
        }

        $title = $attachment->post_title;

        $result = wp_delete_attachment($attachmentId, true);

        if (! $result) {
            return new WP_Error('delete_failed', 'Failed to delete attachment.');
        }

        return [
            'deleted' => true,
            'id' => $attachmentId,
            'title' => $title,
        ];
    }
}
