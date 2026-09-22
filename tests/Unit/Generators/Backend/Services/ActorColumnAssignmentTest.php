<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\CreateServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Services\EditServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: the create/edit service stubs used to hardcode
 * `$validData['created_by_id'] = Auth::id();` / `$validData['updated_by_id']
 * = Auth::id();` as LITERAL lines, unconditionally -- even for a
 * has_creator_updater: false module (no such columns at all; BaseModel's
 * schema-driven getFillable() silently dropped the unknown key, but the
 * generated code was still wrong to emit it). Column names now come from
 * ModuleConfigContract::creatorUpdaterColumns(), and a single-actor module
 * (creator-only, no updated_by) correctly emits no assignment at all on
 * edit.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\CreateServiceGenerator::generateCreatedByAssignment()
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\EditServiceGenerator::generateUpdatedByAssignment()
 */
class ActorColumnAssignmentTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-actor-column-test-' . uniqid();
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

    private function baseCreateConfig(array $overrides = []): array
    {
        return array_merge([
            'features' => ['backend' => ['create' => ['fields' => [
                ['field' => 'name', 'rules' => 'required|string|max:255'],
            ]]]],
        ], $overrides);
    }

    private function baseEditConfig(array $overrides = []): array
    {
        return array_merge([
            'table_name' => 'zzz_actor',
            'features' => ['backend' => ['edit' => ['fields' => [
                ['field' => 'name', 'rules' => 'required|string|max:255'],
            ]]]],
        ], $overrides);
    }

    private function generateCreate(array $config, string $moduleName): string
    {
        $generator = new CreateServiceGenerator($moduleName, 'Custom', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . "/BACKEND/app/Project/Modules/Custom/{$moduleName}/Services/{$moduleName}CreateService.php";
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    private function generateEdit(array $config, string $moduleName): string
    {
        $generator = new EditServiceGenerator($moduleName, 'Custom', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . "/BACKEND/app/Project/Modules/Custom/{$moduleName}/Services/{$moduleName}EditService.php";
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    public function test_create_service_defaults_to_created_by_id(): void
    {
        $content = $this->generateCreate($this->baseCreateConfig(), 'ZzzDefaultCreate');

        $this->assertStringContainsString("\$validData['created_by_id'] = Auth::id();", $content);
    }

    public function test_create_service_uses_configured_column_name(): void
    {
        $content = $this->generateCreate($this->baseCreateConfig([
            'has_creator_updater' => true,
            'creator_updater_columns' => ['created_by' => 'created_by'],
        ]), 'ZzzLegacyCreate');

        $this->assertStringContainsString("\$validData['created_by'] = Auth::id();", $content);
        $this->assertStringNotContainsString('created_by_id', $content);
    }

    public function test_create_service_omits_assignment_when_no_creator_updater(): void
    {
        $content = $this->generateCreate($this->baseCreateConfig([
            'has_creator_updater' => false,
        ]), 'ZzzNoAuditCreate');

        $this->assertStringNotContainsString('created_by_id', $content);
        $this->assertStringNotContainsString("= Auth::id();", $content);
    }

    public function test_edit_service_defaults_to_updated_by_id(): void
    {
        $content = $this->generateEdit($this->baseEditConfig(), 'ZzzDefaultEdit');

        $this->assertStringContainsString("\$validData['updated_by_id'] = Auth::id();", $content);
    }

    public function test_edit_service_uses_configured_column_name(): void
    {
        $content = $this->generateEdit($this->baseEditConfig([
            'has_creator_updater' => true,
            'creator_updater_columns' => ['created_by' => 'created_by', 'updated_by' => 'modified_by'],
        ]), 'ZzzLegacyEdit');

        $this->assertStringContainsString("\$validData['modified_by'] = Auth::id();", $content);
        $this->assertStringNotContainsString('updated_by_id', $content);
    }

    public function test_edit_service_omits_assignment_for_single_actor_creator_only_module(): void
    {
        $content = $this->generateEdit($this->baseEditConfig([
            'has_creator_updater' => true,
            'creator_updater_columns' => ['created_by' => 'created_by', 'updated_by' => null],
        ]), 'ZzzCreatorOnlyEdit');

        $this->assertStringNotContainsString('updated_by', $content);
        $this->assertStringNotContainsString('= Auth::id();', $content);
    }

    public function test_edit_service_omits_assignment_when_no_creator_updater(): void
    {
        $content = $this->generateEdit($this->baseEditConfig([
            'has_creator_updater' => false,
        ]), 'ZzzNoAuditEdit');

        $this->assertStringNotContainsString('updated_by_id', $content);
        $this->assertStringNotContainsString('= Auth::id();', $content);
    }
}
