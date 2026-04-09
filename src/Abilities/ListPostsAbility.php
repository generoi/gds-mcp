<?php

namespace GeneroWP\MCP\Abilities;

use GeneroWP\MCP\Concerns\RestDelegation;
use WP_Error;

final class ListPostsAbility
{
    use RestDelegation;

    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public static function register(): void
    {
        $ability = self::instance();

        HelpAbility::registerAbility('gds/posts-list', [
            'label' => 'List Posts',
            'description' => 'Search and filter content. Delegates to the WordPress REST API — accepts all standard REST parameters. Use gds/post-types-list to discover available types.',
            'category' => 'gds-content',
            'input_schema' => self::getRestInputSchema('/wp/v2/pages', [
                'post_type' => [
                    'type' => 'string',
                    'description' => 'Post type slug to filter by.',
                    'default' => 'page',
                ],
            ]),
            'output_schema' => self::getRestListOutputSchema('/wp/v2/pages'),
            'permission_callback' => '__return_true',
            'execute_callback' => [$ability, 'execute'],
            'meta' => [
                'annotations' => [
                    'readonly' => true,
                    'destructive' => false,
                    'idempotent' => true,
                ],
            ],
        ]);
    }

    public function execute(mixed $input = []): array|WP_Error
    {
        $input = is_array($input) ? $input : [];
        $postType = $input['post_type'] ?? 'page';
        $route = self::getRestRoute($postType);

        if (! $route) {
            return new WP_Error('invalid_post_type', "Post type '{$postType}' is not available via REST API.");
        }

        $params = $input;
        unset($params['post_type']);

        $response = self::restGet($route, $params);

        if (self::isRestError($response)) {
            return self::restErrorToWpError($response);
        }

        $headers = $response->get_headers();

        return [
            'posts' => array_map(fn ($item) => (array) $item, $response->get_data()),
            'total' => (int) ($headers['X-WP-Total'] ?? 0),
            'pages' => (int) ($headers['X-WP-TotalPages'] ?? 0),
        ];
    }
}
