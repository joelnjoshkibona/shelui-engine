<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Pages;

use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\ViewLayoutGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: ViewLayoutGenerator independently computed
 * `$idParam = $viewConfig['idParam'] ?? 'uuid';` -- the same pre-fork
 * escape-hatch pattern FrontendRoutesGenerator had before its own fix --
 * instead of calling the shared BaseComponentGenerator::idParam(). For a
 * has_uuid: false module, the details route this page is mounted under is
 * registered with an `:id` segment (FrontendRoutesGenerator::$idParam), so
 * `route.params.[[idParam]]` resolving here to `route.params.uuid` read
 * undefined: `recordId` (details_layout.stub's computed record id, used for
 * every fetch/restore/action-navigation call on the page) and
 * `navigateTo()`'s route-params object both broke for every generated
 * action on such a module.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Pages\ViewLayoutGenerator::generate()
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::idParam()
 */
class ViewLayoutGeneratorHasUuidFalseTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-view-layout-has-uuid-false-' . uniqid();
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
        $generator = new ViewLayoutGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath($moduleGroup, $moduleName) . "/{$moduleName}DetailsLayout.vue";
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_record_id_and_navigate_to_use_id_param_when_has_uuid_false(): void
    {
        $content = $this->generateAndRead([
            'has_uuid' => false,
            'features' => ['frontend' => [
                'view' => ['enabled' => true],
                'edit' => ['enabled' => true],
                'delete' => ['enabled' => true],
            ]],
        ]);

        $this->assertStringContainsString('route.params.id', $content);
        $this->assertStringContainsString('id: recordId.value', $content);
        $this->assertStringNotContainsString('route.params.uuid', $content);
        $this->assertStringNotContainsString('uuid: recordId.value', $content);
    }

    public function test_record_id_and_navigate_to_use_uuid_param_when_has_uuid_true(): void
    {
        $content = $this->generateAndRead([
            'has_uuid' => true,
            'features' => ['frontend' => [
                'view' => ['enabled' => true],
            ]],
        ]);

        $this->assertStringContainsString('route.params.uuid', $content);
        $this->assertStringContainsString('uuid: recordId.value', $content);
    }

    public function test_explicit_id_param_override_wins_regardless_of_has_uuid(): void
    {
        $content = $this->generateAndRead([
            'has_uuid' => true,
            'features' => ['frontend' => [
                'view' => ['idParam' => 'slug'],
            ]],
        ]);

        $this->assertStringContainsString('route.params.slug', $content);
        $this->assertStringContainsString('slug: recordId.value', $content);
    }

    /**
     * The Edit/Delete modal props MUST bind under the same name
     * EditFormGenerator/DeleteFormGenerator declare on
     * {Module}EditForm/{Module}DeleteForm themselves (`[[idParam]]`, not a
     * literal `uuid` -- see those generators' own has_uuid coverage): Vue
     * drops an attribute that matches no declared prop instead of binding
     * it, so `:uuid="recordId"` against a has_uuid: false module's Edit/
     * Delete form (which declares an `id` prop) silently left that prop at
     * its default and broke every request the Form built from it. The VALUE
     * (`recordId`) is unchanged -- still sourced from the correctly-resolved
     * route param, confirmed by
     * test_record_id_and_navigate_to_use_id_param_when_has_uuid_false().
     */
    public function test_crud_modal_prop_name_tracks_id_param_with_record_id_value(): void
    {
        $contentFalse = $this->generateAndRead([
            'has_uuid' => false,
            'features' => ['frontend' => [
                'view' => ['enabled' => true],
                'edit' => ['enabled' => true],
                'delete' => ['enabled' => true],
            ]],
        ]);

        $this->assertStringContainsString(':id="recordId"', $contentFalse);
        $this->assertStringNotContainsString(':uuid="recordId"', $contentFalse);

        $contentTrue = $this->generateAndRead([
            'has_uuid' => true,
            'features' => ['frontend' => [
                'view' => ['enabled' => true],
                'edit' => ['enabled' => true],
                'delete' => ['enabled' => true],
            ]],
        ]);

        $this->assertStringContainsString(':uuid="recordId"', $contentTrue);
    }
}
