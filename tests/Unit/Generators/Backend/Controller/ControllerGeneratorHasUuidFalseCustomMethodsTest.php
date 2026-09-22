<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Controller;

use Blutrixx\GeneratorEngine\Generators\Backend\Controller\ControllerGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: mirrors RoutesGeneratorHasUuidFalseCustomRoutesTest for
 * the CONTROLLER side of the same fix -- a delegation method's parentKey
 * parameter and an action splash method's record parameter both hardcoded
 * 'uuid' regardless of has_uuid, and MUST resolve identically to the route
 * segment RoutesGenerator registers or Laravel can never bind the URL
 * segment to the method argument.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Controller\ControllerGenerator::generateDelegationMethods()
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Controller\ControllerGenerator::generateActionMethods()
 */
class ControllerGeneratorHasUuidFalseCustomMethodsTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-controller-has-uuid-false-custom-' . uniqid();
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

    private function generateAndRead(array $config, string $moduleName = 'Warehouses'): string
    {
        $generator = new ControllerGenerator($moduleName, 'Custom', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . "/BACKEND/app/Project/Modules/Custom/{$moduleName}/{$moduleName}Controller.php";
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_delegation_method_uses_id_parameter_when_has_uuid_false(): void
    {
        $content = $this->generateAndRead([
            'has_uuid' => false,
            'delegations' => ['stockMovements' => [
                'name' => 'StockMovements',
                'relatedModule' => ['name' => 'StockMovements', 'group' => 'Custom'],
                'operations' => ['list' => ['enabled' => true]],
            ]],
        ]);

        $this->assertStringContainsString('public function listStockMovements(Request $request, string $id)', $content);
        $this->assertStringNotContainsString('string $uuid', $content);
    }

    public function test_delegation_method_uses_uuid_parameter_when_has_uuid_true(): void
    {
        $content = $this->generateAndRead([
            'has_uuid' => true,
            'delegations' => ['stockMovements' => [
                'name' => 'StockMovements',
                'relatedModule' => ['name' => 'StockMovements', 'group' => 'Custom'],
                'operations' => ['list' => ['enabled' => true]],
            ]],
        ]);

        $this->assertStringContainsString('public function listStockMovements(Request $request, string $uuid)', $content);
    }

    public function test_delegation_method_honors_explicit_parent_key_override(): void
    {
        $content = $this->generateAndRead([
            'has_uuid' => false,
            'delegations' => ['stockMovements' => [
                'name' => 'StockMovements',
                'relatedModule' => ['name' => 'StockMovements', 'group' => 'Custom'],
                'parentKey' => 'warehouse_ref',
                'operations' => ['list' => ['enabled' => true]],
            ]],
        ]);

        $this->assertStringContainsString('public function listStockMovements(Request $request, string $warehouse_ref)', $content);
    }

    public function test_action_splash_method_uses_id_parameter_when_has_uuid_false(): void
    {
        $content = $this->generateAndRead([
            'has_uuid' => false,
            'actions' => ['approve' => [
                'name' => 'Approve',
                'splash' => true,
                'operations' => [],
            ]],
        ]);

        $this->assertStringContainsString('public function approveSplash(Request $request, string $id)', $content);
        $this->assertStringContainsString('SplashService::execute($id, $request->all())', $content);
    }

    public function test_action_splash_method_uses_uuid_parameter_when_has_uuid_true(): void
    {
        $content = $this->generateAndRead([
            'has_uuid' => true,
            'actions' => ['approve' => [
                'name' => 'Approve',
                'splash' => true,
                'operations' => [],
            ]],
        ]);

        $this->assertStringContainsString('public function approveSplash(Request $request, string $uuid)', $content);
        $this->assertStringContainsString('SplashService::execute($uuid, $request->all())', $content);
    }
}
