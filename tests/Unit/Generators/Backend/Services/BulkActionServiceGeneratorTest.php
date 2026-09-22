<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\BulkActionServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Generator-unit coverage for BulkActionServiceGenerator, run against a
 * scratch PathManager project root (same harness as PhpUnitTestGeneratorTest/
 * MenusJsonGeneratorTest: PathManager::setProjectRoot() in setUp,
 * reset+recursive cleanup in tearDown).
 *
 * Exercises the two things this generator owns: (1) one
 * {Module}{ActionPascal}Service.php per configured bulk action, with the
 * generic vs. status_target body shapes; (2) that it now goes through
 * BulkActionConfigNormalizer::normalizeAll() instead of hand-rolled
 * empty-key filtering (generator-engine, this session) — an entry with no
 * `key` must not produce a service file at all.
 */
class BulkActionServiceGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-bulk-action-svc-' . uniqid();
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

    private function servicePath(string $actionPascal): string
    {
        return PathManager::getBackendModulePath('Core', 'PurchaseOrders') . "/Services/PurchaseOrders{$actionPascal}Service.php";
    }

    private function assertValidPhpSyntax(string $file): void
    {
        $output = [];
        $exitCode = 0;
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, "Generated file has a PHP syntax error:\n" . implode("\n", $output));
    }

    public function test_generate_is_a_no_op_when_bulk_actions_is_not_configured(): void
    {
        $generator = new BulkActionServiceGenerator('PurchaseOrders', 'Core', [
            'table_name' => 'purchase_orders',
            'features' => ['backend' => ['list' => true]],
        ]);

        $this->assertTrue($generator->generate());
        $this->assertDirectoryDoesNotExist(PathManager::getBackendModulePath('Core', 'PurchaseOrders') . '/Services');
    }

    public function test_generate_is_a_no_op_when_bulk_actions_is_an_empty_array(): void
    {
        $generator = new BulkActionServiceGenerator('PurchaseOrders', 'Core', [
            'table_name' => 'purchase_orders',
            'features' => ['backend' => ['list' => ['bulk_actions' => []]]],
        ]);

        $this->assertTrue($generator->generate());
        $this->assertDirectoryDoesNotExist(PathManager::getBackendModulePath('Core', 'PurchaseOrders') . '/Services');
    }

    public function test_generate_writes_one_service_per_configured_bulk_action(): void
    {
        $generator = new BulkActionServiceGenerator('PurchaseOrders', 'Core', [
            'table_name' => 'purchase_orders',
            'features' => ['backend' => ['list' => [
                'bulk_actions' => [
                    ['key' => 'archive', 'label' => 'Archive'],
                    ['key' => 'cancel', 'label' => 'Cancel'],
                ],
            ]]],
        ]);

        $this->assertTrue($generator->generate());

        $archivePath = $this->servicePath('Archive');
        $cancelPath = $this->servicePath('Cancel');
        $this->assertFileExists($archivePath);
        $this->assertFileExists($cancelPath);
        $this->assertValidPhpSyntax($archivePath);
        $this->assertValidPhpSyntax($cancelPath);
    }

    public function test_generic_action_without_status_target_emits_a_todo_placeholder_body(): void
    {
        $generator = new BulkActionServiceGenerator('PurchaseOrders', 'Core', [
            'table_name' => 'purchase_orders',
            'features' => ['backend' => ['list' => [
                'bulk_actions' => [['key' => 'archive', 'label' => 'Archive']],
            ]]],
        ]);

        $generator->generate();
        $content = (string) file_get_contents($this->servicePath('Archive'));

        $this->assertStringContainsString('class PurchaseOrdersArchiveService', $content);
        $this->assertStringContainsString('public static function execute(array $data, array $params): array', $content);
        $this->assertStringContainsString("// TODO: implement archive for PurchaseOrders", $content);
        $this->assertStringContainsString("firstOrFail();", $content);
        $this->assertStringNotContainsString('status_id', $content);
    }

    public function test_status_target_action_emits_a_status_id_update_body(): void
    {
        $generator = new BulkActionServiceGenerator('PurchaseOrders', 'Core', [
            'table_name' => 'purchase_orders',
            'features' => ['backend' => ['list' => [
                'bulk_actions' => [
                    ['key' => 'markReceived', 'label' => 'Mark Received', 'status_target' => 'RECEIVED'],
                ],
            ]]],
        ]);

        $generator->generate();
        $content = (string) file_get_contents($this->servicePath('MarkReceived'));

        $this->assertStringContainsString('class PurchaseOrdersMarkReceivedService', $content);
        $this->assertStringContainsString("PurchaseOrdersModel::where('uuid', \$params['uuid'])->first();", $content);
        $this->assertStringContainsString("\$model->update(['status_id' => PurchaseOrdersModel::RECEIVED]);", $content);
        $this->assertStringNotContainsString('TODO', $content);
    }

    /**
     * shelui-engine fork: the record-identifier key ('uuid' vs 'id') must
     * match whatever key App\Project\_Src\ListServiceTrait::processBulkAction()
     * actually dispatches under (ListServiceGenerator::generateBulkRecordKeyColumn()) --
     * a has_uuid: false module's generated action service used to query
     * `where('uuid', $params['uuid'])`, a column the table doesn't have.
     */
    public function test_status_target_action_uses_id_when_has_uuid_is_false(): void
    {
        $generator = new BulkActionServiceGenerator('PurchaseOrders', 'Core', [
            'table_name' => 'purchase_orders',
            'has_uuid' => false,
            'features' => ['backend' => ['list' => [
                'bulk_actions' => [
                    ['key' => 'markReceived', 'label' => 'Mark Received', 'status_target' => 'RECEIVED'],
                ],
            ]]],
        ]);

        $generator->generate();
        $content = (string) file_get_contents($this->servicePath('MarkReceived'));

        $this->assertStringContainsString("PurchaseOrdersModel::where('id', \$params['id'])->first();", $content);
        $this->assertStringNotContainsString('uuid', $content);
    }

    public function test_generic_action_uses_id_when_has_uuid_is_false(): void
    {
        $generator = new BulkActionServiceGenerator('PurchaseOrders', 'Core', [
            'table_name' => 'purchase_orders',
            'has_uuid' => false,
            'features' => ['backend' => ['list' => [
                'bulk_actions' => [['key' => 'archive', 'label' => 'Archive']],
            ]]],
        ]);

        $generator->generate();
        $content = (string) file_get_contents($this->servicePath('Archive'));

        $this->assertStringContainsString("\$id = \$params['id'] ?? null;", $content);
        $this->assertStringContainsString("PurchaseOrdersModel::where('id', \$id)->firstOrFail();", $content);
        $this->assertStringNotContainsString('uuid', $content);
    }

    /**
     * The behavior this generator now delegates to BulkActionConfigNormalizer
     * instead of hand-rolling: an entry with no `key` (or an explicitly
     * empty one) is dropped rather than producing a nonsensically-named
     * `PurchaseOrdersService.php` (Str::studly('') === '').
     */
    public function test_generate_skips_an_entry_with_no_key(): void
    {
        $generator = new BulkActionServiceGenerator('PurchaseOrders', 'Core', [
            'table_name' => 'purchase_orders',
            'features' => ['backend' => ['list' => [
                'bulk_actions' => [
                    ['label' => 'No key here'],
                    ['key' => 'archive', 'label' => 'Archive'],
                ],
            ]]],
        ]);

        $this->assertTrue($generator->generate());

        $files = glob(PathManager::getBackendModulePath('Core', 'PurchaseOrders') . '/Services/*.php') ?: [];
        $this->assertSame(['PurchaseOrdersArchiveService.php'], array_map('basename', $files));
    }
}
