<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Schema;

use Blutrixx\GeneratorEngine\Generators\Backend\Migrations\MigrationGenerator;
use Blutrixx\GeneratorEngine\Generators\Backend\Models\ModelGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for ROOT CAUSE B: the same "does this module have soft
 * deletes / timestamps / uuid / creator-updater" fact used to be re-derived
 * independently in ModelGenerator and MigrationGenerator, with different
 * defaulting/fallback rules -- so the two could disagree about the exact
 * same $config:
 *   - ModelGenerator::hasSoftDeletes() read the config flag AND, only when
 *     the flag key was absent, separately rescanned $fields for a literal
 *     'deleted_at' entry.
 *   - MigrationGenerator::hasSoftDeletes() read the config flag with a bare
 *     `?? false` and never rescanned fields at all.
 *
 * Fix: both generators now delegate every one of these decisions to
 * ModuleConfigContract's static accessors -- one resolution rule, one place.
 * This test proves the two generators agree, end to end (by inspecting
 * actual generated file contents), across every combination of
 * flag-present / flag-absent / deleted_at-field-present -- and separately
 * exercises ModuleConfigContract's accessors directly.
 *
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Models\ModelGenerator::hasSoftDeletes()
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Migrations\MigrationGenerator::hasSoftDeletes()
 */
class ModuleConfigContractTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-contract-test-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::setModuleRegistry([]);
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

    // ─── ModuleConfigContract::hasSoftDeletes() direct coverage ────────────

    public function test_contract_has_soft_deletes_trusts_explicit_true(): void
    {
        $this->assertTrue(ModuleConfigContract::hasSoftDeletes(['has_soft_deletes' => true, 'columns' => []]));
    }

    public function test_contract_has_soft_deletes_trusts_explicit_false_even_with_deleted_at_column(): void
    {
        // Explicit flag wins over the fallback rescan -- an explicit false
        // is never overridden by a stray deleted_at-named column.
        $config = [
            'has_soft_deletes' => false,
            'columns' => [['name' => 'deleted_at']],
        ];
        $this->assertFalse(ModuleConfigContract::hasSoftDeletes($config));
    }

    public function test_contract_has_soft_deletes_falls_back_to_column_rescan_when_flag_absent(): void
    {
        $config = ['columns' => [['name' => 'title'], ['name' => 'deleted_at']]];
        $this->assertTrue(ModuleConfigContract::hasSoftDeletes($config));
    }

    public function test_contract_has_soft_deletes_defaults_false_when_flag_and_column_both_absent(): void
    {
        $config = ['columns' => [['name' => 'title']]];
        $this->assertFalse(ModuleConfigContract::hasSoftDeletes($config));
    }

    // ─── (d) ModelGenerator and MigrationGenerator agree on hasSoftDeletes ──
    // across every combination of flag-present / flag-absent /
    // deleted_at-field-present.

    private function columnsWithoutDeletedAt(): array
    {
        return [['name' => 'title', 'type' => 'string']];
    }

    private function columnsWithDeletedAt(): array
    {
        return [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'deleted_at', 'type' => 'timestamp'],
        ];
    }

    private function modelHasSoftDeletes(array $config, string $moduleName): bool
    {
        $modelConfig = array_merge(['connection' => '', 'id_type' => 'integer'], $config);
        $generator = new ModelGenerator($moduleName, 'Sacco', $modelConfig);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . "/BACKEND/app/Project/Modules/Sacco/{$moduleName}/{$moduleName}Model.php";
        $this->assertFileExists($path);
        $content = file_get_contents($path);

        return str_contains($content, ', SoftDeletes');
    }

    private function migrationHasSoftDeletes(array $config, string $moduleName): bool
    {
        $migrationConfig = array_merge([
            'table_name' => strtolower($moduleName),
            'id_type'    => 'autoincrement',
        ], $config);
        $generator = new MigrationGenerator($moduleName, 'Sacco', $migrationConfig);
        $this->assertTrue($generator->generate());

        $matches = glob($this->tmpRoot . "/BACKEND/app/Project/Modules/Sacco/{$moduleName}/Migrations/*_create_{$migrationConfig['table_name']}_table.php");
        $this->assertNotEmpty($matches);
        $content = file_get_contents($matches[0]);

        return str_contains($content, 'softDeletes()');
    }

    /** @return array<string, array{0: array, 1: array, 2: bool}> */
    public static function softDeletesCombinationsProvider(): array
    {
        return [
            'flag absent, no deleted_at column'  => [[], 'columnsWithoutDeletedAt', false],
            'flag absent, deleted_at column present' => [[], 'columnsWithDeletedAt', true],
            'flag explicit false, deleted_at column present' => [['has_soft_deletes' => false], 'columnsWithDeletedAt', false],
            'flag explicit true, no deleted_at column' => [['has_soft_deletes' => true], 'columnsWithoutDeletedAt', true],
            'flag explicit true, deleted_at column present' => [['has_soft_deletes' => true], 'columnsWithDeletedAt', true],
            'flag explicit false, no deleted_at column' => [['has_soft_deletes' => false], 'columnsWithoutDeletedAt', false],
        ];
    }

    #[DataProvider('softDeletesCombinationsProvider')]
    public function test_model_and_migration_generators_agree_on_has_soft_deletes(
        array $flagOverride,
        string $columnsMethod,
        bool $expected
    ): void {
        static $counter = 0;
        $moduleName = 'ZzzContractAgree' . (++$counter);

        $columns = $this->{$columnsMethod}();

        $modelResult = $this->modelHasSoftDeletes(array_merge(['columns' => $columns], $flagOverride), $moduleName);
        $this->assertSame($expected, $modelResult, 'ModelGenerator disagreed with the expected hasSoftDeletes() result.');

        $migrationResult = $this->migrationHasSoftDeletes(array_merge(['columns' => $columns], $flagOverride), $moduleName . 'Mig');
        $this->assertSame($expected, $migrationResult, 'MigrationGenerator disagreed with the expected hasSoftDeletes() result.');

        $this->assertSame(
            $modelResult,
            $migrationResult,
            'ModelGenerator and MigrationGenerator must always agree on hasSoftDeletes() for the same underlying flag/column combination.'
        );
    }

    // ─── ModuleConfigContract::isModelHandMaintained() direct coverage ─────

    public function test_contract_is_model_hand_maintained_defaults_false_when_key_absent(): void
    {
        $this->assertFalse(ModuleConfigContract::isModelHandMaintained(['columns' => []]));
    }

    public function test_contract_is_model_hand_maintained_trusts_explicit_true(): void
    {
        $this->assertTrue(ModuleConfigContract::isModelHandMaintained(['model_hand_maintained' => true]));
    }

    public function test_contract_is_model_hand_maintained_trusts_explicit_false(): void
    {
        $this->assertFalse(ModuleConfigContract::isModelHandMaintained(['model_hand_maintained' => false]));
    }

    // ─── ModuleConfigContract::isMobileAppEnabled() direct coverage ────────

    public function test_contract_is_mobile_app_enabled_defaults_false_when_key_absent(): void
    {
        $this->assertFalse(ModuleConfigContract::isMobileAppEnabled(['columns' => []]));
    }

    public function test_contract_is_mobile_app_enabled_defaults_false_when_mobile_app_block_present_but_no_enabled_key(): void
    {
        $this->assertFalse(ModuleConfigContract::isMobileAppEnabled(['features' => ['mobile_app' => ['mode' => 'online']]]));
    }

    public function test_contract_is_mobile_app_enabled_trusts_explicit_true(): void
    {
        $this->assertTrue(ModuleConfigContract::isMobileAppEnabled(['features' => ['mobile_app' => ['enabled' => true]]]));
    }

    public function test_contract_is_mobile_app_enabled_trusts_explicit_false(): void
    {
        $this->assertFalse(ModuleConfigContract::isMobileAppEnabled(['features' => ['mobile_app' => ['enabled' => false]]]));
    }

    // ─── ModuleConfigContract::isFrontendEnabled() direct coverage ─────────

    public function test_contract_is_frontend_enabled_defaults_true_when_key_absent(): void
    {
        $this->assertTrue(ModuleConfigContract::isFrontendEnabled(['columns' => []]));
    }

    public function test_contract_is_frontend_enabled_defaults_true_when_frontend_block_present_but_no_enabled_key(): void
    {
        $this->assertTrue(ModuleConfigContract::isFrontendEnabled(['features' => ['frontend' => ['list' => []]]]));
    }

    public function test_contract_is_frontend_enabled_trusts_explicit_true(): void
    {
        $this->assertTrue(ModuleConfigContract::isFrontendEnabled(['features' => ['frontend' => ['enabled' => true]]]));
    }

    public function test_contract_is_frontend_enabled_trusts_explicit_false(): void
    {
        $this->assertFalse(ModuleConfigContract::isFrontendEnabled(['features' => ['frontend' => ['enabled' => false]]]));
    }

    // ─── ModuleConfigContract::timestampColumns() direct coverage ──────────

    public function test_contract_timestamp_columns_defaults_to_laravel_pair(): void
    {
        $this->assertSame(
            ['created' => 'created_at', 'updated' => 'updated_at'],
            ModuleConfigContract::timestampColumns(['columns' => []])
        );
    }

    public function test_contract_timestamp_columns_trusts_explicit_override(): void
    {
        $config = ['timestamp_columns' => ['created_at' => 'created_date', 'updated_at' => 'modified_date']];
        $this->assertSame(
            ['created' => 'created_date', 'updated' => 'modified_date'],
            ModuleConfigContract::timestampColumns($config)
        );
    }

    public function test_contract_timestamp_columns_rejects_non_array_override(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleConfigContract::timestampColumns(['timestamp_columns' => 'created_date']);
    }

    public function test_contract_timestamp_columns_rejects_empty_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleConfigContract::timestampColumns(['timestamp_columns' => ['created_at' => '']]);
    }

    // ─── ModuleConfigContract::softDeleteType() / softDeleteColumn() ───────

    public function test_contract_soft_delete_type_defaults_to_timestamp(): void
    {
        $this->assertSame('timestamp', ModuleConfigContract::softDeleteType([]));
    }

    public function test_contract_soft_delete_type_trusts_explicit_flag(): void
    {
        $this->assertSame('flag', ModuleConfigContract::softDeleteType(['soft_delete_type' => 'flag']));
    }

    public function test_contract_soft_delete_type_rejects_unknown_value(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleConfigContract::softDeleteType(['soft_delete_type' => 'is_deleted']);
    }

    public function test_contract_soft_delete_column_defaults_to_deleted_at_for_timestamp_type(): void
    {
        $this->assertSame('deleted_at', ModuleConfigContract::softDeleteColumn([]));
    }

    public function test_contract_soft_delete_column_defaults_to_is_deleted_for_flag_type(): void
    {
        $this->assertSame('is_deleted', ModuleConfigContract::softDeleteColumn(['soft_delete_type' => 'flag']));
    }

    public function test_contract_soft_delete_column_trusts_explicit_override(): void
    {
        $config = ['soft_delete_type' => 'flag', 'soft_delete_column' => 'deleted_flag'];
        $this->assertSame('deleted_flag', ModuleConfigContract::softDeleteColumn($config));
    }

    public function test_contract_soft_delete_column_rejects_empty_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleConfigContract::softDeleteColumn(['soft_delete_column' => '']);
    }

    // ─── ModuleConfigContract::creatorUpdaterColumns() direct coverage ─────

    public function test_contract_creator_updater_columns_defaults_to_historical_pair(): void
    {
        $this->assertSame(
            ['created' => 'created_by_id', 'updated' => 'updated_by_id'],
            ModuleConfigContract::creatorUpdaterColumns([])
        );
    }

    public function test_contract_creator_updater_columns_trusts_explicit_override(): void
    {
        $config = ['creator_updater_columns' => ['created_by' => 'created_by', 'updated_by' => 'modified_by']];
        $this->assertSame(
            ['created' => 'created_by', 'updated' => 'modified_by'],
            ModuleConfigContract::creatorUpdaterColumns($config)
        );
    }

    public function test_contract_creator_updater_columns_allows_null_updated_by_for_single_actor_modules(): void
    {
        $config = ['creator_updater_columns' => ['created_by' => 'created_by', 'updated_by' => null]];
        $this->assertSame(
            ['created' => 'created_by', 'updated' => null],
            ModuleConfigContract::creatorUpdaterColumns($config)
        );
    }

    public function test_contract_creator_updater_columns_rejects_empty_created_by(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleConfigContract::creatorUpdaterColumns(['creator_updater_columns' => ['created_by' => '']]);
    }

    public function test_contract_creator_updater_columns_rejects_empty_updated_by(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModuleConfigContract::creatorUpdaterColumns(['creator_updater_columns' => ['updated_by' => '']]);
    }
}
