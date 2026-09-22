<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Tests;

use Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Generator-unit coverage for PhpUnitTestGenerator (v2.10.11), run against a
 * scratch PathManager project root (mirrors MenusJsonGeneratorTest's harness:
 * PathManager::setProjectRoot() in setUp, reset+recursive cleanup in
 * tearDown) using the REAL persisted module.json for SYSTEM_SHELL's
 * LocationTypes module — copied verbatim into
 * tests/Fixtures/LocationTypesModule.json (see that file) rather than a
 * hand-rolled config array, so this exercises the exact config shape
 * IntrospectionToConfig/the builder UI actually produce.
 *
 * LocationTypes has every backend CRUD feature enabled (list/create/view/
 * edit/delete), so test_generate_writes_full_crud_test_for_a_module_with_every_feature_enabled()
 * expects every conditional method PhpUnitTestGenerator can emit, with the
 * right HTTP status per method. test_generate_omits_delete_method_when_delete_feature_is_disabled()
 * flips features.backend.delete (and its frontend counterpart, inert here
 * but toggled for parity with PlaywrightTestGeneratorTest's equivalent case)
 * off and confirms ONLY the delete-specific method disappears — the
 * unconditional DeleteCheck/filter coverage, and every other CRUD method,
 * must remain.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator
 */
class PhpUnitTestGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-phpunit-testgen-' . uniqid();
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

        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /** @return array<string, mixed> */
    private function locationTypesConfig(): array
    {
        $path = dirname(__DIR__, 4) . '/Fixtures/LocationTypesModule.json';
        $this->assertFileExists($path, "Expected fixture not found: {$path}");

        $config = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($config, 'LocationTypesModule.json did not decode to an array.');

        return $config;
    }

    /** @return string[] Every file PhpUnitTestGenerator wrote under {module}/Tests/, for a module split across N files. */
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

    /**
     * Concatenated content of every split test file for a module — the
     * post-split replacement for "read the one CrudTest.php file". Every
     * existing `assertStringContainsString($content, ...)` /
     * `assertMethodBodyContains($content, $method, ...)` call is agnostic to
     * WHICH physical file a method lives in, so aggregating is sufficient
     * for all of them; only class-shape assertions (there is now more than
     * one class) needed individual updates.
     */
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
            $this->assertValidPhpSyntax($file);
        }
    }

    private function assertValidPhpSyntax(string $file): void
    {
        $output = [];
        $exitCode = 0;
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, "Generated file has a PHP syntax error:\n" . implode("\n", $output));
    }

    /**
     * Assert a substring appears within the body of a specific generated
     * method (rather than merely anywhere in the file) — locates the method
     * by its declaration, then searches only up to the next
     * "    public function " (or end of file).
     */
    private function assertMethodBodyContains(string $content, string $methodName, string $needle): void
    {
        $body = $this->extractMethodBody($content, $methodName);

        $this->assertStringContainsString($needle, $body, "Expected the body of {$methodName}() to contain \"{$needle}\".");
    }

    /**
     * Negative counterpart to assertMethodBodyContains() — scoped to a single
     * method's body rather than the whole file, since some of the fixture
     * literals asserted against here (e.g. a file column's
     * `RelatedModel::factory()->create()->id`) are deliberately correct
     * INSIDE the fixture helper (a direct Model::create() call, bypassing
     * HTTP validation) while being wrong inside an HTTP request payload
     * method — a whole-file assertStringNotContainsString would produce a
     * false failure by also matching the fixture helper.
     */
    private function assertMethodBodyNotContains(string $content, string $methodName, string $needle): void
    {
        $body = $this->extractMethodBody($content, $methodName);

        $this->assertStringNotContainsString($needle, $body, "Expected the body of {$methodName}() NOT to contain \"{$needle}\".");
    }

    /**
     * Locates a method by its declaration and returns everything up to the
     * next "    public function " (or end of file).
     */
    /**
     * $content is generatedContentFor()'s concatenation of every split test
     * file for a module — several files, several classes, one string. The
     * end boundary must stop at whichever comes first: the next method in
     * the SAME class ("\n    public function "), or the start of the NEXT
     * concatenated file ("\n<?php"). Without the second check, the last
     * method in a file would over-capture that file's closing "}" plus the
     * next file's imports/class declaration/first method as if they were
     * still part of this method's body.
     */
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

    public function test_generate_writes_full_crud_test_for_a_module_with_every_feature_enabled(): void
    {
        $config = $this->locationTypesConfig();

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'LocationTypes');
        $content = $this->generatedContentFor('Core', 'LocationTypes');

        // Namespace / imports — module-local, not the old central tests/Feature.
        $this->assertStringContainsString('namespace App\Project\Modules\Core\LocationTypes\Tests;', $content);
        $this->assertStringContainsString('use App\Project\Modules\Core\LocationTypes\LocationTypesModel;', $content);
        $this->assertStringContainsString('use App\Project\Modules\Core\Users\Users\UsersModel;', $content);

        // One file per Service class: a shared base every split class
        // extends, plus one *ServiceTest per enabled CRUD operation.
        $this->assertStringContainsString('abstract class LocationTypesTestCase extends TestCase', $content);
        $this->assertStringContainsString('protected function createLocationTypeFixture(', $content);
        foreach (['List', 'Create', 'View', 'Edit', 'Delete'] as $op) {
            $this->assertStringContainsString(
                "class LocationTypes{$op}ServiceTest extends LocationTypesTestCase",
                $content,
                "Expected a split LocationTypes{$op}ServiceTest class extending the shared base."
            );
        }

        // Fixture helper must end with ->fresh() — the uuid column's DB-level
        // default (see create_location_types_table's `$table->uuid()->default(...)`)
        // is never read back onto the in-memory model Model::create() returns
        // otherwise, leaving $fixture->uuid null and every uuid-path test
        // (view/edit/delete/delete-check) 404ing on a malformed request URL.
        // Confirmed live via a real make:module smoke test before this was added.
        $this->assertMethodBodyContains($content, 'createLocationTypeFixture', '->fresh()');

        // One method per enabled backend feature, plus the two pipeline-unconditional ones.
        $expectedMethods = [
            'test_can_list_location_types',
            'test_can_create_location_type',
            'test_can_view_location_type',
            'test_can_edit_location_type',
            'test_delete_check_reports_no_blocking_relationships',
            'test_can_delete_location_type',
            'test_can_filter_location_types_list_by_name',
            'test_create_location_type_validation_fails_with_missing_required_field',
            // Bulk-action coverage: gated on list alone (route.stub registers
            // POST /bulk-action unconditionally whenever list is enabled),
            // NOT on bulk_actions being configured — LocationTypes has none
            // configured, so only the config-independent validation/403
            // tests are expected here, not the ids-mode happy path.
            'test_bulk_action_validation_fails_with_missing_ids',
            'test_cannot_bulk_action_without_permission',
        ];
        foreach ($expectedMethods as $method) {
            $this->assertStringContainsString(
                "function {$method}(",
                $content,
                "Expected generated test to contain method {$method}()."
            );
        }

        // HTTP status codes: create=201, validation-failure=422, everything else=200.
        $this->assertMethodBodyContains($content, 'test_can_list_location_types', 'assertStatus(200)');
        $this->assertMethodBodyContains($content, 'test_can_create_location_type', 'assertStatus(201)');
        $this->assertMethodBodyContains($content, 'test_can_view_location_type', 'assertStatus(200)');
        $this->assertMethodBodyContains($content, 'test_can_edit_location_type', 'assertStatus(200)');
        $this->assertMethodBodyContains($content, 'test_delete_check_reports_no_blocking_relationships', 'assertStatus(200)');
        $this->assertMethodBodyContains($content, 'test_can_delete_location_type', 'assertStatus(200)');
        $this->assertMethodBodyContains($content, 'test_can_filter_location_types_list_by_name', 'assertStatus(200)');
        $this->assertMethodBodyContains(
            $content,
            'test_create_location_type_validation_fails_with_missing_required_field',
            'assertStatus(422)'
        );
        $this->assertMethodBodyContains(
            $content,
            'test_create_location_type_validation_fails_with_missing_required_field',
            "assertJsonValidationErrors(['name'])"
        );

        // Routes derived from the module's real table/route base.
        $this->assertStringContainsString('/api/location-types/list', $content);
        $this->assertStringContainsString('/api/location-types/create', $content);
        $this->assertStringContainsString('/api/location-types/{$fixture->uuid}/view', $content);
        $this->assertStringContainsString('/api/location-types/{$fixture->uuid}/edit', $content);
        $this->assertStringContainsString('/api/location-types/{$fixture->uuid}/delete/check', $content);
        $this->assertStringContainsString('/api/location-types/{$fixture->uuid}/delete', $content);
        $this->assertStringContainsString("assertDatabaseHas('location_types'", $content);
        // LocationTypesModule.json fixture has no 'has_soft_deletes' key and
        // no 'deleted_at' column -> ModuleConfigContract::hasSoftDeletes()
        // resolves to false via its documented fallback, so the delete test
        // asserts a genuine hard delete (assertDatabaseMissing), not
        // assertSoftDeleted() -- which would fatal with an "Unknown column
        // 'deleted_at'" QueryException against this module's real schema.
        $this->assertStringContainsString("assertDatabaseMissing('location_types'", $content);
        $this->assertStringNotContainsString('assertSoftDeleted(', $content);

        // Bulk-action route (route.stub's second line, unconditional
        // whenever list is enabled): validation and permission coverage use
        // the real route path; LocationTypes has no bulk_actions configured
        // so no concrete action key is asserted here.
        $this->assertStringContainsString('/api/location-types/bulk-action', $content);
        $this->assertMethodBodyContains($content, 'test_bulk_action_validation_fails_with_missing_ids', 'assertStatus(422)');
        $this->assertMethodBodyContains($content, 'test_cannot_bulk_action_without_permission', 'assertStatus(403)');
    }

    public function test_generate_omits_delete_method_when_delete_feature_is_disabled(): void
    {
        $config = $this->locationTypesConfig();
        $config['features']['backend']['delete'] = false;
        $config['features']['frontend']['delete'] = false; // inert for this generator; toggled for parity

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertStringNotContainsString(
            'function test_can_delete_location_type(',
            $content,
            'Disabling features.backend.delete should omit the delete test method entirely.'
        );
        $this->assertStringNotContainsString('assertSoftDeleted(', $content);
        $this->assertStringNotContainsString('/delete"', $content);

        // List filtering is genuinely unconditional — every generated list
        // carries filter support regardless of which CRUD ops are enabled.
        $this->assertStringContainsString('function test_can_filter_location_types_list_by_name(', $content);

        // DeleteCheck is NOT: it rides on `delete`, because that is what
        // decides whether the /{uuid}/delete/check route is generated at all.
        // This block previously asserted the opposite and so locked in the bug
        // — the covered route did not exist, and the test asserted 200 on a
        // 404. See test_deletecheck_test_file_is_not_generated_when_delete_is_disabled.
        $this->assertStringNotContainsString('function test_delete_check_reports_no_blocking_relationships(', $content);
        $this->assertStringNotContainsString('/delete/check', $content);

        // Every other feature-gated method stays untouched.
        $this->assertStringContainsString('function test_can_list_location_types(', $content);
        $this->assertStringContainsString('function test_can_create_location_type(', $content);
        $this->assertStringContainsString('function test_can_view_location_type(', $content);
        $this->assertStringContainsString('function test_can_edit_location_type(', $content);
    }

    /**
     * Regression test for a real generated-and-run failure: a module with a
     * self-referential hierarchy FK (module's own `create_item_categories_table`
     * style `parent_id` column, validated by IntrospectionToConfig as
     * `nullable|integer|exists:item_categories,id`) previously got the
     * generic integer-literal treatment in buildFieldValueLiteral() — always
     * hardcoding `1`. Run for real against a freshly migrated + seeded
     * database (php artisan test), this failed test_can_create_item_category
     * and test_can_edit_item_category with a 422 `validation.exists` error,
     * since no row with id 1 can possibly exist in item_categories on its
     * very first insert. Confirmed live before this fix; the config below
     * (table_name "categories", field "parent_id" with
     * "nullable|integer|exists:categories,id") reproduces the same shape
     * with a hand-rolled config, independent of any module.json fixture.
     */
    public function test_self_referential_nullable_foreign_key_uses_null_not_a_hardcoded_id(): void
    {
        $config = [
            'table_name' => 'categories',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'parent_id', 'rules' => 'nullable|integer|exists:categories,id'],
                        ],
                    ],
                    'view' => true,
                    'edit' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'parent_id', 'rules' => 'nullable|integer|exists:categories,id'],
                        ],
                    ],
                    'delete' => true,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Categories', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Categories');
        $content = $this->generatedContentFor('Core', 'Categories');

        // The fixture helper, the create-payload, and the edit-payload must
        // all use null for the self-referential column — never a hardcoded
        // integer, which would trip `exists:categories,id` on a fresh table.
        $this->assertMethodBodyContains($content, 'createCategoryFixture', "'parent_id' => null,");
        $this->assertMethodBodyContains($content, 'test_can_create_category', "'parent_id' => null,");
        $this->assertMethodBodyContains($content, 'test_can_edit_category', "'parent_id' => null,");
    }

    /**
     * A required (non-nullable) foreign key to a *different* module's table
     * (e.g. Items.item_type_id -> item_types) whose related module is NOT
     * registered in PathManager's module registry (the generator's only
     * source of cross-module identity — see
     * PathManager::findModuleByTable()) has no basis for resolving a real
     * parent row, so it must degrade gracefully to the pre-existing generic
     * integer literal `1` — never emit a reference to a model FQCN it isn't
     * actually sure exists. No PathManager::setModuleRegistry() call is made
     * in this test, so the registry is empty and resolution is expected to
     * fail.
     */
    public function test_required_cross_table_foreign_key_falls_back_to_a_literal_one_when_related_module_is_unknown(): void
    {
        $config = [
            'table_name' => 'items',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'item_type_id', 'rules' => 'required|integer|exists:item_types,id'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'Items');

        $this->assertMethodBodyContains($content, 'test_can_create_item', "'item_type_id' => 1,");
    }

    /**
     * The fix for the cross-module gap documented above: when the FK's
     * foreign table IS resolvable via PathManager's module registry (the
     * same mechanism DelegationServiceGenerator already uses to reference
     * another module's model FQCN), the generated test must CREATE a real
     * parent row at test-run time via the related module's own factory —
     * `ItemTypesModel::factory()->create()->id` — instead of looking one up
     * with `query()->value('id') ?? 1` (a previous fix attempt that doesn't
     * work: generated tests run under RefreshDatabase, so `item_types` is
     * completely empty at fixture time, the lookup always returns null, and
     * the `?? 1` fallback references a row that doesn't exist either).
     */
    public function test_required_cross_table_foreign_key_resolves_a_real_row_when_related_module_is_registered(): void
    {
        PathManager::setModuleRegistry([
            [
                'name'        => 'ItemTypes',
                'module_type' => 'Core',
                'table_name'  => 'item_types',
            ],
        ]);

        try {
            $config = [
                'table_name' => 'items',
                'features' => [
                    'backend' => [
                        'list' => true,
                        'create' => [
                            'fields' => [
                                ['field' => 'name', 'rules' => 'required|string|max:255'],
                                ['field' => 'item_type_id', 'rules' => 'required|integer|exists:item_types,id'],
                            ],
                        ],
                        'view' => false,
                        'edit' => false,
                        'delete' => false,
                    ],
                    'frontend' => [],
                ],
            ];

            $generator = new PhpUnitTestGenerator('Items', 'Core', $config);
            $this->assertTrue($generator->generate());

            $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Items');
            $content = $this->generatedContentFor('Core', 'Items');

            $this->assertMethodBodyContains(
                $content,
                'test_can_create_item',
                "'item_type_id' => \\App\\Project\\Modules\\Core\\ItemTypes\\ItemTypesModel::factory()->create()->id,"
            );
        } finally {
            // Reset the registry so this test's state can't bleed into any
            // other test in the suite.
            PathManager::setModuleRegistry([]);
        }
    }

    /**
     * Self-referential and nullable cross-module FKs must still take the
     * `null` path even now that a registry entry for the related table
     * exists — the registry-resolution branch added for the required
     * cross-module case must never run before, or instead of, the existing
     * self-referential/nullable checks.
     */
    public function test_self_referential_and_nullable_fk_handling_is_unchanged_when_a_registry_is_present(): void
    {
        PathManager::setModuleRegistry([
            [
                'name'        => 'Categories',
                'module_type' => 'Core',
                'table_name'  => 'categories',
            ],
        ]);

        try {
            $config = [
                'table_name' => 'categories',
                'features' => [
                    'backend' => [
                        'list' => true,
                        'create' => [
                            'fields' => [
                                ['field' => 'name', 'rules' => 'required|string|max:255'],
                                ['field' => 'parent_id', 'rules' => 'nullable|integer|exists:categories,id'],
                            ],
                        ],
                        'view' => false,
                        'edit' => false,
                        'delete' => false,
                    ],
                    'frontend' => [],
                ],
            ];

            $generator = new PhpUnitTestGenerator('Categories', 'Core', $config);
            $this->assertTrue($generator->generate());

            $content = $this->generatedContentFor('Core', 'Categories');

            $this->assertMethodBodyContains($content, 'test_can_create_category', "'parent_id' => null,");
        } finally {
            PathManager::setModuleRegistry([]);
        }
    }

    /**
     * Regression test for a real generated-and-run failure: since v2.10.17
     * the backend generators support file_columns-marked upload columns
     * (e.g. 'image_media_id'), whose generated service validates them with a
     * 'file' rule and converts the uploaded file into a Media row at
     * runtime. PhpUnitTestGenerator had no knowledge of file_columns at all,
     * so it fell through to the ordinary integer/FK literal `1` for these
     * columns — confirmed live: 2 of 40 generated tests failed with
     * `validation.file` against a real database. The generated create-test
     * payload must instead submit a fake UploadedFile.
     */
    public function test_file_column_uses_a_fake_upload_literal_in_the_create_payload(): void
    {
        $config = [
            'table_name' => 'item_images',
            'file_columns' => ['image_media_id'],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'image_media_id', 'rules' => 'required|integer|exists:media,id'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'ItemImages');
        $content = $this->generatedContentFor('Core', 'ItemImages');

        $this->assertMethodBodyContains(
            $content,
            'test_can_create_item_image',
            "'image_media_id' => \\Illuminate\\Http\\UploadedFile::fake()->image('test.jpg'),"
        );
    }

    /**
     * The file_columns check must win over the FK/integer inference even
     * though the column is FK-SHAPED — an unsignedBigInteger with an
     * `exists:media,id` rule, exactly like any other required cross-module
     * FK. Without the file-column check running first, this column would
     * otherwise be claimed by the same `exists:` branch that resolves a real
     * parent row via a factory (or falls back to the literal `1`) — neither
     * of which is valid for an HTTP request payload that must submit an
     * actual file for the 'file' validation rule to pass.
     */
    public function test_file_column_check_wins_over_fk_shaped_exists_rule(): void
    {
        PathManager::setModuleRegistry([
            [
                'name'        => 'Media',
                'module_type' => 'Core',
                'table_name'  => 'media',
            ],
        ]);

        try {
            $config = [
                'table_name' => 'item_images',
                'file_columns' => ['image_media_id'],
                'features' => [
                    'backend' => [
                        'list' => true,
                        'create' => [
                            'fields' => [
                                ['field' => 'name', 'rules' => 'required|string|max:255'],
                                ['field' => 'image_media_id', 'rules' => 'required|integer|exists:media,id'],
                            ],
                        ],
                        'view' => false,
                        'edit' => false,
                        'delete' => false,
                    ],
                    'frontend' => [],
                ],
            ];

            $generator = new PhpUnitTestGenerator('ItemImages', 'Core', $config);
            $this->assertTrue($generator->generate());

            $content = $this->generatedContentFor('Core', 'ItemImages');

            $this->assertMethodBodyContains(
                $content,
                'test_can_create_item_image',
                "\\Illuminate\\Http\\UploadedFile::fake()"
            );
            // Scoped to the create-test method body specifically: the
            // fixture helper (createItemImageFixture(), used by direct
            // Model::create() calls, not HTTP requests) legitimately DOES
            // emit the factory-create literal for this same column — that's
            // the ordinary, correct cross-module-FK behavior for a column
            // that bypasses HTTP validation entirely.
            $this->assertMethodBodyNotContains(
                $content,
                'test_can_create_item_image',
                "\\App\\Project\\Modules\\Core\\Media\\MediaModel::factory()->create()->id"
            );
            $this->assertMethodBodyNotContains($content, 'test_can_create_item_image', "'image_media_id' => 1,");
        } finally {
            PathManager::setModuleRegistry([]);
        }
    }

    /**
     * The broken exact-match response assertion this bug would otherwise
     * trade for `validation.file`: the backend replaces the uploaded file
     * with the newly created media row's id at runtime (see
     * BaseServiceGenerator::generateFileColumnUploads()), so
     * `->assertJsonPath('data.image_media_id', $payload['image_media_id'])`
     * can never hold for a file column — $payload['image_media_id'] is an
     * UploadedFile instance, never an id. The generated test must omit that
     * exact-match assertion for file columns and assert the conversion
     * happened (a real Media row exists for the returned id) instead —
     * assertIsInt() on the raw response value is itself broken for a
     * varchar-typed file column (e.g. expenses.receipt_path), which stores
     * MediaService::createFile()'s int return value as a numeric STRING —
     * see PhpUnitTestGenerator::buildCreateTestMethod()'s docblock.
     */
    public function test_file_column_response_assertion_is_adjusted_not_left_broken(): void
    {
        $config = [
            'table_name' => 'item_images',
            'file_columns' => ['image_media_id'],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'image_media_id', 'rules' => 'required|integer|exists:media,id'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'ItemImages');

        $this->assertMethodBodyContains(
            $content,
            'test_can_create_item_image',
            "\$this->assertNotNull(\\App\\Project\\Modules\\Core\\Media\\MediaModel::find((int) \$response->json('data.image_media_id')));"
        );
        $this->assertStringNotContainsString(
            "->assertJsonPath('data.image_media_id', \$payload['image_media_id'])",
            $content
        );
        $this->assertStringNotContainsString('assertIsInt', $content);
    }

    /**
     * The same assertIsInt() -> assertNotNull(MediaModel::find(...)) fix
     * must apply identically inside buildEditTestMethod() — a sibling code
     * path that was previously missed when only buildCreateTestMethod() got
     * fixed (the exact "fix one path, miss its sibling" failure mode this
     * generator has hit repeatedly). Also confirms the fix survives a
     * varchar-typed file column, not just an actual *_media_id integer FK:
     * the assertion must reference MediaModel::find() with an (int) cast
     * regardless of the column's own declared type, since
     * MediaService::createFile() always returns an int but a varchar column
     * round-trips it as a numeric string.
     */
    public function test_file_column_response_assertion_is_adjusted_in_edit_test_too(): void
    {
        $config = [
            'table_name' => 'expenses',
            'file_columns' => ['receipt_path'],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'expense_number', 'rules' => 'required|string|max:50'],
                            ['field' => 'receipt_path', 'rules' => 'nullable|file'],
                        ],
                    ],
                    'view' => false,
                    'edit' => [
                        'fields' => [
                            ['field' => 'expense_number', 'rules' => 'required|string|max:50'],
                            ['field' => 'receipt_path', 'rules' => 'nullable|file'],
                        ],
                    ],
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Expenses', 'Finance', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Finance', 'Expenses');

        $this->assertMethodBodyContains(
            $content,
            'test_can_create_expense',
            "\$this->assertNotNull(\\App\\Project\\Modules\\Core\\Media\\MediaModel::find((int) \$response->json('data.receipt_path')));"
        );
        $this->assertMethodBodyContains(
            $content,
            'test_can_edit_expense',
            "\$this->assertNotNull(\\App\\Project\\Modules\\Core\\Media\\MediaModel::find((int) \$response->json('data.receipt_path')));"
        );
        $this->assertStringNotContainsString('assertIsInt', $content);
    }

    /**
     * Non-file columns must be completely unaffected by the file_columns
     * check — an ordinary integer/FK column with no file_columns entry at
     * all still gets the pre-existing literal `1` treatment (or the
     * cross-module factory-create literal, covered by the tests above this
     * one), never a fake-upload literal.
     */
    public function test_non_file_columns_are_unaffected_by_the_file_column_check(): void
    {
        $config = [
            'table_name' => 'items',
            'file_columns' => [],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'quantity', 'rules' => 'required|integer'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Items');
        $content = $this->generatedContentFor('Core', 'Items');

        $this->assertMethodBodyContains($content, 'test_can_create_item', "'quantity' => 1,");
        $this->assertStringNotContainsString('UploadedFile::fake()', $content);
    }

    /**
     * Regression test for the remaining half of the file_columns bug,
     * confirmed by a live run: postJson()/putJson() JSON-encode the request
     * body, which destroys the UploadedFile instance entirely — and, being
     * array-valued, drags every SIBLING field down with it, since an
     * UploadedFile can't survive json_encode(). The live failure showed a
     * request with content-type: application/json, the upload gone, and a
     * completely unrelated field (item_id) failing validation.required as
     * collateral damage. A module carrying a file_columns entry must instead
     * issue a real multipart request via post() for its create test.
     */
    /**
     * Regression: a multipart request issued via post() sends no
     * `Accept: application/json` header, unlike postJson(). Laravel then
     * treats a validation failure as a browser request and REDIRECTS (302)
     * instead of returning a JSON 422 — so every generated validation test
     * on a file-carrying module failed with "Expected 422 but received 302"
     * while validation had in fact fired correctly. Found by running the
     * generated suite against a real app; the package's own unit tests were
     * green throughout. Every multipart call site must declare JSON
     * acceptance explicitly.
     */
    public function test_multipart_requests_declare_json_acceptance(): void
    {
        $generator = new \ReflectionClass(\Blutrixx\GeneratorEngine\Generators\Backend\Tests\PhpUnitTestGenerator::class);
        $source = file_get_contents($generator->getFileName());

        // Locate every generated `$this->post(` call site emitted by this generator.
        preg_match_all('/\\$this->post\\((.*?)\\)"/', $source, $matches);

        $this->assertNotEmpty($matches[0], 'Expected the generator to emit multipart post() call sites.');

        foreach ($matches[0] as $callSite) {
            $this->assertStringContainsString(
                "'Accept' => 'application/json'",
                $callSite,
                "Multipart call site is missing the Accept header and will 302 on validation failure: {$callSite}"
            );
        }
    }

    public function test_file_carrying_module_create_test_uses_post_not_post_json(): void
    {
        $config = [
            'table_name' => 'item_images',
            'file_columns' => ['image_media_id'],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'image_media_id', 'rules' => 'required|integer|exists:media,id'],
                            ['field' => 'is_primary', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'ItemImages');
        $content = $this->generatedContentFor('Core', 'ItemImages');

        $this->assertMethodBodyContains($content, 'test_can_create_item_image', "\$this->post('/api/item-images/create', \$payload, ['Accept' => 'application/json'])");
        $this->assertMethodBodyNotContains($content, 'test_can_create_item_image', 'postJson(');
    }

    /**
     * Edit-side counterpart. The generated route for edit is registered as
     * PUT (see the module's own generated Routes/api.php), but PHP never
     * populates $_FILES for a PUT request regardless of framework — a real
     * multipart body can only be sent via POST. This mirrors
     * BaseComponentGenerator::generateSubmitCall()'s frontend fix (v2.10.17)
     * exactly: POST the multipart body with a `_method: 'PUT'` override
     * field, which Laravel's method-override spoofing (active for every
     * request, including in-process test requests, via
     * Request::enableHttpMethodParameterOverride()) resolves back to the
     * registered PUT route — the same mechanism Blade's @method('PUT')
     * directive relies on for native HTML file-upload forms.
     */
    public function test_file_carrying_module_edit_test_routes_to_put_via_method_override(): void
    {
        $config = [
            'table_name' => 'item_images',
            'file_columns' => ['image_media_id'],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'image_media_id', 'rules' => 'required|integer|exists:media,id'],
                            ['field' => 'is_primary', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'view' => false,
                    'edit' => [
                        'fields' => [
                            ['field' => 'image_media_id', 'rules' => 'required|integer|exists:media,id'],
                            ['field' => 'is_primary', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'ItemImages');
        $content = $this->generatedContentFor('Core', 'ItemImages');

        $this->assertMethodBodyContains(
            $content,
            'test_can_edit_item_image',
            "\$this->post(\"/api/item-images/{\$fixture->uuid}/edit\", \$payload + ['_method' => 'PUT'], ['Accept' => 'application/json'])"
        );
        $this->assertMethodBodyNotContains($content, 'test_can_edit_item_image', 'putJson(');
    }

    /**
     * The boolean/string requirement mirrors the frontend's own
     * generateSubmitCall() multipart fix (v2.10.17): "Booleans must be sent
     * as 1/0 in FormData" (see SYSTEM_SHELL's own module-pattern docs). A
     * real multipart request carries every field as a string, so a raw PHP
     * `true` literal in the payload array is never what the underlying HTTP
     * layer actually transmits. Only the multipart (file-carrying) path
     * needs this — the plain postJson()/putJson() path already worked
     * correctly with a native boolean and must stay untouched (see the
     * non-file-column test below), and the fixture helper's direct
     * Model::create() call must still receive a real boolean regardless.
     */
    public function test_file_carrying_module_converts_booleans_to_strings_in_the_multipart_payload(): void
    {
        $config = [
            'table_name' => 'item_images',
            'file_columns' => ['image_media_id'],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'image_media_id', 'rules' => 'required|integer|exists:media,id'],
                            ['field' => 'is_primary', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'ItemImages');

        $this->assertMethodBodyContains($content, 'test_can_create_item_image', "'is_primary' => '1',");
        $this->assertMethodBodyNotContains($content, 'test_can_create_item_image', "'is_primary' => true,");

        // The fixture helper bypasses HTTP entirely (direct Model::create()),
        // so it must still receive a real PHP boolean, not the multipart
        // string conversion.
        $this->assertMethodBodyContains($content, 'createItemImageFixture', "'is_primary' => true,");
    }

    /**
     * Byte-for-byte regression guard: a module with NO file_columns entry at
     * all must keep emitting postJson()/putJson() exactly as before this fix
     * — the multipart/post()/_method-override machinery must be fully inert
     * for it, and its boolean literal must stay a native `true`, not the
     * multipart-only '1' string conversion.
     */
    public function test_module_without_file_column_still_uses_post_json_and_put_json(): void
    {
        $config = [
            'table_name' => 'items',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'is_active', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'view' => false,
                    'edit' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'is_active', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Items');
        $content = $this->generatedContentFor('Core', 'Items');

        $this->assertMethodBodyContains($content, 'test_can_create_item', "\$this->postJson('/api/items/create', \$payload)");
        $this->assertMethodBodyContains($content, 'test_can_edit_item', '$this->putJson("/api/items/{$fixture->uuid}/edit", $payload)');
        $this->assertMethodBodyContains($content, 'test_can_create_item', "'is_active' => true,");
        $this->assertStringNotContainsString('_method', $content);
        $this->assertStringNotContainsString("\$this->post(", $content);
    }

    // ─── Mandatory byte-for-byte regression (no enabled backend feature) ──

    /**
     * The strictest form of the "must not change output for a module that
     * doesn't opt into any of the new coverage" guarantee: a module with
     * ZERO enabled backend CRUD features generates ONLY the three
     * pipeline-unconditional methods (fixture helper + deleteCheck +
     * activity + filter), because every gap 1-10 method AND every route-family
     * 2-7 method (export/import-template/bulk/actions/delegations/splash) is
     * gated on at least one CRUD feature or its own dedicated config flag
     * being enabled/present — none of which this config sets. Compared with
     * assertSame() against the exact literal output (not merely
     * assertStringNotContainsString for each new method individually), so
     * any accidental unconditional addition to generate() — not just the
     * ones anticipated here — trips this test.
     *
     * The activity-history method IS present in the expected literal below
     * (unlike every gap/route-family method) because RoutesGenerator emits
     * that route completely unconditionally for every module regardless of
     * config — see buildActivityTestMethod()'s docblock — exactly like the
     * pre-existing deleteCheck/filter coverage above it. Route families
     * 2-7 (export, import, bulk actions, custom actions, delegations,
     * splash) all remain absent here, proving each one's own emission gate
     * (mirroring RoutesGenerator's exact condition) is truly conditional.
     */
    public function test_generate_is_byte_for_byte_unchanged_for_a_module_with_no_enabled_backend_features(): void
    {
        $config = [
            'table_name' => 'widgets',
            'has_soft_deletes' => false,
            'has_creator_updater' => false,
            'features' => [
                'backend' => [
                    'list' => false,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
            'columns' => [],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Widgets');

        // Zero enabled CRUD features means only the base class plus the
        // two still-unconditional split files get written — every other
        // *ServiceTest is gated on a CRUD feature or its own config flag
        // (see generate()'s docblock) and stays entirely absent, not just
        // empty. Separate assertSame() calls, one per file, replace
        // the old single-file byte-for-byte comparison — any accidental
        // unconditional addition to any one of them still trips this test,
        // exactly as before, just scoped to the file it would land in.
        //
        // DeleteCheck used to be listed here as a third unconditional file.
        // It is not unconditional: it rides on `delete`, which is disabled in
        // this config, and generating it produced a test asserting 200 against
        // a /{uuid}/delete/check route the router never registered.
        $dir = PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests';
        $this->assertSame(
            ['WidgetsActivityListServiceTest.php', 'WidgetsListServiceTest.php', 'WidgetsTestCase.php'],
            array_map('basename', $this->generatedTestFiles('Core', 'Widgets')),
            'Expected exactly these three files for a module with zero enabled CRUD features.'
        );

        $expectedTestCase = <<<PHP
<?php

namespace App\Project\Modules\Core\Widgets\Tests;

use App\Project\Modules\Core\Widgets\WidgetsModel;
use App\Project\Modules\Core\Users\Users\UsersModel;
use Laravel\Sanctum\Sanctum;
use Tests\Support\ActsWithoutPermission;
use Tests\TestCase;
// [generator:region:hand-imports:start]
// [generator:region:hand-imports:end]

/**
 * Shared base for every generated Widgets test class.
 *
 * Regenerated freely — it tracks the module's own schema via the fixture builder
 * below — except the hand-fixtures / hand-imports regions below, which survive
 * every --force verbatim (generator-engine v3.5.21+). Everything else in this
 * file is NOT protected the way those regions are.
 */
abstract class WidgetsTestCase extends TestCase
{
    use ActsWithoutPermission;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(UsersModel::find(UsersModel::DEVELOPER));
    }

    protected function createWidgetFixture(array \$overrides = []): WidgetsModel
    {
        return WidgetsModel::create(array_merge([
        ], \$overrides))->fresh();
    }

    // [generator:region:hand-fixtures:start]
    // [generator:region:hand-fixtures:end]
}

PHP;
        $this->assertSame($expectedTestCase, (string) file_get_contents("{$dir}/WidgetsTestCase.php"));

        // No DeleteCheck file: `delete` is disabled in this config, so the
        // /{uuid}/delete/check route is never generated and there is nothing
        // to cover. This assertion used to be a byte-for-byte comparison
        // against a generated WidgetsDeleteCheckServiceTest.php, which is
        // precisely the file that asserted 200 against a non-existent route.
        $this->assertFileDoesNotExist("{$dir}/WidgetsDeleteCheckServiceTest.php");

        $expectedActivity = <<<PHP
<?php

namespace App\Project\Modules\Core\Widgets\Tests;

use App\Project\Modules\Core\Widgets\WidgetsModel;
use App\Project\Modules\Core\Users\Users\UsersModel;

class WidgetsActivityListServiceTest extends WidgetsTestCase
{
    public function test_can_view_widget_activity_history(): void
    {
        \$fixture = \$this->createWidgetFixture();

        \$response = \$this->getJson("/api/widgets/{\$fixture->uuid}/activity");

        \$response->assertStatus(200)
            ->assertJson(['status' => true]);
    }
}

PHP;
        $this->assertSame($expectedActivity, (string) file_get_contents("{$dir}/WidgetsActivityListServiceTest.php"));

        $expectedList = <<<PHP
<?php

namespace App\Project\Modules\Core\Widgets\Tests;

use App\Project\Modules\Core\Widgets\WidgetsModel;
use App\Project\Modules\Core\Users\Users\UsersModel;

class WidgetsListServiceTest extends WidgetsTestCase
{
    public function test_can_filter_widgets_list_by_id(): void
    {
        \$fixture = \$this->createWidgetFixture();

        \$response = \$this->getJson('/api/widgets/list?' . http_build_query([
            'filters' => [
                'id' => ['operator' => 'eq', 'value' => \$fixture->id],
            ],
        ]));

        \$response->assertStatus(200)
            ->assertJson(['status' => true]);

        \$values = collect(\$response->json('data.data'))->pluck('id');
        \$this->assertTrue(\$values->contains(\$fixture->id));
    }
}

PHP;
        $this->assertSame($expectedList, (string) file_get_contents("{$dir}/WidgetsListServiceTest.php"));
    }

    /**
     * Broader companion to the strict zero-feature regression above: a
     * module with every CRUD feature enabled (so gap 1's authorization
     * coverage, and gap 3b's overlength-on-`max:` coverage, both correctly
     * fire — neither is gated on the six schema-shape flags below) but NONE
     * of no soft deletes / no unique column / no FK column / no file column
     * / no enum column / no audit columns must still omit every gap 2-10
     * method that IS gated on one of those flags. This is the test that
     * proves those six gates are truly conditional, independent of gap 1's
     * necessarily broader "any CRUD feature enabled" scope (see this
     * class's own top-of-file note and the task summary for why a single
     * literal byte-identical assertion can't cover both at once).
     */
    public function test_generate_omits_every_schema_shape_gated_method_for_a_module_with_none_of_those_shapes(): void
    {
        $config = [
            'table_name' => 'widgets',
            'has_soft_deletes' => false,
            'has_creator_updater' => false,
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                        ],
                    ],
                    'view' => true,
                    'edit' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                        ],
                    ],
                    'delete' => true,
                ],
                'frontend' => [],
            ],
            'columns' => [
                ['name' => 'name', 'type' => 'string'],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Widgets');
        $content = $this->generatedContentFor('Core', 'Widgets');

        // Gap 1 (feature-gated, not schema-gated) correctly fires.
        $this->assertStringContainsString('function test_cannot_list_widgets_without_permission(', $content);
        $this->assertStringContainsString('function test_cannot_create_widget_without_permission(', $content);
        $this->assertStringContainsString('function test_cannot_view_widget_without_permission(', $content);
        $this->assertStringContainsString('function test_cannot_edit_widget_without_permission(', $content);
        $this->assertStringContainsString('function test_cannot_delete_widget_without_permission(', $content);

        // Gap 3b (every string field carries an implicit max:255 unless
        // explicitly nullable, so this is realistically almost always
        // present too — it is gated on the field shape, not the six
        // module-level flags this test is about) correctly fires.
        $this->assertStringContainsString('function test_create_widget_validation_fails_with_overlength_name(', $content);

        // Every gap gated on one of the six excluded schema shapes must be
        // absent: no unique column (gap 3a), no FK column (gap 3c), no
        // enum column (gap 10), no decimal/scale column (gap 9), no soft
        // deletes (gap 5), no file column (gap 6), no audit columns (gap 4).
        $this->assertStringNotContainsString('validation_fails_with_duplicate_', $content);
        $this->assertStringNotContainsString('validation_fails_with_nonexistent_', $content);
        $this->assertStringNotContainsString('_enum_value(', $content);
        $this->assertStringNotContainsString('decimal_precision_intact', $content);
        $this->assertStringNotContainsString('is_excluded_from_widgets_list', $content);
        $this->assertStringNotContainsString('without_reuploading_', $content);
        // Scoped to the create/edit method bodies — the fixture helper's own
        // 'created_by_id' => UsersModel::DEVELOPER line is a separate,
        // pre-existing, unconditional field this gap must not be judged
        // against.
        $this->assertMethodBodyNotContains($content, 'test_can_create_widget', 'data.created_by_id');
        $this->assertMethodBodyNotContains($content, 'test_can_edit_widget', 'data.updated_by_id');
    }

    // ─── Gap 1: authorization coverage ─────────────────────────────────────

    public function test_generate_emits_one_forbidden_test_per_enabled_crud_feature_sharing_one_helper(): void
    {
        $config = $this->locationTypesConfig();

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        // actingAsUserWithoutPermission() itself is no longer emitted per
        // module — it moved to the hand-maintained, one-time
        // Tests\Support\ActsWithoutPermission trait (SYSTEM_SHELL/BACKEND/
        // tests/Support/), since it carries no module-specific state and
        // used to be duplicated near-identically into every module's own
        // file. The shared base class must `use` it instead of redeclaring it.
        $this->assertStringContainsString('use Tests\Support\ActsWithoutPermission;', $content);
        $this->assertStringContainsString('use ActsWithoutPermission;', $content);
        $this->assertStringNotContainsString('private function actingAsUserWithoutPermission(', $content);

        foreach (['list', 'create', 'view', 'edit', 'delete'] as $feature) {
            $method = "test_cannot_{$feature}_" . ($feature === 'list' ? 'location_types' : 'location_type') . '_without_permission';
            $this->assertStringContainsString("function {$method}(", $content, "Expected forbidden coverage for '{$feature}'.");
            $this->assertMethodBodyContains($content, $method, '$this->actingAsUserWithoutPermission();');
            $this->assertMethodBodyContains($content, $method, 'assertStatus(403);');
        }
    }

    public function test_generate_omits_forbidden_test_for_a_feature_that_is_disabled(): void
    {
        $config = $this->locationTypesConfig();
        $config['features']['backend']['delete'] = false;

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertStringNotContainsString('function test_cannot_delete_location_type_without_permission(', $content);
        // The other four features stay covered.
        $this->assertStringContainsString('function test_cannot_list_location_types_without_permission(', $content);
        $this->assertStringContainsString('function test_cannot_create_location_type_without_permission(', $content);
        $this->assertStringContainsString('function test_cannot_view_location_type_without_permission(', $content);
        $this->assertStringContainsString('function test_cannot_edit_location_type_without_permission(', $content);
    }

    // ─── Gap 3: extra validation coverage ──────────────────────────────────

    public function test_generate_emits_duplicate_and_overlength_validation_tests_for_a_unique_max_constrained_field(): void
    {
        $config = $this->locationTypesConfig();

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertMethodBodyContains($content, 'test_create_location_type_validation_fails_with_duplicate_name', "\$payload['name'] = \$fixture->name;");
        $this->assertMethodBodyContains($content, 'test_create_location_type_validation_fails_with_duplicate_name', 'assertStatus(422)');

        $this->assertMethodBodyContains($content, 'test_create_location_type_validation_fails_with_overlength_name', "str_repeat('a', 256)");
        $this->assertMethodBodyContains($content, 'test_create_location_type_validation_fails_with_overlength_name', 'assertStatus(422)');
    }

    public function test_generate_omits_duplicate_and_overlength_tests_when_no_field_carries_those_rules(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'quantity', 'rules' => 'required|integer'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'Widgets');

        $this->assertStringNotContainsString('validation_fails_with_duplicate_', $content);
        $this->assertStringNotContainsString('validation_fails_with_overlength_', $content);
    }

    public function test_generate_emits_nonexistent_fk_validation_test_for_a_required_cross_module_fk(): void
    {
        $config = [
            'table_name' => 'items',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'item_type_id', 'rules' => 'required|integer|exists:item_types,id'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Items');
        $content = $this->generatedContentFor('Core', 'Items');

        $this->assertMethodBodyContains(
            $content,
            'test_create_item_validation_fails_with_nonexistent_item_type_id',
            "\$payload['item_type_id'] = 999999999;"
        );
        $this->assertMethodBodyContains(
            $content,
            'test_create_item_validation_fails_with_nonexistent_item_type_id',
            'assertStatus(422)'
        );
    }

    public function test_generate_omits_nonexistent_fk_validation_test_for_a_nullable_self_referential_fk(): void
    {
        $config = [
            'table_name' => 'categories',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'parent_id', 'rules' => 'nullable|integer|exists:categories,id'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Categories', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'Categories');

        $this->assertStringNotContainsString('validation_fails_with_nonexistent_', $content);
    }

    // ─── Gap 4: audit-column coverage ──────────────────────────────────────

    public function test_generate_asserts_audit_columns_when_module_has_creator_updater(): void
    {
        $config = $this->locationTypesConfig();
        // LocationTypesModule.json carries no explicit has_creator_updater
        // key, so ModuleConfigContract::hasCreatorUpdater() already defaults
        // it to true — asserted explicitly here so this test documents that
        // assumption rather than relying on it silently.
        $this->assertArrayNotHasKey('has_creator_updater', $config);

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertMethodBodyContains($content, 'test_can_create_location_type', "->assertJsonPath('data.created_by_id', (int) UsersModel::DEVELOPER)");
        $this->assertMethodBodyContains($content, 'test_can_edit_location_type', "->assertJsonPath('data.updated_by_id', (int) UsersModel::DEVELOPER)");
    }

    public function test_generate_omits_audit_column_assertions_when_module_has_no_creator_updater(): void
    {
        $config = $this->locationTypesConfig();
        $config['has_creator_updater'] = false;

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        // Scoped to the two methods' bodies specifically — the fixture
        // helper's own 'created_by_id' => UsersModel::DEVELOPER line is a
        // separate, pre-existing, UNCONDITIONAL field (not this gap's
        // concern) that a whole-file assertStringNotContainsString would
        // wrongly trip on.
        $this->assertMethodBodyNotContains($content, 'test_can_create_location_type', 'data.created_by_id');
        $this->assertMethodBodyNotContains($content, 'test_can_edit_location_type', 'data.updated_by_id');
    }

    // ─── Gap 5: soft-deleted row excluded from list ────────────────────────

    public function test_generate_emits_soft_delete_list_exclusion_test_when_soft_deletes_delete_and_list_are_all_enabled(): void
    {
        $config = $this->locationTypesConfig();
        $config['has_soft_deletes'] = true;

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertStringContainsString('function test_soft_deleted_location_type_is_excluded_from_location_types_list(', $content);
        $this->assertMethodBodyContains(
            $content,
            'test_soft_deleted_location_type_is_excluded_from_location_types_list',
            'assertFalse($recordKeys->contains($fixture->uuid));'
        );
    }

    public function test_generate_omits_soft_delete_list_exclusion_test_when_module_has_no_soft_deletes(): void
    {
        // locationTypesConfig() has no has_soft_deletes key at all, and
        // ModuleConfigContract::hasSoftDeletes() defaults an absent key to
        // false, so the un-mutated fixture already covers this case.
        $config = $this->locationTypesConfig();

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertStringNotContainsString('is_excluded_from_location_types_list', $content);
    }

    // ─── buildDeleteTestMethod()'s own assertion — assertSoftDeleted() vs
    // assertDatabaseMissing() gated on hasSoftDeletes (2026-07-30) ─────────
    //
    // Bug: this always emitted `assertSoftDeleted(...)` unconditionally,
    // regardless of $this->hasSoftDeletes (which the class already computes
    // and uses elsewhere -- see the list-exclusion tests above). Found live:
    // a module with no soft deletes generated a delete test that fatals with
    // "Unknown column 'deleted_at'" the moment it runs, since
    // assertSoftDeleted() queries for a column that doesn't exist on that
    // module's table at all.

    public function test_delete_test_asserts_soft_deleted_when_module_has_soft_deletes(): void
    {
        $config = $this->locationTypesConfig();
        $config['has_soft_deletes'] = true;

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertMethodBodyContains($content, 'test_can_delete_location_type', "assertSoftDeleted('location_types'");
        $this->assertMethodBodyNotContains($content, 'test_can_delete_location_type', 'assertDatabaseMissing(');
    }

    public function test_delete_test_asserts_database_missing_when_module_has_no_soft_deletes(): void
    {
        // locationTypesConfig() has no has_soft_deletes key at all, and
        // ModuleConfigContract::hasSoftDeletes() defaults an absent key to
        // false, so the un-mutated fixture already covers this case.
        $config = $this->locationTypesConfig();

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertMethodBodyContains($content, 'test_can_delete_location_type', "assertDatabaseMissing('location_types'");
        $this->assertMethodBodyNotContains($content, 'test_can_delete_location_type', 'assertSoftDeleted(');
    }

    // ─── Gap 6: file-edit-without-reupload coverage ────────────────────────

    public function test_generate_emits_file_edit_without_reupload_test_when_edit_has_a_file_and_a_non_file_field(): void
    {
        $config = [
            'table_name' => 'item_images',
            'file_columns' => ['image_media_id'],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'image_media_id', 'rules' => 'required|integer|exists:media,id'],
                            ['field' => 'is_primary', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'view' => false,
                    'edit' => [
                        'fields' => [
                            ['field' => 'image_media_id', 'rules' => 'nullable|integer|exists:media,id'],
                            ['field' => 'is_primary', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'ItemImages');
        $content = $this->generatedContentFor('Core', 'ItemImages');

        $this->assertStringContainsString('function test_can_edit_item_image_without_reuploading_image_media_id(', $content);
        $this->assertMethodBodyContains($content, 'test_can_edit_item_image_without_reuploading_image_media_id', '$originalFileValue = $fixture->image_media_id;');
        $this->assertMethodBodyContains($content, 'test_can_edit_item_image_without_reuploading_image_media_id', "\$this->putJson(\"/api/item-images/{\$fixture->uuid}/edit\", \$payload)");
        $this->assertMethodBodyNotContains($content, 'test_can_edit_item_image_without_reuploading_image_media_id', "'image_media_id' =>");
    }

    public function test_generate_omits_file_edit_without_reupload_test_when_module_has_no_file_column(): void
    {
        $config = [
            'table_name' => 'items',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                        ],
                    ],
                    'view' => false,
                    'edit' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                        ],
                    ],
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'Items');

        $this->assertStringNotContainsString('without_reuploading_', $content);
    }

    // ─── Gap 9: decimal precision/scale round-trip coverage ───────────────

    public function test_generate_emits_decimal_precision_test_when_a_create_field_column_carries_scale(): void
    {
        $config = [
            'table_name' => 'item_prices',
            'columns' => [
                ['name' => 'amount', 'type' => 'decimal', 'precision' => 10, 'scale' => 2],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'amount', 'rules' => 'required|numeric'],
                        ],
                    ],
                    'view' => true,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('ItemPrices', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'ItemPrices');
        $content = $this->generatedContentFor('Core', 'ItemPrices');

        $this->assertStringContainsString('function test_can_create_and_view_item_price_with_decimal_precision_intact(', $content);
        $this->assertMethodBodyContains($content, 'test_can_create_and_view_item_price_with_decimal_precision_intact', "\$payload['amount'] = '1.55';");
        $this->assertMethodBodyContains($content, 'test_can_create_and_view_item_price_with_decimal_precision_intact', 'assertEqualsWithDelta(1.55,');
    }

    public function test_generate_omits_decimal_precision_test_when_no_column_carries_scale(): void
    {
        $config = [
            'table_name' => 'items',
            'columns' => [
                ['name' => 'name', 'type' => 'string'],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'Items');

        $this->assertStringNotContainsString('decimal_precision_intact', $content);
    }

    // ─── Gap 10: enum validation coverage ──────────────────────────────────

    public function test_generate_emits_accept_and_reject_enum_tests_when_a_create_field_column_carries_enum_values(): void
    {
        $config = [
            'table_name' => 'orders',
            'columns' => [
                ['name' => 'status', 'type' => 'enum', 'enum_values' => ['pending', 'active', 'cancelled']],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'status', 'rules' => 'required|string'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Orders', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Orders');
        $content = $this->generatedContentFor('Core', 'Orders');

        $this->assertStringContainsString('function test_create_order_accepts_a_valid_status_enum_value(', $content);
        $this->assertMethodBodyContains($content, 'test_create_order_accepts_a_valid_status_enum_value', "\$payload['status'] = 'pending';");
        $this->assertMethodBodyContains($content, 'test_create_order_accepts_a_valid_status_enum_value', 'assertStatus(201);');

        $this->assertStringContainsString('function test_create_order_rejects_an_invalid_status_enum_value(', $content);
        $this->assertMethodBodyContains($content, 'test_create_order_rejects_an_invalid_status_enum_value', 'assertStatus(422)');
    }

    public function test_generate_omits_enum_tests_when_no_column_carries_enum_values(): void
    {
        $config = [
            'table_name' => 'orders',
            'columns' => [
                ['name' => 'status', 'type' => 'string'],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'status', 'rules' => 'required|string|max:255'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Orders', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'Orders');

        $this->assertStringNotContainsString('_enum_value(', $content);
    }

    // ─── Route family 1: activity-history coverage ─────────────────────────

    public function test_generate_emits_activity_history_test_unconditionally(): void
    {
        // Zero enabled backend CRUD features — activity coverage must still
        // fire, since RoutesGenerator emits that route unconditionally.
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => false,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Widgets');
        $content = $this->generatedContentFor('Core', 'Widgets');

        $this->assertStringContainsString('function test_can_view_widget_activity_history(', $content);
        $this->assertMethodBodyContains($content, 'test_can_view_widget_activity_history', '/api/widgets/{$fixture->uuid}/activity');
        $this->assertMethodBodyContains($content, 'test_can_view_widget_activity_history', 'assertStatus(200)');
    }

    // ─── Route family 2: export coverage ────────────────────────────────────

    public function test_generate_emits_export_test_when_list_export_is_enabled(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => ['export' => true],
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Widgets');
        $content = $this->generatedContentFor('Core', 'Widgets');

        $this->assertStringContainsString('function test_can_export_widgets_list(', $content);
        $this->assertMethodBodyContains($content, 'test_can_export_widgets_list', '/api/widgets/list/export');
        $this->assertMethodBodyContains($content, 'test_can_export_widgets_list', 'assertStatus(200)');
    }

    public function test_generate_omits_export_test_when_list_export_is_not_enabled(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'Widgets');

        $this->assertStringNotContainsString('function test_can_export_widgets_list(', $content);
        $this->assertStringNotContainsString('list/export', $content);
    }

    // ─── Route family 3: import-template coverage ──────────────────────────

    public function test_generate_emits_import_template_test_when_list_import_is_enabled(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => ['import' => true],
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Widgets');
        $content = $this->generatedContentFor('Core', 'Widgets');

        $this->assertStringContainsString('function test_can_download_widgets_import_template(', $content);
        $this->assertMethodBodyContains($content, 'test_can_download_widgets_import_template', '/api/widgets/import/template');
        $this->assertMethodBodyContains($content, 'test_can_download_widgets_import_template', 'assertStatus(200)');

        // The sibling file-upload POST /widgets/import route is covered too
        // — both halves of the import feature are emitted under the same
        // `features.backend.list.import` flag (see buildImportFileUploadTestMethod()).
        $this->assertStringContainsString('function test_can_import_widgets_via_uploaded_csv(', $content);
        $this->assertMethodBodyContains($content, 'test_can_import_widgets_via_uploaded_csv', '/api/widgets/import');
        $this->assertMethodBodyContains($content, 'test_can_import_widgets_via_uploaded_csv', "assertJsonPath('data.succeeded_count', 1)");
    }

    public function test_generate_omits_import_template_test_when_list_import_is_not_enabled(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'Widgets');

        $this->assertStringNotContainsString('function test_can_download_widgets_import_template(', $content);
        $this->assertStringNotContainsString('import/template', $content);
        $this->assertStringNotContainsString('function test_can_import_widgets_via_uploaded_csv(', $content);
    }

    // ─── Route family 7: createSplash/editSplash coverage ─────────────────

    public function test_generate_emits_splash_tests_when_constants_are_declared_and_splash_features_enabled(): void
    {
        $config = [
            'table_name' => 'widgets',
            'constants' => ['SOME_CONSTANT' => 1],
            'features' => [
                'backend' => [
                    'list' => false,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                    'createSplash' => ['splashData' => []],
                    'editSplash' => ['splashData' => []],
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Widgets');
        $content = $this->generatedContentFor('Core', 'Widgets');

        $this->assertStringContainsString('function test_can_view_widget_create_splash(', $content);
        $this->assertMethodBodyContains($content, 'test_can_view_widget_create_splash', '/api/widgets/create/splash');
        $this->assertMethodBodyContains($content, 'test_can_view_widget_create_splash', 'assertStatus(200)');

        $this->assertStringContainsString('function test_can_view_widget_edit_splash(', $content);
        $this->assertMethodBodyContains($content, 'test_can_view_widget_edit_splash', '/api/widgets/edit/splash');
        $this->assertMethodBodyContains($content, 'test_can_view_widget_edit_splash', 'assertStatus(200)');
    }

    public function test_generate_omits_splash_tests_when_constants_are_declared_but_splash_features_are_not_enabled(): void
    {
        // constants present, but neither createSplash nor editSplash key is
        // set in features.backend — RoutesGenerator requires BOTH the
        // constants gate AND the feature's own key to be present.
        $config = [
            'table_name' => 'widgets',
            'constants' => ['SOME_CONSTANT' => 1],
            'features' => [
                'backend' => [
                    'list' => false,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'Widgets');

        $this->assertStringNotContainsString('create_splash', $content);
        $this->assertStringNotContainsString('edit_splash', $content);
        $this->assertStringNotContainsString('/splash', $content);
    }

    public function test_generate_omits_splash_tests_when_splash_features_enabled_but_no_constants_declared(): void
    {
        // features.backend.createSplash/editSplash both set, but no
        // 'constants' key at all — RoutesGenerator's $hasSplash gate is
        // false, so no splash route (and therefore no splash test) exists.
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => false,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                    'createSplash' => ['splashData' => []],
                    'editSplash' => ['splashData' => []],
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'Widgets');

        $this->assertStringNotContainsString('create_splash', $content);
        $this->assertStringNotContainsString('edit_splash', $content);
        $this->assertStringNotContainsString('/splash', $content);
    }

    // ─── Route families 5-6: actions[] contract + delegation coverage ─────

    /**
     * Custom actions[] emit an explicit "Add your custom logic here" TODO
     * service body (Features/action/service.stub) that unconditionally
     * returns a 200 regardless of $data/urlParams — no real behavioral
     * assertion is possible, but the permission gate (403 without it) and
     * "never a hard 5xx" contract ARE guaranteed by the framework/routing
     * layer today, independent of that placeholder body (see
     * buildActionContractTestMethods()'s docblock). Delegation `list` needs
     * no related-module field-shape knowledge at all (see
     * buildDelegationTestMethodsFor()'s docblock), so it's always emitted
     * when enabled. Confirmed here with a config that declares both
     * alongside a bulk_actions entry (to prove bulk-action coverage and
     * these newer families coexist correctly for the same module).
     */
    public function test_generate_emits_action_contract_and_delegation_list_tests(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => [
                        'bulk_actions' => [
                            ['key' => 'activate', 'status_target' => 'ACTIVE'],
                        ],
                    ],
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
            'actions' => [
                'quickView' => [
                    'name' => 'quickView',
                    'operations' => ['view' => ['enabled' => true]],
                ],
            ],
            'delegations' => [
                'items' => [
                    'name' => 'items',
                    'operations' => ['list' => ['enabled' => true]],
                ],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Widgets');
        $content = $this->generatedContentFor('Core', 'Widgets');

        // actions[] contract coverage: permission-gated + non-5xx, no
        // behavioral assertion about the placeholder body.
        $this->assertStringContainsString('function test_can_invoke_the_quick_view_action_with_permission(', $content);
        $this->assertStringContainsString('function test_cannot_invoke_the_quick_view_action_without_permission(', $content);
        $this->assertMethodBodyContains($content, 'test_can_invoke_the_quick_view_action_with_permission', "/api/widgets/quick-view/view");
        $this->assertMethodBodyContains($content, 'test_cannot_invoke_the_quick_view_action_without_permission', 'assertStatus(403)');

        // Delegation list coverage.
        $this->assertStringContainsString('function test_can_list_items_delegation(', $content);
        $this->assertMethodBodyContains($content, 'test_can_list_items_delegation', '/items/list');

        // Bulk-action coverage IS present: this config's only bulk_actions
        // entry carries a status_target, so firstGenericBulkActionKey()
        // finds nothing safe to invoke — the ids-mode/filter-mode happy-path
        // tests are correctly absent — but the config-independent
        // validation/403 tests are still emitted, since they're gated on
        // $hasList alone.
        $this->assertStringContainsString('/api/widgets/bulk-action', $content);
        $this->assertStringContainsString('function test_bulk_action_validation_fails_with_missing_ids(', $content);
        $this->assertStringContainsString('function test_cannot_bulk_action_without_permission(', $content);
        $this->assertStringNotContainsString('function test_bulk_action_processes_a_valid_uuid_list_in_ids_mode(', $content);
        $this->assertStringNotContainsString('function test_bulk_action_processes_all_records_in_filter_mode_with_an_empty_filter(', $content);
    }

    /**
     * Negative counterpart: an actions[] entry with urlParams other than the
     * single `['uuid']` shape must NOT get a contract test — this generator
     * has no way to independently verify a hand-rolled multi/non-uuid path
     * is correct (see buildActionServiceTestMethodsForKey()'s docblock).
     */
    public function test_generate_omits_action_contract_test_when_route_shape_is_customized(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => false,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
            'actions' => [
                'archiveByYear' => [
                    'name' => 'archiveByYear',
                    'urlParams' => ['year'],
                    'operations' => ['view' => ['enabled' => true]],
                ],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'Widgets');

        $this->assertStringNotContainsString('archive_by_year', $content);
        $this->assertStringNotContainsString('ArchiveByYear', $content);
    }

    /**
     * The single most common real-world shape for a single-row custom
     * action — `urlParams: ['uuid']` plus a custom `endpoint.path` that
     * embeds `{uuid}` (e.g. an "Approve" action on one Purchase Order) —
     * MUST still get contract coverage: this is the shape virtually every
     * real single-row action uses (it has to address one record somehow),
     * so bailing out here left almost no custom action with any backend
     * test at all. Found live via generator-engine's actions-suite
     * integration fixture (2026-08-08) — the previous version of this
     * method returned [] unconditionally whenever urlParams was non-empty.
     */
    public function test_generate_emits_action_contract_test_for_uuid_parametrized_route(): void
    {
        $config = [
            'table_name' => 'purchase_orders',
            'features' => [
                'backend' => [
                    'list' => false,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
            'actions' => [
                'approve' => [
                    'name' => 'approve',
                    'urlParams' => ['uuid'],
                    'operations' => [
                        'create' => [
                            'enabled' => true,
                            'endpoint' => ['method' => 'POST', 'path' => '/purchase-orders/{uuid}/approve'],
                        ],
                    ],
                ],
            ],
        ];

        $generator = new PhpUnitTestGenerator('PurchaseOrders', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Custom', 'PurchaseOrders');
        $content = $this->generatedContentFor('Custom', 'PurchaseOrders');

        $this->assertStringContainsString('function test_can_invoke_the_approve_action_with_permission(', $content);
        $this->assertStringContainsString('function test_cannot_invoke_the_approve_action_without_permission(', $content);
        $this->assertMethodBodyContains($content, 'test_can_invoke_the_approve_action_with_permission', '$fixture = $this->createPurchaseOrderFixture();');
        $this->assertMethodBodyContains($content, 'test_can_invoke_the_approve_action_with_permission', "'/api/purchase-orders/' . \$fixture->uuid . '/approve'");
    }

    /**
     * Delegation create/edit/view/delete coverage additionally requires the
     * related module to resolve to a real model FQCN (via PathManager's
     * registry) — here it can't (no such module was ever scaffolded in this
     * test's registry), so only the field-shape-independent `list` test is
     * emitted.
     */
    /**
     * Found live building Payments' generated test suite once create/edit
     * were disabled (Step 9 CRUD-selectivity, 2026-08-19): fieldsSource()'s
     * fallback branch (no create/edit fields to read validation rules from)
     * hardcoded 'required|string' for EVERY column regardless of its real
     * type — a decimal 'amount' column got the generic 'Test Field '.uniqid()
     * string literal, which then failed at the DATABASE level inserting into
     * a numeric column (the fixture bypasses validation via a direct
     * Model::create() call, so this wasn't even a 422 — a hard SQL error).
     * Same bug class as v3.1.6's datetime fix, different type category.
     */
    public function test_generate_derives_type_accurate_fixture_values_when_no_create_or_edit_fields_exist(): void
    {
        $config = [
            'table_name' => 'payments',
            'columns' => [
                ['name' => 'id', 'type' => 'id'],
                ['name' => 'direction', 'type' => 'string'],
                ['name' => 'amount', 'type' => 'decimal'],
                ['name' => 'is_reconciled', 'type' => 'boolean'],
                ['name' => 'account_id', 'type' => 'foreignId'],
                ['name' => 'paid_at', 'type' => 'datetime'],
                ['name' => 'uuid', 'type' => 'uuid'],
                ['name' => 'created_at', 'type' => 'timestamp'],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'view' => true,
                    'create' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Payments', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Payments');
        $content = $this->generatedContentFor('Core', 'Payments');

        $this->assertStringNotContainsString("'amount' => 'Test", $content);
        $this->assertStringContainsString("'amount' => 1", $content);
        $this->assertStringNotContainsString("'is_reconciled' => 'Test", $content);
        $this->assertStringContainsString("'is_reconciled' => true", $content);
        $this->assertStringNotContainsString("'account_id' => 'Test", $content);
    }

    /**
     * Regression test for a real reported bug (external maintainer report,
     * 2026-08-23): fieldsSource()'s fallback branch (see the sibling test
     * above for the same branch's own discovery) called
     * fallbackRuleForColumnType(), whose string/varchar/char case ('required
     * . 'string') carries no length awareness at all -- unlike
     * IntrospectionToConfig::buildBackendFields()'s own 'string' rules, which
     * always append max:{length}. Without a 'max:' substring in the rules
     * string, buildFieldValueLiteral()'s max:(\d+) regex never matches, so
     * buildMaxAwareUniqueStringLiteral() never fires and the unclamped 24-char
     * 'Test {Field} ' . uniqid() literal silently overflows any shorter
     * column -- found live on a varchar(20) 'event' column in a list+view-only
     * module (this fallback's only caller).
     */
    public function test_generate_clamps_fixture_values_to_column_length_when_no_create_or_edit_fields_exist(): void
    {
        $config = [
            'table_name' => 'audit_entries',
            'columns' => [
                ['name' => 'id', 'type' => 'id'],
                ['name' => 'event', 'type' => 'string', 'length' => 20],
                ['name' => 'uuid', 'type' => 'uuid'],
                ['name' => 'created_at', 'type' => 'timestamp'],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'view' => true,
                    'create' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('AuditEntries', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'AuditEntries');
        $content = $this->generatedContentFor('Core', 'AuditEntries');

        // The old, unclamped 24-char literal must be gone...
        $this->assertStringNotContainsString("'Test Event ' . uniqid()", $content);
        // ...replaced by a literal truncated to fit inside max:20 (11-char
        // label "Test Event " has no room alongside uniqid()'s fixed 13
        // chars, so the label itself is what gets shortened -- see
        // buildMaxAwareUniqueStringLiteral()'s own docblock for why the
        // label, never uniqid()'s output, is what's truncated).
        $this->assertStringContainsString("'Test Ev' . uniqid()", $content);
    }

    public function test_generate_omits_delegation_view_edit_delete_tests_when_related_module_does_not_resolve(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => false,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
            'delegations' => [
                'items' => [
                    'name' => 'items',
                    'relatedModule' => 'NoSuchModule',
                    'operations' => [
                        'list' => ['enabled' => true],
                        'view' => ['enabled' => true],
                        'delete' => ['enabled' => true],
                    ],
                ],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'Widgets');

        $this->assertStringContainsString('function test_can_list_items_delegation(', $content);
        $this->assertStringNotContainsString('function test_can_view_items_delegation_item(', $content);
        $this->assertStringNotContainsString('function test_can_delete_items_delegation_item(', $content);
    }

    /**
     * Found live building Vendors' reverse Payments delegation
     * (generator-engine v3.3.0's `morphFilter`): a morph-filtered
     * delegation's generated view/edit/delete tests create their `$related`
     * fixture row scoped ONLY by filterKey — omitting the morph type column
     * DelegationServiceGenerator::buildMorphFilterClause() also filters on
     * at runtime. The fixture row exists but isn't scoped to the type the
     * real query filters on, so the generated test 404s against its own
     * generated code. `morphFilter` must also widen the factory `create()`
     * call, not just the runtime query.
     */
    public function test_generate_scopes_delegation_view_test_fixture_by_morph_filter_too(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => false,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
            'delegations' => [
                'payments' => [
                    'name' => 'payments',
                    // Self-delegation: resolves without needing a registry
                    // lookup (mirrors DelegationServiceGenerator's own
                    // self-reference resolution), the simplest way to get a
                    // real resolvable relatedModule in a unit test.
                    'relatedModule' => 'Widgets',
                    'filterKey' => 'payable_id',
                    'morphFilter' => ['column' => 'payable_type', 'value' => 'widget'],
                    'operations' => [
                        'list' => ['enabled' => true],
                        'view' => ['enabled' => true],
                    ],
                ],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Widgets');
        $content = $this->generatedContentFor('Core', 'Widgets');

        $this->assertStringContainsString('function test_can_view_payments_delegation_item(', $content);
        $this->assertMethodBodyContains(
            $content,
            'test_can_view_payments_delegation_item',
            "\\WidgetsModel::factory()->create(['payable_id' => \$parent->id, 'payable_type' => 'widget']);"
        );
    }

    /**
     * The positive counterpart above: a bulk_actions entry WITHOUT a
     * status_target is the one shape BulkActionServiceGenerator emits with
     * no dependency on a model constant existing (see
     * firstGenericBulkActionKey()'s docblock), so the ids-mode happy-path
     * test IS emitted here, using that entry's own key.
     */
    public function test_generate_emits_bulk_action_ids_mode_happy_path_when_a_generic_action_is_configured(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => [
                        'bulk_actions' => [
                            ['key' => 'archive'],
                        ],
                    ],
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Widgets');
        $content = $this->generatedContentFor('Core', 'Widgets');

        $this->assertStringContainsString('function test_bulk_action_processes_a_valid_uuid_list_in_ids_mode(', $content);
        $this->assertMethodBodyContains($content, 'test_bulk_action_processes_a_valid_uuid_list_in_ids_mode', "'action' => 'archive',");
        $this->assertMethodBodyContains($content, 'test_bulk_action_processes_a_valid_uuid_list_in_ids_mode', "'/api/widgets/bulk-action'");
        $this->assertMethodBodyContains($content, 'test_bulk_action_processes_a_valid_uuid_list_in_ids_mode', 'assertStatus(200)');
    }

    /**
     * Mirrors the two tests above but from the "no list feature at all"
     * side: bulk-action coverage must vanish entirely (not just the
     * happy-path half) when list itself is disabled, since route.stub's
     * bulk-action route line only exists inside the list feature's own
     * conditional inclusion.
     */
    public function test_generate_omits_all_bulk_action_tests_when_list_feature_is_disabled(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => false,
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'Widgets');

        $this->assertStringNotContainsString('bulk-action', $content);
        $this->assertStringNotContainsString('bulk_action', $content);
    }

    /**
     * regenerateOnly()'s three kinds ('delegation', 'action', 'bulk_action')
     * write exactly one split file each and leave every other existing file
     * untouched — already covered for 'delegation'/'action' via
     * PlaywrightTestGeneratorTest's equivalent method on the frontend side;
     * this is the same contract for PhpUnitTestGenerator's own
     * 'bulk_action' kind, which had no regenerateOnly() branch at all until
     * now (previously fell through to the unconditional `return false;`
     * with no error). Mirrors this class's own generic-bulk-action fixture
     * shape (`['key' => 'archive']`, no `status_target`) so eligibility
     * matches allGenericBulkActionKeys() exactly.
     */
    public function test_regenerate_only_writes_exactly_the_target_bulk_action_file_and_nothing_else(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => ['bulk_actions' => [['key' => 'archive']]],
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $dir = PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests';
        $before = $this->generatedTestFiles('Core', 'Widgets');
        $this->assertContains($dir . '/WidgetsArchiveServiceTest.php', $before, "the initial generate() call already configured 'archive' — its file must exist before regenerateOnly() adds a second, unrelated key.");

        file_put_contents($dir . '/WidgetsListServiceTest.php', "// HAND-EDITED\n" . file_get_contents($dir . '/WidgetsListServiceTest.php'));
        $handEdited = file_get_contents($dir . '/WidgetsListServiceTest.php');

        $config['features']['backend']['list']['bulk_actions'][] = ['key' => 'restock'];
        $regen = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $regen->setForce(true);
        $this->assertTrue($regen->regenerateOnly('restock', 'bulk_action'));

        $after = $this->generatedTestFiles('Core', 'Widgets');
        $this->assertContains($dir . '/WidgetsRestockServiceTest.php', $after);

        $restockContent = (string) file_get_contents($dir . '/WidgetsRestockServiceTest.php');
        $this->assertStringContainsString('function test_bulk_action_processes_a_valid_uuid_list_in_ids_mode(', $restockContent);
        $this->assertMethodBodyContains($restockContent, 'test_bulk_action_processes_a_valid_uuid_list_in_ids_mode', "'action' => 'restock',");

        // Every previously-existing file, including the hand-edited one and
        // the original 'archive' bulk action's own file, is untouched.
        foreach ($before as $file) {
            $this->assertFileExists($file);
        }
        $this->assertSame($handEdited, file_get_contents($dir . '/WidgetsListServiceTest.php'), 'regenerateOnly() must not touch an unrelated split file');
    }

    /**
     * The status_target eligibility gate applies to regenerateOnly() too —
     * mirroring allGenericBulkActionKeys()'s own filter (see that method's
     * docblock: a status_target entry references a model constant this
     * generator can't verify exists, so it gets no happy-path coverage
     * anywhere, including a scoped regenerateOnly() add).
     */
    public function test_regenerate_only_returns_false_for_a_status_target_bulk_action(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => ['bulk_actions' => [['key' => 'activate', 'status_target' => 'ACTIVE']]],
                    'create' => false,
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $regen = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $regen->setForce(true);
        $this->assertFalse($regen->regenerateOnly('activate', 'bulk_action'));
    }

    // ─── Bug fix: enum columns get a real value everywhere, not just the ────
    // ─── enum-specific accept/reject tests ───────────────────────────────────

    /**
     * The GENERIC field-value literal builder (buildFieldValueLiteral(),
     * used both for the HTTP payload and for the direct-Model::create()
     * fixture helper) must emit a real enum_values member, not the generic
     * 'Test Field ' . uniqid() string. A live 5-module generation run
     * confirmed this broke create/edit/view/list/delete_check/activity/
     * delete/filter tests (a Rule::in([...]) 422s the bogus HTTP payload
     * value) AND the fixture helper (Model::create() bypasses validation
     * entirely, sending the bogus string straight to MySQL and truncating
     * against the column's real ENUM(...) definition).
     */
    public function test_generic_literal_builder_uses_a_real_enum_value_in_both_payload_and_fixture(): void
    {
        $config = [
            'table_name' => 'products',
            'columns' => [
                ['name' => 'price_tier', 'type' => 'enum', 'enum_values' => ['standard', 'premium', 'wholesale']],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'price_tier', 'rules' => 'required|string'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Products', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Products');
        $content = $this->generatedContentFor('Core', 'Products');

        // Direct-create() fixture path.
        $this->assertMethodBodyContains($content, 'createProductFixture', "'price_tier' => 'standard',");

        // HTTP payload path (the same field appears in the create test's
        // own $payload build, BEFORE the enum-specific accept/reject tests
        // override it).
        $this->assertMethodBodyContains($content, 'test_can_create_product', "'price_tier' => 'standard',");

        // Never the generic literal for this field.
        $this->assertStringNotContainsString('Test PriceTier', $content);
    }

    /**
     * An enum value containing a quote must not corrupt the generated PHP
     * literal — mirrors FactoryGenerator's own var_export()-based escaping
     * for the identical problem (see FactoryGenerator::buildValueExpression()'s
     * 'enum' case).
     */
    public function test_generic_literal_builder_escapes_an_enum_value_containing_a_quote(): void
    {
        $config = [
            'table_name' => 'products',
            'columns' => [
                ['name' => 'price_tier', 'type' => 'enum', 'enum_values' => ["standard's", 'premium']],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'price_tier', 'rules' => 'required|string'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Products', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Products');
        $content = $this->generatedContentFor('Core', 'Products');

        $this->assertMethodBodyContains($content, 'createProductFixture', "'price_tier' => 'standard\\'s',");
    }

    /**
     * Reusing the generic literal helper for the enum column's default
     * payload build must not disturb the two enum-specific tests already
     * emitted by buildEnumValidationTestMethods(): "accepts a valid value"
     * still submits a genuinely valid member, and "rejects an invalid
     * value" still submits a deliberately bogus one — both tests OVERRIDE
     * $payload['field'] after the shared buildPayloadLines() call, so
     * neither can be silently defeated by the generic literal now also
     * happening to be a valid enum member.
     */
    public function test_enum_specific_accept_and_reject_tests_remain_correct_after_reusing_the_generic_literal(): void
    {
        $config = [
            'table_name' => 'orders',
            'columns' => [
                ['name' => 'status', 'type' => 'enum', 'enum_values' => ['pending', 'active', 'cancelled']],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'status', 'rules' => 'required|string'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Orders', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Orders');
        $content = $this->generatedContentFor('Core', 'Orders');

        // Accept test: still overrides to a real member and expects 201.
        $this->assertMethodBodyContains($content, 'test_create_order_accepts_a_valid_status_enum_value', "\$payload['status'] = 'pending';");
        $this->assertMethodBodyContains($content, 'test_create_order_accepts_a_valid_status_enum_value', 'assertStatus(201);');

        // Reject test: still overrides to a deliberately bogus value and
        // expects a 422 validation error — NOT accidentally left as the
        // (now-valid) generic literal.
        $this->assertMethodBodyContains($content, 'test_create_order_rejects_an_invalid_status_enum_value', "\$payload['status'] = 'not-a-real-value-' . uniqid();");
        $this->assertMethodBodyContains($content, 'test_create_order_rejects_an_invalid_status_enum_value', 'assertStatus(422)');
        $this->assertMethodBodyContains($content, 'test_create_order_rejects_an_invalid_status_enum_value', "assertJsonValidationErrors(['status'])");
    }

    // ─── Bug fix: multipart boolean response assertions ─────────────────────

    /**
     * On the multipart path, a boolean column's response assertion must
     * compare against a real PHP boolean, never `$payload['field']` — the
     * payload itself correctly holds the STRING '1' (multipart form data
     * carries everything as a string), but the model casts the column to a
     * real boolean, so the JSON response holds `true`, and
     * `assertJsonPath()`'s strict comparison fails `true !== '1'`.
     * Confirmed live: broke test_can_create_item_image,
     * test_can_edit_item_image, and two validation tests in a real
     * multipart module. The payload literal itself must NOT change.
     */
    public function test_multipart_module_boolean_response_assertion_uses_a_real_boolean_not_the_payload(): void
    {
        $config = [
            'table_name' => 'item_images',
            'file_columns' => ['image_media_id'],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'image_media_id', 'rules' => 'required|integer|exists:media,id'],
                            ['field' => 'is_primary', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'view' => false,
                    'edit' => [
                        'fields' => [
                            ['field' => 'image_media_id', 'rules' => 'nullable|integer|exists:media,id'],
                            ['field' => 'is_primary', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('ItemImages', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'ItemImages');
        $content = $this->generatedContentFor('Core', 'ItemImages');

        // The payload literal itself is unchanged: still the multipart string.
        $this->assertMethodBodyContains($content, 'test_can_create_item_image', "'is_primary' => '1',");
        $this->assertMethodBodyContains($content, 'test_can_edit_item_image', "'is_primary' => '1',");

        // The response assertion compares against a real boolean, not the payload.
        $this->assertMethodBodyContains($content, 'test_can_create_item_image', "->assertJsonPath('data.is_primary', true)");
        $this->assertMethodBodyContains($content, 'test_can_edit_item_image', "->assertJsonPath('data.is_primary', true)");
        $this->assertMethodBodyNotContains($content, 'test_can_create_item_image', "->assertJsonPath('data.is_primary', \$payload['is_primary'])");
        $this->assertMethodBodyNotContains($content, 'test_can_edit_item_image', "->assertJsonPath('data.is_primary', \$payload['is_primary'])");
    }

    /**
     * Non-multipart counterpart: a module with no file_columns entry must
     * be COMPLETELY unaffected by the multipart-boolean fix — its payload
     * already holds a real PHP boolean, and the pre-existing
     * `$payload['field']` comparison already passes there.
     */
    public function test_non_multipart_module_boolean_response_assertion_is_unaffected(): void
    {
        $config = [
            'table_name' => 'items',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'is_active', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'view' => false,
                    'edit' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'is_active', 'rules' => 'required|boolean'],
                        ],
                    ],
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Items', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Items');
        $content = $this->generatedContentFor('Core', 'Items');

        $this->assertMethodBodyContains($content, 'test_can_create_item', "'is_active' => true,");
        $this->assertMethodBodyContains($content, 'test_can_create_item', "->assertJsonPath('data.is_active', \$payload['is_active'])");
        $this->assertMethodBodyContains($content, 'test_can_edit_item', "->assertJsonPath('data.is_active', \$payload['is_active'])");
        $this->assertStringNotContainsString("->assertJsonPath('data.is_active', true)", $content);
    }

    // ─── JSON/array column coverage ────────────────────────────────────────

    /**
     * Regression test for a real generated-and-run failure: a `json` column
     * (IntrospectionToConfig::buildBackendFields() emits a bare `array` rule
     * for every normalized_type === 'json' column — reproduced here via a
     * hand-rolled `terms_and_conditions.applies_to` shape, exactly like the
     * real project this was reported from) previously got the generic
     * string-literal fallback (`'Test AppliesTo ' . uniqid()`), which 422s
     * against the generated service's own `["nullable","array"]` validation.
     * The fixture helper, the create-payload, and the edit-payload must all
     * use a real PHP array literal instead.
     */
    public function test_json_array_column_uses_an_array_literal_not_a_string(): void
    {
        $config = [
            'table_name' => 'terms_and_conditions',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'applies_to', 'rules' => 'nullable|array'],
                        ],
                    ],
                    'view' => false,
                    'edit' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'required|string|max:255'],
                            ['field' => 'applies_to', 'rules' => 'nullable|array'],
                        ],
                    ],
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('TermsAndConditions', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'TermsAndConditions');
        $content = $this->generatedContentFor('Core', 'TermsAndConditions');

        $this->assertMethodBodyContains($content, 'createTermsAndConditionFixture', "'applies_to' => ['test'],");
        $this->assertMethodBodyContains($content, 'test_can_create_terms_and_condition', "'applies_to' => ['test'],");
        $this->assertMethodBodyContains($content, 'test_can_edit_terms_and_condition', "'applies_to' => ['test'],");
        $this->assertStringNotContainsString("'Test AppliesTo ' . uniqid()", $content);
        $this->assertStringNotContainsString("'Updated AppliesTo ' . uniqid()", $content);
    }

    /**
     * assertDatabaseHas() cannot safely check a json/array column at all.
     * The obvious problem is a raw PHP array value (PDO has no array bind
     * type); the less obvious one — discovered only by actually running the
     * fix against a real MySQL 8 database as part of this task's mandatory
     * end-to-end verification — is that json_encode()-ing the value first
     * does NOT fix it either: MySQL's native JSON column type does not
     * compare equal (`=`) to a bound string parameter, even when the stored
     * bytes are byte-for-byte identical to the encoded literal (confirmed
     * live via `SELECT applies_to = '["test"]'` returning 0 against a row
     * whose `HEX(applies_to)` was exactly `5B2274657374225D`, while
     * `SELECT applies_to = CAST('["test"]' AS JSON)` on the same row
     * returned 1 — assertDatabaseHas()'s where() builds the former,
     * uncast form). The only reliable fix is to omit the json/array field's
     * key from the where-clause entirely, on both call sites: the create
     * test's single-field fallback (firstDbAssertableField() must skip past
     * `applies_to` onto `title`, since no field here carries a `unique:`
     * rule) and the edit test's multi-field assertion block (which must omit
     * `applies_to`'s own line while still asserting `title`'s).
     */
    public function test_json_array_column_is_excluded_from_assert_database_has(): void
    {
        $config = [
            'table_name' => 'terms_and_conditions',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'applies_to', 'rules' => 'nullable|array'],
                            ['field' => 'title', 'rules' => 'required|string|max:255'],
                        ],
                    ],
                    'view' => false,
                    'edit' => [
                        'fields' => [
                            ['field' => 'applies_to', 'rules' => 'nullable|array'],
                            ['field' => 'title', 'rules' => 'required|string|max:255'],
                        ],
                    ],
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('TermsAndConditions', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'TermsAndConditions');
        $content = $this->generatedContentFor('Core', 'TermsAndConditions');

        // Create test's single-field assertDatabaseHas() falls back to
        // 'title' — the first non-array field — never 'applies_to', even
        // though 'applies_to' is $fields[0].
        $this->assertMethodBodyContains(
            $content,
            'test_can_create_terms_and_condition',
            "\$this->assertDatabaseHas('terms_and_conditions', ['title' => \$payload['title']]);"
        );

        // Edit test's multi-field assertDatabaseHas() block asserts 'title'
        // but omits 'applies_to' entirely — scoped to the assertDatabaseHas()
        // call itself (via its 'uuid' => $fixture->uuid anchor line), since
        // the method body legitimately mentions 'applies_to' elsewhere (the
        // payload construction and the response's assertJsonPath()).
        $editBody = $this->extractMethodBody($content, 'test_can_edit_terms_and_condition');
        $dbAssertStart = strpos($editBody, "'uuid' => \$fixture->uuid,");
        $this->assertNotFalse($dbAssertStart, 'Could not locate the assertDatabaseHas() block.');
        $dbAssertBlock = substr($editBody, $dbAssertStart);

        $this->assertStringContainsString("'title' => \$payload['title'],", $dbAssertBlock);
        $this->assertStringNotContainsString("'applies_to'", $dbAssertBlock);

        // Never any form of the json column handed to either
        // assertDatabaseHas() call — neither the raw-array shape (a PDO
        // array-bind fatal) nor a json_encode()'d one (a silent, always-false
        // MySQL JSON-vs-string comparison).
        $this->assertStringNotContainsString("'applies_to' => \$payload['applies_to']", $content);
        $this->assertStringNotContainsString('json_encode(', $content);
    }

    /**
     * Degenerate edge case: every configured field is json/array-typed, so
     * firstDbAssertableField() has no non-array field left to fall back to
     * at all. The create test must simply omit its assertDatabaseHas() line
     * (never emit one built from an excluded field, and never fatal trying)
     * — the file-assertion/DB-assertion block collapses to nothing rather
     * than a broken assertion.
     */
    public function test_module_with_only_array_fields_omits_the_create_db_assertion(): void
    {
        $config = [
            'table_name' => 'terms_and_conditions',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'applies_to', 'rules' => 'nullable|array'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('TermsAndConditions', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'TermsAndConditions');
        $content = $this->generatedContentFor('Core', 'TermsAndConditions');

        $this->assertMethodBodyNotContains($content, 'test_can_create_terms_and_condition', 'assertDatabaseHas(');
    }

    /**
     * The model casts `json` => `array` (ModelGenerator::getCastType()), so
     * the create/edit response's `data.applies_to` comes back as a real PHP
     * array, and $payload['applies_to'] is already a real PHP array too (the
     * literal fix above) — assertJsonPath()'s strict (`===`) comparison holds
     * for both without any special-casing, unlike the multipart-boolean case
     * (buildResponseAssertLine()'s existing override), which needed one
     * because ITS payload literal deliberately diverges its PHP type
     * ($isMultipart forces a string '1'/'0') from the model's cast type. A
     * json/array column's payload literal never diverges like that on any
     * path, so the plain `$payload['field']` comparison
     * buildResponseAssertLine() already emits by default needs no override
     * here — this test asserts that default is exactly what comes out.
     */
    public function test_json_array_column_response_assertion_uses_the_default_payload_comparison(): void
    {
        $config = [
            'table_name' => 'terms_and_conditions',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'applies_to', 'rules' => 'nullable|array'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('TermsAndConditions', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'TermsAndConditions');

        $this->assertMethodBodyContains(
            $content,
            'test_can_create_terms_and_condition',
            "->assertJsonPath('data.applies_to', \$payload['applies_to'])"
        );
    }

    /**
     * Mandatory regression proof (per this fix's own constraint): a module
     * with NO json/array column at all must produce byte-for-byte identical
     * output to what PhpUnitTestGenerator emitted before this fix — the new
     * `isArrayField()` branches in buildFieldValueLiteral()/
     * buildCreateTestMethod()/buildEditTestMethod() must be true no-ops for
     * every rule string that never contains the bare `array` word. Compares
     * the LocationTypes fixture's full generated output (no json columns in
     * that config) against the exact expected assertions the pre-existing
     * `test_generate_writes_full_crud_test_for_a_module_with_every_feature_enabled()`
     * test already relies on, plus an explicit sanity check that no
     * json_encode()/array-literal artifact leaked in anywhere.
     */
    public function test_module_with_no_json_column_produces_unchanged_output(): void
    {
        $config = $this->locationTypesConfig();

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'LocationTypes');
        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertStringNotContainsString('json_encode(', $content);
        $this->assertStringNotContainsString("['test'],", $content);
        $this->assertStringContainsString(
            "\$this->assertDatabaseHas('location_types', ['name' => \$payload['name']]);",
            $content
        );
    }

    // ─── Defect: max-aware fixture/payload literal ─────────────────────────

    /**
     * Real-world regression: trading_partners.phone is `string|max:20`. The
     * previous unconditional `'Test Phone ' . uniqid()` literal is 24 chars
     * (11-char label + 13-char uniqid()), overflowing the varchar(20) column
     * — Model::create() fixtures 500'd with SQLSTATE 22001, and HTTP
     * payloads 422'd against the service's own `max:20` rule. The fix must
     * shorten the LABEL, never uniqid()'s own output (its tail is what
     * varies fastest, see buildMaxAwareUniqueStringLiteral()'s docblock), so
     * the literal stays both within budget AND unique across the three
     * back-to-back fixture calls buildListTestMethod() emits.
     */
    public function test_string_field_with_max_rule_emits_a_bounded_literal_that_still_varies(): void
    {
        $config = [
            'table_name' => 'trading_partners',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'phone', 'rules' => 'required|string|max:20|unique:trading_partners,phone'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('TradingPartners', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'TradingPartners');
        $content = $this->generatedContentFor('Core', 'TradingPartners');

        // Never the old unbounded literal.
        $this->assertStringNotContainsString("'Test Phone ' . uniqid()", $content);

        // The emitted literal must still end in `. uniqid()` (or a
        // substr(uniqid(), ...) fallback) so repeated fixture calls vary.
        $this->assertMatchesRegularExpression(
            "/'phone' => (?:'[^']*' \\. uniqid\\(\\)|substr\\(uniqid\\(\\), -\\d+\\)),/",
            $content
        );

        // And whatever string literal precedes `. uniqid()` must, combined
        // with uniqid()'s fixed 13-char output, fit within max:20.
        if (preg_match("/'phone' => '([^']*)' \\. uniqid\\(\\),/", $content, $m)) {
            $this->assertLessThanOrEqual(20, strlen($m[1]) + 13);
        }
    }

    /**
     * partner_transaction_types.color is `string|max:7` — smaller than
     * uniqid()'s own 13-character output, so even an EMPTY label overflows.
     * The only remaining option is truncating uniqid()'s own tail (still the
     * fastest-varying part) down to the max budget itself.
     */
    public function test_string_field_with_max_smaller_than_uniqid_falls_back_to_a_truncated_uniqid(): void
    {
        $config = [
            'table_name' => 'partner_transaction_types',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'color', 'rules' => 'required|string|max:7'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('PartnerTransactionTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'PartnerTransactionTypes');
        $content = $this->generatedContentFor('Core', 'PartnerTransactionTypes');

        $this->assertStringContainsString("'color' => substr(uniqid(), -7),", $content);
    }

    /**
     * Mandatory regression proof: a `string` field carrying NO `max:` rule
     * at all must still emit the exact pre-fix literal — the new max-aware
     * branch in buildFieldValueLiteral() must be a true no-op whenever the
     * regex simply doesn't match.
     */
    public function test_string_field_without_max_rule_is_unchanged(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'nickname', 'rules' => 'required|string'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'Widgets');

        $this->assertStringContainsString("'nickname' => 'Test Nickname ' . uniqid(),", $content);
    }

    /**
     * The negative over-length validation test (gap 3b) must be completely
     * unaffected by the max-aware positive-literal fix above: it deliberately
     * builds its own `str_repeat('a', max+1)` value independently of
     * buildFieldValueLiteral()'s uniqid()-based literal, so it must still
     * produce a string LONGER than max regardless of how the fixture/payload
     * literal for that same field is now bounded.
     */
    public function test_overlength_validation_test_still_exceeds_max_after_the_bounded_literal_fix(): void
    {
        $config = [
            'table_name' => 'trading_partners',
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'phone', 'rules' => 'required|string|max:20|unique:trading_partners,phone'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('TradingPartners', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'TradingPartners');

        $this->assertMethodBodyContains(
            $content,
            'test_create_trading_partner_validation_fails_with_overlength_phone',
            "str_repeat('a', 21)"
        );
    }

    // ─── Defect: composite unique constraints ──────────────────────────────

    /**
     * Real-world regression: daily_marketing_plans has
     * UNIQUE(marketing_officer_id, plan_date), and the generated list test
     * calls createDailyMarketingPlanFixture() three times in a row with no
     * overrides — every row got an identical pair, so the third insert threw
     * "Duplicate entry for key ...uq_daily_marketing_plans_officer_date".
     * `marketing_officer_id` is FK-shaped (normalized_type foreignId, via
     * SchemaIntrospector's `_id`-suffix convention — no real FK constraint
     * needed); `plan_date` is a plain `date` column, the cheap, safe member
     * to vary. Also proves the emitted `static $uniqueSequence` counter,
     * incremented per call, rather than an FK member.
     */
    public function test_composite_unique_constraint_varies_its_non_fk_member_across_fixture_calls(): void
    {
        $config = [
            'table_name' => 'daily_marketing_plans',
            'unique_constraints' => [
                [
                    'columns' => ['marketing_officer_id', 'plan_date'],
                    'name'    => 'uq_daily_marketing_plans_officer_date',
                ],
            ],
            'columns' => [
                ['name' => 'plan_date', 'type' => 'date'],
                ['name' => 'marketing_officer_id', 'type' => 'foreignId'],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'plan_date', 'rules' => 'required|date'],
                            ['field' => 'marketing_officer_id', 'rules' => 'required|integer'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('DailyMarketingPlans', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'DailyMarketingPlans');
        $content = $this->generatedContentFor('Core', 'DailyMarketingPlans');

        $this->assertMethodBodyContains($content, 'createDailyMarketingPlanFixture', 'static $uniqueSequence = 0;');
        $this->assertMethodBodyContains($content, 'createDailyMarketingPlanFixture', '$uniqueSequence++;');
        $this->assertMethodBodyContains(
            $content,
            'createDailyMarketingPlanFixture',
            "'plan_date' => now()->addDays(\$uniqueSequence)->toDateString(),"
        );
        $this->assertMethodBodyNotContains($content, 'createDailyMarketingPlanFixture', "'marketing_officer_id' => \$uniqueSequence");
    }

    /**
     * When EVERY member of a composite unique constraint is FK-shaped, this
     * generator has no safe way to vary it (see
     * firstNonFkCompositeUniqueMember()'s docblock) — it must skip the
     * constraint entirely rather than emit a fixture guaranteed to collide.
     */
    public function test_composite_unique_constraint_with_only_fk_members_is_skipped(): void
    {
        $config = [
            'table_name' => 'widgets',
            'unique_constraints' => [
                [
                    'columns' => ['owner_id', 'category_id'],
                    'name'    => 'uq_widgets_owner_category',
                ],
            ],
            'columns' => [
                ['name' => 'owner_id', 'type' => 'foreignId'],
                ['name' => 'category_id', 'type' => 'foreignId'],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'owner_id', 'rules' => 'required|integer'],
                            ['field' => 'category_id', 'rules' => 'required|integer'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'Widgets');

        $this->assertMethodBodyNotContains($content, 'createWidgetFixture', 'uniqueSequence');
    }

    /**
     * Regression (v3.4.15): an `enum` member of a composite unique constraint
     * was picked as the varying member and handed `'U' . $uniqueSequence`,
     * which is not one of the column's declared values — so every generated
     * fixture call died on
     * `SQLSTATE[01000]: Warning: 1265 Data truncated for column 'order_type'`.
     *
     * Found live on a real 49-module POS scaffold: `item_prices` has
     * UNIQUE(item_id, price_list_id, order_type, location_id) where three of
     * the four members are FKs, so the lone non-FK member — the enum — was
     * selected by elimination. 12 of that module's generated tests failed and
     * no other module was affected, because it was the only table in the
     * schema with an enum inside a composite unique key.
     */
    public function test_composite_unique_constraint_skips_enum_member(): void
    {
        $config = $this->itemPricesShapedConfig('enum');

        $generator = new PhpUnitTestGenerator('ItemPrices', 'Catalog', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Catalog', 'ItemPrices');

        // No varying member exists, so the whole constraint is skipped.
        $this->assertMethodBodyNotContains($content, 'createItemPriceFixture', 'uniqueSequence');
        // And crucially the enum keeps whatever valid literal the payload
        // builder emitted, rather than being overwritten with 'U1'.
        $this->assertMethodBodyNotContains($content, 'createItemPriceFixture', "'order_type' => 'U'");
    }

    /**
     * Same rule, same reason: a boolean has only two legal values, so it
     * cannot disambiguate the three back-to-back rows buildListTestMethod()
     * creates, and a string sequence value would be coerced to 0/1 anyway.
     */
    public function test_composite_unique_constraint_skips_boolean_member(): void
    {
        $config = $this->itemPricesShapedConfig('boolean');

        $generator = new PhpUnitTestGenerator('ItemPrices', 'Catalog', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Catalog', 'ItemPrices');

        $this->assertMethodBodyNotContains($content, 'createItemPriceFixture', 'uniqueSequence');
        $this->assertMethodBodyNotContains($content, 'createItemPriceFixture', "'order_type' => 'U'");
    }

    /**
     * Guard against over-correcting: a plain string member of the same
     * constraint MUST still be picked and varied. Without this, "skip the
     * fixed-domain types" could quietly regress into "skip every constraint".
     */
    public function test_composite_unique_constraint_still_varies_a_string_member(): void
    {
        $config = $this->itemPricesShapedConfig('string');

        $generator = new PhpUnitTestGenerator('ItemPrices', 'Catalog', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Catalog', 'ItemPrices');

        $this->assertMethodBodyContains($content, 'createItemPriceFixture', 'static $uniqueSequence = 0;');
        $this->assertMethodBodyContains($content, 'createItemPriceFixture', "'order_type' => 'U' . \$uniqueSequence,");
    }

    /**
     * Regression (v3.4.17): disabling an operation left its previously
     * generated test file on disk forever. The routes are gone, so every test
     * in it asserts against a 404, and --force never cleaned it up because
     * writeSplitFile() simply skipped the write and returned success.
     *
     * Found live on a real 49-module POS scaffold: four modules reduced to
     * list+view kept 16 Create/Edit/Delete/DeleteCheck files between them and
     * failed 46 tests.
     */
    public function test_disabling_an_operation_removes_its_test_file_under_force(): void
    {
        // 1. Generate with create enabled — the file must exist.
        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $this->widgetConfig(true));
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $createPath = PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsCreateServiceTest.php';
        $this->assertFileExists($createPath, 'Expected a CreateServiceTest while create is enabled.');

        // 2. Regenerate with create disabled — the stale file must be removed.
        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $this->widgetConfig(false));
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $this->assertFileDoesNotExist(
            $createPath,
            'A disabled operation must not leave its test file behind — its routes no longer exist.'
        );

        // The still-enabled operations must survive untouched.
        $this->assertFileExists(
            PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsListServiceTest.php',
            'Disabling create must not disturb the list tests.'
        );
    }

    /**
     * The guard that matters: an ordinary (non --force) run must never delete a
     * file it did not just decide to replace — the same rule
     * deleteStaleMonolithicFileIfPresent() has always followed.
     */
    public function test_disabling_an_operation_leaves_the_file_alone_without_force(): void
    {
        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $this->widgetConfig(true));
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $createPath = PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsCreateServiceTest.php';
        $this->assertFileExists($createPath);

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $this->widgetConfig(false));
        $generator->setForce(false);
        // Return value is deliberately NOT asserted: writeFile() returns false
        // for every file it skips because it already exists, so a non-force
        // rerun over a generated module always reports false. What matters here
        // is only that nothing was deleted.
        $generator->generate();

        $this->assertFileExists(
            $createPath,
            'Without --force the generator must not delete anything, even a now-orphaned file.'
        );
    }

    /**
     * Regression: DeleteCheck was the one operation generate() never gated on
     * its own $has* flag — it built its method array unconditionally, so the
     * array was never empty, renderSplitTestFile() never returned null, and the
     * v3.4.17 cleanup above could never reach this suffix even though its own
     * SCOPE docblock listed it.
     *
     * The route generator omits /{uuid}/delete/check entirely when delete is
     * disabled, so the file it left behind asserted 200 against a route that
     * does not exist. Found live on the same 49-module POS scaffold: v3.4.17
     * correctly removed 12 of the 16 stale files across four list+view modules
     * (ItemStock, ItemBatches, ItemSerials, StockMovements) and left exactly
     * the 4 DeleteCheck ones.
     */
    public function test_deletecheck_test_file_is_not_generated_when_delete_is_disabled(): void
    {
        // widgetConfig() has delete => false in both variants.
        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $this->widgetConfig(true));
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $this->assertFileDoesNotExist(
            PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsDeleteCheckServiceTest.php',
            'DeleteCheck has no route when delete is disabled, so its test file must never be written.'
        );
    }

    /**
     * The removal half: a DeleteCheck file generated back when delete WAS
     * enabled must be cleaned up once delete is turned off, exactly like the
     * Create/Edit/Delete files already are.
     */
    public function test_disabling_delete_removes_a_previously_generated_deletecheck_file(): void
    {
        $enabled = $this->widgetConfig(true);
        $enabled['features']['backend']['delete'] = true;

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $enabled);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $deleteCheckPath = PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsDeleteCheckServiceTest.php';
        $this->assertFileExists($deleteCheckPath, 'Expected a DeleteCheckServiceTest while delete is enabled.');

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $this->widgetConfig(true));
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $this->assertFileDoesNotExist(
            $deleteCheckPath,
            'Disabling delete must remove the now-orphaned DeleteCheck test file.'
        );
    }

    /**
     * Guard against over-correcting: gating DeleteCheck on delete must not
     * quietly stop generating it for the ordinary case where delete IS enabled.
     */
    public function test_deletecheck_test_file_is_still_generated_when_delete_is_enabled(): void
    {
        $enabled = $this->widgetConfig(true);
        $enabled['features']['backend']['delete'] = true;

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $enabled);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $this->assertFileExists(
            PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsDeleteCheckServiceTest.php',
            'Delete is enabled, so its delete/check route exists and must still be covered.'
        );
    }

    /**
     * Regression: a module whose create fields are ALL nullable still got
     * test_create_x_validation_fails_with_missing_required_field, because
     * firstRequiredField() fell back to $fields[0] when nothing carried
     * `required`. The emitted test unset a nullable field and asserted 422,
     * which the generated rules explicitly permit — so it asserted the
     * opposite of the module's real contract and failed on 201.
     *
     * Found live on a `Categories` module: one nullable `name` column,
     * CreateService rules "nullable|string|max:255", and a test demanding a
     * validation error for omitting it.
     */
    public function test_missing_required_field_test_is_skipped_when_no_field_is_required(): void
    {
        $generator = new PhpUnitTestGenerator('Categories', 'System', $this->allNullableConfig());
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('System', 'Categories');

        $this->assertStringNotContainsString(
            'function test_create_category_validation_fails_with_missing_required_field(',
            $content,
            'No field is required, so there is no omit-it-and-get-422 behaviour to cover.'
        );

        // The create coverage itself must survive — only this one gap is absent.
        $this->assertStringContainsString('function test_can_create_category(', $content);
    }

    /**
     * Guard against over-correcting: the ordinary case, where a required field
     * does exist, must still get the test.
     */
    public function test_missing_required_field_test_is_still_generated_when_a_field_is_required(): void
    {
        $config = $this->allNullableConfig();
        $config['features']['backend']['create']['fields'][0]['rules'] = 'required|string|max:255';

        $generator = new PhpUnitTestGenerator('Categories', 'System', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('System', 'Categories');

        $this->assertStringContainsString(
            'function test_create_category_validation_fails_with_missing_required_field(',
            $content
        );
        $this->assertStringContainsString("unset(\$payload['name']);", $content);
    }

    /**
     * The real Categories shape: every create field nullable.
     *
     * @return array<string, mixed>
     */
    protected function allNullableConfig(): array
    {
        return [
            'table_name' => 'categories',
            'columns' => [
                ['name' => 'name', 'type' => 'string', 'nullable' => true],
            ],
            'features' => [
                'backend' => [
                    'list'   => true,
                    'view'   => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'rules' => 'nullable|string|max:255'],
                        ],
                    ],
                ],
                'frontend' => [],
            ],
        ];
    }

    /**
     * The case that rules out gating on $hasDelete alone: RoutesGenerator
     * emits /{uuid}/delete/check for a module declaring `deleteCheck` with no
     * `delete` operation at all, so that route still needs its test.
     */
    public function test_deletecheck_test_file_is_generated_for_deletecheck_without_delete(): void
    {
        $config = $this->widgetConfig(true);
        $config['features']['backend']['deleteCheck'] = true;
        // delete stays false — only deleteCheck is declared.

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $this->assertFileExists(
            PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsDeleteCheckServiceTest.php',
            'A module declaring deleteCheck on its own still gets the route, so it still needs the test.'
        );
        $this->assertFileDoesNotExist(
            PathManager::getBackendModulePath('Core', 'Widgets') . '/Tests/WidgetsDeleteServiceTest.php',
            'deleteCheck alone must not pull in the Delete tests.'
        );
    }

    /**
     * Minimal module whose `create` operation can be toggled, so one fixture
     * drives both the removal and the no-force guard above.
     *
     * @return array<string, mixed>
     */
    protected function widgetConfig(bool $withCreate): array
    {
        $backend = [
            'list'   => true,
            'view'   => true,
            'edit'   => false,
            'delete' => false,
        ];
        if ($withCreate) {
            $backend['create'] = [
                'fields' => [
                    ['field' => 'name', 'rules' => 'required|string'],
                ],
            ];
        }

        return [
            'table_name' => 'widgets',
            'columns' => [
                ['name' => 'name', 'type' => 'string'],
            ],
            'features' => [
                'backend'  => $backend,
                'frontend' => [],
            ],
        ];
    }

    /**
     * The real item_prices shape: a four-column composite unique whose only
     * non-FK member is `order_type`, typed per the caller so one fixture can
     * drive the enum/boolean/string cases above.
     */
    protected function itemPricesShapedConfig(string $orderTypeColumnType): array
    {
        return [
            'table_name' => 'item_prices',
            'unique_constraints' => [
                [
                    'columns' => ['item_id', 'price_list_id', 'order_type', 'location_id'],
                    'name'    => 'uq_item_prices_combination',
                ],
            ],
            'columns' => [
                ['name' => 'item_id',       'type' => 'foreignId'],
                ['name' => 'price_list_id', 'type' => 'foreignId'],
                ['name' => 'order_type',    'type' => $orderTypeColumnType],
                ['name' => 'location_id',   'type' => 'foreignId'],
                ['name' => 'price',         'type' => 'decimal'],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            ['field' => 'item_id',       'rules' => 'required|integer'],
                            ['field' => 'price_list_id', 'rules' => 'required|integer'],
                            ['field' => 'order_type',    'rules' => 'nullable|string'],
                            ['field' => 'location_id',   'rules' => 'nullable|integer'],
                            ['field' => 'price',         'rules' => 'required|numeric'],
                        ],
                    ],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];
    }

    /**
     * Mandatory regression proof: a module with no `unique_constraints` at
     * all (the overwhelming majority) must keep byte-for-byte identical
     * fixture-helper output — the new composite-unique machinery must be a
     * true no-op when that config key is absent/empty.
     */
    public function test_no_composite_unique_constraints_leaves_fixture_helper_unchanged(): void
    {
        $config = $this->locationTypesConfig();
        $this->assertArrayNotHasKey('unique_constraints', $config);

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        // No `static $uniqueSequence` machinery leaked in, and the
        // pre-existing field literal is exactly what it was before this fix.
        $this->assertMethodBodyNotContains($content, 'createLocationTypeFixture', 'uniqueSequence');
        $this->assertMethodBodyContains($content, 'createLocationTypeFixture', "'name' => 'Test Name ' . uniqid(),");
        $this->assertMethodBodyContains($content, 'createLocationTypeFixture', '->fresh()');
    }

    /**
     * Config shape shared by the datetime/timestamp fixture tests below: a
     * module with a true `date` column (event_date) alongside a `datetime`
     * column (triggered_at) — both carry the IDENTICAL bare 'date'
     * validation rule (BaseServiceGenerator::generateValidationRules()
     * emits 'date' for 'date', 'datetime', AND 'timestamp' normalized types
     * alike), so only the column's own findColumnConfig() 'type' — supplied
     * here via the 'columns' array, exactly as IntrospectionToConfig::
     * buildColumn() populates it — can tell them apart. Mirrors the real
     * alert_logs.triggered_at bug 1:1 (datetime column, `required|date`
     * rule, THC_V2's live failure).
     *
     * @return array<string, mixed>
     */
    private function eventsConfigWithDateAndDateTimeColumns(): array
    {
        $fields = [
            ['field' => 'name', 'rules' => 'required|string|max:255|unique:events,name'],
            ['field' => 'event_date', 'rules' => 'required|date'],
            ['field' => 'triggered_at', 'rules' => 'required|date'],
        ];

        return [
            'table_name' => 'events',
            'columns' => [
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'event_date', 'type' => 'date'],
                ['name' => 'triggered_at', 'type' => 'datetime'],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => ['fields' => $fields],
                    'view' => false,
                    'edit' => ['fields' => $fields],
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];
    }

    /**
     * The core fix: a true `date` column must keep emitting a date-only
     * fixture/payload literal, while a `datetime` column sharing the exact
     * same `date` validation rule must emit a full local datetime string
     * instead — confirmed by findColumnConfig()'s 'type', not by the rules
     * string (which is identical for both).
     */
    public function test_datetime_column_gets_a_full_datetime_fixture_literal_while_a_true_date_column_stays_date_only(): void
    {
        $generator = new PhpUnitTestGenerator('Events', 'Core', $this->eventsConfigWithDateAndDateTimeColumns());
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Events');
        $content = $this->generatedContentFor('Core', 'Events');

        // Fixture helper.
        $this->assertMethodBodyContains($content, 'createEventFixture', "'event_date' => now()->toDateString(),");
        $this->assertMethodBodyContains($content, 'createEventFixture', "'triggered_at' => now()->format('Y-m-d H:i:s'),");

        // Create-test payload — same distinction.
        $this->assertMethodBodyContains($content, 'test_can_create_event', "'event_date' => now()->toDateString(),");
        $this->assertMethodBodyContains($content, 'test_can_create_event', "'triggered_at' => now()->format('Y-m-d H:i:s'),");

        // Edit-test payload ('Updated' prefix doesn't affect a date/datetime
        // literal — no label is ever embedded in either branch).
        $this->assertMethodBodyContains($content, 'test_can_edit_event', "'event_date' => now()->toDateString(),");
        $this->assertMethodBodyContains($content, 'test_can_edit_event', "'triggered_at' => now()->format('Y-m-d H:i:s'),");
    }

    /**
     * Bug (found live 2026-08-18, StockTransfers, Inventory cluster): V1's
     * own bulk-gen wizard-port (BackendFeatureDeriver::validations(), a
     * documented "faithful port" of generator.ts's own
     * generateColumnsValidations()) never emits a 'date' rule at all for
     * datetime/timestamp columns -- only for a column whose type is EXACTLY
     * 'date' -- so a real datetime/timestamp column can arrive here with a
     * rules string that is ENTIRELY silent about dates (just "nullable").
     * The sibling test above covers "rules says 'date', which shape?" --
     * this covers "rules doesn't mention 'date' at all, is it still
     * date-shaped?" A generic 'Test Field ' . uniqid() string handed to a
     * real datetime-cast column throws a Carbon InvalidFormatException the
     * moment the fixture helper's Model::create() touches it.
     */
    public function test_datetime_column_gets_a_datetime_fixture_even_when_its_rules_never_mention_date(): void
    {
        $fields = [
            ['field' => 'name', 'rules' => 'required|string|max:255'],
            ['field' => 'picked_up_at', 'rules' => 'nullable'],
        ];
        $config = [
            'table_name' => 'stock_transfers',
            'columns' => [
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'picked_up_at', 'type' => 'datetime'],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => ['fields' => $fields],
                    'view' => false,
                    'edit' => ['fields' => $fields],
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('StockTransfers', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'StockTransfers');
        $content = $this->generatedContentFor('Core', 'StockTransfers');

        $this->assertMethodBodyContains($content, 'createStockTransferFixture', "'picked_up_at' => now()->format('Y-m-d H:i:s'),");
        $this->assertStringNotContainsString("'picked_up_at' => 'Test PickedUpAt", $content);
    }

    /**
     * The second half of the fix: a datetime/timestamp field's response-body
     * assertion must compare Carbon INSTANTS via an assertJsonPath() closure
     * (Illuminate\Testing\AssertableJsonString::assertPath() special-cases a
     * Closure expectation), never a raw `=== $payload['field']` string
     * comparison — the API response serializes the stored value in UTC
     * regardless of the app's configured timezone, so a same-instant value
     * never string-equals the local-time string this test itself submitted.
     * A true `date` column has no such timezone round-trip at all, so it
     * must keep the plain, pre-existing string-equality assertion
     * completely unchanged.
     */
    public function test_datetime_column_response_assertion_compares_carbon_instants_not_raw_strings(): void
    {
        $generator = new PhpUnitTestGenerator('Events', 'Core', $this->eventsConfigWithDateAndDateTimeColumns());
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'Events');

        $dateTimeAssertion = <<<'PHP'
->assertJsonPath('data.triggered_at', fn ($value) => \Carbon\Carbon::parse($value)->equalTo(\Carbon\Carbon::parse($payload['triggered_at'])))
PHP;
        $this->assertMethodBodyContains($content, 'test_can_create_event', $dateTimeAssertion);
        $this->assertMethodBodyContains($content, 'test_can_edit_event', $dateTimeAssertion);

        // The true `date` column is completely unaffected — still the
        // original plain string-equality assertion.
        $this->assertMethodBodyContains(
            $content,
            'test_can_create_event',
            "->assertJsonPath('data.event_date', \$payload['event_date'])"
        );
        $this->assertMethodBodyNotContains($content, 'test_can_create_event', "Carbon::parse(\$value)->equalTo(\\Carbon\\Carbon::parse(\$payload['event_date']");
    }

    /**
     * assertDatabaseHas() has no closure escape hatch the way
     * assertJsonPath() does — it always builds a raw string-equality
     * where-clause. A datetime/timestamp field can therefore never be
     * safely folded into that call the way an ordinary field is: the edit
     * test's combined assertDatabaseHas() must OMIT triggered_at from its
     * where-array entirely and instead carry a standalone Carbon-instant
     * assertTrue() check for it, right alongside the composite
     * assertDatabaseHas() call that still covers every other edited field
     * (including the true `date` column, unaffected).
     */
    public function test_datetime_column_is_excluded_from_the_composite_assert_database_has_and_gets_its_own_carbon_check(): void
    {
        $generator = new PhpUnitTestGenerator('Events', 'Core', $this->eventsConfigWithDateAndDateTimeColumns());
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'Events');

        $editBody = $this->extractMethodBody($content, 'test_can_edit_event');

        // The composite assertDatabaseHas() call still covers 'name' and
        // 'event_date' but must not carry a raw 'triggered_at' => ... pair.
        $this->assertStringContainsString("'event_date' => \$payload['event_date'],", $editBody);
        $this->assertStringNotContainsString("'triggered_at' => \$payload['triggered_at'],", $editBody);

        // ...replaced by its own dedicated instant-comparison assertion.
        $expectedCarbonCheck = <<<'PHP'
$this->assertTrue(\Carbon\Carbon::parse(EventsModel::where('uuid', $fixture->uuid)->value('triggered_at'))->equalTo(\Carbon\Carbon::parse($payload['triggered_at'])));
PHP;
        $this->assertStringContainsString($expectedCarbonCheck, $editBody);
    }

    /**
     * firstDbAssertableField() must skip a datetime/timestamp field the same
     * way it already skips an array/json field — the create test's
     * single-field assertDatabaseHas() fallback would otherwise pick
     * triggered_at whenever it happens to be the module's only `unique:`
     * field, emitting a raw string-equality check that (per the docblock on
     * buildResponseAssertLine()) is never reliably correct for this column
     * type. 'name' carries the module's only `unique:` rule here, so it must
     * be the field chosen instead.
     */
    public function test_create_test_never_picks_a_datetime_field_for_its_single_field_assert_database_has(): void
    {
        $generator = new PhpUnitTestGenerator('Events', 'Core', $this->eventsConfigWithDateAndDateTimeColumns());
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'Events');

        $createBody = $this->extractMethodBody($content, 'test_can_create_event');

        $this->assertStringContainsString("assertDatabaseHas('events', ['name' => \$payload['name']]);", $createBody);
        $this->assertStringNotContainsString('triggered_at', (string) strstr($createBody, 'assertDatabaseHas'));
    }

    /**
     * buildViewTestMethod()'s extra first-field assertion is a sibling code
     * path to buildResponseAssertLine() that was missed when only the
     * $payload-comparison form was fixed: `$fixture->{$firstField}` is a
     * real Carbon OBJECT for ANY date-shaped column (ModelGenerator::
     * getCastType() casts a plain 'date' column to 'date:Y-m-d', still a
     * Carbon instance in PHP, not just a 'datetime'/'timestamp' column), so
     * `assertJsonPath('data.field', $fixture->field)` always fails —
     * PHPUnit::assertSame() comparing the JSON response's string against a
     * Carbon object, never a value mismatch. Mirrors the real
     * daily_marketing_plans.plan_date bug 1:1: a true `date` column (no
     * timezone round-trip at all — see buildFieldValueLiteral()'s date
     * branch), first field in the module's fields array, `view` enabled.
     */
    public function test_view_test_compares_a_date_shaped_first_field_as_a_carbon_instant_not_a_raw_object(): void
    {
        $fields = [
            ['field' => 'plan_date', 'rules' => 'required|date'],
            ['field' => 'notes', 'rules' => 'nullable|string'],
        ];

        $config = [
            'table_name' => 'daily_marketing_plans',
            'columns' => [
                ['name' => 'plan_date', 'type' => 'date'],
                ['name' => 'notes', 'type' => 'string'],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => ['fields' => $fields],
                    'view' => ['fields' => $fields],
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('DailyMarketingPlans', 'Marketing', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Marketing', 'DailyMarketingPlans');
        $content = $this->generatedContentFor('Marketing', 'DailyMarketingPlans');

        $expected = <<<'PHP'
->assertJsonPath('data.plan_date', fn ($value) => \Carbon\Carbon::parse($value)->equalTo(\Carbon\Carbon::parse($fixture->plan_date)))
PHP;
        $this->assertMethodBodyContains($content, 'test_can_view_daily_marketing_plan', $expected);
        $this->assertMethodBodyNotContains(
            $content,
            'test_can_view_daily_marketing_plan',
            "->assertJsonPath('data.plan_date', \$fixture->plan_date)"
        );
    }

    /**
     * Regression proof: an ordinary non-date first field must keep the
     * pre-existing plain-equality assertion completely unchanged — the
     * date-shaped branch above must be a true no-op for every other column
     * type.
     */
    public function test_view_test_keeps_plain_equality_for_a_non_date_first_field(): void
    {
        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $this->locationTypesConfig());
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertMethodBodyContains(
            $content,
            'test_can_view_location_type',
            "->assertJsonPath('data.name', \$fixture->name)"
        );
    }

    /**
     * A `decimal` first field gets an exact ->assertJsonPath('data.field', $fixture->field)
     * comparison, not the delta-tolerant one below reserved for genuine float/double columns.
     * ModelGenerator::getCastType() casts a `decimal` column to 'decimal:{scale}' (2026-08-25 fix
     * — a decimal(15,2) money column cast to plain 'float' reintroduced the exact rounding drift
     * decimal(P,S) storage exists to prevent), a fixed-precision numeric STRING on both the
     * fixture attribute and the JSON response — the two sides format identically, so unlike the
     * old plain-'float' cast (which could collapse a whole number to an int on one side and stay
     * a float on the other), there is no type mismatch left for a delta comparison to work around.
     */
    public function test_view_test_uses_exact_comparison_for_a_decimal_shaped_first_field(): void
    {
        $fields = [
            ['field' => 'amount', 'rules' => 'required|numeric'],
        ];

        $config = [
            'table_name' => 'payments',
            'columns' => [
                ['name' => 'amount', 'type' => 'decimal', 'scale' => 2],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => ['fields' => $fields],
                    'view' => ['fields' => $fields],
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Payments', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Custom', 'Payments');
        $content = $this->generatedContentFor('Custom', 'Payments');

        $this->assertMethodBodyContains(
            $content,
            'test_can_view_payment',
            "->assertJsonPath('data.amount', \$fixture->amount)"
        );
        $this->assertMethodBodyNotContains(
            $content,
            'test_can_view_payment',
            "assertEqualsWithDelta"
        );
    }

    /**
     * Bug: a decimal/float/double first field went through the same
     * strict-equality ->assertJsonPath('data.field', $fixture->field) branch
     * as an ordinary field — but $fixture->field is PHP float(1.0) (via
     * ModelGenerator::getCastType()'s 'float' cast) while the HTTP
     * response's JSON-decoded value collapses a whole number to int(1),
     * so assertJsonPath()'s strict === failed for any whole-number fixture
     * value. Found live via the morphs-suite integration fixture
     * (PaymentsModel::amount, 2026-08-08). Fix: assertEqualsWithDelta(),
     * the same tolerant comparison buildDecimalPrecisionTestMethod()
     * already uses for its own decimal assertion. Still applies to genuine
     * `float`/`double` columns after the 2026-08-25 fix above — those still
     * cast to plain 'float' and keep the same int-vs-float mismatch.
     */
    public function test_view_test_uses_delta_comparison_for_a_float_shaped_first_field(): void
    {
        $fields = [
            ['field' => 'amount', 'rules' => 'required|numeric'],
        ];

        $config = [
            'table_name' => 'payments',
            'columns' => [
                ['name' => 'amount', 'type' => 'float'],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => ['fields' => $fields],
                    'view' => ['fields' => $fields],
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Payments', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Custom', 'Payments');
        $content = $this->generatedContentFor('Custom', 'Payments');

        $this->assertMethodBodyContains(
            $content,
            'test_can_view_payment',
            "\$this->assertEqualsWithDelta(\$fixture->amount, \$response->json('data.amount'), 0.0001);"
        );
        $this->assertMethodBodyNotContains(
            $content,
            'test_can_view_payment',
            "->assertJsonPath('data.amount', \$fixture->amount)"
        );
    }

    /**
     * buildResponseAssertLine()'s Create-test sibling to the view-test coverage above. A
     * `decimal` column's response value is a fixed-precision numeric STRING (e.g. a submitted
     * bare `1` round-trips as `"1.00"`), but the submitted payload literal a generated Create
     * test builds is whatever raw type buildFieldValueLiteral() picked — a plain `=== $payload
     * ['field']` comparison then fails comparing "1.00" against 1. Found live building
     * Pangisha's payment engine (2026-08-25): ContractsCreateServiceTest/-EditServiceTest,
     * InstallmentsEditServiceTest, PaymentsCreateServiceTest, PaymentAllocations{Create,Edit}
     * ServiceTest, Units{Create,Edit}ServiceTest all failed this exact way the moment their
     * decimal columns picked up the 'decimal:{scale}' cast. Fix: format the expected side to
     * the column's own scale, matching Eloquent's own formatting exactly.
     */
    public function test_create_test_formats_the_expected_value_for_a_decimal_column(): void
    {
        $fields = [
            ['field' => 'amount', 'rules' => 'required|numeric'],
        ];

        $config = [
            'table_name' => 'payments',
            'columns' => [
                ['name' => 'amount', 'type' => 'decimal', 'scale' => 2],
            ],
            'features' => [
                'backend' => [
                    'list' => true,
                    'create' => ['fields' => $fields],
                    'view' => false,
                    'edit' => false,
                    'delete' => false,
                ],
                'frontend' => [],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Payments', 'Custom', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Custom', 'Payments');
        $content = $this->generatedContentFor('Custom', 'Payments');

        $this->assertMethodBodyContains(
            $content,
            'test_can_create_payment',
            "->assertJsonPath('data.amount', number_format((float) \$payload['amount'], 2, '.', ''))"
        );
        $this->assertMethodBodyNotContains(
            $content,
            'test_can_create_payment',
            "->assertJsonPath('data.amount', \$payload['amount'])"
        );
    }

    // ─── Delegation HTTP-verb regression (found 2026-08-06) ────────────────

    /**
     * Bug: delegationHttpMethod()'s fallback used to collapse every
     * operation other than list/view onto a single generic 'post' default —
     * so a generated delete test called postJson() against a route
     * RoutesGenerator (via DelegationConfigNormalizer::getOperationDefaults())
     * registers with ->delete(...), and an edit test called postJson()
     * against a route registered with ->put(...). Both always failed with
     * 405 the moment a real delegation exercised them — caught via a live
     * Warehouses/StockMovements verification pass, since no real
     * SYSTEM_SHELL module had a non-empty delegations config before this
     * session's own fixtures.
     *
     * relatedModule.name deliberately equals the module's own name here —
     * resolveDelegationRelatedModelFqcn()'s self-referential branch
     * resolves an FQCN with no PathManager registry setup required, which
     * is all this test needs (view/delete/edit only need SOME resolvable
     * related-module FQCN, not a genuinely different module).
     */
    public function test_delegation_delete_and_edit_tests_use_the_correct_default_http_verb(): void
    {
        $config = [
            'table_name' => 'widgets',
            'features' => [
                'backend' => [
                    'list' => false, 'create' => false, 'view' => false, 'edit' => false, 'delete' => false,
                ],
                'frontend' => [],
            ],
            'delegations' => [
                'items' => [
                    'name' => 'items',
                    'relatedModule' => ['name' => 'Widgets'],
                    'filterKey' => 'widget_id',
                    'parentIdField' => 'id',
                    'operations' => [
                        'view' => ['enabled' => true],
                        'delete' => ['enabled' => true],
                        'edit' => [
                            'enabled' => true,
                            'backend' => ['fields' => [['field' => 'name', 'rules' => 'required|string']]],
                        ],
                    ],
                ],
            ],
        ];

        $generator = new PhpUnitTestGenerator('Widgets', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'Widgets');
        $content = $this->generatedContentFor('Core', 'Widgets');

        $this->assertStringContainsString('function test_can_delete_items_delegation_item(', $content);
        $this->assertMethodBodyContains($content, 'test_can_delete_items_delegation_item', 'deleteJson(');
        $this->assertMethodBodyNotContains($content, 'test_can_delete_items_delegation_item', 'postJson(');

        $this->assertStringContainsString('function test_can_edit_items_delegation_item(', $content);
        $this->assertMethodBodyContains($content, 'test_can_edit_items_delegation_item', 'putJson(');
        $this->assertMethodBodyNotContains($content, 'test_can_edit_items_delegation_item', 'postJson(');
    }

    // ─── json_rules: generated tests submit the declared sample (plan 035) ──

    private function jsonRulesSample(): array
    {
        return ['max_per_hour' => 20, 'windows' => [['start' => '08:00', 'end' => '17:00']]];
    }

    private function withParamsJsonRulesField(bool $paramsFirst = false): array
    {
        $config = $this->locationTypesConfig();
        $config['columns'][] = ['name' => 'params', 'type' => 'json', 'nullable' => true];

        $paramsField = ['field' => 'params', 'rules' => 'nullable|array', 'messages' => []];
        if ($paramsFirst) {
            array_unshift($config['features']['backend']['create']['fields'], $paramsField);
        } else {
            $config['features']['backend']['create']['fields'][] = $paramsField;
        }
        $config['features']['backend']['edit']['fields'][] = $paramsField;

        $config['json_rules'] = [
            'params' => [
                'rules' => [
                    'max_per_hour' => 'required|integer|min:1',
                    'windows' => 'nullable|array',
                    'windows.*.start' => 'required_with:params.windows.*.end|date_format:H:i',
                    'windows.*.end' => 'required_with:params.windows.*.start|date_format:H:i',
                ],
                'sample' => $this->jsonRulesSample(),
            ],
        ];

        return $config;
    }

    public function test_json_rules_field_submits_the_declared_sample_not_the_test_placeholder(): void
    {
        $config = $this->withParamsJsonRulesField();

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertStringContainsString(
            "'params' => json_decode('{\"max_per_hour\":20,\"windows\":[{\"start\":\"08:00\",\"end\":\"17:00\"}]}', true),",
            $content
        );
        $this->assertStringNotContainsString("'params' => ['test']", $content);
    }

    public function test_json_rules_field_response_assertion_uses_loose_equality(): void
    {
        $config = $this->withParamsJsonRulesField();

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertStringContainsString(
            "->assertJsonPath('data.params', fn (\$value) => \$value == \$payload['params'])",
            $content
        );
    }

    public function test_json_rules_field_generated_files_all_lint(): void
    {
        $config = $this->withParamsJsonRulesField();

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $this->assertAllGeneratedFilesHaveValidSyntax('Core', 'LocationTypes');
    }

    public function test_json_rules_field_as_the_first_field_gets_a_loose_equality_view_assertion(): void
    {
        $config = $this->withParamsJsonRulesField(paramsFirst: true);

        $generator = new PhpUnitTestGenerator('LocationTypes', 'Core', $config);
        $this->assertTrue($generator->generate());

        $content = $this->generatedContentFor('Core', 'LocationTypes');

        $this->assertMethodBodyContains(
            $content,
            'test_can_view_location_type',
            "fn (\$value) => \$value == \$fixture->params"
        );
    }
}
