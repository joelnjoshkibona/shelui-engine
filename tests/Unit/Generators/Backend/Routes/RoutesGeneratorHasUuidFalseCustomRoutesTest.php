<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Routes;

use Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: the standard CRUD routes' {uuid}/{id} segment was
 * fixed via [[routeKeyParam]] (see RoutesGeneratorRouteKeyParamTest), but
 * delegation routes and an action's splash route independently hardcoded
 * 'uuid' as their parentKey/segment default -- a has_uuid: false module
 * (this project's real legacy-repointed tables) generated a delegation tab
 * or an action splash pointing at a `{uuid}` segment the module's own
 * routes never actually receive, 404ing every time.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator::generateDelegationRoutes()
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator::generateActionRoutes()
 */
class RoutesGeneratorHasUuidFalseCustomRoutesTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-routes-has-uuid-false-custom-' . uniqid();
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
        $generator = new RoutesGenerator($moduleName, 'Custom', $config);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . "/BACKEND/app/Project/Modules/Custom/{$moduleName}/Routes/api.php";
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function delegationConfig(bool $hasUuid, ?string $explicitParentKey = null): array
    {
        $delegation = [
            'name' => 'StockMovements',
            'relatedModule' => ['name' => 'StockMovements', 'group' => 'Custom'],
            'filterKey' => 'warehouse_id',
            'operations' => [
                'list' => ['enabled' => true],
                'view' => ['enabled' => true],
            ],
        ];
        if ($explicitParentKey !== null) {
            $delegation['parentKey'] = $explicitParentKey;
        }

        return [
            'module_name' => 'Warehouses',
            'module_type' => 'Custom',
            'table_name' => 'warehouses',
            'id_type' => $hasUuid ? 'uuid' : 'bigint',
            'has_uuid' => $hasUuid,
            'columns' => [],
            'delegations' => ['stockMovements' => $delegation],
        ];
    }

    public function test_delegation_route_uses_id_segment_when_has_uuid_false(): void
    {
        $content = $this->generateAndRead($this->delegationConfig(false));

        $this->assertStringContainsString("->get('/warehouses/{id}/stock-movements/list'", $content);
        $this->assertStringContainsString("->get('/warehouses/{id}/stock-movements/{itemUuid}/view'", $content);
        $this->assertStringNotContainsString('{uuid}/stock-movements', $content);
    }

    public function test_delegation_route_uses_uuid_segment_when_has_uuid_true(): void
    {
        $content = $this->generateAndRead($this->delegationConfig(true));

        $this->assertStringContainsString("->get('/warehouses/{uuid}/stock-movements/list'", $content);
    }

    public function test_delegation_route_honors_explicit_parent_key_override_regardless_of_has_uuid(): void
    {
        $content = $this->generateAndRead($this->delegationConfig(false, 'warehouse_ref'));

        $this->assertStringContainsString("->get('/warehouses/{warehouse_ref}/stock-movements/list'", $content);
    }

    private function actionSplashConfig(bool $hasUuid): array
    {
        return [
            'module_name' => 'Warehouses',
            'module_type' => 'Custom',
            'table_name' => 'warehouses',
            'id_type' => $hasUuid ? 'uuid' : 'bigint',
            'has_uuid' => $hasUuid,
            'columns' => [],
            'actions' => [
                'approve' => [
                    'name' => 'Approve',
                    'splash' => true,
                    'operations' => [],
                ],
            ],
        ];
    }

    public function test_action_splash_route_uses_id_segment_when_has_uuid_false(): void
    {
        $content = $this->generateAndRead($this->actionSplashConfig(false));

        $this->assertStringContainsString("->get('/warehouses/{id}/approve/splash'", $content);
        $this->assertStringNotContainsString('{uuid}/approve/splash', $content);
    }

    public function test_action_splash_route_uses_uuid_segment_when_has_uuid_true(): void
    {
        $content = $this->generateAndRead($this->actionSplashConfig(true));

        $this->assertStringContainsString("->get('/warehouses/{uuid}/approve/splash'", $content);
    }
}
