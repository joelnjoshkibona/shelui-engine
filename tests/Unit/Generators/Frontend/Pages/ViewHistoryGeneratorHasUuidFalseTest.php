<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Pages;

use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\ViewHistoryGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: ViewHistoryGenerator independently computed
 * `$idParam = $viewConfig['idParam'] ?? 'uuid';`, the same pre-fork
 * hardcoded-'uuid' fallback FrontendRoutesGenerator/ViewLayoutGenerator had,
 * instead of the shared BaseComponentGenerator::idParam(). This test proves
 * the resolution itself is now correct and consistent with every sibling
 * View generator (matching FrontendRoutesGeneratorHasUuidFalseTest's and
 * ViewLayoutGeneratorHasUuidFalseTest's coverage of the same fallback).
 *
 * Note: `history/history.stub`'s own markup does not currently interpolate
 * a `[[idParam]]` placeholder anywhere (EntityHistoryPage.vue -- legacy,
 * hand-maintained app infrastructure per this project's own CLAUDE.md --
 * reads no such prop from this wrapper), so generated output is byte-
 * identical regardless of has_uuid today; this is a smoke/no-crash test
 * plus a documentation of that fact, not a content-diff assertion. If a
 * future stub change starts consuming [[idParam]], this fix is already
 * correctly wired to feed it the resolved value.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Pages\ViewHistoryGenerator::generate()
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::idParam()
 */
class ViewHistoryGeneratorHasUuidFalseTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-view-history-has-uuid-false-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetProjectRoot();
        $this->removeDirectory($this->tmpRoot);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function generateAndRead(array $config, string $moduleName = 'Warehouses', string $moduleGroup = 'Custom'): string
    {
        $generator = new ViewHistoryGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath($moduleGroup, $moduleName) . "/{$moduleName}DetailsHistoryPage.vue";
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_generates_successfully_when_has_uuid_false(): void
    {
        $content = $this->generateAndRead([
            'has_uuid' => false,
            'features' => ['frontend' => ['view' => ['enabled' => true]]],
        ]);

        $this->assertStringContainsString("module=\"warehouses\"", $content);
        $this->assertStringContainsString('EntityHistoryPage', $content);
    }

    public function test_generates_identically_when_has_uuid_true(): void
    {
        $contentFalse = $this->generateAndRead([
            'has_uuid' => false,
            'features' => ['frontend' => ['view' => ['enabled' => true]]],
        ], 'Warehouses', 'Custom');

        $contentTrue = $this->generateAndRead([
            'has_uuid' => true,
            'features' => ['frontend' => ['view' => ['enabled' => true]]],
        ], 'Warehouses', 'Custom');

        // idParam isn't wired into history.stub's markup (see class docblock),
        // so today has_uuid does not change this file's output at all.
        $this->assertSame($contentFalse, $contentTrue);
    }
}
