<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Tests;

use Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork, Bug 2: {Module}TestCase::setUp() (`Sanctum::actingAs(
 * UsersModel::find(UsersModel::DEVELOPER))`) and the audit-column fixture/
 * assertion lines used to hardcode the literal FQCN
 * `App\Project\Modules\Core\Users\Users\UsersModel` — a fake, never-real
 * placeholder module (doubled `Users\Users`) — completely independent of
 * module.json, in TWO separate places (PhpUnitTestGenerator's own
 * USERS_MODEL_FQCN constant, AND, independently, ModelGenerator's
 * generateAuditRelationships(); see ModelGeneratorCustomColumnNamesTest).
 * A consuming app that deletes that fake module (shelui_erp did: replaced by
 * a real App\Project\Modules\Core\Users\User\UserModel) got a generated test
 * suite that fatals the moment it runs
 * ("Class \"...\Users\Users\UsersModel\" not found"), with no override able
 * to fix it.
 *
 * ModuleConfigContract::testActorModel()/testActorIdExpression() are now the
 * single source of truth every `UsersModel::...` reference in this
 * generator's output resolves through (see PhpUnitTestGenerator::
 * testActorFqcn()/testActorIdExpression()/testActorIdExpressionQualified()),
 * defaulting to creatorUpdaterModel() — a SEPARATE, existing concept (what
 * model a row's creator_updater_columns actually point at) — so a consumer
 * that only ever overrode creator_updater_model keeps working unchanged, and
 * one whose Sanctum guard's model genuinely differs from a module's own
 * audit-column target overrides test_actor_model independently.
 *
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract::testActorModel()
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract::testActorIdExpression()
 * @see \Blutrixx\GeneratorEngine\Schema\ModuleConfigContract::creatorUpdaterModel()
 */
class PhpUnitTestGeneratorTestActorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-phpunit-testgen-test-actor-' . uniqid();
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
    private function locationTypesConfig(array $overrides = []): array
    {
        $path = dirname(__DIR__, 4) . '/Fixtures/LocationTypesModule.json';
        $this->assertFileExists($path, "Expected fixture not found: {$path}");

        $config = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($config, 'LocationTypesModule.json did not decode to an array.');

        return array_merge($config, $overrides);
    }

    private function testCaseContent(string $group, string $module): string
    {
        $path = PathManager::getBackendModulePath($group, $module) . "/Tests/{$module}TestCase.php";
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** Every generated Tests/*.php file's content, concatenated. */
    private function allGeneratedContent(string $group, string $module): string
    {
        $dir = PathManager::getBackendModulePath($group, $module) . '/Tests';
        $files = glob($dir . '/*.php') ?: [];
        $this->assertNotEmpty($files, "Expected at least one generated Tests/ file for {$module}.");
        sort($files);

        $content = '';
        foreach ($files as $file) {
            $content .= (string) file_get_contents($file) . "\n";

            $output = [];
            $exitCode = 0;
            exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);
            $this->assertSame(0, $exitCode, "Generated file has a PHP syntax error:\n" . implode("\n", $output));
        }

        return $content;
    }

    public function test_default_test_actor_matches_the_historical_hardcoded_value(): void
    {
        $config = $this->locationTypesConfig();
        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $testCase = $this->testCaseContent('Core', 'LocationTypes');

        $this->assertStringContainsString(
            'use App\Project\Modules\Core\Users\Users\UsersModel;',
            $testCase
        );
        $this->assertStringContainsString(
            'Sanctum::actingAs(UsersModel::find(UsersModel::DEVELOPER));',
            $testCase
        );

        $content = $this->allGeneratedContent('Core', 'LocationTypes');
        $this->assertStringContainsString("(int) UsersModel::DEVELOPER", $content);
    }

    public function test_test_actor_model_override_changes_the_import_and_every_reference(): void
    {
        $config = $this->locationTypesConfig([
            'test_actor_model' => 'App\\Project\\Modules\\Core\\Users\\User\\UserModel',
        ]);
        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $testCase = $this->testCaseContent('Core', 'LocationTypes');

        $this->assertStringContainsString(
            'use App\Project\Modules\Core\Users\User\UserModel as UsersModel;',
            $testCase
        );
        // test_actor_id_expression wasn't overridden, so the alias-relative
        // default expression is unchanged -- only WHAT it resolves to moved.
        $this->assertStringContainsString(
            'Sanctum::actingAs(UsersModel::find(UsersModel::DEVELOPER));',
            $testCase
        );

        $content = $this->allGeneratedContent('Core', 'LocationTypes');
        $this->assertStringNotContainsString('Users\Users\UsersModel', $content);
    }

    public function test_test_actor_id_expression_override_changes_the_actor_value_everywhere(): void
    {
        $config = $this->locationTypesConfig([
            'test_actor_id_expression' => 'UsersModel::first()->id',
        ]);
        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $testCase = $this->testCaseContent('Core', 'LocationTypes');
        $this->assertStringContainsString(
            'Sanctum::actingAs(UsersModel::find(UsersModel::first()->id));',
            $testCase
        );

        $content = $this->allGeneratedContent('Core', 'LocationTypes');
        $this->assertStringNotContainsString('UsersModel::DEVELOPER', $content);
        $this->assertStringContainsString('(int) UsersModel::first()->id', $content);
    }

    /**
     * test_actor_model absent, creator_updater_model overridden: the test
     * actor falls back to creatorUpdaterModel() (ModuleConfigContract::
     * testActorModel()'s documented default) -- a consumer that only ever
     * knew about the older creator_updater_model key keeps working
     * unchanged, with no separate test_actor_model override required.
     */
    public function test_test_actor_model_defaults_to_creator_updater_model_when_not_set(): void
    {
        $config = $this->locationTypesConfig([
            'creator_updater_model' => 'App\\Project\\Modules\\Core\\Users\\User\\UserModel',
        ]);
        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $testCase = $this->testCaseContent('Core', 'LocationTypes');
        $this->assertStringContainsString(
            'use App\Project\Modules\Core\Users\User\UserModel as UsersModel;',
            $testCase
        );
    }

    /**
     * The two concepts are independent: overriding BOTH keys to DIFFERENT
     * classes proves test_actor_model wins for the Sanctum-actor question
     * without disturbing creator_updater_model's own (separately tested —
     * see ModelGeneratorCustomColumnNamesTest) effect on the Model's
     * creator()/updater() relations.
     */
    public function test_test_actor_model_overrides_independently_of_creator_updater_model(): void
    {
        $config = $this->locationTypesConfig([
            'creator_updater_model' => 'App\\Project\\Modules\\Core\\Workers\\Worker\\WorkerModel',
            'test_actor_model' => 'App\\Project\\Modules\\Core\\Users\\User\\UserModel',
        ]);
        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $testCase = $this->testCaseContent('Core', 'LocationTypes');
        $this->assertStringContainsString(
            'use App\Project\Modules\Core\Users\User\UserModel as UsersModel;',
            $testCase
        );
        $this->assertStringNotContainsString('WorkerModel', $testCase);
    }
}
