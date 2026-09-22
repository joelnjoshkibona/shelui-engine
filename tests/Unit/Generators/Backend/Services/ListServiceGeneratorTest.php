<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\ListServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for the "nullable location_id silently excluded from
 * list results" bug (found 2026-08-17 via the retail-ERP demo fixture, live
 * PHPUnit failure after a module regeneration).
 *
 * `LocationContextService::applyLocationFiltering()`'s `whereIn(location_id,
 * $accessibleIds)` never matches a NULL location_id, per SQL semantics --
 * silently dropping any "applies everywhere" row from location-scoped list
 * queries. The trait already had an opt-out (`$locationScopeIncludesNull =
 * true`, added 2026-08-08), but no generator ever emitted it, so every
 * module with a genuinely nullable `location_id` column inherited this bug
 * with no way to fix it short of a hand-edit that a future regen would wipe.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\ListServiceGenerator::generateLocationScopeIncludesNull()
 */
class ListServiceGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-list-service-test-' . uniqid();
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

    private function generateAndRead(array $config, string $moduleName = 'ItemPrices', string $moduleGroup = 'Custom'): string
    {
        $generator = new ListServiceGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate(), 'ListServiceGenerator::generate() should report a successful write.');

        $path = $this->tmpRoot . "/BACKEND/app/Project/Modules/{$moduleGroup}/{$moduleName}/Services/{$moduleName}ListService.php";
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    private function baseConfig(array $columns): array
    {
        return [
            'columns' => $columns,
            'features' => [
                'backend' => [
                    'list' => [
                        'fields' => [],
                    ],
                ],
            ],
        ];
    }

    public function test_nullable_location_id_column_declares_the_opt_out(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            ['name' => 'location_id', 'type' => 'foreignId', 'nullable' => true, 'relatedModule' => 'Locations'],
        ]));

        $this->assertStringContainsString('protected static bool $locationScopeIncludesNull = true;', $content);
    }

    public function test_non_nullable_location_id_column_does_not_declare_the_opt_out(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            ['name' => 'location_id', 'type' => 'foreignId', 'nullable' => false, 'relatedModule' => 'Locations'],
        ]));

        $this->assertStringNotContainsString('locationScopeIncludesNull', $content);
    }

    public function test_no_location_id_column_at_all_does_not_declare_the_opt_out(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            ['name' => 'name', 'type' => 'string', 'nullable' => false],
        ]));

        $this->assertStringNotContainsString('locationScopeIncludesNull', $content);
    }

    /**
     * `location_bearing: false` with a location_id column is a deliberate opt-out. The record scope
     * honoured it (it keys on the model flag) but the list filter keys on the column alone, so such
     * a module's list was scoped while its by-uuid view was not -- found by the super-suite
     * fixture's location-isolation test (SuitePings).
     */
    public function test_an_explicit_location_bearing_false_opts_the_list_out_of_location_scoping(): void
    {
        $config = $this->baseConfig([
            ['name' => 'location_id', 'type' => 'foreignId', 'nullable' => true, 'relatedModule' => 'Locations'],
        ]);
        $config['location_bearing'] = false;

        $content = $this->generateAndRead($config);

        $this->assertStringContainsString('protected static bool $locationScopeDisabled = true;', $content);
        $this->assertStringNotContainsString('locationScopeIncludesNull', $content, 'a disabled scope has no NULL rule to declare');
    }

    /** Silence is not an opt-out: an undeclared module with a location_id column is scoped today and must stay so. */
    public function test_no_declaration_never_disables_the_list_scope(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            ['name' => 'location_id', 'type' => 'foreignId', 'nullable' => false, 'relatedModule' => 'Locations'],
        ]));

        $this->assertStringNotContainsString('locationScopeDisabled', $content);
    }

    public function test_location_bearing_true_never_disables_the_list_scope(): void
    {
        $config = $this->baseConfig([
            ['name' => 'location_id', 'type' => 'foreignId', 'nullable' => false, 'relatedModule' => 'Locations'],
        ]);
        $config['location_bearing'] = true;

        $this->assertStringNotContainsString('locationScopeDisabled', $this->generateAndRead($config));
    }

    /** Nothing to disable without the column. */
    public function test_location_bearing_false_without_a_location_column_emits_nothing(): void
    {
        $config = $this->baseConfig([['name' => 'name', 'type' => 'string', 'nullable' => false]]);
        $config['location_bearing'] = false;

        $this->assertStringNotContainsString('locationScopeDisabled', $this->generateAndRead($config));
    }

    private function importConfig(array $createFields): array
    {
        $config = $this->baseConfig([['name' => 'title', 'type' => 'string', 'nullable' => false]]);
        $config['features']['backend']['list']['import'] = true;
        $config['features']['frontend']['create']['fields'] = $createFields;

        return $config;
    }

    /**
     * `$importColumns` used to be emitted empty, so the app's template fell back to the filterable
     * columns with an EMPTY label each: a header row of blank cells. The create fields are what an
     * imported row becomes, so they are the columns (found by the super-suite fixture).
     */
    public function test_import_columns_are_the_create_fields_with_their_labels(): void
    {
        $content = $this->generateAndRead($this->importConfig([
            ['field' => 'title', 'label' => 'Title', 'field_type' => 'input'],
            ['field' => 'assignee_id', 'label' => "Assign to", 'field_type' => 'api-select'],
        ]));

        $this->assertStringContainsString("'title' => 'Title',", $content);
        $this->assertStringContainsString("'assignee_id' => 'Assign to',", $content);
        $this->assertStringNotContainsString("// 'field_name' => 'Field Label'", $content);
    }

    public function test_file_and_morph_fields_are_not_import_columns(): void
    {
        $content = $this->generateAndRead($this->importConfig([
            ['field' => 'title', 'label' => 'Title', 'field_type' => 'input'],
            ['field' => 'attachment', 'label' => 'Attachment', 'field_type' => 'file-input'],
            ['field' => 'payable_type', 'label' => 'Payable', 'field_type' => 'morph-select'],
        ]));

        $this->assertStringContainsString("'title' => 'Title',", $content);
        $this->assertStringNotContainsString("'attachment'", $content);
        $this->assertStringNotContainsString("'payable_type'", $content);
    }

    public function test_a_label_with_a_quote_cannot_break_the_generated_file(): void
    {
        $content = $this->generateAndRead($this->importConfig([
            ['field' => 'title', 'label' => "Customer's title", 'field_type' => 'input'],
        ]));

        $tmp = tempnam(sys_get_temp_dir(), 'gen_lint_') . '.php';
        file_put_contents($tmp, $content);
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $exit);
        unlink($tmp);

        $this->assertSame(0, $exit, implode("\n", $output));
    }

    public function test_no_create_fields_keeps_the_placeholder_comment(): void
    {
        $content = $this->generateAndRead($this->importConfig([]));

        $this->assertStringContainsString("// 'field_name' => 'Field Label',", $content);
    }

    public function test_php_lints_clean_with_the_opt_out_declared(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            ['name' => 'location_id', 'type' => 'foreignId', 'nullable' => true, 'relatedModule' => 'Locations'],
        ]));

        $tmpFile = tempnam(sys_get_temp_dir(), 'gen_lint_') . '.php';
        file_put_contents($tmpFile, $content);
        exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $exitCode);
        unlink($tmpFile);

        $this->assertSame(0, $exitCode, 'Generated file has a PHP syntax error: ' . implode("\n", $output));
    }

    /**
     * The generated ListService's own row-enricher seam (v3.5.21): an
     * optional last `?callable $enrich` parameter forwarded through
     * execute()/export()/process() to the consuming app's
     * ListServiceTrait::processListQuery()/exportData(). No hook body is
     * ever generated here -- this file is rewritten wholesale on --force
     * (no hand regions), so an enricher must live in a hand-owned service,
     * never in this stub's own output.
     */
    public function test_execute_accepts_an_optional_enricher(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            ['name' => 'name', 'type' => 'string', 'nullable' => false],
        ]));

        $this->assertStringContainsString(
            '?Builder $query = null, ?callable $enrich = null): mixed',
            $content
        );
    }

    public function test_list_and_export_paths_forward_the_enricher(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            ['name' => 'name', 'type' => 'string', 'nullable' => false],
        ]));

        $this->assertStringContainsString('self::process($validData, $query, $enrich)', $content);
        $this->assertStringContainsString('self::export($validData, $format, $query, $enrich)', $content);
        $this->assertStringContainsString('self::processListQuery($data, $processListQuery, true, $enrich)', $content);
        $this->assertStringContainsString('false, $format, $enrich)', $content);
    }

    public function test_no_enrich_hook_body_is_generated(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            ['name' => 'name', 'type' => 'string', 'nullable' => false],
        ]));

        $this->assertStringNotContainsString('function enrich', $content);
    }

    public function test_php_lints_clean_with_the_enricher_seam(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            ['name' => 'name', 'type' => 'string', 'nullable' => false],
        ]));

        $tmpFile = tempnam(sys_get_temp_dir(), 'gen_lint_') . '.php';
        file_put_contents($tmpFile, $content);
        exec('php -l ' . escapeshellarg($tmpFile) . ' 2>&1', $output, $exitCode);
        unlink($tmpFile);

        $this->assertSame(0, $exitCode, 'Generated file has a PHP syntax error: ' . implode("\n", $output));
    }

    // ─── $bulkRecordKeyColumn (shelui-engine fork) ──────────────────────────

    /**
     * App\Project\_Src\ListServiceTrait::processBulkAction() reads this
     * property (falling back to 'uuid' when absent) to know which column
     * identifies a record for bulk dispatch -- a has_uuid: false module
     * needs it declared explicitly since it has no 'uuid' column at all.
     */
    public function test_bulk_record_key_column_emitted_when_has_uuid_is_false(): void
    {
        $config = $this->baseConfig([
            ['name' => 'name', 'type' => 'string', 'nullable' => false],
        ]);
        $config['has_uuid'] = false;
        $content = $this->generateAndRead($config);

        $this->assertStringContainsString("protected static string \$bulkRecordKeyColumn = 'id';", $content);
    }

    public function test_bulk_record_key_column_omitted_by_default(): void
    {
        $content = $this->generateAndRead($this->baseConfig([
            ['name' => 'name', 'type' => 'string', 'nullable' => false],
        ]));

        $this->assertStringNotContainsString('bulkRecordKeyColumn', $content);
    }
}
