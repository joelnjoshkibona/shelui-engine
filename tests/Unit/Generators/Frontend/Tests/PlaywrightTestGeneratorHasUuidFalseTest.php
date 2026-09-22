<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Tests;

use Blutrixx\GeneratorEngine\Generators\Frontend\Tests\PlaywrightTestGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: PlaywrightTestGenerator's create-response fallback path
 * (no plain text/number "anchor" field to search the list for -- see
 * pickAnchorField()) used to read the newly-created record's own identifier
 * straight off the create API response via the LITERAL field name
 * `?.data?.uuid`. A has_uuid: false module's create response has no `uuid`
 * key at all (only `.data.id` -- see ModuleConfigContract::hasUuid()), so
 * `createdRecordUuid`/`recordUuid` was always null and every generated
 * create/fixture spec for such a module threw "no uuid was found in the
 * response" on every single run.
 *
 * Fix mirrors FrontendRoutesGenerator::$idParam / BaseComponentGenerator::
 * idParam(): a constructor-computed `$idParam` property ('uuid'/'id', or the
 * `features.frontend.view.idParam` escape hatch when set), consulted at the
 * two call sites that read this module's own record identifier directly off
 * an API JSON response body (buildCreateBlock()'s and
 * buildFixtureCreateBody()'s own "no anchor field" branches).
 *
 * The DOM-driven paths elsewhere in this generator (uuidFromTestId() reading
 * a rendered `data-testid` attribute back into `recordUuid`, or the
 * `{ uuid: recordUuid }` fixture-record shape/`cleanupRecord(page, uuid)`
 * parameter) are deliberately NOT touched here -- they never assume which
 * field name backs the value, only that a real identifier is embedded in the
 * already-rendered `[[moduleName]]-{action}-{value}` attribute, so they work
 * unchanged for a has_uuid: false module. Renaming those purely-internal JS
 * identifiers would be cosmetic churn only, not a correctness fix -- this
 * test only asserts on the two spots where the ACTUAL VALUE read would have
 * been wrong.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Tests\PlaywrightTestGenerator
 */
class PlaywrightTestGeneratorHasUuidFalseTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-playwright-has-uuid-false-' . uniqid();
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

    /**
     * A single REQUIRED api-select create field and nothing else. Every
     * field type in PlaywrightTestGenerator::SELECT_FIELD_TYPES is excluded
     * by isScalarField(), so pickAnchorField() resolves to null and
     * buildCreateBlock()/buildFixtureCreateBody() both take their
     * "no anchor field -- read the record key straight off the create
     * response" branch, which is exactly the branch this fix touches.
     *
     * @return array<string, mixed>
     */
    private function noAnchorFieldConfig(bool $hasUuid, ?string $idParamOverride = null): array
    {
        $viewFeature = $idParamOverride !== null ? ['idParam' => $idParamOverride] : true;

        return [
            'has_uuid' => $hasUuid,
            'features' => [
                'frontend' => [
                    'list' => true,
                    'create' => [
                        'fields' => [
                            [
                                'field' => 'category_id',
                                'field_type' => 'api-select',
                                'required' => true,
                                'label' => 'Category',
                                'splashKey' => 'categories',
                            ],
                        ],
                    ],
                    'view' => $viewFeature,
                    'delete' => true,
                ],
            ],
        ];
    }

    private function generate(array $config, string $moduleName = 'Warehouses', string $moduleGroup = 'Custom'): PlaywrightTestGenerator
    {
        $generator = new PlaywrightTestGenerator($moduleName, $moduleGroup, $config);
        $this->assertTrue($generator->generate());

        return $generator;
    }

    private function readGenerated(string $suffix, string $moduleName = 'Warehouses', string $moduleGroup = 'Custom'): string
    {
        $path = PathManager::getFrontendModulePath($moduleGroup, $moduleName) . "/e2e/" . strtolower($moduleName) . "-{$suffix}.e2e.js";
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function readFixtures(string $moduleName = 'Warehouses', string $moduleGroup = 'Custom'): string
    {
        $path = PathManager::getFrontendModulePath($moduleGroup, $moduleName) . '/e2e/_fixtures.js';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_create_response_reads_id_field_when_has_uuid_false(): void
    {
        $this->generate($this->noAnchorFieldConfig(false));

        $create = $this->readGenerated('create');
        $fixtures = $this->readFixtures();

        $this->assertStringContainsString('?.data?.id ?? null', $create);
        $this->assertStringContainsString('no id was found in the response', $create);
        $this->assertStringNotContainsString('?.data?.uuid', $create);

        $this->assertStringContainsString('?.data?.id ?? null', $fixtures);
        $this->assertStringContainsString('no id was found in the response', $fixtures);
        $this->assertStringNotContainsString('?.data?.uuid', $fixtures);
    }

    public function test_create_response_reads_uuid_field_when_has_uuid_true(): void
    {
        $this->generate($this->noAnchorFieldConfig(true));

        $create = $this->readGenerated('create');
        $fixtures = $this->readFixtures();

        $this->assertStringContainsString('?.data?.uuid ?? null', $create);
        $this->assertStringContainsString('no uuid was found in the response', $create);

        $this->assertStringContainsString('?.data?.uuid ?? null', $fixtures);
        $this->assertStringContainsString('no uuid was found in the response', $fixtures);
    }

    public function test_explicit_id_param_override_wins_regardless_of_has_uuid(): void
    {
        $this->generate($this->noAnchorFieldConfig(true, 'slug'));

        $create = $this->readGenerated('create');
        $fixtures = $this->readFixtures();

        $this->assertStringContainsString('?.data?.slug ?? null', $create);
        $this->assertStringContainsString('no slug was found in the response', $create);

        $this->assertStringContainsString('?.data?.slug ?? null', $fixtures);
        $this->assertStringNotContainsString('?.data?.uuid', $fixtures);
    }

    /**
     * The fixture-record return shape (`{ uuid: recordUuid }`) and
     * cleanupRecord()'s own `uuid` parameter name are internal JS plumbing,
     * not something a has_uuid: false module needs functionally correct --
     * every caller destructures that same literal `uuid` key regardless
     * (see split.e2e.stub). This is exactly the naming-only case this pass
     * deliberately leaves alone; asserted here so a future edit doesn't
     * "helpfully" rename it and call that part of this fix.
     */
    public function test_fixture_return_shape_and_cleanup_param_stay_named_uuid_when_has_uuid_false(): void
    {
        $this->generate($this->noAnchorFieldConfig(false));

        $fixtures = $this->readFixtures();

        $this->assertStringContainsString('return { uuid: recordUuid };', $fixtures);
        $this->assertStringContainsString('export async function cleanupRecord(page, uuid) {', $fixtures);
    }
}
