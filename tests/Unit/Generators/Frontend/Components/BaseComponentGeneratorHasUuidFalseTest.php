<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\EditFormGenerator;
use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\ListPageGenerator;
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
 *
 * Also covers a related but separate gap: generateCustomCellRenderersFromListFields()'s
 * `isFk` branch always emitted `<RelatedRecordLink module="..." :uuid="row.x?.uuid">`
 * -- hardcoding the RELATED module's own record-identifier field name, not
 * this module's own idParam(). A `RelatedRecordLink` pointed at a
 * has_uuid: false related module needs `?.id`, not `?.uuid`, since that's
 * the field the loaded relation actually carries. Fixed via a new
 * resolveRelatedIdParam(string $relatedModule) helper (mirrors
 * BaseServiceGenerator::resolveChildAuditColumn()'s PathManager::
 * findModuleInRegistry() lookup shape), defaulting to 'uuid' when the
 * related module isn't registered -- same as before this fix, and
 * consistent with RelatedRecordLink.vue itself degrading to inert text for
 * an unregistered target regardless of prop name.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::resolveRelatedIdParam()
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::generateCustomCellRenderersFromListFields()
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
        PathManager::setModuleRegistry([]);
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

    // ─── generateCustomCellRenderersFromListFields()'s RelatedRecordLink ───
    // ─── FK cell renderer: resolveRelatedIdParam()                        ───

    private function generateListPageAndRead(array $config, string $moduleName = 'Warehouses', string $moduleGroup = 'Core'): string
    {
        $generator = new ListPageGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath($moduleGroup, $moduleName) . "/{$moduleName}ListPage.vue";
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return array<string, mixed> */
    private function listConfigWithFkField(): array
    {
        return array_replace_recursive($this->config(), [
            'features' => [
                'frontend' => [
                    'list' => [
                        'fields' => [
                            ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                            [
                                'key' => 'location_id', 'label' => 'Location', 'type' => 'text',
                                'data' => 'location?.name', 'isFk' => true,
                                'relatedModule' => 'Locations', 'displayField' => 'name',
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_related_record_link_reads_id_when_related_module_has_uuid_false(): void
    {
        PathManager::setModuleRegistry([
            ['name' => 'Locations', 'has_uuid' => false, 'module_type' => 'Core', 'group_name' => null, 'table_name' => 'locations'],
        ]);

        $content = $this->generateListPageAndRead($this->listConfigWithFkField());

        $this->assertStringContainsString('<RelatedRecordLink module="Locations" :uuid="row.location?.id">', $content);
        $this->assertStringNotContainsString('row.location?.uuid', $content);
    }

    public function test_related_record_link_reads_uuid_when_related_module_has_uuid_true(): void
    {
        PathManager::setModuleRegistry([
            ['name' => 'Locations', 'has_uuid' => true, 'module_type' => 'Core', 'group_name' => null, 'table_name' => 'locations'],
        ]);

        $content = $this->generateListPageAndRead($this->listConfigWithFkField());

        $this->assertStringContainsString('<RelatedRecordLink module="Locations" :uuid="row.location?.uuid">', $content);
    }

    public function test_related_record_link_defaults_to_uuid_when_related_module_not_registered(): void
    {
        // No PathManager::setModuleRegistry() call -- the related module
        // isn't known yet (not generated, or a hand-authored module.json
        // field pointing at a module this project never registers). Must
        // keep emitting exactly what every pre-fix generated module already
        // has on disk: `?.uuid`, never a guessed `?.id`.
        $content = $this->generateListPageAndRead($this->listConfigWithFkField());

        $this->assertStringContainsString('<RelatedRecordLink module="Locations" :uuid="row.location?.uuid">', $content);
    }

    public function test_related_record_link_ignores_this_modules_own_has_uuid(): void
    {
        // THIS module (Warehouses) is has_uuid: false, but the related
        // module (Locations) is a normal has_uuid: true module -- the two
        // are resolved completely independently, via two different
        // registry/config lookups (idParam() for this module, plus
        // resolveRelatedIdParam() for the related one).
        PathManager::setModuleRegistry([
            ['name' => 'Locations', 'has_uuid' => true, 'module_type' => 'Core', 'group_name' => null, 'table_name' => 'locations'],
        ]);

        $config = array_replace_recursive($this->listConfigWithFkField(), ['has_uuid' => false]);
        $content = $this->generateListPageAndRead($config);

        $this->assertStringContainsString('<RelatedRecordLink module="Locations" :uuid="row.location?.uuid">', $content);
    }
}
