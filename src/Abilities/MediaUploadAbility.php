<?php

namespace GeneroWP\MCP\Abilities;

use WP_Error;

final class MediaUploadAbility
{
    private const MAX_BASE64_BYTES = 10 * 1024 * 1024; // 10 MB decoded

    public static function register(): void
    {
        HelpAbility::registerAbility('gds/media-upload', [
            'label' => 'Upload Media',
            'description' => 'Upload a file to the WordPress Media Library. Provide either base64-encoded data (for small files from chat) or a URL to download from. Returns the attachment ID, URL, and available sizes.',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'base64' => [
                        'type' => 'string',
                        'description' => 'Base64-encoded file content. Use for small files (<10 MB).',
                    ],
                    'url' => [
                        'type' => 'string',
                        'description' => 'URL to download the file from. Use for large files or external images.',
                    ],
                    'filename' => [
                        'type' => 'string',
                        'description' => 'Filename with extension (e.g. "photo.jpg"). Required for base64, optional for URL (auto-detected).',
                    ],
                    'alt_text' => [
                        'type' => 'string',
                        'description' => 'Alt text for accessibility.',
                    ],
                    'title' => [
                        'type' => 'string',
                        'description' => 'Attachment title. Defaults to filename without extension.',
                    ],
                    'caption' => [
                        'type' => 'string',
                        'description' => 'Attachment caption / description.',
                    ],
                    'post_parent' => [
                        'type' => 'integer',
                        'description' => 'Attach to this post ID (sets the parent).',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'url' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'alt_text' => ['type' => 'string'],
                    'media_type' => ['type' => 'string'],
                    'mime_type' => ['type' => 'string'],
                    'sizes' => ['type' => 'object'],
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

    public function execute(mixed $input = []): array|WP_Error
    {
        $input = (array) ($input ?? []);

        if (! current_user_can('upload_files')) {
            return new WP_Error('forbidden', 'You do not have permission to upload files.', ['status' => 403]);
        }

        // Require admin functions for media_handle_sideload.
        if (! function_exists('media_handle_sideload')) {
            require_once ABSPATH.'wp-admin/includes/image.php';
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/media.php';
        }

        $hasBase64 = ! empty($input['base64']);
        $hasUrl = ! empty($input['url']);

        if (! $hasBase64 && ! $hasUrl) {
            return new WP_Error('missing_source', 'Provide either "base64" (encoded file data) or "url" (to download from).');
        }

        if ($hasBase64 && $hasUrl) {
            return new WP_Error('ambiguous_source', 'Provide either "base64" or "url", not both.');
        }

        if ($hasUrl) {
            return $this->uploadFromUrl($input);
        }

        return $this->uploadFromBase64($input);
    }

    private function uploadFromUrl(array $input): array|WP_Error
    {
        $url = $input['url'];

        // Validate URL scheme to prevent SSRF.
        $scheme = wp_parse_url($url, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return new WP_Error('invalid_url', 'URL must use http or https scheme.');
        }

        // download_url() uses wp_safe_remote_get() which blocks private IPs.
        // Timeout prevents slow-drip DoS; file size checked after download.
        $tmpFile = download_url($url, 30);
        if (is_wp_error($tmpFile)) {
            return $tmpFile;
        }

        // Reject excessively large downloads (default WP upload limit or 50 MB).
        $maxBytes = wp_max_upload_size() ?: 50 * 1024 * 1024;
        if (filesize($tmpFile) > $maxBytes) {
            @unlink($tmpFile);

            return new WP_Error('file_too_large', sprintf(
                'Downloaded file exceeds maximum upload size of %d MB.',
                $maxBytes / 1024 / 1024
            ));
        }

        // Derive filename from URL if not provided.
        $filename = $input['filename'] ?? basename(wp_parse_url($url, PHP_URL_PATH)) ?: 'download';
        $filename = sanitize_file_name($filename);

        return $this->sideloadAndRespond($tmpFile, $filename, $input);
    }

    private function uploadFromBase64(array $input): array|WP_Error
    {
        if (empty($input['filename'])) {
            return new WP_Error('missing_filename', 'Filename is required when uploading base64 data.');
        }

        $filename = sanitize_file_name($input['filename']);

        // Strip data URI prefix if present (e.g. "data:image/png;base64,iVBOR...")
        $base64 = $input['base64'];
        if (str_contains($base64, ',')) {
            $base64 = substr($base64, strpos($base64, ',') + 1);
        }

        // Reject oversized base64 before allocating the decode buffer.
        // Base64 encodes 3 bytes as 4 chars, so decoded size ≈ strlen * 3/4.
        $maxBase64Len = (int) ceil(self::MAX_BASE64_BYTES * 4 / 3) + 4;
        if (strlen($base64) > $maxBase64Len) {
            return new WP_Error('file_too_large', sprintf(
                'File exceeds maximum size of %d MB. Use the "url" parameter for large files.',
                self::MAX_BASE64_BYTES / 1024 / 1024
            ));
        }

        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            return new WP_Error('invalid_base64', 'The base64 data is not valid.');
        }

        if (strlen($decoded) > self::MAX_BASE64_BYTES) {
            return new WP_Error('file_too_large', sprintf(
                'File exceeds maximum size of %d MB. Use the "url" parameter for large files.',
                self::MAX_BASE64_BYTES / 1024 / 1024
            ));
        }

        $tmpFile = wp_tempnam($filename);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents($tmpFile, $decoded);

        return $this->sideloadAndRespond($tmpFile, $filename, $input);
    }

    private function sideloadAndRespond(string $tmpFile, string $filename, array $input): array|WP_Error
    {
        $postParent = (int) ($input['post_parent'] ?? 0);

        // Let WordPress detect the MIME type from file content.
        $fileArray = [
            'name' => $filename,
            'tmp_name' => $tmpFile,
        ];

        $attachmentId = media_handle_sideload($fileArray, $postParent);

        // Clean up temp file if sideload failed.
        if (is_wp_error($attachmentId)) {
            @unlink($tmpFile);

            return $attachmentId;
        }

        // Set optional metadata.
        if (! empty($input['alt_text'])) {
            update_post_meta($attachmentId, '_wp_attachment_image_alt', sanitize_text_field($input['alt_text']));
        }

        $postUpdate = ['ID' => $attachmentId];
        if (! empty($input['title'])) {
            $postUpdate['post_title'] = sanitize_text_field($input['title']);
        }
        if (! empty($input['caption'])) {
            $postUpdate['post_excerpt'] = sanitize_text_field($input['caption']);
        }
        if (count($postUpdate) > 1) {
            wp_update_post($postUpdate);
        }

        return $this->formatResponse($attachmentId);
    }

    private function formatResponse(int $attachmentId): array
    {
        $metadata = wp_get_attachment_metadata($attachmentId);

        $sizes = [];
        if (! empty($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size => $data) {
                $src = wp_get_attachment_image_src($attachmentId, $size);
                $sizes[$size] = [
                    'url' => $src ? $src[0] : '',
                    'width' => $data['width'],
                    'height' => $data['height'],
                ];
            }
        }

        return [
            'id' => $attachmentId,
            'url' => wp_get_attachment_url($attachmentId),
            'title' => get_the_title($attachmentId),
            'alt_text' => get_post_meta($attachmentId, '_wp_attachment_image_alt', true) ?: '',
            'media_type' => get_post_mime_type($attachmentId) ? explode('/', get_post_mime_type($attachmentId))[0] : '',
            'mime_type' => get_post_mime_type($attachmentId) ?: '',
            'sizes' => $sizes,
        ];
    }
}
