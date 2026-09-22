<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\DeleteFormGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: delete/form.stub used to declare a prop literally
 * named `uuid` (`uuid: { type: String, required: true }`) and read
 * `props.uuid` for the check/submit endpoint URLs and the auto-exposed
 * `uuid` template binding for the "open full page" router-link, regardless
 * of `has_uuid` -- for a has_uuid: false module, every caller
 * (DeletePageGenerator's page.stub, ViewLayoutGenerator's own-module
 * delete modal) passes the record's real `id`, never a `uuid`, so the
 * prop -- and every URL built from it -- was always undefined, breaking
 * the delete-relationship-check GET and the delete DELETE request outright.
 *
 * DeleteFormGenerator extends BaseGenerator directly (there is no
 * BaseComponentGenerator subclass for the delete form), so it resolves the
 * idParam the same way FrontendRoutesGenerator's own constructor property
 * does, rather than via BaseComponentGenerator::idParam().
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\DeleteFormGenerator
 */
class DeleteFormGeneratorHasUuidFalseTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-deleteformgen-has-uuid-false-' . uniqid();
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
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($dir);
    }

    private function generateAndRead(array $config, string $moduleName = 'Warehouses', string $moduleGroup = 'Core'): string
    {
        $generator = new DeleteFormGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath($moduleGroup, $moduleName) . "/Components/{$moduleName}DeleteForm.vue";
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_prop_and_endpoints_use_id_when_has_uuid_false(): void
    {
        $content = $this->generateAndRead(['has_uuid' => false]);

        $this->assertStringContainsString('id: { type: String, required: true },', $content);
        $this->assertStringContainsString(
            'const checkEndpoint = computed(() => props.checkUrl || `/warehouses/${props.id}/delete/check`)',
            $content
        );
        $this->assertStringContainsString(
            'const submitEndpoint = computed(() => props.submitUrl || `/warehouses/${props.id}/delete`)',
            $content
        );
        $this->assertStringContainsString('router-link v-if="modal" :to="`/warehouses/${id}/delete`"', $content);
        $this->assertStringNotContainsString('uuid', $content);
    }

    public function test_prop_and_endpoints_use_uuid_when_has_uuid_true(): void
    {
        $content = $this->generateAndRead(['has_uuid' => true]);

        $this->assertStringContainsString('uuid: { type: String, required: true },', $content);
        $this->assertStringContainsString(
            'const checkEndpoint = computed(() => props.checkUrl || `/warehouses/${props.uuid}/delete/check`)',
            $content
        );
        $this->assertStringContainsString('router-link v-if="modal" :to="`/warehouses/${uuid}/delete`"', $content);
    }

    public function test_prop_and_endpoints_use_uuid_when_has_uuid_is_absent_default(): void
    {
        // has_uuid defaults to true -- an existing module.json (pre-fork, no
        // `has_uuid` key at all) must keep generating byte-identical `uuid`
        // prop/endpoint output.
        $content = $this->generateAndRead([]);

        $this->assertStringContainsString('uuid: { type: String, required: true },', $content);
        $this->assertStringContainsString(
            'const submitEndpoint = computed(() => props.submitUrl || `/warehouses/${props.uuid}/delete`)',
            $content
        );
    }

    public function test_prop_honors_explicit_id_param_override_regardless_of_has_uuid(): void
    {
        $config = [
            'has_uuid' => true,
            'features' => ['frontend' => ['view' => ['idParam' => 'slug']]],
        ];

        $content = $this->generateAndRead($config);

        $this->assertStringContainsString('slug: { type: String, required: true },', $content);
        $this->assertStringContainsString(
            'const submitEndpoint = computed(() => props.submitUrl || `/warehouses/${props.slug}/delete`)',
            $content
        );
    }
}
