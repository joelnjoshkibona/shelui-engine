<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\EditFormGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: BaseComponentGenerator::idParam() (the shared
 * 'uuid'-or-'id' resolution every Frontend component generator should call
 * instead of recomputing its own copy -- see the method's own docblock) was
 * added but left two of THIS file's own call sites still hardcoding the
 * literal 'uuid' JS identifier, both reachable only through
 * EditFormGenerator (the concrete subclass that calls them), since
 * BaseComponentGenerator itself is abstract:
 *
 * - generateFormFooter('edit', ...)'s "open full page" <router-link> built
 *   its URL as `` `/{route}/${uuid}/edit` `` -- a Vue template expression
 *   referencing a bare `uuid` binding, unconditionally, regardless of
 *   whether the record's own identifier prop is actually named `uuid` or
 *   `id` for a has_uuid: false module.
 * - buildEditDraftBlocks()'s useDraft(...) call read the draft's record key
 *   off `props.uuid` unconditionally, for the same reason.
 *
 * Both now resolve via $this->idParam(), matching
 * FrontendRoutesGenerator::$idParam's identical constructor-computed
 * default and edit/form.stub's own `uuid`-named prop declaration (which a
 * has_uuid: false module's generated EditForm.vue must declare under the
 * matching resolved name for either of these to actually be defined at
 * runtime).
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::idParam()
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::generateFormFooter()
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::buildEditDraftBlocks()
 */
class BaseComponentGeneratorHasUuidFalseTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-basecomponentgen-has-uuid-false-' . uniqid();
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

    private function generateEditFormAndRead(array $config, string $moduleName = 'Warehouses', string $moduleGroup = 'Core'): string
    {
        $generator = new EditFormGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath($moduleGroup, $moduleName) . "/Components/{$moduleName}EditForm.vue";
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    // ─── generateFormFooter('edit') "open full page" router-link ────────────

    public function test_open_full_link_uses_id_param_when_has_uuid_false(): void
    {
        $content = $this->generateEditFormAndRead($this->config(['has_uuid' => false]));

        $this->assertStringContainsString('router-link v-if="modal" :to="`/warehouses/${id}/edit`"', $content);
        $this->assertStringNotContainsString('${uuid}/edit', $content);
    }

    public function test_open_full_link_uses_uuid_when_has_uuid_true(): void
    {
        $content = $this->generateEditFormAndRead($this->config(['has_uuid' => true]));

        $this->assertStringContainsString('router-link v-if="modal" :to="`/warehouses/${uuid}/edit`"', $content);
    }

    public function test_open_full_link_uses_uuid_when_has_uuid_is_absent_default(): void
    {
        // has_uuid defaults to true (ModuleConfigContract::hasUuid()) -- a
        // module.json that never mentions the flag at all must keep
        // generating exactly what it did before this fork's idParam() fix.
        $content = $this->generateEditFormAndRead($this->config());

        $this->assertStringContainsString('router-link v-if="modal" :to="`/warehouses/${uuid}/edit`"', $content);
    }

    public function test_open_full_link_honors_explicit_id_param_override_regardless_of_has_uuid(): void
    {
        $config = $this->config(['has_uuid' => true]);
        $config['features']['frontend']['view'] = ['idParam' => 'slug'];

        $content = $this->generateEditFormAndRead($config);

        $this->assertStringContainsString('router-link v-if="modal" :to="`/warehouses/${slug}/edit`"', $content);
    }

    // ─── buildEditDraftBlocks()'s useDraft() record-key argument ────────────

    public function test_draft_record_key_uses_id_param_when_has_uuid_false(): void
    {
        $content = $this->generateEditFormAndRead($this->config(['has_uuid' => false]));

        // Only asserts on the useDraft() call itself (buildEditDraftBlocks()'s
        // own output) -- edit/form.stub's OWN separate `props.uuid` prop
        // declaration and submit/view endpoint URLs are a different group's
        // fix (features/edit/form.stub, out of this file's scope) and may
        // still legitimately read `props.uuid` elsewhere in the same output.
        $this->assertStringContainsString("useDraft('Warehouses', 'Core', 'edit', props.id)", $content);
        $this->assertStringNotContainsString("useDraft('Warehouses', 'Core', 'edit', props.uuid)", $content);
    }

    public function test_draft_record_key_uses_uuid_when_has_uuid_true(): void
    {
        $content = $this->generateEditFormAndRead($this->config(['has_uuid' => true]));

        $this->assertStringContainsString("useDraft('Warehouses', 'Core', 'edit', props.uuid)", $content);
    }
}
