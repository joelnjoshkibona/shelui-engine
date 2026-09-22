<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\EditFormGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: edit/form.stub used to declare a prop literally named
 * `uuid` (`uuid: { type: String, required: true }`) and read `props.uuid` to
 * build both the submit and view endpoint URLs, regardless of `has_uuid` --
 * for a has_uuid: false module, every caller (EditPageGenerator's
 * page.stub, ViewLayoutGenerator's own-module edit modal) passes the
 * record's real `id`, never a `uuid`, so the prop -- and every URL built
 * from it -- was always undefined.
 *
 * BaseComponentGenerator::generateFormFooter()/buildEditDraftBlocks() were
 * already fixed (a prior pass) to read `props.{idParam}` / the
 * auto-exposed `{idParam}` template binding for the "open full page" link
 * and the useDraft() call -- this is the matching fix on the prop
 * declaration itself those two depend on, resolved via the same
 * $this->idParam() (BaseComponentGenerator).
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\EditFormGenerator
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::idParam()
 */
class EditFormGeneratorHasUuidFalseTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-editformgen-has-uuid-false-' . uniqid();
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
                    'edit' => true,
                    'delete' => true,
                ],
                'frontend' => [
                    'list' => ['primaryField' => 'name'],
                    'create' => [
                        'fields' => [
                            ['field' => 'name', 'label' => 'Name', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                        ],
                    ],
                    'view' => true,
                    'edit' => [
                        'fields' => [
                            ['field' => 'name', 'label' => 'Name', 'field_type' => 'input', 'type' => 'text', 'required' => true],
                        ],
                    ],
                    'delete' => true,
                ],
            ],
        ], $overrides);
    }

    private function generateAndRead(array $config, string $moduleName = 'Warehouses', string $moduleGroup = 'Core'): string
    {
        $generator = new EditFormGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath($moduleGroup, $moduleName) . "/Components/{$moduleName}EditForm.vue";
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_prop_and_endpoints_use_id_when_has_uuid_false(): void
    {
        $content = $this->generateAndRead($this->config(['has_uuid' => false]));

        $this->assertStringContainsString('id: { type: String, required: true },', $content);
        $this->assertStringContainsString('return props.submitUrl || `/warehouses/${props.id}/edit`', $content);
        $this->assertStringContainsString('return props.viewUrl || `/warehouses/${props.id}/view`', $content);
        $this->assertStringNotContainsString('uuid: { type: String, required: true },', $content);
        $this->assertStringNotContainsString('props.uuid', $content);
    }

    public function test_prop_and_endpoints_use_uuid_when_has_uuid_true(): void
    {
        $content = $this->generateAndRead($this->config(['has_uuid' => true]));

        $this->assertStringContainsString('uuid: { type: String, required: true },', $content);
        $this->assertStringContainsString('return props.submitUrl || `/warehouses/${props.uuid}/edit`', $content);
        $this->assertStringContainsString('return props.viewUrl || `/warehouses/${props.uuid}/view`', $content);
    }

    public function test_prop_and_endpoints_use_uuid_when_has_uuid_is_absent_default(): void
    {
        // has_uuid defaults to true -- an existing module.json (pre-fork,
        // no `has_uuid` key at all) must keep generating byte-identical
        // `uuid` prop/endpoint output.
        $content = $this->generateAndRead($this->config());

        $this->assertStringContainsString('uuid: { type: String, required: true },', $content);
        $this->assertStringContainsString('return props.submitUrl || `/warehouses/${props.uuid}/edit`', $content);
    }

    public function test_prop_honors_explicit_id_param_override_regardless_of_has_uuid(): void
    {
        $config = $this->config(['has_uuid' => true]);
        $config['features']['frontend']['view'] = ['idParam' => 'slug'];

        $content = $this->generateAndRead($config);

        $this->assertStringContainsString('slug: { type: String, required: true },', $content);
        $this->assertStringContainsString('return props.submitUrl || `/warehouses/${props.slug}/edit`', $content);
    }

    /**
     * Cross-check against BaseComponentGenerator's own already-fixed call
     * sites (generateFormFooter()'s "open full page" router-link and
     * buildEditDraftBlocks()'s useDraft() call) -- both read the SAME
     * resolved idParam, so a has_uuid: false module's generated EditForm
     * must be internally consistent: the prop this test asserts on is
     * exactly what those two other call sites already expect to exist.
     */
    public function test_prop_name_matches_what_form_footer_and_draft_setup_already_read_when_has_uuid_false(): void
    {
        $content = $this->generateAndRead($this->config(['has_uuid' => false]));

        $this->assertStringContainsString('id: { type: String, required: true },', $content);
        $this->assertStringContainsString('router-link v-if="modal" :to="`/warehouses/${id}/edit`"', $content);
        $this->assertStringContainsString("useDraft('Warehouses', 'Core', 'edit', props.id)", $content);
    }
}
