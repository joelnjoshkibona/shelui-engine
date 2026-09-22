<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Pages;

use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\EditPageGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: edit/page.stub used to hardcode
 * `const id = route.params.uuid as string` and pass that id through as
 * `<{Module}EditForm :uuid="id" .../>` unconditionally -- for a has_uuid:
 * false module, the actual route this page is mounted under is registered
 * as `:id/edit` (see FrontendRoutesGenerator::$idParam), so
 * `route.params.uuid` read undefined and the EditForm never received a
 * record identifier at all. EditPageGenerator extends BaseGenerator
 * directly (no $this->idParam() available -- that's BaseComponentGenerator-
 * only), so it resolves the idParam the same way
 * FrontendRoutesGenerator::$idParam's own constructor property does. The
 * prop name passed to <{Module}EditForm> must also match EditFormGenerator's
 * own renamed prop (see EditFormGeneratorHasUuidFalseTest).
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Pages\EditPageGenerator
 */
class EditPageGeneratorHasUuidFalseTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-editpagegen-has-uuid-false-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetProjectRoot();
        PathManager::resetModuleSubGroup();
        $this->removeDirectory($this->tmpRoot);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($dir);
    }

    private function generateAndRead(array $config, string $moduleName = 'Warehouses', string $moduleGroup = 'Core'): string
    {
        $config = array_replace_recursive([
            'features' => ['frontend' => ['edit' => true]],
        ], $config);

        $generator = new EditPageGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath($moduleGroup, $moduleName) . "/{$moduleName}EditPage.vue";
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_id_is_read_from_id_param_and_passed_through_when_has_uuid_false(): void
    {
        $content = $this->generateAndRead(['has_uuid' => false]);

        $this->assertStringContainsString('const id = route.params.id as string', $content);
        $this->assertStringContainsString('<WarehousesEditForm :id="id" :cancel-link="backLink" />', $content);
        $this->assertStringNotContainsString('uuid', $content);
    }

    public function test_id_is_read_from_uuid_param_and_passed_through_when_has_uuid_true(): void
    {
        $content = $this->generateAndRead(['has_uuid' => true]);

        $this->assertStringContainsString('const id = route.params.uuid as string', $content);
        $this->assertStringContainsString('<WarehousesEditForm :uuid="id" :cancel-link="backLink" />', $content);
    }

    public function test_id_is_read_from_uuid_param_when_has_uuid_is_absent_default(): void
    {
        $content = $this->generateAndRead([]);

        $this->assertStringContainsString('const id = route.params.uuid as string', $content);
        $this->assertStringContainsString('<WarehousesEditForm :uuid="id" :cancel-link="backLink" />', $content);
    }

    public function test_honors_explicit_id_param_override_regardless_of_has_uuid(): void
    {
        $content = $this->generateAndRead([
            'has_uuid' => true,
            'features' => ['frontend' => ['edit' => true, 'view' => ['idParam' => 'slug']]],
        ]);

        $this->assertStringContainsString('const id = route.params.slug as string', $content);
        $this->assertStringContainsString('<WarehousesEditForm :slug="id" :cancel-link="backLink" />', $content);
    }
}
