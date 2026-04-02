<?php

namespace GeneroWP\MCP\Concerns;

trait BlockExamples
{
    /**
     * Attributes that distinguish structurally different block usages.
     * Color/decoration attributes are excluded to avoid near-duplicate examples.
     */
    private static array $structuralAttrs = ['className', 'layout', 'tagName', 'align', 'templateLock'];

    /**
     * Load and parse demo page for block examples.
     *
     * @return array{0: array{id: int, title: string}|null, 1: array<string, string[]>}
     */
    private static function loadDemoExamples(int $limit = 10, ?string $style = null): array
    {
        $pageId = self::getDemoPageId();
        if (! $pageId) {
            return [null, []];
        }

        $post = get_post($pageId);
        if (! $post || ! $post->post_content) {
            return [null, []];
        }

        $blocks = parse_blocks($post->post_content);
        $examples = [];
        $fingerprints = [];
        self::collectBlockExamples($blocks, $examples, $fingerprints, $limit, $style);

        $demoPage = [
            'id' => $post->ID,
            'title' => $post->post_title,
        ];

        return [$demoPage, $examples];
    }

    private static function getDemoPageId(): ?int
    {
        /** @var int $pageId Override via filter for project-specific demo pages. */
        $pageId = (int) apply_filters('gds_mcp_block_demo_page_id', 0);
        if ($pageId > 0) {
            return $pageId;
        }

        // Auto-discover by well-known slugs.
        foreach (['demo-blocks', 'block-demo', 'design-system', 'styleguide'] as $slug) {
            $page = get_page_by_path($slug);
            if ($page && $page->post_status === 'publish') {
                return $page->ID;
            }
        }

        return null;
    }

    /**
     * Search published posts for real-world examples of a specific block.
     *
     * @return array<int, array{post_id: int, post_title: string, post_type: string, markup: string}>
     */
    private static function searchPostsForBlock(string $blockName, ?string $postType = null, int $limit = 10, ?string $style = null): array
    {
        global $wpdb;

        // The block comment tag: "wp:name" for core, "wp:ns/name" for custom.
        $commentTag = str_starts_with($blockName, 'core/')
            ? 'wp:'.substr($blockName, 5)
            : 'wp:'.$blockName;

        $demoPageId = self::getDemoPageId() ?? 0;
        $frontPageId = (int) get_option('page_on_front', 0);

        // Find published posts containing this block (exclude demo page).
        // Prioritize: synced patterns (wp_block), front page, then recency.
        $typeClause = '';
        $params = ['%<!-- '.$wpdb->esc_like($commentTag).'%', $demoPageId, $frontPageId];
        if ($postType) {
            $typeClause = 'AND post_type = %s';
            $params[] = $postType;
        }
        $params[] = $limit;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $postIds = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status = 'publish'
             AND post_content LIKE %s
             AND ID != %d
             {$typeClause}
             ORDER BY
               CASE
                 WHEN post_type = 'wp_block' THEN 0
                 WHEN ID = %d THEN 1
                 ELSE 2
               END,
               post_modified DESC
             LIMIT %d",
            ...$params
        ));

        if (! $postIds) {
            return [];
        }

        $results = [];
        $fingerprints = [];

        foreach ($postIds as $postId) {
            $post = get_post((int) $postId);
            if (! $post) {
                continue;
            }

            $blocks = parse_blocks($post->post_content);
            $found = self::findBlockInstances($blocks, $blockName);

            foreach ($found as $block) {
                if ($style && ! self::blockHasStyle($block, $style)) {
                    continue;
                }

                $fp = self::structuralFingerprint($block);
                if (in_array($fp, $fingerprints, true)) {
                    continue;
                }

                $serialized = serialize_block($block);

                $results[] = [
                    'post_id' => $post->ID,
                    'post_title' => $post->post_title,
                    'post_type' => $post->post_type,
                    'markup' => $serialized,
                ];
                $fingerprints[] = $fp;

                if (count($results) >= $limit) {
                    return $results;
                }
            }
        }

        return $results;
    }

    /**
     * Recursively find all instances of a specific block within parsed blocks.
     */
    private static function findBlockInstances(array $blocks, string $blockName): array
    {
        $found = [];
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === $blockName) {
                $found[] = $block;
            }
            if (! empty($block['innerBlocks'])) {
                $found = array_merge($found, self::findBlockInstances($block['innerBlocks'], $blockName));
            }
        }

        return $found;
    }

    /**
     * Recursively collect deduplicated block examples grouped by block name.
     */
    private static function collectBlockExamples(array $blocks, array &$examples, array &$fingerprints = [], int $limit = 10, ?string $style = null): void
    {
        foreach ($blocks as $block) {
            if (empty($block['blockName'])) {
                continue;
            }

            $name = $block['blockName'];

            if (! isset($examples[$name])) {
                $examples[$name] = [];
                $fingerprints[$name] = [];
            }

            if ($style && ! self::blockHasStyle($block, $style)) {
                // Still recurse — inner blocks may match.
            } elseif (count($examples[$name]) < $limit) {
                $fp = self::structuralFingerprint($block);

                if (! in_array($fp, $fingerprints[$name], true)) {
                    $examples[$name][] = serialize_block($block);
                    $fingerprints[$name][] = $fp;
                }
            }

            // Recurse into inner blocks.
            if (! empty($block['innerBlocks'])) {
                self::collectBlockExamples($block['innerBlocks'], $examples, $fingerprints, $limit, $style);
            }
        }
    }

    /**
     * Compute a fingerprint that captures structural differences while ignoring
     * cosmetic attributes like colors.
     */
    private static function structuralFingerprint(array $block): string
    {
        $attrs = $block['attrs'] ?? [];

        // Extract structural attributes.
        $structural = [];
        foreach (self::$structuralAttrs as $key) {
            if (isset($attrs[$key])) {
                $structural[$key] = $attrs[$key];
            }
        }

        // Strip className values that are purely color-related (has-*-color, has-background).
        if (isset($structural['className'])) {
            $classes = preg_replace(
                '/\bhas-[\w-]*-(?:color|background-color)\b|\bhas-background\b/',
                '',
                $structural['className']
            );
            $classes = trim(preg_replace('/\s+/', ' ', $classes));
            $structural['className'] = $classes ?: null;
        }

        // Include inner block structure (block names only, not their attrs).
        $innerNames = array_map(
            fn ($inner) => $inner['blockName'] ?? '',
            $block['innerBlocks'] ?? []
        );

        return md5(json_encode([$structural, $innerNames]));
    }

    /**
     * Check if a parsed block has a specific style applied (is-style-{name} in className).
     */
    private static function blockHasStyle(array $block, string $style): bool
    {
        $className = $block['attrs']['className'] ?? '';
        if (! $className) {
            return false;
        }

        $classes = explode(' ', $className);

        return in_array('is-style-'.$style, $classes, true);
    }
}
