<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services\Delegation;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\Delegation\DelegationServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Nested-sub-group namespace resolution for delegation `use` imports —
 * mirrors the live bug reproduced against SYSTEM_SHELL's five Item* modules
 * (System\Custom\{Module}): ItemCategoriesItemsService, ItemsItemPricesService,
 * etc. all imported `App\Project\Modules\Core\{Module}` instead of
 * `App\Project\Modules\System\Custom\{Module}`.
 *
 * Unlike an auto-derived FK relation (topologically sorted so the target
 * module is always generated -- and registered -- first), a delegation
 * routinely points from a parent module (FK target, scaffolded first) to a
 * child module (FK source, scaffolded later) that simply isn't in the
 * registry yet when the delegation service is generated. See
 * DelegationServiceGenerator::resolveRelatedModuleGroupPath() for the fix.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\Delegation\DelegationServiceGenerator
 */
class DelegationServiceGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-delegation-test-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::setModuleRegistry([]);
        PathManager::resetModuleSubGroup();
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

    private function baseConfig(): array
    {
        return [
            'connection' => '',
            'id_type'    => 'integer',
            'columns'    => [
                ['name' => 'title', 'type' => 'string'],
            ],
        ];
    }

    public function test_delegation_to_a_not_yet_registered_sibling_in_nested_subgroup_resolves_full_namespace(): void
    {
        // Mirrors ItemCategoriesItemsService: ItemCategories (System\Custom,
        // scaffolded first, as the FK target) declares a delegation listing
        // "Items" (also System\Custom) as children -- Items is scaffolded
        // *later* (it's the FK source), so it is deliberately NOT registered
        // here. Only the caller-declared sub-group ("Custom") is available.
        PathManager::setModuleSubGroup('Custom');

        $generator = new DelegationServiceGenerator(
            'ItemCategories',
            'System',
            $this->baseConfig(),
            'items',
            [
                'name'          => 'Items',
                'relatedModule' => ['name' => 'Items', 'group' => 'Custom'],
                'filterKey'     => 'item_category_id',
            ]
        );
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/System/Custom/ItemCategories/Services/ItemCategoriesItemsService.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);

        $this->assertStringContainsString('use App\Project\Modules\System\Custom\Items\ItemsModel;', $content);
        $this->assertStringNotContainsString('App\Project\Modules\Core\Items', $content);
    }

    public function test_delegation_to_an_already_registered_module_prefers_the_registry(): void
    {
        // When the target IS already known, the registry entry (authoritative)
        // must win over the caller-declared hint.
        PathManager::setModuleRegistry([
            ['name' => 'ItemPrices', 'module_type' => 'System', 'group_name' => 'Custom'],
        ]);
        PathManager::setModuleSubGroup('Custom');

        $generator = new DelegationServiceGenerator(
            'Items',
            'System',
            $this->baseConfig(),
            'itemPrices',
            [
                'name'          => 'ItemPrices',
                'relatedModule' => ['name' => 'ItemPrices', 'group' => 'Custom'],
                'filterKey'     => 'item_id',
            ]
        );
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/System/Custom/Items/Services/ItemsItemPricesService.php';
        $content = file_get_contents($path);

        $this->assertStringContainsString('use App\Project\Modules\System\Custom\ItemPrices\ItemPricesModel;', $content);
    }

    /**
     * shelui-engine fork: the parentKey default used to be hardcoded 'uuid'
     * regardless of has_uuid -- for a has_uuid: false parent module (this
     * project's legacy-repointed tables), the generated list() method both
     * declared a `string $uuid` parameter no caller would ever supply
     * (RoutesGenerator/ControllerGenerator resolve 'id' for the same
     * config) and queried the parent's own table `where('uuid', ...)`
     * against a column that doesn't exist.
     */
    public function test_parent_key_default_is_id_when_has_uuid_is_false(): void
    {
        $config = $this->baseConfig();
        $config['has_uuid'] = false;

        $generator = new DelegationServiceGenerator(
            'Warehouses',
            'Custom',
            $config,
            'stockMovements',
            [
                'name'          => 'StockMovements',
                'relatedModule' => ['name' => 'StockMovements', 'group' => 'Custom'],
                'filterKey'     => 'warehouse_id',
                'operations'    => ['list' => ['enabled' => true]],
            ]
        );
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Custom/Warehouses/Services/WarehousesStockMovementsService.php';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('public static function list(string $id, array $params = []): array', $content);
        $this->assertStringContainsString("\$parent = \$parentQuery->where('id', \$id)->firstOrFail();", $content);
        $this->assertStringNotContainsString('uuid', $content);
    }

    public function test_self_delegation_resolves_via_self_registration(): void
    {
        // e.g. Accounts.children -> Accounts. BaseGenerator registers the
        // current module under its own name before generate() runs, so this
        // resolves through the ordinary registry path -- no special-casing
        // needed here, but the outcome must still be the full nested namespace.
        PathManager::setModuleSubGroup('Custom');

        $generator = new DelegationServiceGenerator(
            'ItemCategories',
            'System',
            $this->baseConfig(),
            'children',
            [
                'name'          => 'Children',
                'relatedModule' => ['name' => 'ItemCategories', 'group' => 'Custom'],
                'filterKey'     => 'parent_id',
            ]
        );
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/System/Custom/ItemCategories/Services/ItemCategoriesChildrenService.php';
        $content = file_get_contents($path);

        $this->assertStringContainsString('App\Project\Modules\System\Custom\ItemCategories\ItemCategoriesModel', $content);
    }

    public function test_flat_core_delegation_is_unaffected(): void
    {
        // No sub-group anywhere in play -- must behave exactly as before.
        PathManager::setModuleRegistry([
            ['name' => 'Comments', 'module_type' => 'Core'],
        ]);

        $generator = new DelegationServiceGenerator(
            'Posts',
            'Core',
            $this->baseConfig(),
            'comments',
            [
                'name'          => 'Comments',
                'relatedModule' => ['name' => 'Comments', 'group' => null],
                'filterKey'     => 'post_id',
            ]
        );
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Posts/Services/PostsCommentsService.php';
        $content = file_get_contents($path);

        $this->assertStringContainsString('use App\Project\Modules\Core\Comments\CommentsModel;', $content);
    }

    public function test_unresolvable_delegation_target_fails_loudly_instead_of_defaulting_to_core(): void
    {
        // No registry entry AND no caller-declared sub-group hint at all --
        // genuinely unresolvable. A delegation is an explicit, blueprint
        // -authored declaration (not a guess), so this must throw rather
        // than silently emit `use App\Project\Modules\Core\Bogus\BogusModel;`.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Bogus/');

        $generator = new DelegationServiceGenerator(
            'Items',
            'System',
            $this->baseConfig(),
            'bogus',
            [
                'name'          => 'Bogus',
                'relatedModule' => ['name' => 'Bogus', 'group' => null],
                'filterKey'     => 'item_id',
            ]
        );
        $generator->setForce(true);
        $generator->generate();
    }

    // ─── Thin proxy over native services (2026-08-05) ──────────────────────
    //
    // Redesign: DelegationServiceGenerator used to reimplement list/create/
    // edit/view/delete from scratch against {RelatedModule}Model directly --
    // its own independent eager-load/filterable-fields/validation-rules
    // building (the two eager-load tests this section replaces asserted on
    // that old, now-deleted machinery). That skipped the related module's own
    // beforeCreate/afterCreate/beforeUpdate/afterUpdate hooks entirely and had
    // no cascade-delete check. Now every method resolves the parent, builds a
    // scoped query/forced fields, and delegates execution to the related
    // module's own native static service -- so those hooks and the native
    // eager-load config actually apply to delegation traffic too, "by
    // construction" rather than by a second, independently-maintained
    // implementation.

    private function fullOperationsConfig(): array
    {
        return [
            'name'          => 'Locations',
            'relatedModule' => ['name' => 'Locations', 'group' => 'Custom'],
            'filterKey'     => 'status_id',
            'parentIdField' => 'id',
            'operations'    => [
                'list'   => ['enabled' => true, 'backend' => [
                    'bulk_actions' => [['key' => 'archive']],
                    'export'       => true,
                    'import'       => true,
                ]],
                'create' => ['enabled' => true],
                'edit'   => ['enabled' => true],
                'view'   => ['enabled' => true],
                'delete' => ['enabled' => true],
            ],
        ];
    }

    private function generateFullDelegation(): string
    {
        PathManager::setModuleSubGroup('Custom');

        $generator = new DelegationServiceGenerator(
            'Statuses',
            'Core',
            $this->baseConfig(),
            'locations',
            $this->fullOperationsConfig()
        );
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Custom/Statuses/Services/StatusesLocationsService.php';
        return file_get_contents($path);
    }

    public function test_delegation_list_delegates_to_native_list_service_with_scoped_query(): void
    {
        $content = $this->generateFullDelegation();

        $this->assertStringContainsString('use App\Project\Modules\Core\Custom\Locations\Services\LocationsListService;', $content);
        $this->assertStringContainsString(
            "LocationsModel::query()->where('status_id', \$parent->id);",
            $content
        );
        $this->assertStringContainsString(
            "return LocationsListService::execute(\$params, \$export, \$format, \$query);",
            $content
        );
    }

    /**
     * list() is export-aware: reads export/format off its own $params
     * rather than hardcoding false/'csv' — this is what lets the
     * delegation's export{Delegation}() controller method (which bakes
     * export=true/format=X into the same $data blob passed as $params)
     * actually trigger export mode through the exact same proxy method.
     */
    public function test_delegation_list_reads_export_and_format_from_params(): void
    {
        $content = $this->generateFullDelegation();

        $this->assertStringContainsString(
            "\$export = filter_var(\$params['export'] ?? false, FILTER_VALIDATE_BOOLEAN);",
            $content
        );
        $this->assertStringContainsString(
            "\$format = \$params['format'] ?? 'csv';",
            $content
        );
    }

    public function test_delegation_create_delegates_to_native_create_service_with_forced_scope(): void
    {
        $content = $this->generateFullDelegation();

        $this->assertStringContainsString('use App\Project\Modules\Core\Custom\Locations\Services\LocationsCreateService;', $content);
        $this->assertStringContainsString("\$forced = ['status_id' => \$parent->id];", $content);
        $this->assertStringContainsString(
            'return LocationsCreateService::execute(array_merge($data, $forced), $forced);',
            $content
        );
        $this->assertStringNotContainsString('LocationsModel::create(', $content);
    }

    public function test_delegation_edit_delegates_to_native_edit_service_with_scoped_query_and_forced_scope(): void
    {
        $content = $this->generateFullDelegation();

        $this->assertStringContainsString('use App\Project\Modules\Core\Custom\Locations\Services\LocationsEditService;', $content);
        // Confirmed decision: edit gets the same anti-tampering FK-pinning as
        // create -- a record can't be re-parented away from its shown tab via
        // that same tab's edit form.
        $this->assertStringContainsString(
            'return LocationsEditService::execute(array_merge($data, $forced), [\'uuid\' => $itemUuid], $query);',
            $content
        );
        $this->assertStringNotContainsString('->update(', $content);
    }

    public function test_delegation_view_delegates_to_native_view_service_with_scoped_query(): void
    {
        $content = $this->generateFullDelegation();

        $this->assertStringContainsString('use App\Project\Modules\Core\Custom\Locations\Services\LocationsViewService;', $content);
        $this->assertStringContainsString(
            "return LocationsViewService::execute(['uuid' => \$itemUuid], \$query);",
            $content
        );
    }

    public function test_delegation_delete_delegates_to_native_delete_service_with_scoped_query(): void
    {
        $content = $this->generateFullDelegation();

        $this->assertStringContainsString('use App\Project\Modules\Core\Custom\Locations\Services\LocationsDeleteService;', $content);
        $this->assertStringContainsString(
            "return LocationsDeleteService::execute([], ['uuid' => \$itemUuid], \$query);",
            $content
        );
        $this->assertStringNotContainsString('->delete();', $content);
    }

    public function test_delegation_delete_check_method_is_generated_when_delete_enabled(): void
    {
        $content = $this->generateFullDelegation();

        $this->assertStringContainsString('use App\Project\Modules\Core\Custom\Locations\Services\LocationsDeleteCheckService;', $content);
        $this->assertStringContainsString('public static function deleteCheck(string $uuid, string $itemUuid): array', $content);
        $this->assertStringContainsString(
            "return LocationsDeleteCheckService::execute(['uuid' => \$itemUuid], \$query);",
            $content
        );
    }

    public function test_delegation_delete_check_method_is_omitted_when_delete_disabled(): void
    {
        PathManager::setModuleSubGroup('Custom');

        $config = $this->fullOperationsConfig();
        $config['operations']['delete']['enabled'] = false;

        $generator = new DelegationServiceGenerator('Statuses', 'Core', $this->baseConfig(), 'locations', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Custom/Statuses/Services/StatusesLocationsService.php';
        $content = file_get_contents($path);

        $this->assertStringNotContainsString('DeleteCheckService', $content);
        $this->assertStringNotContainsString('function deleteCheck', $content);
    }

    public function test_delegation_bulk_action_delegates_to_native_bulk_action_processing_with_scoped_query(): void
    {
        $content = $this->generateFullDelegation();

        $this->assertStringContainsString('public static function bulkAction(string $uuid, array $data): array', $content);
        $this->assertStringContainsString(
            "LocationsModel::query()->where('status_id', \$parent->id);",
            $content
        );
        $this->assertStringContainsString(
            'return LocationsListService::execute_bulkAction($data, $query);',
            $content
        );
    }

    public function test_delegation_bulk_action_method_is_omitted_when_no_bulk_actions_are_configured(): void
    {
        PathManager::setModuleSubGroup('Custom');

        $config = $this->fullOperationsConfig();
        $config['operations']['list']['backend']['bulk_actions'] = [];

        $generator = new DelegationServiceGenerator('Statuses', 'Core', $this->baseConfig(), 'locations', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Custom/Statuses/Services/StatusesLocationsService.php';
        $content = file_get_contents($path);

        $this->assertStringNotContainsString('function bulkAction', $content);
        $this->assertStringNotContainsString('execute_bulkAction', $content);
    }

    public function test_delegation_import_delegates_to_native_import_processing_with_forced_scope(): void
    {
        $content = $this->generateFullDelegation();

        $this->assertStringContainsString(
            'public static function import(string $uuid, array $data, ?\Illuminate\Http\UploadedFile $file): array',
            $content
        );
        $this->assertStringContainsString("\$forced = ['status_id' => \$parent->id];", $content);
        $this->assertStringContainsString(
            'return LocationsListService::execute_import($data, $file, $forced);',
            $content
        );
        $this->assertStringContainsString('public static function importTemplate(string $format = \'csv\'): mixed', $content);
        $this->assertStringContainsString(
            'return LocationsListService::getImportTemplate($format);',
            $content
        );
    }

    public function test_delegation_import_methods_are_omitted_when_import_is_not_configured(): void
    {
        PathManager::setModuleSubGroup('Custom');

        $config = $this->fullOperationsConfig();
        $config['operations']['list']['backend']['import'] = false;

        $generator = new DelegationServiceGenerator('Statuses', 'Core', $this->baseConfig(), 'locations', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Custom/Statuses/Services/StatusesLocationsService.php';
        $content = file_get_contents($path);

        $this->assertStringNotContainsString('function import(', $content);
        $this->assertStringNotContainsString('function importTemplate', $content);
        $this->assertStringNotContainsString('execute_import', $content);
        $this->assertStringNotContainsString('getImportTemplate', $content);
    }

    public function test_delegation_export_import_bulk_action_methods_omitted_when_list_operation_itself_is_disabled(): void
    {
        PathManager::setModuleSubGroup('Custom');

        $config = $this->fullOperationsConfig();
        $config['operations']['list']['enabled'] = false;

        $generator = new DelegationServiceGenerator('Statuses', 'Core', $this->baseConfig(), 'locations', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Custom/Statuses/Services/StatusesLocationsService.php';
        $content = file_get_contents($path);

        $this->assertStringNotContainsString('function bulkAction', $content);
        $this->assertStringNotContainsString('function import', $content);
    }

    public function test_delegation_service_no_longer_declares_dead_config_properties(): void
    {
        // $defaults was declared on every generated delegation service but
        // never read anywhere (an abandoned earlier design) -- removed
        // outright. $parentKey/$filterKey/$parentIdField were also
        // never read via $this->, only interpolated as literals at
        // generation time -- removed for the same reason. The thin design
        // also no longer needs ListServiceTrait/$filterableFields/
        // $sortableFields/$filterableRelationships/$eagerLoadRelationships --
        // the related module's own native service applies its own.
        $content = $this->generateFullDelegation();

        $this->assertStringNotContainsString('$defaults', $content);
        $this->assertStringNotContainsString('$parentKey', $content);
        $this->assertStringNotContainsString('$filterKey =', $content);
        $this->assertStringNotContainsString('$parentIdField', $content);
        $this->assertStringNotContainsString('ListServiceTrait', $content);
        $this->assertStringNotContainsString('$filterableFields', $content);
        $this->assertStringNotContainsString('DB::beginTransaction', $content);
        $this->assertStringNotContainsString('Auth::id()', $content);
    }

    /**
     * Found + fixed 2026-08-06, alongside the identical bug in
     * ActionServiceGenerator (see ActionGenerationTest): every one of these
     * 9 methods was `public function` (instance method) while every other
     * generated Service in this codebase (Create/Edit/Delete/View/List, and
     * every hand-written one) is `public static function`. The matching
     * controller_method_*.stub files called `$service = new {Service}();
     * $service->{method}(...)`, which cannot work at all against a
     * class that is (correctly) meant to be static-only. Confirmed live
     * while wiring a real Users -> UserLocations delegation with `list`
     * enabled: the CLI-generated Controller method would have thrown
     * immediately (an object was constructed for a class with no
     * meaningful instance state, which is at best a code smell, but the
     * inconsistency itself -- every sibling generator being static except
     * this one -- was the actual defect).
     */
    public function test_all_delegation_service_methods_are_static(): void
    {
        $content = $this->generateFullDelegation();

        foreach (['list', 'create', 'edit', 'view', 'delete', 'deleteCheck', 'bulkAction', 'importTemplate', 'import'] as $method) {
            $this->assertStringContainsString(
                "public static function {$method}(",
                $content,
                "{$method}() must be declared static, matching every other generated Service."
            );
        }
        $this->assertStringNotContainsString('public function ', $content, 'no delegation service method should be a plain instance method');
    }

    /**
     * A morph-target reverse delegation (e.g. Vendors -> Payments, where
     * payments.payable_id/payable_type polymorphically references many
     * owner types) needs a SECOND where clause -- filtering by filterKey
     * (payable_id) alone would also match a PurchaseOrder or Expense whose
     * id happens to numerically collide with this Vendor's id, since
     * payable_id is shared across every polymorphic owner type.
     */
    public function test_morph_filter_adds_a_second_where_clause_to_every_query_building_method(): void
    {
        PathManager::setModuleSubGroup('AccountsPayments');

        $generator = new DelegationServiceGenerator(
            'Vendors',
            'System',
            $this->baseConfig(),
            'payments',
            [
                'name'          => 'Payments',
                'relatedModule' => ['name' => 'Payments', 'group' => 'AccountsPayments'],
                'filterKey'     => 'payable_id',
                'morphFilter'   => ['column' => 'payable_type', 'value' => 'vendor'],
                'operations'    => [
                    'list'   => ['enabled' => true],
                    'view'   => ['enabled' => true],
                    'delete' => ['enabled' => true],
                ],
            ]
        );
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/System/AccountsPayments/Vendors/Services/VendorsPaymentsService.php';
        $content = file_get_contents($path);

        $this->assertStringContainsString(
            "PaymentsModel::query()->where('payable_id', \$parent->id)->where('payable_type', 'vendor');",
            $content
        );
    }

    /**
     * An ordinary (non-morph) delegation must stay byte-identical to before
     * `morphFilter` existed -- the extra where clause only ever appears when
     * a caller explicitly opts in.
     */
    public function test_ordinary_delegation_without_morph_filter_is_unaffected(): void
    {
        $content = $this->generateFullDelegation();

        $this->assertStringContainsString(
            "LocationsModel::query()->where('status_id', \$parent->id);",
            $content
        );
        $this->assertStringNotContainsString('payable_type', $content);
    }

    /**
     * Plan 039: a delegation's parent fetch goes through the same
     * method_exists()-guarded applyRecordScope() seam a native fetch does,
     * scoped to the PARENT module (StatusesModel here), so a foreign parent
     * 404s before any child row is ever touched.
     *
     * Exactly 4 operations enabled (view/edit/delete/create; list
     * deliberately omitted, unlike fullOperationsConfig()) still produces
     * FIVE method bodies with the seam: buildDeleteMethod()'s guard and
     * buildDeleteCheckMethod()'s guard both read the same
     * operations.delete.enabled flag, so enabling delete always also emits
     * deleteCheck's body alongside it (create, edit, view, delete,
     * deleteCheck = 5).
     */
    public function test_scoped_parent_fetch_appears_in_every_enabled_operation_including_delete_check(): void
    {
        PathManager::setModuleSubGroup('Custom');

        $generator = new DelegationServiceGenerator(
            'Statuses',
            'Core',
            $this->baseConfig(),
            'locations',
            [
                'name'          => 'Locations',
                'relatedModule' => ['name' => 'Locations', 'group' => 'Custom'],
                'filterKey'     => 'status_id',
                'parentIdField' => 'id',
                'operations'    => [
                    'create' => ['enabled' => true],
                    'edit'   => ['enabled' => true],
                    'view'   => ['enabled' => true],
                    'delete' => ['enabled' => true],
                ],
            ]
        );
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Custom/Statuses/Services/StatusesLocationsService.php';
        $content = file_get_contents($path);

        $this->assertSame(5, substr_count($content, 'applyRecordScope('));
        $this->assertSame(5, substr_count($content, 'method_exists('));
        $this->assertStringContainsString("if (method_exists(StatusesModel::class, 'applyRecordScope')) {", $content);
        $this->assertStringContainsString('$parentQuery = StatusesModel::applyRecordScope($parentQuery);', $content);

        $tmpFile = $this->tmpRoot . '/lint_check.php';
        file_put_contents($tmpFile, $content);
        exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $exitCode);
        $this->assertSame(0, $exitCode, 'Generated file must be syntactically valid PHP: ' . implode("\n", $output));
    }
}
