<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Components\Delegations;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\Delegations\DelegationModalComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: DelegationModalComponentGenerator::adaptDelegationToCustomFeature()'s
 * `'parentKey' => $delegation['parentKey'] ?? 'uuid'` used to hardcode the
 * literal 'uuid' fallback for the DELEGATING (parent) module's own
 * record-identifier key, regardless of has_uuid -- now `?? $this->idParam()`
 * ('uuid' when ModuleConfigContract::hasUuid(), else 'id'). Mirrors the
 * already-fixed DelegationConfigNormalizer/RoutesGenerator parentKey default
 * on the backend.
 *
 * CustomFeatureModalComponentGeneratorHasUuidFalseTest already covers the
 * downstream `CustomFeatureModalComponentGenerator::generateCustomFeature()`
 * consumer of `parentKey` directly; this test instead exercises the real
 * entry point -- `DelegationModalComponentGenerator::generateDelegation()` --
 * to prove the adapter actually wires a has_uuid: false parent's resolved
 * key ('id') through to the generated modal's endpoint paths end to end,
 * which no existing test did (DelegationModalComponentGenerator was
 * previously referenced only in comments/`@see`, never instantiated, by any
 * test in this repo).
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\Delegations\DelegationModalComponentGenerator
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\CustomFeatures\CustomFeatureModalComponentGenerator
 */
class DelegationModalComponentGeneratorHasUuidFalseTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-delegation-modal-has-uuid-false-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetProjectRoot();
        $this->removeDirectory($this->tmpRoot);
        parent::tearDown();
    }

    private function delegation(string $idPlaceholder): array
    {
        // Explicit endpoint.path on both operations -- deliberately avoids
        // the generator's own DEFAULT-path branch (referencing
        // $this->moduleNameLower/$featureNameLower, neither of which
        // CustomFeatureModalComponentGenerator ever assigns), a pre-existing,
        // unrelated bug documented in CustomFeatureModalComponentGeneratorHasUuidFalseTest
        // and left unfixed as out of scope for this pass.
        return [
            'name' => 'Approvals',
            'label' => 'Approvals',
            'uiType' => 'modal',
            'relatedModule' => ['name' => 'Approvals'],
            // parentKey deliberately absent -- must resolve via
            // $this->idParam() rather than a hardcoded 'uuid'.
            'operations' => [
                'list' => [
                    'enabled' => true,
                    'endpoint' => ['path' => "/warehouses/{{$idPlaceholder}}/approvals"],
                ],
                'create' => [
                    'enabled' => true,
                    'endpoint' => ['path' => "/warehouses/{{$idPlaceholder}}/approvals"],
                ],
            ],
        ];
    }

    private function generate(array $config, string $idPlaceholder): string
    {
        $generator = new DelegationModalComponentGenerator('Warehouses', 'Custom', $config);
        $this->assertTrue($generator->generateDelegation('approvals', $this->delegation($idPlaceholder)));

        $path = PathManager::getFrontendModulePath('Custom', 'Warehouses') . '/WarehousesApprovalsModal.vue';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * Real bug this fix closes: with the old hardcoded `?? 'uuid'` default,
     * str_replace("{uuid}", ...) inside CustomFeatureModalComponentGenerator
     * never matched a real "{id}" placeholder, so it leaked into the
     * generated endpoint verbatim -- a request to ".../{id}/approvals/create",
     * a 404 against any real route, for every has_uuid: false parent module's
     * modal-style delegation.
     */
    public function test_parent_key_resolves_to_id_when_has_uuid_false(): void
    {
        $content = $this->generate(['has_uuid' => false], 'id');

        $this->assertStringContainsString('${props.parentUuid}', $content);
        $this->assertStringNotContainsString('{id}', $content);
        $this->assertStringNotContainsString('/warehouses/{id}/', $content);
    }

    public function test_parent_key_resolves_to_uuid_when_has_uuid_true(): void
    {
        $content = $this->generate(['has_uuid' => true], 'uuid');

        $this->assertStringContainsString('${props.parentUuid}', $content);
        $this->assertStringNotContainsString('{uuid}', $content);
    }

    /**
     * An explicit delegation.parentKey config still wins over the resolved
     * default, has_uuid notwithstanding -- the escape hatch this fix must
     * not remove.
     */
    public function test_explicit_parent_key_config_still_wins_over_has_uuid_default(): void
    {
        $delegation = $this->delegation('custom_key');
        $delegation['parentKey'] = 'custom_key';

        $generator = new DelegationModalComponentGenerator('Warehouses', 'Custom', ['has_uuid' => false]);
        $this->assertTrue($generator->generateDelegation('approvals', $delegation));

        $path = PathManager::getFrontendModulePath('Custom', 'Warehouses') . '/WarehousesApprovalsModal.vue';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('${props.parentUuid}', $content);
        $this->assertStringNotContainsString('{custom_key}', $content);
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
}
