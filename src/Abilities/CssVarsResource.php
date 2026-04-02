<?php

namespace GeneroWP\MCP\Abilities;

use WP_Error;

final class CssVarsResource
{
    public static function register(): void
    {
        wp_register_ability('gds/design/css-vars', [
            'label' => 'CSS Custom Properties',
            'description' => 'All resolved CSS custom properties from the theme stylesheet (:root declarations). Includes colors, typography, spacing, grid, buttons, inputs, and layout tokens with actual values (hex, clamp, calc).',
            'category' => 'gds-content',
            'input_schema' => [
                'type' => 'object',
                'properties' => new \stdClass,
                'additionalProperties' => false,
            ],
            'output_schema' => [
                'type' => 'object',
            ],
            'permission_callback' => [self::class, 'checkPermission'],
            'execute_callback' => [self::class, 'execute'],
            'meta' => [
                'uri' => 'design://css-vars',
                'mimeType' => 'application/json',
                'mcp' => [
                    'type' => 'resource',
                    'public' => true,
                ],
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

        if (! current_user_can('edit_posts')) {
            return new WP_Error('insufficient_capability', 'You do not have permission to read design tokens.');
        }

        return true;
    }

    public static function execute(?array $input = []): array
    {
        return ['variables' => ThemeJsonResource::extractCssCustomProperties()];
    }
}
