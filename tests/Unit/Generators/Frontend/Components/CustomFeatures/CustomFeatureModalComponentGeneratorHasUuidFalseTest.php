<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Components\CustomFeatures;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\CustomFeatures\CustomFeatureModalComponentGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * shelui-engine fork: `$parentKey = $customFeature['parentKey'] ?? 'uuid';`
 * used to hardcode the literal 'uuid' fallback for the DELEGATING (parent)
 * module's own record-identifier key, regardless of has_uuid. $parentKey only
 * changes generated output when a delegation's own `endpoint.path` config is
 * explicit AND uses a placeholder matching the real resolved key -- the
 * generator's own DEFAULT endpoint path (built when no endpoint.path is
 * configured at all) round-trips through a literal-placeholder replace either
 * way and was never actually broken by this default. Reached only when
 * DelegationModalComponentGenerator::adaptDelegationToCustomFeature() is
 * bypassed (that adapter always sets 'parentKey' itself, now via the
 * identical $this->idParam() default) -- exercised directly here since
 * generateCustomFeature() is a public entry point on its own.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\CustomFeatures\CustomFeatureModalComponentGenerator
 * @see \Blutrixx\GeneratorEngine\Generators\Frontend\Components\Delegations\DelegationModalComponentGenerator
 */
class CustomFeatureModalComponentGeneratorHasUuidFalseTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/generator-engine-modal-component-has-uuid-false-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
        PathManager::setProjectRoot($this->tmpRoot);
    }

    protected function tearDown(): void
    {
        PathManager::resetProjectRoot();
        $this->removeDirectory($this->tmpRoot);
        parent::tearDown();
    }

    private function customFeature(string $idPlaceholder): array
    {
        return [
            'name' => 'Approvals',
            'displayType' => 'header-action',
            'relatedModule' => ['name' => 'Approvals'],
            // parentKey deliberately absent -- must resolve via
            // $this->idParam() rather than a hardcoded 'uuid'.
            'features' => [
                'backend' => [
                    'list'   => ['enabled' => true, 'endpoint' => ['path' => "/warehouses/{{$idPlaceholder}}/approvals"]],
                    'create' => ['enabled' => true, 'endpoint' => ['path' => "/warehouses/{{$idPlaceholder}}/approvals"]],
                ],
                'frontend' => [],
            ],
        ];
    }

    private function generate(array $config, string $idPlaceholder): string
    {
        $generator = new CustomFeatureModalComponentGenerator('Warehouses', 'Custom', $config);
        $this->assertTrue($generator->generateCustomFeature('approvals', $this->customFeature($idPlaceholder)));

        $path = PathManager::getFrontendModulePath('Custom', 'Warehouses') . '/WarehousesApprovalsModal.vue';
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * A delegation's own explicit endpoint.path written using the module's
     * REAL parent key ('{id}' for has_uuid: false) must resolve -- before this
     * fix, $parentKey always defaulted to 'uuid', so the str_replace("{uuid}",
     * ...) call never matched a literal "{id}" placeholder and it leaked into
     * the generated endpoint verbatim (a request to ".../{id}/approvals/create",
     * a 404 against any real route).
     */
    public function test_explicit_id_placeholder_endpoint_resolves_when_has_uuid_false(): void
    {
        $content = $this->generate(['has_uuid' => false], 'id');

        $this->assertStringContainsString('${props.parentUuid}', $content);
        $this->assertStringNotContainsString('{id}', $content);
        $this->assertStringNotContainsString('/warehouses/{id}/', $content);
    }

    public function test_explicit_uuid_placeholder_endpoint_still_resolves_when_has_uuid_true(): void
    {
        $content = $this->generate(['has_uuid' => true], 'uuid');

        $this->assertStringContainsString('${props.parentUuid}', $content);
        $this->assertStringNotContainsString('{uuid}', $content);
    }

    // NOTE: the generator's own default-path branch (no endpoint.path
    // configured at all, i.e. $listConfig['endpoint']['path'] genuinely
    // absent rather than an empty string) is intentionally NOT covered here.
    // It references $this->moduleNameLower/$featureNameLower, neither of
    // which this class ever assigns -- a pre-existing bug, unrelated to
    // has_uuid, found while writing this test and left unfixed as out of
    // scope for this pass (see the shelui-engine task report). In the real
    // pipeline this branch is effectively unreached anyway:
    // DelegationConfigNormalizer::normalize() (FrontendPipeline calls it
    // before DelegationModalComponentGenerator ever runs) always sets
    // operations.{op}.endpoint.path to '' by default, and '' -- not null --
    // defeats the `??` this branch's condition relies on.

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
