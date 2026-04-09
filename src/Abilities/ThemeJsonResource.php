<?php

namespace GeneroWP\MCP\Abilities;

use WP_Error;
use WP_Theme_JSON_Resolver;

final class ThemeJsonResource
{
    public static function register(): void
    {
        HelpAbility::registerAbility('gds/design-theme-json', [
            'label' => 'Theme JSON',
            'description' => 'Read-only design tokens from theme.json (color palette, gradients, spacing, font sizes, layout, per-block overrides) and resolved CSS custom properties from the built stylesheet. Read design://css-vars for just the CSS variables.',
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
                'uri' => 'theme://json',
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
        $merged = WP_Theme_JSON_Resolver::get_merged_data();
        $settings = $merged->get_settings();

        $result = [];

        // Layout.
        if (! empty($settings['layout'])) {
            $result['layout'] = [
                'contentSize' => $settings['layout']['contentSize'] ?? null,
                'wideSize' => $settings['layout']['wideSize'] ?? null,
            ];
        }

        // Colors.
        if (! empty($settings['color'])) {
            $color = $settings['color'];

            if (! empty($color['palette']['theme'])) {
                $result['colors'] = array_map(fn ($c) => [
                    'slug' => $c['slug'],
                    'name' => $c['name'],
                    'color' => $c['color'],
                ], $color['palette']['theme']);
            }

            if (! empty($color['gradients']['theme'])) {
                $result['gradients'] = array_map(fn ($g) => [
                    'slug' => $g['slug'],
                    'name' => $g['name'],
                    'gradient' => $g['gradient'],
                ], $color['gradients']['theme']);
            }
        }

        // Spacing.
        if (! empty($settings['spacing']['spacingSizes']['theme'])) {
            $result['spacing'] = array_map(fn ($s) => [
                'slug' => $s['slug'],
                'name' => $s['name'],
                'size' => $s['size'],
            ], $settings['spacing']['spacingSizes']['theme']);
        }

        // Typography — global font sizes.
        if (! empty($settings['typography']['fontSizes']['theme'])) {
            $result['font_sizes'] = array_map(fn ($f) => [
                'slug' => $f['slug'],
                'name' => $f['name'],
                'size' => $f['size'],
            ], $settings['typography']['fontSizes']['theme']);
        }

        // Per-block overrides (heading font sizes, button colors, etc.).
        $blockOverrides = [];
        foreach (($settings['blocks'] ?? []) as $blockName => $blockSettings) {
            $override = [];

            if (! empty($blockSettings['typography']['fontSizes']['theme'])) {
                $override['font_sizes'] = array_map(fn ($f) => [
                    'slug' => $f['slug'],
                    'name' => $f['name'],
                    'size' => $f['size'],
                ], $blockSettings['typography']['fontSizes']['theme']);
            }

            if (! empty($blockSettings['color']['palette']['theme'])) {
                $override['colors'] = array_map(fn ($c) => [
                    'slug' => $c['slug'],
                    'name' => $c['name'],
                    'color' => $c['color'],
                ], $blockSettings['color']['palette']['theme']);
            }

            if ($override) {
                $blockOverrides[$blockName] = $override;
            }
        }

        if ($blockOverrides) {
            $result['block_overrides'] = $blockOverrides;
        }

        // Resolve CSS custom properties from the built stylesheet.
        $cssVars = self::extractCssCustomProperties();
        if ($cssVars) {
            $result['css_custom_properties'] = $cssVars;
        }

        return $result;
    }

    /**
     * Extract CSS custom property declarations from the theme's built stylesheet.
     *
     * Parses all :root blocks (handles multiple, handles minified CSS) using a
     * brace-depth state machine, then extracts --property: value declarations.
     *
     * @return array<string, string> Property name => resolved value
     */
    public static function extractCssCustomProperties(): array
    {
        // Check common build output paths across different toolchains.
        $candidates = [
            'build/app-styles.css',
            'dist/app.css',
            'public/app.css',
            'build/app.css',
        ];

        $cssFile = null;
        foreach ($candidates as $candidate) {
            $path = get_theme_file_path($candidate);
            if (file_exists($path)) {
                $cssFile = $path;
                break;
            }
        }

        if (! $cssFile) {
            return [];
        }

        $css = file_get_contents($cssFile);
        if (! $css) {
            return [];
        }

        // Collect content from all :root { ... } blocks using brace-depth tracking.
        $rootContents = [];
        $offset = 0;
        $len = strlen($css);

        while (($pos = strpos($css, ':root', $offset)) !== false) {
            // Ensure :root is preceded by a boundary (start of file, whitespace, }, ;, or ,).
            if ($pos > 0 && ! preg_match('/[\s},;{]/', $css[$pos - 1])) {
                $offset = $pos + 5;

                continue;
            }

            // Find the opening brace.
            $braceStart = strpos($css, '{', $pos);
            if ($braceStart === false) {
                break;
            }

            // Track brace depth to find the matching close.
            $depth = 1;
            $i = $braceStart + 1;
            while ($i < $len && $depth > 0) {
                if ($css[$i] === '{') {
                    $depth++;
                } elseif ($css[$i] === '}') {
                    $depth--;
                }
                $i++;
            }

            if ($depth === 0) {
                $rootContents[] = substr($css, $braceStart + 1, $i - $braceStart - 2);
            }

            $offset = $i;
        }

        if (! $rootContents) {
            return [];
        }

        // Parse --property: value declarations from all :root blocks.
        $vars = [];
        $combined = implode(';', $rootContents);

        if (preg_match_all('/(--[\w-]+)\s*:\s*([^;]+)/', $combined, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $vars[trim($match[1])] = trim($match[2]);
            }
        }

        return $vars;
    }
}
