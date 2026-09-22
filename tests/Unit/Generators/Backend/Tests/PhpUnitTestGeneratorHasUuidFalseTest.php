<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Tests;

use Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: PhpUnitTestGenerator hardcoded `$fixture->uuid` (and
 * literal 'uuid' DB columns/JSON paths) throughout every generated test
 * method, with zero has_uuid awareness anywhere in the class — confirmed via
 * grep before this fix, not a guess. A has_uuid: false module (this
 * project's legacy-repointed tables, routed by RoutesGenerator/
 * ControllerGenerator/the Model on `id` since an earlier pass this same
 * session) got a full generated test SUITE that still assumed `uuid`,
 * hitting `/{module}//view` (uuid resolving to null) or fatal-erroring on
 * the undefined Eloquent attribute.
 *
 * Reuses the real LocationTypesModule.json fixture (same one
 * PhpUnitTestGeneratorTest uses) with has_uuid forced false, so this
 * exercises the exact config shape a real generated module produces, not a
 * hand-trimmed config that might accidentally dodge a code path.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator
 */
class PhpUnitTestGeneratorHasUuidFalseTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-phpunit-testgen-has-uuid-false-' . uniqid();
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

        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** @return array<string, mixed> */
    private function locationTypesConfigWithoutUuid(): array
    {
        $path = dirname(__DIR__, 4) . '/Fixtures/LocationTypesModule.json';
        $this->assertFileExists($path, "Expected fixture not found: {$path}");

        $config = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($config, 'LocationTypesModule.json did not decode to an array.');

        $config['has_uuid'] = false;

        return $config;
    }

    private function generatedTestFiles(string $group, string $module): array
    {
        $dir = PathManager::getBackendModulePath($group, $module) . '/Tests';
        if (!is_dir($dir)) {
            return [];
        }
        $files = glob($dir . '/*.php') ?: [];
        sort($files);
        return $files;
    }

    private function generatedContentFor(string $group, string $module): string
    {
        $files = $this->generatedTestFiles($group, $module);
        $this->assertNotEmpty($files, "Expected at least one generated Tests/ file for {$module}.");

        $content = '';
        foreach ($files as $file) {
            $content .= (string) file_get_contents($file) . "\n";
        }
        return $content;
    }

    private function assertAllGeneratedFilesHaveValidSyntax(string $group, string $module): void
    {
        $files = $this->generatedTestFiles($group, $module);
        $this->assertNotEmpty($files, "Expected at least one generated Tests/ file for {$module}.");
        foreach ($files as $file) {
            $output = [];
            $exitCode = 0;
            exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);
            $this->assertSame(0, $exitCode, "Generated file has a PHP syntax error:\n" . implode("\n", $output));
        }
    }

    public function test_generated_suite_never_references_uuid_at_all(): void
    {
        $config = $this->locationTypesConfigWithoutUuid();
        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'LocationTypes');

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertStringNotContainsString('uuid', $content, 'A has_uuid: false module\'s generated test suite must never reference uuid anywhere.');
    }

    public function test_view_test_uses_id_in_url_and_assertion(): void
    {
        $config = $this->locationTypesConfigWithoutUuid();
        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertStringContainsString('{$fixture->id}/view', $content);
        $this->assertStringContainsString("assertJsonPath('data.id', \$fixture->id)", $content);
    }

    public function test_create_test_asserts_database_has_id_not_uuid(): void
    {
        $config = $this->locationTypesConfigWithoutUuid();
        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertStringContainsString("'id' => \$fixture->id,", $content);
    }

    public function test_edit_and_delete_tests_use_id_in_url(): void
    {
        $config = $this->locationTypesConfigWithoutUuid();
        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertStringContainsString('{$fixture->id}/edit', $content);
        $this->assertStringContainsString('{$fixture->id}/delete', $content);
        $this->assertStringContainsString('{$fixture->id}/delete/check', $content);
    }

    public function test_activity_test_uses_id_in_url(): void
    {
        $config = $this->locationTypesConfigWithoutUuid();
        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertStringContainsString('{$fixture->id}/activity', $content);
    }

    public function test_soft_delete_excluded_from_list_test_plucks_id(): void
    {
        $config = $this->locationTypesConfigWithoutUuid();
        $config['has_soft_deletes'] = true;
        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertStringContainsString("collect(\$response->json('data.data'))->pluck('id')", $content);
        $this->assertStringContainsString('$recordKeys->contains($fixture->id)', $content);
    }

    public function test_flag_soft_delete_type_asserts_flag_column_instead_of_soft_deleted(): void
    {
        $config = $this->locationTypesConfigWithoutUuid();
        $config['has_soft_deletes'] = true;
        $config['soft_delete_type'] = 'flag';
        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertStringContainsString("assertDatabaseHas('location_types', ['id' => \$fixture->id, 'is_deleted' => 1]);", $content);
        $this->assertStringNotContainsString('assertSoftDeleted', $content);
    }

    public function test_bulk_action_ids_mode_test_sends_id_values(): void
    {
        $config = $this->locationTypesConfigWithoutUuid();
        $config['features']['backend']['list']['bulk_actions'] = [
            ['key' => 'archive', 'label' => 'Archive'],
        ];
        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertStringContainsString("'ids' => [\$first->id, \$second->id],", $content);
    }

    public function test_creator_updater_assertions_unaffected_by_has_uuid(): void
    {
        $config = $this->locationTypesConfigWithoutUuid();
        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertStringContainsString("assertJsonPath('data.created_by_id', (int) UsersModel::DEVELOPER)", $content);
        $this->assertStringContainsString("assertJsonPath('data.updated_by_id', (int) UsersModel::DEVELOPER)", $content);
    }

    /**
     * Mirrors RoutesGeneratorHasUuidFalseCustomRoutesTest/
     * ControllerGeneratorHasUuidFalseCustomMethodsTest's delegation
     * parentKey coverage, on the test-generation side: this class reads
     * $delegation['parentContext']/['parentKey'] independently of
     * DelegationConfigNormalizer (same root cause those two classes'
     * docblocks describe), so it needed the identical fix.
     */
    public function test_delegation_list_test_uses_parent_id_when_has_uuid_false(): void
    {
        $config = $this->locationTypesConfigWithoutUuid();
        $config['delegations'] = [
            'items' => [
                'name' => 'items',
                'operations' => ['list' => ['enabled' => true]],
            ],
        ];
        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertStringContainsString('function test_can_list_items_delegation(', $content);
        $this->assertMethodBodyContains($content, 'test_can_list_items_delegation', '{$parent->id}/items/list');
    }

    private function assertMethodBodyContains(string $content, string $methodName, string $needle): void
    {
        $body = $this->extractMethodBody($content, $methodName);

        $this->assertStringContainsString($needle, $body, "Expected the body of {$methodName}() to contain \"{$needle}\".");
    }

    private function extractMethodBody(string $content, string $methodName): string
    {
        $start = strpos($content, "function {$methodName}(");
        $this->assertNotFalse($start, "Could not locate function {$methodName}( in generated content.");

        $nextMethodPos = strpos($content, "\n    public function ", $start + 1);
        $nextFilePos = strpos($content, "\n<?php", $start + 1);

        $end = match (true) {
            $nextMethodPos === false && $nextFilePos === false => null,
            $nextMethodPos === false => $nextFilePos,
            $nextFilePos === false => $nextMethodPos,
            default => min($nextMethodPos, $nextFilePos),
        };

        return $end === null ? substr($content, $start) : substr($content, $start, $end - $start);
    }
}
