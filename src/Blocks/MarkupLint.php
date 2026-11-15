<?php

namespace GeneroWP\MCP\Blocks;

use WP_Block_Supports;
use WP_Block_Type_Registry;
use WP_Error;

/**
 * Checks block markup an agent is about to save. Markup WordPress cannot
 * render, or that the editor discards on save, is rejected; anything that
 * saves but probably not as meant comes back as warnings.
 *
 * Block types are looked up in the PHP registry, which is what blocks://catalog
 * lists. Blocks the editor registers only in JavaScript are missing there, so
 * an unregistered name that already appears in saved content is let through.
 */
final class MarkupLint
{
    private const SCHEMA_TYPES = ['array', 'object', 'string', 'number', 'integer', 'boolean', 'null'];

    /**
     * @return list<string>|WP_Error Warnings, or the reason the markup is refused.
     */
    public static function markup(string $markup): array|WP_Error
    {
        return self::blocks(parse_blocks($markup));
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks  Parsed blocks.
     * @return list<string>|WP_Error
     */
    public static function blocks(array $blocks): array|WP_Error
    {
        // Core adds block-supports attributes once, on init; a block
        // registered later lacks them until this runs again (it skips those
        // already there).
        WP_Block_Supports::init();

        $unknown = [];
        $errors = [];
        $warnings = [];
        self::walk($blocks, $unknown, $errors, $warnings);

        $unknown = array_filter(array_unique($unknown), fn ($name) => ! self::usedInSavedContent($name));
        if ($unknown) {
            $messages = array_map(
                fn ($name) => sprintf('Unknown block type "%s"%s: it would save but render nothing.', $name, self::closestHint($name, self::registeredNames(), 'closest registered')),
                $unknown,
            );
            $messages[] = 'List the registered blocks with gds/block-types-list.';
            array_unshift($errors, ['unknown_block_type', implode(' ', $messages)]);
        }

        if ($errors) {
            return new WP_Error($errors[0][0], implode(' ', array_column($errors, 1)));
        }

        return array_values(array_unique($warnings));
    }

    /**
     * Warnings for attributes about to be set on a block: keys its type does
     * not define, values that fail the type's schema, and raw values that equal
     * a theme.json preset.
     *
     * @param  array<string, mixed>  $attrs
     * @return list<string>
     */
    public static function attrs(string $blockName, array $attrs): array
    {
        WP_Block_Supports::init();

        return self::attrsOf($blockName, $attrs);
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return list<string>
     */
    private static function attrsOf(string $blockName, array $attrs): array
    {
        $type = WP_Block_Type_Registry::get_instance()->get_registered($blockName);
        if (! $type) {
            return [];
        }

        // Includes the attributes block supports add (className, style, …).
        $defs = (array) $type->get_attributes();
        $warnings = [];

        foreach ($attrs as $key => $value) {
            $key = (string) $key;
            if (! isset($defs[$key])) {
                $warnings[] = sprintf('%s has no attribute "%s"%s; the editor drops it.', $blockName, $key, self::closestHint($key, array_keys($defs), 'closest'));

                continue;
            }

            $schema = self::valueSchema($defs[$key]);
            if ($schema) {
                $valid = rest_validate_value_from_schema($value, $schema, $key);
                if (is_wp_error($valid)) {
                    $warnings[] = sprintf('%s attribute "%s": %s The editor falls back to the default.', $blockName, $key, $valid->get_error_message());
                }
            }
        }

        if (is_array($attrs['style'] ?? null)) {
            array_push($warnings, ...self::presetWarnings($blockName, $attrs['style'], $defs));
        }

        return $warnings;
    }

    /**
     * Why inner content on a core/block reference is refused.
     */
    public static function syncedPatternError(mixed $ref): WP_Error
    {
        $target = is_numeric($ref) ? 'id '.(int) $ref : 'the id in its "ref" attribute';

        return new WP_Error(
            'synced_pattern_content',
            "core/block is a synced pattern: it renders the wp_block post it references, and inner blocks or HTML written inside it are discarded on save. Edit the pattern itself with gds/blocks-patch or gds/content-update (type \"blocks\") on {$target}; that changes it everywhere it is used.",
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @param  list<string>  $unknown
     * @param  list<array{string, string}>  $errors  Code and message of each refusal.
     * @param  list<string>  $warnings
     */
    private static function walk(array $blocks, array &$unknown, array &$errors, array &$warnings): void
    {
        $registry = WP_Block_Type_Registry::get_instance();

        foreach ($blocks as $block) {
            $name = $block['blockName'] ?? null;
            // A null name is freeform (classic) HTML, which renders as is.
            if (! is_string($name)) {
                continue;
            }

            if ($name === 'core/missing') {
                $errors[] = ['invalid_block_markup', 'core/missing is the editor\'s placeholder for a block that is not registered; write the block itself.'];
            } elseif (! $registry->is_registered($name)) {
                $unknown[] = $name;
            } else {
                array_push($warnings, ...self::attrsOf($name, (array) ($block['attrs'] ?? [])));
            }

            if ($name === 'core/block' && (! empty($block['innerBlocks']) || trim((string) ($block['innerHTML'] ?? '')) !== '')) {
                $errors[] = ['synced_pattern_content', self::syncedPatternError($block['attrs']['ref'] ?? null)->get_error_message()];
            }

            self::walk($block['innerBlocks'] ?? [], $unknown, $errors, $warnings);
        }
    }

    /**
     * The type and enum of an attribute definition, as a schema
     * rest_validate_value_from_schema() accepts, or null when it has neither.
     *
     * @return array<string, mixed>|null
     */
    private static function valueSchema(mixed $def): ?array
    {
        if (! is_array($def) || ! isset($def['type'])) {
            return null;
        }

        // Block attributes may be "rich-text", which is stored as a string.
        $type = $def['type'] === 'rich-text' ? 'string' : $def['type'];
        if (array_diff((array) $type, self::SCHEMA_TYPES)) {
            return null;
        }

        $schema = ['type' => $type];
        if (isset($def['enum']) && is_array($def['enum'])) {
            $schema['enum'] = $def['enum'];
        }

        return $schema;
    }

    /**
     * Raw style values that equal a theme.json preset. Only warned about: the
     * preset attribute also adds has-*-color classes to a static block's saved
     * HTML, so swapping the value here would leave that HTML out of step.
     *
     * @param  array<string, mixed>  $style
     * @param  array<string, mixed>  $defs
     * @return list<string>
     */
    private static function presetWarnings(string $blockName, array $style, array $defs): array
    {
        $warnings = [];

        $checks = [
            ['color', 'text', 'textColor', 'color'],
            ['color', 'background', 'backgroundColor', 'color'],
            ['color', 'gradient', 'gradient', 'gradient'],
            ['border', 'color', 'borderColor', 'color'],
            ['typography', 'fontSize', 'fontSize', 'font-size'],
        ];
        foreach ($checks as [$group, $prop, $presetAttr, $kind]) {
            $value = $style[$group][$prop] ?? null;
            $slug = is_string($value) ? (self::presets($kind)[self::normalize($value)] ?? null) : null;
            if ($slug === null) {
                continue;
            }

            $fix = isset($defs[$presetAttr])
                ? sprintf('set %s: "%s" and remove style.%s.%s', $presetAttr, $slug, $group, $prop)
                : sprintf('use "var:preset|%s|%s"', $kind, $slug);
            $warnings[] = sprintf('%s style.%s.%s "%s" is the "%s" %s preset; %s, so it follows the theme.', $blockName, $group, $prop, $value, $slug, $kind, $fix);
        }

        foreach (['padding', 'margin', 'blockGap'] as $prop) {
            $value = $style['spacing'][$prop] ?? null;
            $sides = is_array($value) ? $value : ['' => $value];
            foreach ($sides as $side => $size) {
                $slug = is_string($size) ? (self::presets('spacing')[self::normalize($size)] ?? null) : null;
                if ($slug === null) {
                    continue;
                }
                $path = 'style.spacing.'.$prop.($side === '' ? '' : '.'.$side);
                $warnings[] = sprintf('%s %s "%s" is the "%s" spacing preset; use "var:preset|spacing|%s", so it follows the theme.', $blockName, $path, $size, $slug, $slug);
            }
        }

        return $warnings;
    }

    /**
     * The presets the editor offers, as normalized value => slug. Theme
     * presets win over core's defaults, which count only while the theme
     * leaves them enabled.
     *
     * @param  'color'|'gradient'|'font-size'|'spacing'  $kind
     * @return array<string, string>
     */
    private static function presets(string $kind): array
    {
        [$path, $valueKey, $defaultsFlag] = match ($kind) {
            'color' => [['color', 'palette'], 'color', ['color', 'defaultPalette']],
            'gradient' => [['color', 'gradients'], 'gradient', ['color', 'defaultGradients']],
            'font-size' => [['typography', 'fontSizes'], 'size', ['typography', 'defaultFontSizes']],
            'spacing' => [['spacing', 'spacingSizes'], 'size', ['spacing', 'defaultSpacingSizes']],
        };

        $byOrigin = (array) wp_get_global_settings($path);
        $origins = ['default', 'theme', 'custom'];
        if (wp_get_global_settings($defaultsFlag) === false) {
            $origins = ['theme', 'custom'];
        }

        $presets = [];
        foreach (array_reverse($origins) as $origin) {
            foreach ((array) ($byOrigin[$origin] ?? []) as $preset) {
                $value = $preset[$valueKey] ?? null;
                if (is_string($value) && isset($preset['slug'])) {
                    $presets[self::normalize($value)] ??= (string) $preset['slug'];
                }
            }
        }

        return $presets;
    }

    private static function normalize(string $value): string
    {
        return strtolower(trim($value));
    }

    /**
     * Whether saved content (not revisions) already uses this block, so the
     * editor knows it even without a PHP registration: a block registered
     * only in JavaScript, or one from a plugin that is not active.
     */
    private static function usedInSavedContent(string $name): bool
    {
        global $wpdb;

        $serialized = str_starts_with($name, 'core/') ? substr($name, 5) : $name;
        $like = '%'.$wpdb->esc_like('<!-- wp:'.$serialized.' ').'%';

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->posts} WHERE post_type <> 'revision' AND post_status <> 'auto-draft' AND post_content LIKE %s LIMIT 1",
            $like,
        ));
    }

    /**
     * @return list<string>
     */
    private static function registeredNames(): array
    {
        return array_keys(WP_Block_Type_Registry::get_instance()->get_all_registered());
    }

    /**
     * " ({label}: "{nearest candidate}")", or nothing without candidates.
     *
     * @param  list<string>  $candidates
     */
    private static function closestHint(string $name, array $candidates, string $label): string
    {
        $closest = self::closest($name, $candidates);

        return $closest === '' ? '' : sprintf(' (%s: "%s")', $label, $closest);
    }

    /**
     * The candidate nearest to $name. A block of the same name in another
     * namespace (core/card for gds/card) counts as nearly equal.
     *
     * @param  list<string>  $candidates
     */
    private static function closest(string $name, array $candidates): string
    {
        $best = '';
        $bestScore = PHP_INT_MAX;
        $base = substr((string) strrchr('/'.$name, '/'), 1);

        foreach ($candidates as $candidate) {
            $score = levenshtein($name, $candidate);
            if ($base !== $name && substr((string) strrchr('/'.$candidate, '/'), 1) === $base) {
                $score = min($score, 1);
            }
            if ($score < $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }
}
