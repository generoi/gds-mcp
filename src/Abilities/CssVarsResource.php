<?php

namespace GeneroWP\MCP\Abilities;

final class CssVarsResource
{
    public static function register(): void
    {
        HelpAbility::registerAbility('gds/design-css-vars', [
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
            'permission_callback' => '__return_true',
            'execute_callback' => [new self, 'execute'],
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

    public function execute(mixed $input = []): array
    {
        $input = is_array($input) ? $input : [];

        return ['variables' => ThemeJsonResource::extractCssCustomProperties()];
    }
}
