<?php

namespace GeneroWP\MCP\Tests;

use WP_UnitTestCase;

/**
 * Base test case that handles Polylang language assignment for factory-created posts.
 */
class TestCase extends WP_UnitTestCase
{
    /**
     * Create a post and assign it the default Polylang language (if Polylang is active).
     * Without this, Polylang's query filters exclude the post from search results.
     */
    protected function createPost(array $args = []): int
    {
        $postId = self::factory()->post->create($args);

        if (function_exists('pll_set_post_language') && function_exists('pll_default_language')) {
            pll_set_post_language($postId, pll_default_language());
        }

        return $postId;
    }

    /**
     * Create multiple posts with default language assigned.
     *
     * @return int[]
     */
    protected function createPosts(int $count, array $args = []): array
    {
        $ids = self::factory()->post->create_many($count, $args);

        if (function_exists('pll_set_post_language') && function_exists('pll_default_language')) {
            $lang = pll_default_language();
            foreach ($ids as $id) {
                pll_set_post_language($id, $lang);
            }
        }

        return $ids;
    }
}
