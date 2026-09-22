<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend;

use Blutrixx\GeneratorEngine\Generators\Frontend\Pages\ViewLayoutGenerator;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for page-wrapper stub / child-component stub prop
 * name drift.
 *
 * Bug: from commit 96b4150 (v2.7.0, ~2026-06-16) through commit 9ce850c
 * (~2026-07-15), `edit/page.stub` rendered its EditForm child with
 * `:id="id"`, while `edit/form.stub`'s own defineProps() declared the prop
 * as `uuid` (required), not `id`. Every module scaffolded during that
 * ~month-long window therefore got a broken standalone
 * `/{module}/:uuid/edit` route: the form's required `uuid` prop was always
 * undefined there, even though the Edit MODAL flow (wired through
 * view/details_layout.stub, which correctly passes `:uuid="recordId"`)
 * kept working the whole time. The bug was fixed incidentally by an
 * unrelated styling commit, with no changelog entry and no test coverage —
 * so the same class of bug (a page-wrapper stub and its child stub
 * silently drifting out of sync on a required prop name) could reintroduce
 * itself at any time without anyone noticing.
 *
 * This test reads the raw .stub files directly (the stubs, not a generated
 * module, are the source of truth to protect) and, for every page/child
 * stub pair below, asserts that any REQUIRED prop the child stub declares
 * via defineProps() is passed under the exact same name by the
 * page-wrapper stub's opening tag for that child component.
 *
 * Exception: the details layout's Edit/Delete modals no longer live in
 * view/details_layout.stub. They are emitted by
 * ViewLayoutGenerator::generateCrudOperationBlocks(), which only writes each one
 * for an operation the module actually has (2026-08-25 -- an unconditional import
 * of a form file that was never generated broke `vite build` outright). For those
 * two pairs the generator's OUTPUT is the artifact to protect, so they generate a
 * layout with both operations enabled and assert against that.
 */
class PageFormPropConsistencyTest extends TestCase
{
    private function templatesDir(): string
    {
        return dirname(__DIR__, 4) . '/src/Generators/Templates/frontend/features';
    }

    private function readStub(string $relativePath): string
    {
        $path = $this->templatesDir() . '/' . $relativePath;
        $this->assertFileExists($path, "Expected stub file not found: {$path}");

        return (string) file_get_contents($path);
    }

    private function kebabToCamel(string $value): string
    {
        return (string) preg_replace_callback(
            '/-([a-z])/',
            static fn (array $m): string => strtoupper($m[1]),
            $value
        );
    }

    /**
     * shelui-engine fork: edit/form.stub and delete/form.stub's own
     * record-identifier prop (previously always the literal `uuid`) is now
     * declared -- and wired to from edit/page.stub, delete/page.stub -- as
     * the `[[idParam]]` placeholder (see CreateFormGenerator::idParam() /
     * EditFormGenerator::idParam() / DeleteFormGenerator's matching
     * resolution: 'uuid' when ModuleConfigContract::hasUuid(), else 'id').
     * This test reads RAW, unresolved .stub text -- it never runs a
     * generator -- so a literal `[[idParam]]` token appears verbatim in
     * both extraction sites below. Normalizing it to its documented DEFAULT
     * resolution ('uuid', matching has_uuid's own true-when-absent default,
     * see ModuleConfigContract::hasUuid()) keeps this test's actual mission
     * -- catching a page stub and its child stub silently drifting apart on
     * a prop name -- intact and independent of the has_uuid fork concern:
     * both sides of every pair this test checks now consistently use the
     * SAME placeholder token, so they still only match each other, not an
     * arbitrary fixed string.
     */
    private function normalizeIdParamPlaceholder(string $content): string
    {
        return str_replace('[[idParam]]', 'uuid', $content);
    }

    /**
     * Extract every `:propName="expr"` attribute bound on the first opening
     * tag matching $tagNamePattern within $stubContent.
     *
     * @return array<string, true> prop names (camelCase) => true
     */
    private function extractPassedProps(string $stubContent, string $tagNamePattern): array
    {
        $stubContent = $this->normalizeIdParamPlaceholder($stubContent);

        if (!preg_match('/<' . $tagNamePattern . '\b([^>]*)>/', $stubContent, $tagMatch)) {
            $this->fail("Could not locate an opening tag matching /{$tagNamePattern}/ in the given stub content.");
        }

        preg_match_all('/:([\w-]+)="[^"]*"/', $tagMatch[1], $bindingMatches);

        $props = [];
        foreach ($bindingMatches[1] as $rawName) {
            $props[$this->kebabToCamel($rawName)] = true;
        }

        return $props;
    }

    private function extractDefinePropsBlock(string $stubContent): string
    {
        if (!preg_match('/defineProps\(\{(.*?)\}\)/s', $stubContent, $match)) {
            $this->fail('Could not locate a defineProps({...}) block in the given stub content.');
        }

        return $match[1];
    }

    /**
     * Names of props declared with `required: true` (no nested braces
     * expected in a required prop's definition in these stubs).
     *
     * @return string[]
     */
    private function extractRequiredProps(string $stubContent): array
    {
        $stubContent = $this->normalizeIdParamPlaceholder($stubContent);
        $propsBlock = $this->extractDefinePropsBlock($stubContent);

        preg_match_all('/(\w+)\s*:\s*\{[^{}]*\brequired\s*:\s*true\b[^{}]*\}/', $propsBlock, $matches);

        return $matches[1];
    }

    /**
     * Every top-level prop name declared in the defineProps() block,
     * required or not (placeholder tokens like [[splashPropBlock]] are
     * stripped first so a placeholder-prefixed prop name is still found at
     * the start of its line).
     *
     * @return string[]
     */
    private function extractDeclaredPropNames(string $stubContent): array
    {
        $propsBlock = $this->extractDefinePropsBlock($stubContent);
        $clean = (string) preg_replace('/\[\[[^\]]*\]\]/', '', $propsBlock);

        preg_match_all('/^\s*(\w+)\s*:/m', $clean, $matches);

        return $matches[1];
    }

    /**
     * Assert that every required prop declared by the child stub is
     * passed, under the exact same name, by the page/parent stub's opening
     * tag for that child component.
     */
    private function assertRequiredPropsWired(
        string $pageStubRelative,
        string $tagNamePattern,
        string $childStubRelative,
        string $context
    ): void {
        $this->assertRequiredPropsWiredInContent(
            $this->readStub($pageStubRelative),
            $pageStubRelative,
            $tagNamePattern,
            $childStubRelative,
            $context
        );
    }

    /**
     * Same assertion against already-rendered content, for markup the generator emits
     * itself rather than reading out of a stub.
     */
    private function assertRequiredPropsWiredInContent(
        string $pageContent,
        string $pageStubRelative,
        string $tagNamePattern,
        string $childStubRelative,
        string $context
    ): void {
        $passedProps = $this->extractPassedProps($pageContent, $tagNamePattern);
        $requiredProps = $this->extractRequiredProps($this->readStub($childStubRelative));

        $this->assertNotEmpty(
            $requiredProps,
            "Sanity check failed for {$context}: expected at least one required prop in "
            . "{$childStubRelative}'s defineProps() block, found none. Did the stub's shape change?"
        );

        foreach ($requiredProps as $requiredProp) {
            $this->assertArrayHasKey(
                $requiredProp,
                $passedProps,
                "{$context}: {$pageStubRelative} does not pass a \":{$requiredProp}\"-named prop to its "
                . "child component tag, but {$childStubRelative} declares '{$requiredProp}' as a required prop. "
                . 'Props actually passed: [' . implode(', ', array_keys($passedProps)) . ']. '
                . 'This is the exact class of bug that shipped between commits 96b4150 and 9ce850c '
                . '(edit/page.stub passed :id="id" while edit/form.stub required "uuid"), silently breaking '
                . 'the standalone edit route for every module scaffolded in that window.'
            );
        }
    }

    public function test_edit_page_stub_passes_required_prop_matching_edit_form_stub(): void
    {
        $this->assertRequiredPropsWired(
            'edit/page.stub',
            '\[\[ModuleName\]\]EditForm',
            'edit/form.stub',
            'edit/page.stub -> edit/form.stub'
        );
    }

    public function test_delete_page_stub_passes_required_prop_matching_delete_form_stub(): void
    {
        $this->assertRequiredPropsWired(
            'delete/page.stub',
            '\[\[ModuleName\]\]DeleteForm',
            'delete/form.stub',
            'delete/page.stub -> delete/form.stub'
        );
    }

    public function test_details_layout_edit_modal_passes_required_prop_matching_edit_form_stub(): void
    {
        $this->assertRequiredPropsWiredInContent(
            $this->generateDetailsLayoutWithAllOperations(),
            'ViewLayoutGenerator::generateCrudOperationBlocks()',
            'OrdersEditForm',
            'edit/form.stub',
            'details layout (Edit modal) -> edit/form.stub'
        );
    }

    public function test_details_layout_delete_modal_passes_required_prop_matching_delete_form_stub(): void
    {
        $this->assertRequiredPropsWiredInContent(
            $this->generateDetailsLayoutWithAllOperations(),
            'ViewLayoutGenerator::generateCrudOperationBlocks()',
            'OrdersDeleteForm',
            'delete/form.stub',
            'details layout (Delete modal) -> delete/form.stub'
        );
    }

    /** Render a details layout for a module that has every CRUD operation enabled. */
    private function generateDetailsLayoutWithAllOperations(): string
    {
        $tmpRoot = sys_get_temp_dir() . '/generator-engine-details-layout-props-' . uniqid();
        mkdir($tmpRoot, 0755, true);
        PathManager::setProjectRoot($tmpRoot);

        try {
            $generator = new ViewLayoutGenerator('Orders', 'Core', [
                'features' => ['frontend' => [
                    'view' => ['enabled' => true],
                    'edit' => ['enabled' => true],
                    'delete' => ['enabled' => true],
                ]],
            ]);
            $generator->setForce(true);
            $this->assertTrue($generator->generate(), 'ViewLayoutGenerator failed to write its layout.');

            $path = PathManager::getFrontendModulePath('Core', 'Orders') . '/OrdersDetailsLayout.vue';
            $this->assertFileExists($path);

            return (string) file_get_contents($path);
        } finally {
            PathManager::resetProjectRoot();
            $this->removeDirectory($tmpRoot);
        }
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

    /**
     * create/form.stub currently has no required identifier prop (a new
     * record has no id yet), so there is no required-prop name to protect
     * the way edit/delete do. Instead, this guards the general class of
     * drift: every prop create/page.stub passes must actually be declared
     * by create/form.stub, and if a required prop is ever introduced this
     * test starts failing so a human adds proper coverage above.
     */
    public function test_create_page_stub_props_are_all_declared_by_create_form_stub(): void
    {
        $pageContent = $this->readStub('create/page.stub');
        $formContent = $this->readStub('create/form.stub');

        $passedProps = $this->extractPassedProps($pageContent, '\[\[ModuleName\]\]CreateForm');
        $declaredNames = $this->extractDeclaredPropNames($formContent);

        $this->assertNotEmpty(
            $passedProps,
            'Sanity check: create/page.stub should pass at least one prop to its CreateForm child tag.'
        );

        foreach (array_keys($passedProps) as $propName) {
            $this->assertContains(
                $propName,
                $declaredNames,
                "create/page.stub passes a \":{$propName}\"-named prop to its CreateForm child tag, but "
                . "create/form.stub's defineProps() does not declare a prop named '{$propName}'."
            );
        }

        $this->assertSame(
            [],
            $this->extractRequiredProps($formContent),
            'create/form.stub now declares a required prop — add an explicit assertRequiredPropsWired() '
            . 'case for create/page.stub above, like the edit/delete pairs.'
        );
    }
}
