<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Routes;

use Blutrixx\GeneratorEngine\Generators\Frontend\Routes\FrontendRoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: `features.frontend.view.idParam` already existed as an
 * escape hatch (pre-fork) for the edit/view routes, but always defaulted to
 * the literal 'uuid' when absent -- and the delete route and a `uiType:
 * "page"` action's own route never consulted it at all, hardcoding `:uuid`
 * unconditionally. A has_uuid: false module (this project's legacy-repointed
 * tables) got a delete/action route pointing at a `:uuid` segment nothing
 * in the generated page ever populates.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Routes\FrontendRoutesGenerator
 */
class FrontendRoutesGeneratorHasUuidFalseTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-frontend-routes-has-uuid-false-' . uniqid();
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
        $generator = new FrontendRoutesGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $routesPath = $this->tmpRoot . "/FRONTEND/src/pages/modules/" . strtolower($moduleGroup) . "/{$moduleName}/routes.ts";
        $this->assertFileExists($routesPath);

        return (string) file_get_contents($routesPath);
    }

    public function test_all_routes_use_id_segment_when_has_uuid_false(): void
    {
        $content = $this->generateAndRead([
            'has_uuid' => false,
            'features' => ['frontend' => [
                'list' => true, 'create' => true, 'edit' => true, 'delete' => true, 'view' => true,
            ]],
        ]);

        $this->assertStringContainsString("path: '/warehouses/:id/edit'", $content);
        $this->assertStringContainsString("path: '/warehouses/:id/delete'", $content);
        $this->assertStringContainsString("path: '/warehouses/:id/details'", $content);
        $this->assertStringContainsString('to.params.id', $content);
        $this->assertStringNotContainsString('uuid', $content);
    }

    public function test_all_routes_use_uuid_segment_when_has_uuid_true(): void
    {
        $content = $this->generateAndRead([
            'has_uuid' => true,
            'features' => ['frontend' => [
                'list' => true, 'edit' => true, 'delete' => true, 'view' => true,
            ]],
        ]);

        $this->assertStringContainsString("path: '/warehouses/:uuid/edit'", $content);
        $this->assertStringContainsString("path: '/warehouses/:uuid/delete'", $content);
        $this->assertStringContainsString("path: '/warehouses/:uuid/details'", $content);
    }

    // ─── {Module}ModuleConfig's own idParam field (used by RelatedRecordLink ───
    // ─── to read THIS module's identifier off a loaded relation) ────────────

    public function test_module_config_export_idparam_is_id_when_has_uuid_false(): void
    {
        $content = $this->generateAndRead([
            'has_uuid' => false,
            'features' => ['frontend' => ['view' => true]],
        ]);

        $this->assertStringContainsString("export const WarehousesModuleConfig: EntityModuleConfig = {", $content);
        $this->assertStringContainsString("idParam: 'id',", $content);
    }

    public function test_module_config_export_idparam_is_uuid_when_has_uuid_true(): void
    {
        $content = $this->generateAndRead([
            'has_uuid' => true,
            'features' => ['frontend' => ['view' => true]],
        ]);

        $this->assertStringContainsString("idParam: 'uuid',", $content);
    }

    public function test_module_config_export_idparam_honors_explicit_override(): void
    {
        $content = $this->generateAndRead([
            'has_uuid' => true,
            'features' => ['frontend' => ['view' => ['idParam' => 'slug']]],
        ]);

        $this->assertStringContainsString("idParam: 'slug',", $content);
    }

    public function test_explicit_id_param_override_wins_regardless_of_has_uuid(): void
    {
        $content = $this->generateAndRead([
            'has_uuid' => true,
            'features' => ['frontend' => [
                'edit' => true, 'delete' => true,
                'view' => ['idParam' => 'slug'],
            ]],
        ]);

        $this->assertStringContainsString("path: '/warehouses/:slug/edit'", $content);
        $this->assertStringContainsString("path: '/warehouses/:slug/delete'", $content);
    }

    public function test_page_action_route_uses_id_segment_when_has_uuid_false(): void
    {
        $content = $this->generateAndRead([
            'has_uuid' => false,
            'features' => ['frontend' => ['view' => true]],
            'actions' => [
                'approve' => [
                    'name' => 'Approve',
                    'uiType' => 'page',
                    'hasUI' => true,
                ],
            ],
        ]);

        $this->assertStringContainsString("path: '/warehouses/:id/approve'", $content);
        $this->assertStringNotContainsString('uuid', $content);
    }
}
