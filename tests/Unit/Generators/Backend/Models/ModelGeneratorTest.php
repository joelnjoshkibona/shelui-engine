<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Models;

use Blutrixx\GeneratorEngine\Generators\Backend\Models\ModelGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for ModelGenerator — actually invokes generate() and
 * inspects the real generated *Model.php file on disk, exercising the same
 * code path used to produce the real defective models this session
 * (LedgerTransactionTypes/LedgerTransactions/MemberPhones).
 *
 * Bug 1 ($timestamps — defect class 2): created_at/updated_at are
 * deliberately excluded from $config['columns'] (see
 * SchemaIntrospector::SKIP_COLUMNS), so ModelGenerator::generateTimestamps()
 * checking "does $this->fields contain 'created_at'" was checking a list
 * that NEVER contains it — regardless of whether the real migrated table had
 * timestamps. Every generated model got `public $timestamps = false;`,
 * silently leaving created_at/updated_at NULL forever.
 *
 * Fix: default to timestamps-enabled (Laravel's own default — most tables
 * have $table->timestamps()) unless the config explicitly says otherwise via
 * 'has_timestamps' (set by IntrospectionToConfig::build() from
 * SchemaIntrospector::hasTimestamps()).
 *
 * Bug 2 (SoftDeletes — defect class 3): the backend model.stub template
 * unconditionally `use HasFactory, SoftDeletes;` regardless of whether the
 * table has a deleted_at column at all — breaking every query with "unknown
 * column deleted_at" (confirmed for LedgerTransactionsModel).
 *
 * Fix: SoftDeletes import + trait are now conditional on 'has_soft_deletes'
 * (default false — soft deletes are opt-in).
 *
 * Bug 3 (bogus relation guessing — defect class 6): a column merely NAMED
 * like a foreign key (ends in "_id") with no real relatedModule used to get
 * a relation guessed from its name with no check that the guessed module
 * actually exists (e.g. "external_trans_id" -> a nonexistent "ExternalTrans"
 * module).
 *
 * Fix: a guessed (not explicitly-FK-derived) module name must resolve
 * against the module registry/project before a relation is emitted for it.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Models\ModelGenerator
 */
class ModelGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-model-test-' . uniqid();
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

    /**
     * Base config every test starts from. 'connection' MUST always be
     * present (even as '') — ModelGenerator::generateConnection() falls
     * back to the Laravel config() helper when it's absent entirely, which
     * doesn't exist in this plain-PHPUnit environment.
     */
    private function baseConfig(array $overrides = []): array
    {
        return array_merge([
            'connection' => '',
            'id_type'    => 'integer',
            'columns'    => [
                ['name' => 'title', 'type' => 'string'],
            ],
        ], $overrides);
    }

    private function generateAndRead(array $config, string $moduleName = 'TestLedger', string $moduleGroup = 'Sacco'): string
    {
        $generator = new ModelGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate(), 'ModelGenerator::generate() should report a successful write.');

        $path = $this->tmpRoot . "/BACKEND/app/Project/Modules/{$moduleGroup}/{$moduleName}/{$moduleName}Model.php";
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    // ─── Bug 1: $timestamps (defect class 2) ───────────────────────────────

    public function test_timestamps_are_enabled_by_default_when_config_omits_has_timestamps(): void
    {
        // Mirrors the real bug scenario: a "columns" list with no
        // created_at/updated_at entries (because those are stripped by
        // SchemaIntrospector before this config was even built) and no
        // explicit 'has_timestamps' key — must NOT disable timestamps.
        $content = $this->generateAndRead($this->baseConfig());

        $this->assertStringNotContainsString(
            'public $timestamps = false;',
            $content,
            'A model whose config carries no timestamps signal must default to Eloquent\'s own timestamps-enabled behaviour, not be silently disabled.'
        );
    }

    public function test_timestamps_disabled_only_when_explicitly_configured(): void
    {
        $content = $this->generateAndRead($this->baseConfig(['has_timestamps' => false]));

        $this->assertStringContainsString('public $timestamps = false;', $content);
    }

    public function test_timestamps_enabled_explicitly_also_omits_the_false_override(): void
    {
        $content = $this->generateAndRead($this->baseConfig(['has_timestamps' => true]));

        $this->assertStringNotContainsString('public $timestamps = false;', $content);
    }

    /**
     * shelui-engine fork: superseded the old "rescan $fields for a literal
     * created_date/modified_date pair, regardless of has_timestamps" branch
     * this test used to cover — that rescan fired even when has_timestamps
     * was explicitly false, disagreeing with MigrationGenerator (which only
     * ever read the flag, and would try to ALSO emit a conflicting
     * `$table->timestamps()` call whenever the flag defaulted true for the
     * same config). The single resolution rule is now
     * ModuleConfigContract::timestampColumns(): has_timestamps: true, plus
     * a `timestamp_columns` override, and the legacy-named columns are
     * NOT declared in columns[] — same convention as the Laravel-default
     * pair being excluded from columns[] for every other module.
     */
    public function test_custom_timestamp_column_names_use_custom_constants(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'has_timestamps' => true,
            'timestamp_columns' => ['created_at' => 'created_date', 'updated_at' => 'modified_date'],
        ]));

        $this->assertStringContainsString("const CREATED_AT = 'created_date';", $content);
        $this->assertStringContainsString("const UPDATED_AT = 'modified_date';", $content);
        $this->assertStringNotContainsString('public $timestamps = false;', $content);
    }

    // ─── Bug 2: SoftDeletes (defect class 3) ────────────────────────────────

    public function test_soft_deletes_is_omitted_by_default(): void
    {
        // Mirrors the real bug scenario for LedgerTransactionsModel: the
        // migration has no deleted_at column, and config carries no
        // 'has_soft_deletes' signal.
        $content = $this->generateAndRead($this->baseConfig());

        $this->assertStringNotContainsString('SoftDeletes', $content, 'SoftDeletes must not be referenced anywhere when the table has no deleted_at column.');
        $this->assertStringContainsString('use HasFactory;', $content);
    }

    public function test_soft_deletes_is_added_when_table_actually_has_deleted_at(): void
    {
        $content = $this->generateAndRead($this->baseConfig(['has_soft_deletes' => true]));

        $this->assertStringContainsString('use Illuminate\Database\Eloquent\SoftDeletes;', $content);
        $this->assertStringContainsString('use HasFactory, SoftDeletes;', $content);
        // Exactly one import — no leftover/duplicate.
        $this->assertSame(1, substr_count($content, 'use Illuminate\Database\Eloquent\SoftDeletes;'));
    }

    // ─── Bug 3: bogus relation guessing (defect class 6) ────────────────────

    public function test_guessed_relation_is_skipped_when_the_guessed_module_does_not_exist(): void
    {
        // "external_trans_id" is normalized_type 'foreignId' here to mirror
        // the upstream SchemaIntrospector mis-tagging this test's sibling
        // (SchemaIntrospectorNormalizeTypeTest) proves is now fixed — this
        // test asserts ModelGenerator's OWN independent safety net for the
        // case where a 'foreignId' column still slips through with no
        // resolvable relatedModule (e.g. hand-authored config, or any other
        // future path that produces the same shape).
        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'external_trans_id', 'type' => 'foreignId', 'relatedModule' => ''],
            ],
        ]));

        $this->assertStringNotContainsString('externalTrans', $content, 'A relation must never be emitted for a guessed module name that does not resolve to any known module.');
        $this->assertStringNotContainsString('ExternalTrans', $content);
    }

    public function test_guessed_relation_is_emitted_when_the_guessed_module_does_resolve(): void
    {
        PathManager::setModuleRegistry([
            ['name' => 'ExternalTrans', 'module_type' => 'Sacco'],
        ]);

        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'external_trans_id', 'type' => 'foreignId', 'relatedModule' => ''],
            ],
        ]));

        $this->assertStringContainsString('public function externalTrans()', $content, 'A guessed module name must still resolve normally once it is a real, known module.');
        $this->assertStringContainsString('\\App\\Project\\Modules\\Sacco\\ExternalTrans\\ExternalTransModel::class', $content);
    }

    public function test_explicit_related_module_relation_is_never_gated_by_registry_resolution(): void
    {
        // A relatedModule that came from REAL FK metadata (not guessed from
        // the column name) must always be honoured, even with an empty
        // registry — the registry-resolution gate only applies to guessed
        // names (defect class 6), not to explicit, FK-derived ones.
        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'category_id', 'type' => 'foreignId', 'relatedModule' => 'Categories'],
            ],
        ]));

        $this->assertStringContainsString('public function category()', $content);
        $this->assertStringContainsString('CategoriesModel::class', $content);
    }

    // ─── Nested-sub-group namespace resolution (registry-timing bug) ──────
    //
    // make:modules-from-db nests every domain group under System (e.g.
    // blueprint group "Custom" -> App\Project\Modules\System\Custom\{Module})
    // and only appends a module to the array registry *after* it finishes
    // generating. That left a module unable to find *itself* while still
    // being generated -- a self-referential belongsTo() (e.g. parent_id on
    // ItemCategories) fell through to PathManager::resolveBackendModuleNamespace()'s
    // silent Core default instead of its own real System\Custom\ItemCategories
    // namespace. Fixed via PathManager::registerCurrentModule(), called from
    // BaseGenerator's constructor for every generator.

    public function test_self_referential_relation_in_nested_subgroup_resolves_full_namespace(): void
    {
        // Mirrors the live ItemCategoriesModel bug exactly: System group,
        // "Custom" sub-group, parent_id belongsTo the module itself.
        PathManager::setModuleSubGroup('Custom');

        $generator = new ModelGenerator('ItemCategories', 'System', $this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'parent_id', 'type' => 'foreignId', 'relatedModule' => 'ItemCategories'],
            ],
        ]));
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/System/Custom/ItemCategories/ItemCategoriesModel.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);

        $this->assertStringContainsString('public function parent()', $content);
        $this->assertStringContainsString(
            '\App\Project\Modules\System\Custom\ItemCategories\ItemCategoriesModel::class',
            $content,
            'A self-referential relation on a nested module must resolve its own full Group\SubGroup\Module namespace, not fall back to Core.'
        );
        $this->assertStringNotContainsString('Modules\Core\ItemCategories', $content);
    }

    public function test_auto_derived_cross_module_relation_in_nested_subgroup_resolves_full_namespace(): void
    {
        // The related module ("ItemCategories") is a *different*, already-
        // registered module -- exercises the ordinary registry lookup path
        // (PathManager::resolveBackendModuleNamespace()), not the self-
        // reference fix, but for a nested sub-group target.
        PathManager::setModuleRegistry([
            ['name' => 'ItemCategories', 'module_type' => 'System', 'group_name' => 'Custom'],
        ]);
        PathManager::setModuleSubGroup('Custom');

        $generator = new ModelGenerator('Items', 'System', $this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'item_category_id', 'type' => 'foreignId', 'relatedModule' => 'ItemCategories'],
            ],
        ]));
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/System/Custom/Items/ItemsModel.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);

        $this->assertStringContainsString(
            '\App\Project\Modules\System\Custom\ItemCategories\ItemCategoriesModel::class',
            $content
        );
        $this->assertStringNotContainsString('Modules\Core\ItemCategories', $content);
    }

    public function test_flat_module_relation_is_unaffected_by_nested_subgroup_fix(): void
    {
        // A plain Core module with no sub-group must keep resolving exactly
        // as before -- no stray "\Custom" (or any other) segment leaking in.
        PathManager::setModuleRegistry([
            ['name' => 'Statuses', 'module_type' => 'Core'],
        ]);

        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'status_id', 'type' => 'foreignId', 'relatedModule' => 'Statuses'],
            ],
        ]), 'Widgets', 'Core');

        $this->assertStringContainsString('\App\Project\Modules\Core\Statuses\StatusesModel::class', $content);
    }

    // ─── Finding 3: hand-authored relations.hasMany/belongsToMany must not
    //     silently default an unresolvable module to 'Core' ─────────────────

    public function test_manual_has_many_relation_throws_when_declared_module_does_not_resolve(): void
    {
        // A typo'd module name in a hand-authored relations.hasMany[] entry
        // used to silently fall through determineModuleGroup()'s registry
        // lookups and default to 'Core', emitting a belongsTo/hasMany
        // pointing at a class that was never generated. It must now fail
        // loudly at generation time instead.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/OrderItemsTypo/');

        $this->generateAndRead($this->baseConfig([
            'relations' => [
                'hasMany' => [
                    ['module' => 'OrderItemsTypo', 'method' => 'items', 'foreignKey' => 'order_id'],
                ],
            ],
        ]));
    }

    public function test_manual_belongs_to_many_relation_throws_when_declared_module_does_not_resolve(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/TagzTypo/');

        $this->generateAndRead($this->baseConfig([
            'relations' => [
                'belongsToMany' => [
                    ['module' => 'TagzTypo', 'method' => 'tags', 'pivotTable' => 'order_tag'],
                ],
            ],
        ]));
    }

    public function test_manual_has_many_relation_resolves_normally_once_module_is_registered(): void
    {
        PathManager::setModuleRegistry([
            ['name' => 'OrderItems', 'module_type' => 'Sacco'],
        ]);

        $content = $this->generateAndRead($this->baseConfig([
            'relations' => [
                'hasMany' => [
                    ['module' => 'OrderItems', 'method' => 'items', 'foreignKey' => 'order_id'],
                ],
            ],
        ]));

        $this->assertStringContainsString('public function items()', $content);
        $this->assertStringContainsString('OrderItemsModel::class', $content);
    }

    // ─── relations.morphMany (v3.4.0) — the reverse side of a morph target,
    //     e.g. Vendors::payments() for Payments.payable ─────────────────────

    public function test_manual_morph_many_relation_throws_when_declared_module_does_not_resolve(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/PaymentsTypo/');

        $this->generateAndRead($this->baseConfig([
            'relations' => [
                'morphMany' => [
                    ['module' => 'PaymentsTypo', 'method' => 'payments', 'morphName' => 'payable'],
                ],
            ],
        ]));
    }

    public function test_manual_morph_many_relation_resolves_normally_once_module_is_registered(): void
    {
        PathManager::setModuleRegistry([
            ['name' => 'Payments', 'module_type' => 'AccountsPayments'],
        ]);

        $content = $this->generateAndRead($this->baseConfig([
            'relations' => [
                'morphMany' => [
                    ['module' => 'Payments', 'method' => 'payments', 'morphName' => 'payable'],
                ],
            ],
        ]));

        $this->assertStringContainsString('public function payments()', $content);
        $this->assertStringContainsString("return \$this->morphMany(", $content);
        $this->assertStringContainsString("PaymentsModel::class, 'payable'", $content);
    }

    /**
     * The whole point of moving this into `relations.morphMany` config
     * (replacing v3.3.0's post-generation ModelRelationInjector splice):
     * the relation must survive a second `generate()` call using the SAME
     * config, exactly like every other relation type already does. The old
     * splice-based approach had no such guarantee — a plain regenerate
     * silently wiped it (confirmed live in a real project, 2026-08-19).
     */
    public function test_manual_morph_many_relation_survives_a_second_generate_call(): void
    {
        PathManager::setModuleRegistry([
            ['name' => 'Payments', 'module_type' => 'AccountsPayments'],
        ]);

        $config = $this->baseConfig([
            'relations' => [
                'morphMany' => [
                    ['module' => 'Payments', 'method' => 'payments', 'morphName' => 'payable'],
                ],
            ],
        ]);

        $this->generateAndRead($config);
        $content = $this->generateAndRead($config);

        $this->assertEquals(1, substr_count($content, 'public function payments('));
    }

    // ─── Bug 4: creator()/updater() relations always emitted (found while
    //     porting StockTransfers — second confirmed occurrence of the same
    //     "ignores has_*/config detection" defect class as bugs 1/2 above,
    //     this time for the paired created_by_id/updated_by_id columns) ────

    public function test_creator_and_updater_relations_are_emitted_by_default(): void
    {
        // Mirrors every normal module: config carries no 'has_creator_updater'
        // signal at all — must default to emitting both relations (the
        // project's own convention: audit columns are the norm).
        $content = $this->generateAndRead($this->baseConfig());

        $this->assertStringContainsString('public function creator(): \Illuminate\Database\Eloquent\Relations\BelongsTo', $content);
        $this->assertStringContainsString('public function updater(): \Illuminate\Database\Eloquent\Relations\BelongsTo', $content);
    }

    public function test_creator_and_updater_relations_are_omitted_when_table_has_no_audit_columns(): void
    {
        // Mirrors the real bug: a table like price_lists/stock_transfers with
        // NO created_by_id/updated_by_id columns at all used to still get
        // both relations emitted unconditionally, referencing columns that
        // don't exist — every eager-load / query on them threw a SQL error.
        $content = $this->generateAndRead($this->baseConfig(['has_creator_updater' => false]));

        $this->assertStringNotContainsString('function creator()', $content);
        $this->assertStringNotContainsString('function updater()', $content);
        $this->assertStringNotContainsString('created_by_id', $content);
        $this->assertStringNotContainsString('updated_by_id', $content);
    }

    public function test_creator_and_updater_relations_emitted_when_explicitly_true(): void
    {
        $content = $this->generateAndRead($this->baseConfig(['has_creator_updater' => true]));

        $this->assertStringContainsString('function creator()', $content);
        $this->assertStringContainsString('function updater()', $content);
    }

    // ─── Bug 5: bare 'date' cast loses its calendar day over JSON ─────────
    //
    // Confirmed live on a real generated ItemPrices module: with
    // config('app.timezone') set to a non-UTC zone (Africa/Dar_es_Salaam,
    // UTC+3 — this project's actual app timezone), a `date`-cast
    // `effective_date` column stored as 2026-07-24 came back from the real
    // HTTP API as "2026-07-23T21:00:00.000000Z" — the *previous* calendar
    // day — because Eloquent's bare 'date' cast serializes via Carbon's
    // toJSON(), which converts to UTC before formatting. The generated
    // PHPUnit test's own `assertJsonPath('data.effective_date',
    // now()->toDateString())` failed against this, and any real frontend
    // consuming the field would render the wrong date. getCastType('date')
    // now emits 'date:Y-m-d' — Eloquent's parameterized date cast, which
    // formats with ->format($format) directly and is NOT timezone-shifted.

    public function test_date_type_column_uses_a_parameterized_cast_not_bare_date(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'effective_date', 'type' => 'date'],
            ],
        ]));

        $this->assertStringContainsString("'effective_date' => 'date:Y-m-d'", $content);
        $this->assertStringNotContainsString("'effective_date' => 'date'", $content);
    }

    public function test_datetime_type_column_still_uses_the_plain_datetime_cast(): void
    {
        // Only the date-only cast needed the format pin. datetime/timestamp
        // columns carry a real time-of-day component, so the normal
        // UTC-converting serialization is correct and expected there —
        // confirm this fix didn't touch that branch.
        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'happened_at', 'type' => 'datetime'],
            ],
        ]));

        $this->assertStringContainsString("'happened_at' => 'datetime'", $content);
    }

    // ─── enum column casts ──────────────────────────────────────────────────
    //
    // generateCasts()/getCastType() previously had no 'enum' branch at all, so
    // an enum column fell through to the default `null` (no-cast) case
    // silently — indistinguishable from "nobody thought about this type yet".
    // getCastType('enum') now explicitly returns 'string': there is no PHP
    // backed-enum class anywhere in this pipeline for a cast to reference, so
    // 'string' documents (at the Model) that the attribute is a closed set of
    // string values, mirroring the Rule::in() validation and Select2Field the
    // rest of the pipeline already emit for the same column.

    public function test_enum_type_column_gets_an_explicit_string_cast(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'price_tier', 'type' => 'enum', 'enum_values' => ['standard', 'premium', 'wholesale']],
            ],
        ]));

        $this->assertStringContainsString("'price_tier' => 'string'", $content);
    }

    // ─── decimal column casts ────────────────────────────────────────────────
    //
    // Bug (found 2026-08-25 building Pangisha's payment engine): getCastType()
    // folded 'decimal' into the same branch as 'float'/'double', so a
    // decimal(15,2) money column was cast to plain 'float' — reintroducing
    // exactly the rounding drift decimal(P,S) storage exists to prevent (a
    // stored 40.00 round-tripped as PHP 40.0, comparing unequal to a
    // decimal-precision service's computed '40.00'). getCastType('decimal', $scale)
    // now emits 'decimal:{scale}' — Eloquent's fixed-precision numeric-STRING
    // cast, matching the column's own semantics exactly. 'float'/'double' are
    // deliberately left casting to plain 'float' below: unlike 'decimal',
    // neither carries a real fixed scale to cast to.

    public function test_decimal_type_column_uses_a_scale_aware_cast(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'amount', 'type' => 'decimal', 'precision' => 15, 'scale' => 2],
            ],
        ]));

        $this->assertStringContainsString("'amount' => 'decimal:2'", $content);
        $this->assertStringNotContainsString("'amount' => 'float'", $content);
    }

    public function test_decimal_type_column_without_an_explicit_scale_defaults_to_two(): void
    {
        // Mirrors MigrationGenerator's own `$scale ?? 2` default for a decimal
        // column whose scale wasn't explicitly configured.
        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'amount', 'type' => 'decimal'],
            ],
        ]));

        $this->assertStringContainsString("'amount' => 'decimal:2'", $content);
    }

    public function test_float_and_double_type_columns_still_use_the_plain_float_cast(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'weight', 'type' => 'float'],
                ['name' => 'distance', 'type' => 'double'],
            ],
        ]));

        $this->assertStringContainsString("'weight' => 'float'", $content);
        $this->assertStringContainsString("'distance' => 'float'", $content);
    }

    // ─── file_columns -> belongsTo(Media) relationship ──────────────────────
    //
    // A column marked via IntrospectionToConfig's file_columns meta
    // (threaded to config['file_columns'] at the top level) is a plain
    // unsignedBigInteger column with no real DB FK -- mirrors the
    // hand-written MobileReleasesModel::apkMedia()/otaMedia() convention
    // exactly: belongsTo(MediaModel::class, column), method name = column
    // with '_id' stripped, camelCased.

    public function test_file_column_generates_belongs_to_media_relationship(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            'file_columns' => ['image_media_id'],
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'image_media_id', 'type' => 'integer'],
            ],
        ]));

        $this->assertStringContainsString('public function imageMedia(): \Illuminate\Database\Eloquent\Relations\BelongsTo', $content);
        $this->assertStringContainsString("\$this->belongsTo(\n            \\App\\Project\\Modules\\Core\\Media\\MediaModel::class, 'image_media_id'", $content);
    }

    public function test_apk_media_id_style_column_derives_apk_media_method_name(): void
    {
        // Mirrors MobileReleasesModel::apkMedia() exactly: 'apk_media_id' -> 'apkMedia'.
        $content = $this->generateAndRead($this->baseConfig([
            'file_columns' => ['apk_media_id'],
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'apk_media_id', 'type' => 'integer'],
            ],
        ]));

        $this->assertStringContainsString('public function apkMedia(): \Illuminate\Database\Eloquent\Relations\BelongsTo', $content);
    }

    public function test_file_column_relationship_is_not_gated_by_module_registry(): void
    {
        // Unlike guessed FK targets (Bug 3 above), Media's namespace is
        // hardcoded, not resolved via the registry -- an empty registry must
        // NOT suppress this relation.
        PathManager::setModuleRegistry([]);

        $content = $this->generateAndRead($this->baseConfig([
            'file_columns' => ['image_media_id'],
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'image_media_id', 'type' => 'integer'],
            ],
        ]));

        $this->assertStringContainsString('public function imageMedia()', $content);
    }

    public function test_file_columns_entry_not_among_this_modules_own_columns_is_skipped(): void
    {
        // A file_columns entry naming a column this module doesn't actually
        // have (typo, or meant for a different module) must not emit a
        // relation referencing a nonexistent column.
        $content = $this->generateAndRead($this->baseConfig([
            'file_columns' => ['some_other_module_column_id'],
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
            ],
        ]));

        $this->assertStringNotContainsString('someOtherModuleColumn', $content);
        $this->assertStringNotContainsString('MediaModel', $content);
    }

    public function test_no_file_columns_configured_emits_no_media_relationship_regression_guard(): void
    {
        // The common case: no 'file_columns' key at all. Must generate
        // exactly as before -- zero diff, no Media reference anywhere.
        $content = $this->generateAndRead($this->baseConfig());

        $this->assertStringNotContainsString('MediaModel', $content);
        $this->assertStringNotContainsString('Media', $content);
    }

    // ─── Bug 6: newFactory() override (hard blocker) ────────────────────────
    //
    // A new FactoryGenerator now emits `{Module}Factory.php` CO-LOCATED in
    // the module directory (App\Project\Modules\{Group}[\{SubGroup}]\{Module})
    // rather than Laravel's default `Database\Factories\` location, matching
    // every hand-built SYSTEM_SHELL factory (StatusesFactory, LocationsFactory,
    // MobileReleasesFactory, ...). Without a `newFactory()` override, Laravel's
    // HasFactory trait guesses the factory class from Database\Factories\...,
    // which will never match — so `{Module}Model::factory()` throws, turning
    // PhpUnitTestGenerator's new cross-module FK fixtures
    // (`{Module}Model::factory()->create()->id`) into a fatal error instead of
    // a plain validation failure.
    //
    // Convention copied verbatim from StatusesModel/LocationsModel/
    // MobileReleasesModel::newFactory() — unqualified `{Module}Factory::new()`,
    // relying on the model and its co-located factory sharing the exact same
    // namespace (confirmed: FactoryGenerator writes into $this->getNamespace(),
    // the identical BaseGenerator helper ModelGenerator's own [[namespace]]
    // placeholder already resolves through). BaseModel provides no factory
    // resolution of its own (verified against app/Project/_Src/BaseModel.php),
    // so this override is required on every generated model, unconditionally.

    public function test_model_emits_newFactory_override_for_a_flat_module(): void
    {
        $content = $this->generateAndRead($this->baseConfig(), 'Items', 'Core');

        $this->assertStringContainsString(
            "protected static function newFactory()\n    {\n        return ItemsFactory::new();\n    }",
            $content
        );
    }

    public function test_model_emits_newFactory_override_for_a_nested_sub_grouped_module(): void
    {
        // Mirrors LocationsModel exactly: Core group, "Locations" sub-group,
        // "Locations" module -- App\Project\Modules\Core\Locations\Locations.
        PathManager::setModuleSubGroup('Locations');

        $generator = new ModelGenerator('Locations', 'Core', $this->baseConfig());
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Core/Locations/Locations/LocationsModel.php';
        $this->assertFileExists($path);
        $content = file_get_contents($path);

        $this->assertStringContainsString('namespace App\Project\Modules\Core\Locations\Locations;', $content);
        $this->assertStringContainsString(
            "protected static function newFactory()\n    {\n        return LocationsFactory::new();\n    }",
            $content
        );
        // Same-namespace reference -- must NOT be fully-qualified with a
        // leading backslash (that would only be needed if the factory lived
        // in a different namespace than the model).
        $this->assertStringNotContainsString('\\LocationsFactory::new()', $content);
    }

    // ─── model_hand_maintained (v2.31.0) ────────────────────────────────────

    public function test_hand_maintained_model_is_never_overwritten_even_with_force(): void
    {
        $config = $this->baseConfig(['model_hand_maintained' => true]);
        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Sacco/TestLedger/TestLedgerModel.php';

        // First generate() call: no file exists yet, so it must still be
        // written -- "hand-maintained" means "generator never overwrites an
        // existing file", not "generator never writes this file at all".
        $first = new ModelGenerator('TestLedger', 'Sacco', $config);
        $first->setForce(true);
        $this->assertTrue($first->generate());
        $this->assertFileExists($path);

        // Simulate a developer's hand-edit landing on disk after the first
        // generate.
        file_put_contents($path, "<?php\n// hand-edited, must survive --force\n");

        $second = new ModelGenerator('TestLedger', 'Sacco', $config);
        $second->setForce(true);
        $second->generate();

        $this->assertSame(
            "<?php\n// hand-edited, must survive --force\n",
            file_get_contents($path),
            'A model_hand_maintained Model.php must never be overwritten by a --force regenerate.'
        );
    }

    public function test_model_is_overwritten_by_force_when_not_hand_maintained(): void
    {
        // Control case, same shape as the test above minus the flag --
        // proves the write-once behaviour is opt-in, not a general
        // regression in --force's normal overwrite semantics.
        $config = $this->baseConfig();
        $path = $this->tmpRoot . '/BACKEND/app/Project/Modules/Sacco/TestLedger/TestLedgerModel.php';

        $first = new ModelGenerator('TestLedger', 'Sacco', $config);
        $first->setForce(true);
        $this->assertTrue($first->generate());

        file_put_contents($path, "<?php\n// hand-edited, should NOT survive --force here\n");

        $second = new ModelGenerator('TestLedger', 'Sacco', $config);
        $second->setForce(true);
        $second->generate();

        $this->assertStringNotContainsString(
            'should NOT survive --force here',
            file_get_contents($path),
            'Without model_hand_maintained, --force must overwrite Model.php as normal.'
        );
    }
}
