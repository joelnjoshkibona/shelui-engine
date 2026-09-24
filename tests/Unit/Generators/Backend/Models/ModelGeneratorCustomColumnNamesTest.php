<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Models;

use Blutrixx\GeneratorEngine\Generators\Backend\Models\ModelGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: proves ModelGenerator emits a Model that actually
 * works against a pre-existing table with legacy column naming — the
 * 'flag' soft-delete type (App\Project\_Src\Traits\HasIsDeleted instead of
 * Laravel's own SoftDeletes) and single-actor (creator-only, no updater)
 * audit tracking, mirroring MigrationGeneratorCustomColumnNamesTest for the
 * migration side of the same config.
 *
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract::softDeleteType()
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract::softDeleteColumn()
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract::creatorUpdaterColumns()
 */
class ModelGeneratorCustomColumnNamesTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-model-custom-columns-test-' . uniqid();
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

    private function generateAndRead(array $config, string $moduleName, string $moduleGroup = 'Sacco'): string
    {
        $fullConfig = array_merge([
            'connection' => '',
            'id_type'    => 'integer',
            'columns'    => [['name' => 'title', 'type' => 'string']],
        ], $config);

        $generator = new ModelGenerator($moduleName, $moduleGroup, $fullConfig);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = $this->tmpRoot . "/BACKEND/app/Project/Modules/{$moduleGroup}/{$moduleName}/{$moduleName}Model.php";
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    public function test_flag_soft_delete_type_uses_has_is_deleted_trait_not_soft_deletes(): void
    {
        $content = $this->generateAndRead([
            'has_soft_deletes' => true,
            'soft_delete_type' => 'flag',
        ], 'ZzzFlagModel');

        $this->assertStringContainsString('use App\Project\_Src\Traits\HasIsDeleted;', $content);
        $this->assertStringContainsString('use HasFactory, HasIsDeleted;', $content);
        $this->assertStringNotContainsString('SoftDeletes', $content);
    }

    public function test_flag_soft_delete_type_with_default_column_omits_property_override(): void
    {
        $content = $this->generateAndRead([
            'has_soft_deletes' => true,
            'soft_delete_type' => 'flag',
        ], 'ZzzFlagDefaultCol');

        $this->assertStringNotContainsString('isDeletedColumn', $content);
    }

    public function test_flag_soft_delete_type_with_custom_column_emits_property_override(): void
    {
        $content = $this->generateAndRead([
            'has_soft_deletes' => true,
            'soft_delete_type' => 'flag',
            'soft_delete_column' => 'deleted_flag',
        ], 'ZzzFlagCustomCol');

        $this->assertStringContainsString("protected static string \$isDeletedColumn = 'deleted_flag';", $content);
    }

    public function test_timestamp_soft_delete_type_with_custom_column_emits_deleted_at_const(): void
    {
        $content = $this->generateAndRead([
            'has_soft_deletes' => true,
            'soft_delete_column' => 'removed_at',
        ], 'ZzzTimestampCustomCol');

        $this->assertStringContainsString('use Illuminate\Database\Eloquent\SoftDeletes;', $content);
        $this->assertStringContainsString("const DELETED_AT = 'removed_at';", $content);
    }

    public function test_default_soft_deletes_omits_deleted_at_const(): void
    {
        $content = $this->generateAndRead(['has_soft_deletes' => true], 'ZzzDefaultSoftDeletes');

        $this->assertStringNotContainsString('DELETED_AT', $content);
    }

    public function test_custom_creator_updater_columns_emit_matching_relations(): void
    {
        $content = $this->generateAndRead([
            'has_creator_updater' => true,
            'creator_updater_columns' => ['created_by' => 'created_by', 'updated_by' => 'modified_by'],
        ], 'ZzzLegacyAuditModel');

        $this->assertStringContainsString("::class, 'created_by', 'id'", $content);
        $this->assertStringContainsString("::class, 'modified_by', 'id'", $content);
        $this->assertStringContainsString('public function creator()', $content);
        $this->assertStringContainsString('public function updater()', $content);
        $this->assertStringNotContainsString('created_by_id', $content);
        $this->assertStringNotContainsString('updated_by_id', $content);
    }

    public function test_single_actor_creator_only_module_omits_updater_relation(): void
    {
        $content = $this->generateAndRead([
            'has_creator_updater' => true,
            'creator_updater_columns' => ['created_by' => 'created_by', 'updated_by' => null],
        ], 'ZzzCreatorOnlyModel');

        $this->assertStringContainsString('public function creator()', $content);
        $this->assertStringNotContainsString('public function updater()', $content);
        $this->assertStringNotContainsString('updated_by', $content);
    }

    public function test_default_creator_updater_model_is_the_historical_placeholder(): void
    {
        $content = $this->generateAndRead(['has_creator_updater' => true], 'ZzzDefaultAuditModelTarget');

        $this->assertStringContainsString(
            "\\App\\Project\\Modules\\Core\\Users\\Users\\UsersModel::class, 'created_by_id', 'id'",
            $content
        );
    }

    /**
     * Bug 2 (shelui-engine): creator()/updater() used to hardcode this exact
     * fake FQCN directly (`$usersNs = '\\App\\Project\\Modules\\Core\\Users\\
     * Users\\UsersModel';`), so a consuming app that deleted that module
     * (retired as a never-real placeholder, e.g. shelui_erp) had no way to
     * point the relation at its real Sanctum-authenticatable model without
     * hand-editing every generated Model.php after every regenerate.
     * ModuleConfigContract::creatorUpdaterModel() is now the one resolution
     * rule ModelGenerator (this test) and PhpUnitTestGenerator (see
     * PhpUnitTestGeneratorTestActorTest) both defer to.
     */
    public function test_creator_updater_model_override_changes_the_relation_target(): void
    {
        $content = $this->generateAndRead([
            'has_creator_updater' => true,
            'creator_updater_model' => 'App\\Project\\Modules\\Core\\Users\\User\\UserModel',
        ], 'ZzzOverriddenAuditModelTarget');

        $this->assertStringContainsString(
            "\\App\\Project\\Modules\\Core\\Users\\User\\UserModel::class, 'created_by_id', 'id'",
            $content
        );
        $this->assertStringNotContainsString('Users\\Users\\UsersModel', $content);
    }

    public function test_custom_audit_column_declared_as_foreign_id_is_not_double_related(): void
    {
        // A column literally named 'created_by' with type foreignId must
        // still be skipped by the auto-relationship loop -- it's handled by
        // generateAuditRelationships() via creator(), not a generic belongsTo.
        $content = $this->generateAndRead([
            'has_creator_updater' => true,
            'creator_updater_columns' => ['created_by' => 'created_by', 'updated_by' => null],
            'columns' => [
                ['name' => 'title', 'type' => 'string'],
                ['name' => 'created_by', 'type' => 'foreignId', 'relatedModule' => 'Users'],
            ],
        ], 'ZzzAuditNoDoubleRelation');

        $this->assertSame(1, substr_count($content, 'function creator()'));
    }
}
