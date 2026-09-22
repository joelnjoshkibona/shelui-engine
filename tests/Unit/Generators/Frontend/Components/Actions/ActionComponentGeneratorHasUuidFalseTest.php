<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Components\Actions;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\Actions\ActionComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: action/form.stub and action/page.stub used to hardcode
 * a `uuid` prop/route-param unconditionally for this module's own record
 * identifier -- the record an action operates on when it targets an existing
 * row. The backend's already-fixed ActionServiceGenerator::routeKeyParam()
 * established `urlParams: ['id']` as the correct config convention for a
 * has_uuid: false module's action (see its own docblock); this is the
 * frontend half of that fix -- form.stub/page.stub now declare/read
 * `[[idParam]]` ('uuid' or 'id') instead of always `uuid`, so
 * buildEndpointExpression()'s config-driven `${props.$1}` substitution
 * (unchanged, still purely urlParams-driven) actually resolves against a
 * prop the Form declares.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\Actions\ActionComponentGenerator
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Services\Action\ActionServiceGenerator::routeKeyParam()
 */
class ActionComponentGeneratorHasUuidFalseTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-actioncomponentgen-has-uuid-false-' . uniqid();
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

    private function baseAction(): array
    {
        return [
            'name' => 'approve',
            'label' => 'Approve',
            'hasUI' => true,
            'uiType' => 'page',
            'urlParams' => ['id'],
            'operations' => ['edit' => ['enabled' => true, 'endpoint' => ['path' => '/warehouses/{id}/approve']]],
        ];
    }

    private function generateAndReadForm(array $config, array $action, string $moduleName = 'Warehouses', string $moduleGroup = 'Custom'): string
    {
        $generator = new ActionComponentGenerator($moduleName, $moduleGroup, $config);
        $this->assertTrue($generator->generateAction('approve', $action));

        $actionName = \Illuminate\Support\Str::studly($action['name']);
        $path = PathManager::getFrontendModulePath($moduleGroup, $moduleName) . "/Components/{$moduleName}{$actionName}Form.vue";
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function generateAndReadPage(array $config, array $action, string $moduleName = 'Warehouses', string $moduleGroup = 'Custom'): string
    {
        $generator = new ActionComponentGenerator($moduleName, $moduleGroup, $config);
        $this->assertTrue($generator->generateAction('approve', $action));

        $actionName = \Illuminate\Support\Str::studly($action['name']);
        $path = PathManager::getFrontendModulePath($moduleGroup, $moduleName) . "/{$moduleName}{$actionName}Page.vue";
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_form_declares_id_prop_when_has_uuid_false(): void
    {
        $content = $this->generateAndReadForm(['has_uuid' => false], $this->baseAction());

        $this->assertStringContainsString("id: { type: String, default: '' },", $content);
        $this->assertStringNotContainsString("uuid: { type: String, default: '' },", $content);
        // Config-driven urlParams ['id'] substitution now resolves against a
        // prop the Form actually declares.
        $this->assertStringContainsString('${props.id}', $content);
        $this->assertDoesNotMatchRegularExpression('/\[\[\w+\]\]/', $content, 'no unresolved [[placeholder]] tokens may remain');
    }

    public function test_form_declares_uuid_prop_when_has_uuid_true(): void
    {
        $action = $this->baseAction();
        $action['urlParams'] = ['uuid'];
        $action['operations']['edit']['endpoint']['path'] = '/warehouses/{uuid}/approve';

        $content = $this->generateAndReadForm(['has_uuid' => true], $action);

        $this->assertStringContainsString("uuid: { type: String, default: '' },", $content);
        $this->assertStringContainsString('${props.uuid}', $content);
    }

    public function test_page_binds_id_prop_and_reads_route_params_id_when_has_uuid_false(): void
    {
        $content = $this->generateAndReadPage(['has_uuid' => false], $this->baseAction());

        $this->assertStringContainsString(':id="uuid"', $content);
        // The exact live code line (not the explanatory comment, which
        // deliberately mentions the literal word "uuid" in prose).
        $this->assertStringContainsString("const uuid = computed(() => String(route.params.id ?? ''))", $content);
        $this->assertStringNotContainsString(':uuid="uuid"', $content);
        $this->assertStringNotContainsString('route.params.uuid ??', $content);
        $this->assertDoesNotMatchRegularExpression('/\[\[\w+\]\]/', $content, 'no unresolved [[placeholder]] tokens may remain');
    }

    public function test_page_binds_uuid_prop_and_reads_route_params_uuid_when_has_uuid_true(): void
    {
        $content = $this->generateAndReadPage(['has_uuid' => true], $this->baseAction());

        $this->assertStringContainsString(':uuid="uuid"', $content);
        $this->assertStringContainsString("const uuid = computed(() => String(route.params.uuid ?? ''))", $content);
        $this->assertStringNotContainsString('route.params.id ??', $content);
    }
}
