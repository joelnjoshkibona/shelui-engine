<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend;

use Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\ApiContractGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: ApiContractGenerator::buildRoutes() parses RoutesGenerator's
 * own emitted Routes/api.php content (see ApiContractRouteParityTest) rather than
 * re-deriving paths from module.json — so once the backend RoutesGenerator started
 * emitting `{id}` instead of `{uuid}` for a `has_uuid: false` module (see
 * BaseGenerator::replacePlaceholders()'s `[[routeKeyParam]]` default and
 * RoutesGenerator's own `$routeKeyParam`/`$parentKey`/`$splashRouteKeyParam`
 * resolutions), this generator needed to be checked for any assumption that the
 * per-record segment is always literally `uuid`.
 *
 * ROUTE_PATTERN/PERMISSION_PATTERN (buildRoutes()) are generic regexes over
 * method/path/controller-method/permission and never hardcode the segment name, so
 * no fix was needed here — this test locks that in as a regression guard, the same
 * way FrontendRoutesGeneratorHasUuidFalseTest guards the sibling frontend routes.ts
 * generator. `flags.uuid` (conventionFlags()/buildModuleContract()) already reported
 * `has_uuid` as a plain boolean before this pass and needed no change either; covered
 * here too since it feeds the same contract file.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\ApiContractGenerator
 * @see \Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\ApiContractRouteParityTest
 */
class ApiContractGeneratorHasUuidFalseTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-api-contract-has-uuid-false-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetModuleSubGroup();
        PathManager::resetProjectRoot();
        $this->removeDirectory($this->tmpRoot);
        parent::tearDown();
    }

    public function test_flags_report_uuid_false(): void
    {
        $contract = (new ApiContractGenerator('Warehouses', 'Core', self::config(false)))->buildModuleContract();

        $this->assertFalse($contract['flags']['uuid']);
    }

    public function test_flags_report_uuid_true_by_default(): void
    {
        $contract = (new ApiContractGenerator('Warehouses', 'Core', self::config(true)))->buildModuleContract();

        $this->assertTrue($contract['flags']['uuid']);
    }

    public function test_contract_routes_use_id_segment_when_has_uuid_false(): void
    {
        $config = self::config(false);

        $contract = (new ApiContractGenerator('Warehouses', 'Core', $config))->buildModuleContract();
        $paths    = array_column($contract['routes'], 'path');

        $this->assertNotEmpty($paths, 'fixture emitted no routes at all');

        $viewRoute = null;
        foreach ($contract['routes'] as $route) {
            if ($route['method'] === 'GET' && str_ends_with($route['path'], '/view')) {
                $viewRoute = $route;
                break;
            }
        }
        $this->assertNotNull($viewRoute, 'no view route in the contract');
        $this->assertSame('/warehouses/{id}/view', $viewRoute['path']);

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('{uuid}', $path, "route path still contains a literal {uuid}: {$path}");
        }
    }

    /**
     * Proves buildRoutes() is a genuine pass-through: whatever RoutesGenerator
     * writes to Routes/api.php for a has_uuid: false module is exactly what ends up
     * in the contract, path-for-path — the same parity ApiContractRouteParityTest
     * proves for the has_uuid: true default, just for the other flag value.
     */
    public function test_contract_routes_match_the_generated_routes_file_for_has_uuid_false(): void
    {
        $config = self::config(false);

        (new RoutesGenerator('Warehouses', 'Core', $config))->setForce(true)->generate();
        $routesFile = PathManager::getBackendModulePath('Core', 'Warehouses') . '/Routes/api.php';
        $this->assertFileExists($routesFile);

        preg_match_all(
            '/->(get|post|put|patch|delete)\(\s*[\'"]([^\'"]+)[\'"]/i',
            (string) file_get_contents($routesFile),
            $matches,
            PREG_SET_ORDER
        );
        $emitted = array_map(
            static fn (array $m): string => strtoupper($m[1]) . ' ' . $m[2],
            $matches
        );
        sort($emitted);

        $contract = (new ApiContractGenerator('Warehouses', 'Core', $config))->buildModuleContract();
        $fromContract = array_map(
            static fn (array $r): string => $r['method'] . ' ' . $r['path'],
            $contract['routes']
        );
        sort($fromContract);

        $this->assertNotEmpty($emitted, 'fixture emitted no routes at all');
        $this->assertSame($emitted, $fromContract, 'api-contract.json disagrees with the emitted Routes/api.php for a has_uuid: false module');
    }

    // ─── Fixtures ─────────────────────────────────────────────────────────────

    private static function config(bool $hasUuid): array
    {
        return [
            'module_name' => 'Warehouses',
            'module_type' => 'Core',
            'table_name'  => 'warehouses',
            'id_type'     => 'bigint',
            'has_uuid'    => $hasUuid,
            'columns'     => [
                ['name' => 'name', 'type' => 'string', 'nullable' => false, 'unique' => true],
            ],
            'features' => [
                'backend' => [
                    'list'   => ['enabled' => true],
                    'create' => ['enabled' => true],
                    'view'   => ['enabled' => true],
                    'edit'   => ['enabled' => true],
                    'delete' => ['enabled' => true],
                ],
            ],
        ];
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
}
