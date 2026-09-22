<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\CreateFormGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: create/form.stub's handleCreated() used to read
 * `response.data.uuid` unconditionally to build the post-create redirect
 * (`/{route}/${uuid}/details`) -- for a has_uuid: false module the create
 * response never has a `uuid` key at all (see ModelGenerator/MigrationGenerator's
 * own has_uuid gating), so that read was always undefined and the redirect
 * silently fell back to `cancelLink` (the list page) instead of the new
 * record's own details page -- the route FrontendRoutesGenerator actually
 * registers for such a module is `:id/details`, not `:uuid/details`.
 *
 * Fixed via the same $this->idParam() (BaseComponentGenerator) resolution
 * every other Frontend component generator in this fork now uses.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\CreateFormGenerator
 */
class CreateFormGeneratorHasUuidFalseTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-createformgen-has-uuid-false-' . uniqid();
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
    private function config(array $overrides = []): array
    {
        return array_replace_recursive([
            'table_name' => 'warehouses',
            'features' => [
                'backend' => [
                    'list' => ['filterFields' => [['key' => 'id', 'type' => 'text']]],
                    'create' => true,
                    'view' => true,
                ],
                'frontend' => [
                    'list' => ['primaryField' => 'name'],
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'label' => 'Name', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                        ],
                    ],
                    'view' => true,
                ],
            ],
        ], $overrides);
    }

    private function generateAndRead(array $config, string $moduleName = 'Warehouses', string $moduleGroup = 'Core'): string
    {
        $generator = new CreateFormGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath($moduleGroup, $moduleName) . "/Components/{$moduleName}CreateForm.vue";
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_handle_created_reads_id_field_when_has_uuid_false(): void
    {
        $content = $this->generateAndRead($this->config(['has_uuid' => false]));

        $this->assertStringContainsString('handleCreated(response.data.id, response.data.id)', $content);
        $this->assertStringNotContainsString('response.data.uuid', $content);
    }

    public function test_handle_created_reads_uuid_field_when_has_uuid_true(): void
    {
        $content = $this->generateAndRead($this->config(['has_uuid' => true]));

        $this->assertStringContainsString('handleCreated(response.data.id, response.data.uuid)', $content);
    }

    public function test_handle_created_reads_uuid_field_when_has_uuid_is_absent_default(): void
    {
        // has_uuid defaults to true (ModuleConfigContract::hasUuid()) -- a
        // module.json that never mentions the flag at all must keep
        // generating exactly what it did before this fork's idParam fix.
        $content = $this->generateAndRead($this->config());

        $this->assertStringContainsString('handleCreated(response.data.id, response.data.uuid)', $content);
    }

    public function test_handle_created_honors_explicit_id_param_override_regardless_of_has_uuid(): void
    {
        $config = $this->config(['has_uuid' => true]);
        $config['features']['frontend']['view'] = ['idParam' => 'slug'];

        $content = $this->generateAndRead($config);

        $this->assertStringContainsString('handleCreated(response.data.id, response.data.slug)', $content);
    }

    public function test_post_create_redirect_still_targets_the_id_segment_route_when_has_uuid_false(): void
    {
        // The redirect ITSELF (`router.push(... ? \`/{route}/\${uuid}/details\` : ...)`)
        // was never the bug -- it already interpolates whatever the local
        // `uuid` param holds. This just confirms that line is untouched and
        // still correctly wired to the now-correctly-sourced value.
        $content = $this->generateAndRead($this->config(['has_uuid' => false]));

        $this->assertStringContainsString('`/warehouses/${uuid}/details`', $content);
    }
}
