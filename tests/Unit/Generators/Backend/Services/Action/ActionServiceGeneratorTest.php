<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Backend\Services\Action;

use Blutrixx\GeneratorEngine\Generators\Backend\Services\Action\ActionServiceGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for ActionServiceGenerator's writeFileOnce() switch.
 *
 * Bug: the generated service stub's entire purpose is a "Add your custom
 * logic here" TODO a developer fills in by hand (see
 * UsersForceResetPasswordService.php for the real, shipped shape this stub
 * is meant to grow into). Plain writeFile() force-overwrites on every
 * regenerate (V1/SYSTEM_SHELL/THC_V2's own ModuleGenerationService calls
 * setForce(true) on every generator it constructs except migrations), so a
 * developer's hand-written business logic was silently discarded back to the
 * empty stub the next time the module regenerated for any unrelated reason
 * (a schema tweak, a second action, etc) -- same bug class already fixed for
 * the inline-items wrapper component.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\BaseGenerator::writeFileOnce()
 */
class ActionServiceGeneratorTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-actionservicegen-test-' . uniqid();
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

    private function filePath(string $moduleGroup, string $moduleName): string
    {
        return PathManager::getBackendModulePath($moduleGroup, $moduleName) . "/Services/{$moduleName}ReceiveService.php";
    }

    public function test_first_generation_writes_the_stub(): void
    {
        $generator = new ActionServiceGenerator('PurchaseOrders', 'Demo', [], 'receive', ['name' => 'receive']);

        $this->assertTrue($generator->generate());

        $path = $this->filePath('Demo', 'PurchaseOrders');
        $this->assertFileExists($path);
        $this->assertStringContainsString('Add your custom logic here', (string) file_get_contents($path));
    }

    public function test_regenerate_with_force_does_not_clobber_hand_written_logic(): void
    {
        $generator = new ActionServiceGenerator('PurchaseOrders', 'Demo', [], 'receive', ['name' => 'receive']);
        $generator->generate();

        $path = $this->filePath('Demo', 'PurchaseOrders');
        $handWritten = "<?php\n// hand-written receiving logic — must survive regeneration\nclass PurchaseOrdersReceiveService {}\n";
        file_put_contents($path, $handWritten);

        // Mirrors ModuleGenerationService::generateModule(), which calls
        // setForce(true) on every generator it constructs (except
        // migrations) before regenerating an already-built module.
        $regenerated = new ActionServiceGenerator('PurchaseOrders', 'Demo', [], 'receive', ['name' => 'receive']);
        $regenerated->setForce(true);
        $regenerated->generate();

        $this->assertSame($handWritten, file_get_contents($path), 'a forced regenerate must not overwrite hand-written action service logic');
    }

    /**
     * shelui-engine fork: the record-lookup seam used to trigger only on a
     * literal 'uuid' urlParam -- a has_uuid: false module's action
     * (urlParams: ['id'], the correct convention for such a module) got no
     * auto-scoped lookup at all. ModuleConfigContract::hasUuid() now decides
     * which param name to look for, and the lookup body itself queries and
     * binds that same name instead of a hardcoded 'uuid'/$uuid.
     */
    public function test_record_lookup_uses_id_when_has_uuid_is_false(): void
    {
        $generator = new ActionServiceGenerator('PurchaseOrders', 'Demo', ['has_uuid' => false], 'receive', [
            'name' => 'receive',
            'urlParams' => ['id'],
        ]);

        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->filePath('Demo', 'PurchaseOrders'));

        $this->assertStringContainsString("\$record = \$recordQuery->where('id', \$id)->first();", $content);
        $this->assertStringNotContainsString('uuid', $content);
    }

    public function test_record_lookup_absent_when_urlparams_names_something_else(): void
    {
        $generator = new ActionServiceGenerator('PurchaseOrders', 'Demo', ['has_uuid' => false], 'receive', [
            'name' => 'receive',
            'urlParams' => ['year'],
        ]);

        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->filePath('Demo', 'PurchaseOrders'));

        $this->assertStringNotContainsString('recordQuery', $content);
    }

    // ─── Plan 033: serviceMethod / serviceArgs on the first-time stub ────────

    public function test_default_signature_with_a_url_param_when_keys_are_absent(): void
    {
        $generator = new ActionServiceGenerator('PurchaseOrders', 'Demo', [], 'receive', [
            'name' => 'receive',
            'urlParams' => ['uuid'],
        ]);

        $this->assertTrue($generator->generate());

        $content = (string) file_get_contents($this->filePath('Demo', 'PurchaseOrders'));

        $this->assertStringContainsString(
            'public static function execute(array $data, string $uuid, array $params = []): array',
            $content
        );
        $this->assertStringContainsString('return self::process($data, $uuid, $params);', $content);
        $this->assertStringContainsString(
            'protected static function process(array $data, string $uuid, array $params = []): array',
            $content
        );
    }

    public function test_custom_signature_with_service_method_and_args(): void
    {
        $generator = new ActionServiceGenerator('PurchaseOrders', 'Demo', [], 'receive', [
            'name' => 'receive',
            'urlParams' => ['uuid'],
            'serviceMethod' => 'sendFromConsole',
            'serviceArgs' => ['request', 'param:uuid'],
        ]);

        $this->assertTrue($generator->generate());

        $path = $this->filePath('Demo', 'PurchaseOrders');
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString(
            'public static function sendFromConsole(\Illuminate\Http\Request $request, string $uuid, array $params = []): array',
            $content
        );
        $this->assertStringContainsString('return self::process($request, $uuid, $params);', $content);
        $this->assertStringContainsString(
            'protected static function process(\Illuminate\Http\Request $request, string $uuid, array $params = []): array',
            $content
        );

        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $exitCode);
        $this->assertSame(0, $exitCode, implode("\n", $output));
    }

    public function test_invalid_service_args_throws_and_writes_nothing(): void
    {
        $generator = new ActionServiceGenerator('PurchaseOrders', 'Demo', [], 'receive', [
            'name' => 'receive',
            'serviceArgs' => ['body'],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        try {
            $generator->generate();
        } finally {
            $this->assertFileDoesNotExist($this->filePath('Demo', 'PurchaseOrders'));
        }
    }
}
