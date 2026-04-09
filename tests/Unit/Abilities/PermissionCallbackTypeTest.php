<?php

namespace GeneroWP\MCP\Tests\Unit\Abilities;

use WP_UnitTestCase;

/**
 * Verify all ability checkPermission methods accept non-array input.
 *
 * WP core's WP_Ability::invoke_callback() can pass a string or null
 * instead of an array when the MCP adapter transforms the input schema.
 * All checkPermission methods must handle this gracefully.
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
    public function test_checkPermission_accepts_string_input(string $class): void
    {
        $result = $class::checkPermission('unexpected string');
        $this->assertNotInstanceOf(\TypeError::class, $result);
    }

    /**
     * @dataProvider abilityClassProvider
     */
    public function test_checkPermission_accepts_null_input(string $class): void
    {
        $result = $class::checkPermission(null);
        $this->assertNotInstanceOf(\TypeError::class, $result);
    }

    public static function abilityClassProvider(): array
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
                if (! preg_match('/function checkPermission\(/', $content)) {
                    continue;
                }

                // Extract FQCN from namespace + class name.
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
