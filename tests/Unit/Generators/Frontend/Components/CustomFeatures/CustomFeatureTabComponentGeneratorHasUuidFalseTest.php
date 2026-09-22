<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Components\CustomFeatures;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\Delegations\DelegationTabComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: tab_action.stub's `uuid` computed ref -- the PARENT
 * module's own record identifier, which every endpoint this tab builds
 * (list/create/edit/delete/view, via `${uuid.value}`) is interpolated from --
 * used to fall back to a hardcoded `route.params.uuid` when `props.uuid` is
 * unset (the case whenever this tab is reached through the details PAGE, not
 * the view modal: details_layout.stub's `<router-view>` passes no `:uuid=`
 * prop at all, only `:data`/`:refresh-key`). For a has_uuid: false parent
 * module the parent details route registers an `:id` segment instead
 * (FrontendRoutesGenerator's own `$idParam` fix), so that literal read
 * undefined and every request this tab issues broke (".../undefined/...").
 *
 * Also exercises DelegationTabComponentGenerator::adaptDelegationToCustomFeature()'s
 * `parentKey` default fix -- not read by this generator today (see its own
 * docblock), but resolved here via the same `$this->idParam()` call so the two
 * stay consistent if a future caller starts consuming it.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\CustomFeatures\CustomFeatureTabComponentGenerator
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\Delegations\DelegationTabComponentGenerator
 */
class CustomFeatureTabComponentGeneratorHasUuidFalseTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-tab-component-has-uuid-false-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetModuleSubGroup();
        PathManager::resetProjectRoot();
        PathManager::setModuleRegistry([]);
        $this->removeDirectory($this->tmpRoot);
        parent::tearDown();
    }

    private function config(bool $hasUuid): array
    {
        return [
            'has_uuid'    => $hasUuid,
            'module_name' => 'Warehouses',
            'module_type' => 'Custom',
            'table_name'  => 'warehouses',
            'features'    => ['frontend' => [
                'list' => ['enabled' => true], 'view' => ['enabled' => true],
            ]],
            'delegations' => [
                'stockmovements' => [
                    'name' => 'StockMovements', 'label' => 'Stock Movements', 'uiType' => 'tab',
                    'relatedModule' => ['name' => 'StockMovements', 'group' => 'Custom'],
                    'filterKey' => 'warehouse_id', 'parentIdField' => 'id',
                    // parentKey deliberately absent -- must resolve via
                    // $this->idParam() (has_uuid) rather than a hardcoded 'uuid'.
                    'operations' => [
                        'list' => ['enabled' => true, 'frontend' => ['fields' => [['key' => 'quantity', 'label' => 'Quantity']]]],
                    ],
                ],
            ],
        ];
    }

    private function generateTab(array $config): string
    {
        PathManager::setModuleRegistry([
            ['name' => 'StockMovements', 'module_type' => 'Custom', 'group_name' => null, 'table_name' => 'stock_movements'],
        ]);

        (new DelegationTabComponentGenerator('Warehouses', 'Custom', $config))
            ->generateDelegation('stockmovements', $config['delegations']['stockmovements']);

        $tab = $this->findFile('WarehousesStockMovementsTab.vue');
        $this->assertNotNull($tab, 'delegation tab was not generated');
        return $tab;
    }

    public function test_parent_record_id_reads_from_route_params_id_when_has_uuid_false(): void
    {
        $tab = $this->generateTab($this->config(false));

        // The exact live code line (not the explanatory comment, which
        // deliberately mentions the literal word "uuid" in prose).
        $this->assertStringContainsString(
            "const uuid = computed(() => String(props.uuid || route.params.id || ''))",
            $tab
        );
        $this->assertStringNotContainsString('route.params.uuid ||', $tab);
        // The local computed ref keeps its name -- cosmetic only, per the
        // fork's "don't rename what's already value-correct" rule -- so every
        // endpoint built off it (${uuid.value}) is unaffected by this fix.
        $this->assertStringContainsString('${uuid.value}/stock-movements/list', $tab);
        $this->assertDoesNotMatchRegularExpression('/\[\[\w+\]\]/', $tab, 'no unresolved [[placeholder]] tokens may remain');
    }

    public function test_parent_record_id_reads_from_route_params_uuid_when_has_uuid_true(): void
    {
        $tab = $this->generateTab($this->config(true));

        $this->assertStringContainsString(
            "const uuid = computed(() => String(props.uuid || route.params.uuid || ''))",
            $tab
        );
        $this->assertStringNotContainsString('route.params.id ||', $tab);
    }

    private function findFile(string $needleInName): ?string
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmpRoot, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (str_contains($file->getFilename(), $needleInName)) {
                return file_get_contents($file->getPathname());
            }
        }
        return null;
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
}
