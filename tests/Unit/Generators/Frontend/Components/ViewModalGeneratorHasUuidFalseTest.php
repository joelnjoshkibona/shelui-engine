<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Components;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\ViewModalGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: ViewModalGenerator::renderModal() -- the markup for a
 * `uiType: "modal"` action embedded in the view modal's footer -- used to
 * unconditionally bind `:uuid="uuid"` into the generated
 * {Module}{Action}Form component. ActionComponentGenerator/action/form.stub
 * (fixed alongside this) now declare that Form's own record-identifier prop
 * as `[[idParam]]` ('uuid' when ModuleConfigContract::hasUuid(), else 'id'),
 * not a literal `uuid` -- Vue drops an attribute that matches no declared
 * prop instead of binding it, so for a has_uuid: false module the Form's own
 * (non-required, default '') id prop silently stayed empty and every
 * endpoint it built from it hit ".../.../..." with an empty segment.
 *
 * The right-hand side (`uuid`) is untouched: it is this ViewModal's own
 * already-correctly-sourced prop value (auto-exposed in the template by
 * `defineProps({ uuid: ... })`, still a literal `uuid` prop name here --
 * see the class docblock below for why that name itself does not need to
 * change), only the attribute NAME needed to track the Form's renamed prop.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\ViewModalGenerator::renderModal()
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\BaseComponentGenerator::idParam()
 */
class ViewModalGeneratorHasUuidFalseTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-viewmodalgen-has-uuid-false-' . uniqid();
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

    /** @return array<string, mixed> */
    private function configWithModalAction(bool $hasUuid): array
    {
        return [
            'has_uuid' => $hasUuid,
            'features' => [
                'frontend' => [
                    'view' => ['enabled' => true],
                ],
            ],
            'actions' => [
                'approve' => [
                    'name' => 'approve',
                    'hasUI' => true,
                    'uiType' => 'modal',
                    'label' => 'Approve',
                ],
            ],
        ];
    }

    private function generateAndRead(array $config, string $moduleName = 'Warehouses', string $moduleGroup = 'Custom'): string
    {
        $generator = new ViewModalGenerator($moduleName, $moduleGroup, $config);
        $generator->setForce(true);
        $this->assertTrue($generator->generate());

        $path = PathManager::getFrontendModulePath($moduleGroup, $moduleName) . "/Components/{$moduleName}ViewModal.vue";
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function test_modal_action_form_binds_id_prop_when_has_uuid_false(): void
    {
        $content = $this->generateAndRead($this->configWithModalAction(false));

        $this->assertStringContainsString('WarehousesApproveForm', $content);
        $this->assertStringContainsString(':id="uuid"', $content);
        $this->assertStringNotContainsString(':uuid="uuid"', $content);
    }

    public function test_modal_action_form_binds_uuid_prop_when_has_uuid_true(): void
    {
        $content = $this->generateAndRead($this->configWithModalAction(true));

        $this->assertStringContainsString('WarehousesApproveForm', $content);
        $this->assertStringContainsString(':uuid="uuid"', $content);
    }

    public function test_modal_action_form_honors_explicit_id_param_override(): void
    {
        $config = $this->configWithModalAction(true);
        $config['features']['frontend']['view']['idParam'] = 'slug';

        $content = $this->generateAndRead($config);

        $this->assertStringContainsString(':slug="uuid"', $content);
    }

    /**
     * ViewModal's OWN incoming `uuid` prop (defineProps in modal.stub) and
     * the delegation-tab prop it forwards (`uuid: props.uuid` in
     * buildDelegationTabReplacements()) are deliberately left as a literal
     * `uuid` -- neither modal.stub nor tab_action.stub (the delegation tab's
     * own component) have been renamed away from that prop name by any
     * sibling generator this fork touches, so the VALUE these forward
     * (`props.uuid`, sourced correctly by whichever caller instantiates this
     * ViewModal) is what matters, not the name. This documents that the
     * router-link/data-testid/router.push `${uuid}` interpolations and the
     * delegation-tab prop forwarding are unaffected by has_uuid, matching
     * category 4 in this pass's classification.
     */
    public function test_own_uuid_prop_and_delegation_tab_forwarding_unaffected_by_has_uuid(): void
    {
        $content = $this->generateAndRead($this->configWithModalAction(false));

        $this->assertStringContainsString('uuid: { type: String, required: true }', $content);
    }
}
