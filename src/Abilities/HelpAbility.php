<?php

namespace GeneroWP\MCP\Abilities;

/**
 * Returns a grouped summary of all available GDS MCP abilities.
 * Use this as the starting point to discover what tools are available.
 */
final class HelpAbility
{
    /** @var array<string, array{label: string, description: string, type: string, uri: string|null}> */
    private static array $registry = [];

    /**
     * Register a WordPress ability AND add it to the help index.
     * Use instead of wp_register_ability() to auto-index for discoverability.
     */
    public static function registerAbility(string $name, array $args): void
    {
        // Ensure empty properties serializes as JSON {} not [] for rest_validate_value_from_schema().
        if (isset($args['input_schema']['properties']) && $args['input_schema']['properties'] === []) {
            $args['input_schema']['properties'] = new \stdClass;
        }

        // Provide a default so null input normalizes to an empty object.
        if (isset($args['input_schema']['type']) && $args['input_schema']['type'] === 'object' && ! array_key_exists('default', $args['input_schema'])) {
            $args['input_schema']['default'] = new \stdClass;
        }

        wp_register_ability($name, $args);

        $meta = $args['meta'] ?? [];
        $type = ($meta['mcp']['type'] ?? 'tool') === 'resource' ? 'resource' : 'tool';

        self::$registry[$name] = [
            'label' => $args['label'] ?? '',
            'description' => $args['description'] ?? '',
            'type' => $type,
            'uri' => $meta['uri'] ?? null,
        ];
    }

    public static function register(): void
    {
        wp_register_ability('gds/help', [
            'label' => 'GDS MCP Help',
            'description' => 'Get a grouped summary of all available tools and resources. Start here to discover what you can do.',
            'category' => 'gds-content',
            'output_schema' => [
                'type' => 'object',
                'properties' => [
                    'groups' => ['type' => 'array'],
                    'total' => ['type' => 'integer'],
                    'tip' => ['type' => 'string'],
                ],
            ],
            'permission_callback' => '__return_true',
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

    public static function execute(mixed $input = []): array
    {
        $input = (array) ($input ?? []);
        $groups = [];
        $total = 0;

        foreach (self::$registry as $name => $info) {
            $suffix = explode('/', $name, 2)[1] ?? 'other';
            $group = self::extractGroup($suffix);

            if (! isset($groups[$group])) {
                $groups[$group] = [
                    'name' => $group,
                    'abilities' => [],
                ];
            }

            $groups[$group]['abilities'][] = [
                'name' => $name,
                'label' => $info['label'],
                'description' => $info['description'],
                'type' => $info['type'],
                'uri' => $info['uri'],
            ];
            $total++;
        }

        ksort($groups);

        return [
            'groups' => array_values($groups),
            'total' => $total,
            'tip' => 'Read resources first (blocks://catalog, site://pages, design://css-vars, acf://fields) to understand the site, then use tools to create/update content.',
        ];
    }

    /**
     * Extract the resource group from an ability suffix.
     * e.g. "posts-list" -> "posts", "post-types-list" -> "post-types",
     *      "translations-create-term" -> "translations".
     */
    private static function extractGroup(string $suffix): string
    {
        $knownGroups = [
            'posts', 'post-types', 'media', 'menus', 'terms',
            'translations', 'strings', 'languages', 'forms',
            'seo', 'redirects', 'cache', 'activity',
            'blocks', 'design', 'acf', 'site',
        ];

        foreach ($knownGroups as $group) {
            if (str_starts_with($suffix, $group.'-') || $suffix === $group) {
                return $group;
            }
        }

        return $suffix;
    }
}
