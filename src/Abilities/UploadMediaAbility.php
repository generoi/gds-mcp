<?php

namespace GeneroWP\MCP\Abilities;

use WP_Error;

/**
 * Upload media from a URL using WordPress core's media_sideload_image().
 * This is the safest approach -- WP core handles:
 *   - Download to temp directory
 *   - MIME type validation
 *   - File extension whitelist (wp_check_filetype_and_ext)
 *   - Filename sanitization
 *   - Moving to uploads directory
 *   - Attachment post creation
 */
final class UploadMediaAbility
{
    public static function register(): void
    {
        HelpAbility::registerAbility('gds/media-upload', [
            'label' => 'Upload Media from URL',
            'description' => 'Download an image or file from a URL and add it to the WordPress media library. Uses WordPress core sideload which validates file types and sanitizes filenames. Optionally set it as a post\'s featured image.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'The URL to download the file from.',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Title for the media attachment. Defaults to filename.',
                    ],
                    'alt' => [
                        'type' => 'string',
                        'description' => 'Alt text for images.',
                    ],
                    'post_id' => [
                        'type' => 'integer',
                        'description' => 'Attach the media to this post. Also sets as featured image if set_featured is true.',
                    ],
                    'set_featured' => [
                        'type' => 'boolean',
                        'description' => 'Set as the featured image for the specified post_id.',
                        'default' => false,
                    ],
                ],
                'required' => ['url'],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'url' => ['type' => 'string'],
                    'mime_type' => ['type' => 'string'],
                    'filename' => ['type' => 'string'],
                    'featured_for' => ['type' => ['integer', 'null']],
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

    public static function checkPermission(mixed $input = []): bool|WP_Error
    {
        $input = is_array($input) ? $input : [];
        if (! is_user_logged_in()) {
            return new WP_Error('authentication_required', 'User must be authenticated.');
        }

        if (! current_user_can('upload_files')) {
            return new WP_Error('insufficient_capability', 'You do not have permission to upload files.');
        }

        return true;
    }

    public static function execute(?array $input = []): array|WP_Error
    {
        $url = $input['url'] ?? '';

        if (empty($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_url', 'A valid URL is required.');
        }

        // Only allow http/https URLs.
        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return new WP_Error('invalid_scheme', 'Only http and https URLs are allowed.');
        }

        // Require media handling functions.
        if (! function_exists('media_sideload_image')) {
            require_once ABSPATH.'wp-admin/includes/media.php';
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/image.php';
        }

        $postId = $input['post_id'] ?? 0;
        $description = $input['title'] ?? '';

        // media_sideload_image handles:
        // - download_url() to temp file
        // - wp_check_filetype_and_ext() for MIME validation
        // - media_handle_sideload() for sanitization + attachment creation
        $attachmentId = media_sideload_image($url, $postId, $description, 'id');

        if (is_wp_error($attachmentId)) {
            return $attachmentId;
        }

        // Set alt text if provided.
        if (! empty($input['alt'])) {
            update_post_meta($attachmentId, '_wp_attachment_image_alt', sanitize_text_field($input['alt']));
        }

        // Set title if provided (media_sideload_image uses it as description, not title).
        if (! empty($input['title'])) {
            wp_update_post([
                'ID' => $attachmentId,
                'post_title' => sanitize_text_field($input['title']),
            ]);
        }

        // Set as featured image if requested.
        $featuredFor = null;
        if (! empty($input['set_featured']) && $postId) {
            set_post_thumbnail($postId, $attachmentId);
            $featuredFor = $postId;
        }

        $attachment = get_post($attachmentId);

        return [
            'id' => $attachmentId,
            'title' => $attachment->post_title,
            'url' => wp_get_attachment_url($attachmentId),
            'mime_type' => $attachment->post_mime_type,
            'filename' => basename(get_attached_file($attachmentId) ?: ''),
            'featured_for' => $featuredFor,
        ];
    }
}
