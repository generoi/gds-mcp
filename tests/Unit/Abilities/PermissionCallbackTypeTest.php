<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use WP_UnitTestCase;

/**
 * Verify all ability checkPermission and execute methods accept non-array input.
 *
 * WP core's WP_Ability::invoke_callback() can pass a string or null
 * instead of an array when the MCP adapter transforms the input schema.
 * All callbacks must handle this gracefully.
 */
class PermissionCallbackTypeTest extends WP_UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
    }

    /**
     * @dataProvider abilityClassProvider
     */
    public function test_check_permission_accepts_string_input(string $class): void
    {
        $result = $class::checkPermission('unexpected string');
        $this->assertNotInstanceOf(\TypeError::class, $result);
    }

    /**
     * @dataProvider abilityClassProvider
     */
    public function test_check_permission_accepts_null_input(string $class): void
    {
        $result = $class::checkPermission(null);
        $this->assertNotInstanceOf(\TypeError::class, $result);
    }

    /**
     * @dataProvider executeClassProvider
     */
    public function test_execute_accepts_string_input(string $class): void
    {
        $result = $class::execute('unexpected string');
        // Should return WP_Error (missing required fields) or array, not throw TypeError
        $this->assertTrue(is_array($result) || is_wp_error($result));
    }

    /**
     * @dataProvider executeClassProvider
     */
    public function test_execute_accepts_null_input(string $class): void
    {
        $result = $class::execute(null);
        $this->assertTrue(is_array($result) || is_wp_error($result));
    }

    public static function executeClassProvider(): array
    {
        return self::findClassesWithMethod('execute');
    }

    public static function abilityClassProvider(): array
    {
        return self::findClassesWithMethod('checkPermission');
    }

    private static function findClassesWithMethod(string $method): array
    {
        $classes = [];
        $srcDir = dirname(__DIR__, 3).'/src';

        foreach (['Abilities', 'Integrations'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator("{$srcDir}/{$dir}")
            );

            foreach ($iterator as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $content = file_get_contents($file->getPathname());
                if (! preg_match("/function {$method}\\(/", $content)) {
                    continue;
                }

                if (preg_match('/namespace\s+([\w\\\\]+);/', $content, $ns)
                    && preg_match('/class\s+(\w+)/', $content, $cn)) {
                    $fqcn = $ns[1].'\\'.$cn[1];
                    $classes[$cn[1]] = [$fqcn];
                }
            }
        }

        return $classes;
    }
}
