<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Migrations;

use Blutrixx\GeneratorEngine\Generators\Backend\Migrations\MigrationGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: a module adapted onto a pre-existing table (this
 * project's real "adapt in place, one module one table" tables) rarely uses
 * Laravel's own created_at/updated_at, deleted_at, created_by_id/updated_by_id
 * naming -- the legacy convention is created_date/modified_date, an integer
 * is_deleted flag, and created_by/modified_by (sometimes unpaired). Proves
 * MigrationGenerator emits real, correctly named columns + indexes for every
 * one of ModuleConfigContract's new column-naming accessors, instead of the
 * Laravel defaults every module generated before this fork used.
 *
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract::timestampColumns()
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract::softDeleteType()
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract::softDeleteColumn()
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract::creatorUpdaterColumns()
 */
class MigrationGeneratorCustomColumnNamesTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-migration-custom-columns-test-' . uniqid();
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

    private function generate(string $moduleName, array $config): string
    {
        $tableName = strtolower($moduleName);
        $fullConfig = array_merge([
            'table_name' => $tableName,
            'id_type'    => 'autoincrement',
            'columns'    => [['name' => 'title', 'type' => 'string']],
        ], $config);

        $generator = new MigrationGenerator($moduleName, 'Sacco', $fullConfig);
        $this->assertTrue($generator->generate());

        $files = glob($this->tmpRoot . "/BACKEND/app/Project/Modules/Sacco/{$moduleName}/Migrations/*_create_{$tableName}_table.php") ?: [];
        $this->assertCount(1, $files);

        return file_get_contents($files[0]);
    }

    public function test_custom_timestamp_column_names_emit_spelled_out_timestamp_calls(): void
    {
        $content = $this->generate('ZzzLegacyTimestamps', [
            'has_timestamps' => true,
            'timestamp_columns' => ['created_at' => 'created_date', 'updated_at' => 'modified_date'],
        ]);

        $this->assertStringNotContainsString('$table->timestamps();', $content);
        $this->assertStringContainsString("\$table->timestamp('created_date')->nullable();", $content);
        $this->assertStringContainsString("\$table->timestamp('modified_date')->nullable();", $content);
    }

    public function test_default_timestamp_column_names_still_emit_plain_timestamps_call(): void
    {
        $content = $this->generate('ZzzDefaultTimestamps', ['has_timestamps' => true]);

        $this->assertStringContainsString('$table->timestamps();', $content);
    }

    public function test_flag_soft_delete_type_emits_boolean_column_not_soft_deletes(): void
    {
        $content = $this->generate('ZzzFlagSoftDelete', [
            'has_soft_deletes' => true,
            'soft_delete_type' => 'flag',
        ]);

        $this->assertStringNotContainsString('softDeletes()', $content);
        $this->assertStringContainsString("\$table->boolean('is_deleted')->default(false);", $content);
        $this->assertStringContainsString("\$table->index(['is_deleted'], 'idx_zzzflagsoftdelete_is_deleted');", $content);
    }

    public function test_flag_soft_delete_type_honors_custom_column_name(): void
    {
        $content = $this->generate('ZzzFlagCustomCol', [
            'has_soft_deletes' => true,
            'soft_delete_type' => 'flag',
            'soft_delete_column' => 'deleted_flag',
        ]);

        $this->assertStringContainsString("\$table->boolean('deleted_flag')->default(false);", $content);
    }

    public function test_timestamp_soft_delete_type_with_custom_column_name_uses_named_soft_deletes_call(): void
    {
        $content = $this->generate('ZzzTimestampCustomCol', [
            'has_soft_deletes' => true,
            'soft_delete_column' => 'removed_at',
        ]);

        $this->assertStringNotContainsString('$table->softDeletes();', $content);
        $this->assertStringContainsString("\$table->softDeletes('removed_at');", $content);
    }

    public function test_default_soft_delete_still_emits_plain_soft_deletes_call(): void
    {
        $content = $this->generate('ZzzDefaultSoftDelete', ['has_soft_deletes' => true]);

        $this->assertStringContainsString('$table->softDeletes();', $content);
    }

    public function test_custom_creator_updater_column_names(): void
    {
        $content = $this->generate('ZzzLegacyAudit', [
            'has_creator_updater' => true,
            'creator_updater_columns' => ['created_by' => 'created_by', 'updated_by' => 'modified_by'],
        ]);

        $this->assertStringContainsString("\$table->foreignId('created_by');", $content);
        $this->assertStringContainsString("\$table->foreignId('modified_by')->nullable();", $content);
        $this->assertStringNotContainsString("created_by_id", $content);
        $this->assertStringNotContainsString("updated_by_id", $content);
        $this->assertStringContainsString("\$table->index(['created_by'], 'idx_zzzlegacyaudit_created_by');", $content);
        $this->assertStringContainsString("\$table->index(['modified_by'], 'idx_zzzlegacyaudit_modified_by');", $content);
    }

    public function test_single_actor_creator_only_module_omits_updated_by_entirely(): void
    {
        $content = $this->generate('ZzzCreatorOnly', [
            'has_creator_updater' => true,
            'creator_updater_columns' => ['created_by' => 'created_by', 'updated_by' => null],
        ]);

        $this->assertStringContainsString("\$table->foreignId('created_by');", $content);
        $this->assertStringNotContainsString('updated_by', $content);
        $this->assertStringContainsString("\$table->index(['created_by'], 'idx_zzzcreatoronly_created_by');", $content);
        $this->assertStringNotContainsString("idx_zzzcreatoronly_updated_by", $content);
    }

    public function test_default_creator_updater_column_names_unchanged(): void
    {
        $content = $this->generate('ZzzDefaultAudit', ['has_creator_updater' => true]);

        $this->assertStringContainsString("\$table->foreignId('created_by_id');", $content);
        $this->assertStringContainsString("\$table->foreignId('updated_by_id')->nullable();", $content);
    }

    /**
     * Regression test for the `ward` duplication bug: a real legacy table
     * already has its own introspected `created_by` column (a plain,
     * nullable integer with no `_id` suffix -- SchemaIntrospector::
     * SKIP_COLUMNS only recognises 'created_by_id', so this survives
     * introspection as an ordinary column, see MigrationGenerator::
     * columnAlreadyDeclaredElsewhere()'s docblock), and
     * creator_updater_columns maps the creator relation onto that SAME
     * name, with no updater column at all (updated_by: null). Before the
     * fix, generateAuditFields() emitted a SECOND, wrong-typed
     * `$table->foreignId('created_by');` for the identical column name,
     * alongside the real `$table->integer('created_by')->nullable();`
     * generateSchema() already emitted from the introspected column — this
     * asserts exactly one declaration survives.
     */
    public function test_creator_updater_column_matching_an_already_introspected_column_is_not_duplicated(): void
    {
        $content = $this->generate('ZzzWardLike', [
            'columns' => [
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'created_by', 'type' => 'integer', 'nullable' => true],
            ],
            'has_creator_updater' => true,
            'creator_updater_columns' => ['created_by' => 'created_by', 'updated_by' => null],
        ]);

        $this->assertSame(
            1,
            substr_count($content, "('created_by'"),
            "created_by must be declared exactly once — got:\n{$content}"
        );
        $this->assertStringContainsString("\$table->integer('created_by')->nullable();", $content);
        $this->assertStringNotContainsString("\$table->foreignId('created_by')", $content);
        $this->assertStringNotContainsString('updated_by', $content);
    }

    /**
     * Same duplication bug, confirmed to reach generateTimestampsLine() and
     * generateSoftDeletesLine() too — both read $this->fields the same way
     * generateAuditFields() does, so a legacy table's real introspected
     * `created_date`/`modified_date`/`is_deleted` columns (this project's
     * other real legacy naming, see this class's own docblock) hit the
     * exact same "config names a column introspection already produced"
     * shape.
     */
    public function test_custom_timestamp_and_flag_soft_delete_columns_matching_already_introspected_columns_are_not_duplicated(): void
    {
        $content = $this->generate('ZzzWardLikeFull', [
            'columns' => [
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'created_date', 'type' => 'dateTime', 'nullable' => true],
                ['name' => 'modified_date', 'type' => 'dateTime', 'nullable' => true],
                ['name' => 'is_deleted', 'type' => 'boolean', 'nullable' => false, 'default' => 0],
            ],
            'has_timestamps' => true,
            'timestamp_columns' => ['created_at' => 'created_date', 'updated_at' => 'modified_date'],
            'has_soft_deletes' => true,
            'soft_delete_type' => 'flag',
        ]);

        foreach (['created_date', 'modified_date', 'is_deleted'] as $column) {
            $this->assertSame(
                1,
                substr_count($content, "('{$column}'"),
                "{$column} must be declared exactly once — got:\n{$content}"
            );
        }
        $this->assertStringContainsString("\$table->dateTime('created_date')->nullable();", $content);
        $this->assertStringContainsString("\$table->dateTime('modified_date')->nullable();", $content);
        $this->assertStringContainsString("\$table->boolean('is_deleted')->default(false);", $content);
        $this->assertStringNotContainsString("\$table->timestamp('created_date')", $content);
        $this->assertStringNotContainsString("\$table->timestamp('modified_date')", $content);
    }

    /**
     * The already-declared guard must never suppress the Laravel-convention
     * DEFAULT names ('created_at'/'updated_at'/'deleted_at'/'created_by_id'/
     * 'updated_by_id'), even if one happens to appear in a hand-authored
     * `columns` array — SchemaIntrospector::SKIP_COLUMNS guarantees a REAL
     * introspected table never puts one there, so its presence is the
     * flag-absent fallback-detection signal ModuleConfigContract::
     * hasSoftDeletes() documents, not a real second declaration. Mirrors
     * ModuleConfigContractTest::test_model_and_migration_generators_agree_on_has_soft_deletes()'s
     * "deleted_at column present, flag absent" case at the MigrationGenerator
     * level directly.
     */
    public function test_reserved_default_column_names_are_never_treated_as_already_declared(): void
    {
        $content = $this->generate('ZzzReservedNamesSignal', [
            'columns' => [
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'deleted_at', 'type' => 'timestamp'],
            ],
            // has_soft_deletes deliberately omitted — this exercises
            // hasSoftDeletes()'s rescan-based fallback.
        ]);

        $this->assertStringContainsString('$table->softDeletes();', $content);
    }
}
