<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Pages;

use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\DeletePageGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: delete/page.stub used to hardcode
 * `const uuid = computed(() => route.params.uuid as string)` and pass that
 * through as `<{Module}DeleteForm :uuid="uuid" .../>` unconditionally --
 * for a has_uuid: false module, the actual route this page is mounted
 * under is registered as `:id/delete` (see
 * FrontendRoutesGenerator::$idParam), so `route.params.uuid` read
 * undefined and the DeleteForm never received a record identifier at all.
 * The LOCAL variable is still named `uuid` (renaming it is cosmetic only,
 * not required -- see the fork's has_uuid pass conventions); only its
 * SOURCE (`route.params.[[idParam]]`) and the PROP NAME it's passed under
 * (which must match DeleteFormGenerator's own renamed prop) needed fixing.
 * DeletePageGenerator extends BaseGenerator directly (no $this->idParam()
 * available), so it resolves the idParam the same way
 * FrontendRoutesGenerator::$idParam's own constructor property does.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Pages\DeletePageGenerator
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\DeleteFormGenerator
 */
class DeletePageGeneratorHasUuidFalseTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-deletepagegen-has-uuid-false-' . uniqid();
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
        $generator = new DeletePageGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath($moduleGroup, $moduleName) . "/{$moduleName}DeletePage.vue";
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_uuid_var_sourced_from_id_param_and_passed_as_id_prop_when_has_uuid_false(): void
    {
        $content = $this->generateAndRead(['has_uuid' => false]);

        $this->assertStringContainsString('const uuid = computed(() => route.params.id as string)', $content);
        $this->assertStringContainsString('<WarehousesDeleteForm :id="uuid" :cancel-link="backLink" />', $content);
    }

    public function test_uuid_var_sourced_from_uuid_param_and_passed_as_uuid_prop_when_has_uuid_true(): void
    {
        $content = $this->generateAndRead(['has_uuid' => true]);

        $this->assertStringContainsString('const uuid = computed(() => route.params.uuid as string)', $content);
        $this->assertStringContainsString('<WarehousesDeleteForm :uuid="uuid" :cancel-link="backLink" />', $content);
    }

    public function test_uuid_var_sourced_from_uuid_param_when_has_uuid_is_absent_default(): void
    {
        $content = $this->generateAndRead([]);

        $this->assertStringContainsString('const uuid = computed(() => route.params.uuid as string)', $content);
        $this->assertStringContainsString('<WarehousesDeleteForm :uuid="uuid" :cancel-link="backLink" />', $content);
    }

    public function test_honors_explicit_id_param_override_regardless_of_has_uuid(): void
    {
        $content = $this->generateAndRead([
            'has_uuid' => true,
            'features' => ['frontend' => ['view' => ['idParam' => 'slug']]],
        ]);

        $this->assertStringContainsString('const uuid = computed(() => route.params.slug as string)', $content);
        $this->assertStringContainsString('<WarehousesDeleteForm :slug="uuid" :cancel-link="backLink" />', $content);
    }
}
