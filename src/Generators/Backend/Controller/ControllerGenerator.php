<?php

namespace Blutrixx\GeneratorEngine\Generators\Backend\Controller;

use Blutrixx\GeneratorEngine\Generators\BaseGenerator;
use Blutrixx\GeneratorEngine\Generators\PatchesRegions;
use Blutrixx\GeneratorEngine\Generators\PathManager;
use Blutrixx\GeneratorEngine\Helpers\ActionServiceInvocation;
use Blutrixx\GeneratorEngine\Schema\ModuleConfigContract;

class ControllerGenerator extends BaseGenerator
{
    use PatchesRegions;

    /** Region wrapping delegation/action service imports — see addDelegationMethods()/addActionMethods(). */
    private const CUSTOM_IMPORTS_REGION = 'custom-imports';
    /** Region wrapping delegation/action controller methods — see addDelegationMethods()/addActionMethods(). */
    private const CUSTOM_METHODS_REGION = 'custom-methods';
    /**
     * Region for hand-written `use` lines that must survive --force verbatim
     * (engine v3.5.17) — see the identical rationale on RoutesGenerator::
     * HAND_ROUTES_REGION. Applies to ANY generated `use` line, not just the
     * ones inside custom-imports: the standard per-feature service imports
     * live outside any region entirely, but a hand-imports collision still
     * omits them, same as a standard route can be shadowed by hand-routes.
     */
    private const HAND_IMPORTS_REGION = 'hand-imports';
    /**
     * Region for hand-written controller methods that must survive --force
     * verbatim (engine v3.5.17). Applies to ANY generated method (standard
     * feature, export/import, splash, or delegation/action), matched by
     * method NAME alone — there is no verb/path here, just one class per
     * file, so a name collision is unambiguous.
     */
    private const HAND_METHODS_REGION = 'hand-methods';

    protected array $features;
    protected array $delegations;
    protected array $actions;

    public function __construct(string $moduleName, string $moduleGroup = 'Core', array $config = [])
    {
        parent::__construct($moduleName, $moduleGroup, $config);

        // Detect enabled features strictly from new payload: features.backend,
        // using the exact same enablement rule RoutesGenerator uses (see
        // resolveEnabledBackendFeatures() below) so a module's controller
        // methods and its routes can never silently diverge -- previously
        // this constructor hardcoded all six standard features as always
        // "enabled" and only consulted $backendFeatures for the splash
        // check below, so e.g. a module with 'create' disabled in
        // features.backend still got a dead create{Module}() method with no
        // route pointing at it.
        $this->features = [];
        $backendFeatures = $config['features']['backend'] ?? [];
        $hasSplash = !empty($config['constants']);
        foreach (self::resolveEnabledBackendFeatures($backendFeatures, $hasSplash) as $featureName => $enabled) {
            $this->features[$featureName] = [true];
        }

        $this->delegations = $config['delegations'] ?? [];
        $this->actions = $config['actions'] ?? [];
    }

    /**
     * Resolve which standard backend features (list, create, view, edit,
     * delete, deleteCheck, createSplash, editSplash) are enabled from the
     * raw features.backend config block.
     *
     * MUST stay byte-for-byte identical to the equivalent inline logic in
     * RoutesGenerator::__construct()
     * (src/Generators/Backend/Routes/RoutesGenerator.php) -- these two
     * generators derive which controller methods vs. which routes to emit
     * from the very same config, and any drift between the two produces a
     * controller method with no route pointing at it (or a route pointing
     * at a controller method that doesn't exist). Kept as a local, private
     * copy rather than extracted to BaseGenerator/a shared trait because
     * this fix's file scope is restricted to ControllerGenerator.php; if
     * RoutesGenerator's copy is ever edited again, this one must be updated
     * to match -- ControllerGeneratorTest's drift-guard test asserts the
     * two generators' outputs stay in lockstep for the same config, so any
     * future divergence here fails loudly instead of shipping silently.
     *
     * Rules:
     * - A standard feature is enabled iff its own key is present in
     *   $backendFeatures (isset()).
     * - 'deleteCheck' additionally follows 'delete': enabled whenever
     *   'delete' is configured, even without its own key.
     * - 'createSplash' / 'editSplash' are opt-in twice over: $hasSplash
     *   (the caller gates this on !empty($config['constants'])) AND their
     *   own key present in $backendFeatures.
     *
     * @param array<string, mixed> $backendFeatures
     * @return array<string, true> enabled feature name => true, in the
     *         canonical order: list, create, view, edit, delete,
     *         createSplash, editSplash, deleteCheck.
     */
    private static function resolveEnabledBackendFeatures(array $backendFeatures, bool $hasSplash): array
    {
        $enabled = [];
        foreach (['list', 'create', 'view', 'edit', 'delete', 'createSplash', 'editSplash', 'deleteCheck'] as $featureName) {
            // Skip splash features unless constants are declared
            if (in_array($featureName, ['createSplash', 'editSplash'], true) && !$hasSplash) {
                continue;
            }
            if (isset($backendFeatures[$featureName]) || ($featureName === 'deleteCheck' && isset($backendFeatures['delete']))) {
                $enabled[$featureName] = true;
            }
        }
        return $enabled;
    }

    public function generate(): bool
    {
        $filePath = "{$this->modulePath}/{$this->moduleName}Controller.php";

        // Half-present markers (Design rule 6, engine v3.5.17) — see the
        // identical rationale on RoutesGenerator::generate(). Checked across
        // all four regions this generator owns.
        $existingContent = null;
        if ($this->force && is_file($filePath)) {
            $existingContent = file_get_contents($filePath);
            foreach ([self::CUSTOM_IMPORTS_REGION, self::CUSTOM_METHODS_REGION, self::HAND_IMPORTS_REGION, self::HAND_METHODS_REGION] as $region) {
                if ($this->regionMarkerCount($existingContent, $region) === 1) {
                    PathManager::reportIssue("{$filePath}: region {$region} has only one marker; not regenerating this file");

                    return false;
                }
            }
        }

        return $this->writeFile($filePath, $this->buildContent($existingContent));
    }

    /**
     * Render this module's complete Controller.php as a string, without
     * writing it. Mirrors RoutesGenerator::buildContent()'s split for the
     * same reason: called with no argument this always returns exactly a
     * fresh file's output (empty hand-* regions, parity); given an existing
     * file's bytes (engine v3.5.17), it migrates anything in custom-imports/
     * custom-methods that no longer matches what module.json currently
     * generates into hand-imports/hand-methods with a warning, then lets a
     * hand-owned method name or import statement win over a freshly
     * generated one with the same name/statement — silently when it is a
     * byte-for-byte identical copy, with a warning otherwise. See
     * HAND_METHODS_REGION/HAND_IMPORTS_REGION above and PatchesRegions.
     */
    public function buildContent(?string $existingContent = null): string
    {
        $content = $this->getTemplateContent('controller', 'backend');

        // Standard feature methods — one block per feature, so a hand
        // override of just one never disturbs the rest.
        $standardMethodBlocks = [];
        $processedFeatures = [];
        foreach ($this->features as $feature => $columns) {
            if (in_array($feature, $processedFeatures, true)) {
                continue;
            }
            $processedFeatures[] = $feature;
            if (!in_array($feature, ['createSplash', 'editSplash'], true)) {
                $standardMethodBlocks[] = $this->generateControllerMethod($feature);
            }
        }
        // Generate splash methods if their own feature key is enabled.
        // Matches RoutesGenerator, which does not additionally require
        // 'create'/'edit' itself to be enabled -- $this->features already
        // only contains 'createSplash'/'editSplash' when $hasSplash AND
        // their own backendFeatures key are both present (see
        // resolveEnabledBackendFeatures() above).
        if (isset($this->features['createSplash'])) {
            $standardMethodBlocks[] = $this->generateControllerMethod('createSplash');
        }
        if (isset($this->features['editSplash'])) {
            $standardMethodBlocks[] = $this->generateControllerMethod('editSplash');
        }

        // Generate export/import methods if enabled in list config
        $listConfig = $this->config['features']['backend']['list'] ?? null;
        if (!empty($listConfig)) {
            if (!empty($listConfig['export'])) {
                $standardMethodBlocks[] = $this->generateExportMethod();
            }
            if (!empty($listConfig['import'])) {
                $standardMethodBlocks[] = $this->generateImportTemplateMethod();
                $standardMethodBlocks[] = $this->generateImportMethod();
            }
        }

        // Delegation and action methods — what has always lived inside
        // custom-methods, wrapped unconditionally (even when empty) so
        // every freshly generated module already carries the markers
        // make:delegation/make:action need to append a method later via
        // addDelegationMethods()/addActionMethods() — see PatchesRegions. A
        // module generated by an older engine version that predates this
        // region self-heals the markers in on first incremental use instead.
        $customMethods = [];
        foreach ($this->delegations as $delegationKey => $delegation) {
            $customMethods[] = $this->generateDelegationMethods($delegationKey, $delegation);
        }
        foreach ($this->actions as $actionKey => $action) {
            $customMethods[] = $this->generateActionMethods($actionKey, $action);
        }
        // trim(..., "\n") only, not a full trim() — a full trim() would eat
        // the first method's leading 4-space indent along with the outer
        // blank lines, leaving it flush against the left margin while every
        // other method in the block stays properly indented.
        $customMethodsBlock = trim(implode("\n\n", array_filter($customMethods)), "\n");

        // Build all use statements
        $servicesNs = $this->getNamespace() . "\\Services";
        $uses = [];
        $uses[] = "use App\\Project\\_Src\\Traits\\HasActivityHistory;";

        // Standard service imports — stay unmarked/static, never change incrementally, unlike the
        // delegation/action imports.
        //
        // Derived from $this->features, the SAME resolution that decides which controller methods and routes
        // exist, not from a fixed list. It was a fixed list, so a module whose `delete` (or `edit`, ...) had been
        // deliberately removed from features.backend still got `use ...OrdersDeleteService;` on every regen:
        // the method and the route stayed gone but the import for a class that no longer exists on disk came
        // back. Harmless at runtime (PHP resolves a `use` only when the class is referenced) but dead code
        // creeping back into a file that had been cleaned up. ActivityList is not a feature: the
        // HasActivityHistory trait always needs it. Order is unchanged, so a fully-featured module's output is
        // byte-for-byte what it was.
        $featureServices = [
            'list' => 'List', 'create' => 'Create', 'view' => 'View', 'edit' => 'Edit',
            'delete' => 'Delete', 'deleteCheck' => 'DeleteCheck',
        ];
        $standardServices = [];
        foreach ($featureServices as $feature => $svc) {
            if (isset($this->features[$feature])) {
                $standardServices[] = $svc;
            }
        }
        $standardServices[] = 'ActivityList';
        // Splash services: opt-in twice over (constants declared AND the feature key present), as for the methods.
        foreach (['createSplash' => 'CreateSplash', 'editSplash' => 'EditSplash'] as $feature => $svc) {
            if (isset($this->features[$feature])) {
                $standardServices[] = $svc;
            }
        }
        foreach ($standardServices as $svc) {
            $uses[] = "use {$servicesNs}\\{$this->moduleName}{$svc}Service;";
        }
        $usesBlock = implode("\n", array_unique($uses));

        // Delegation and action service imports — what has always lived
        // inside custom-imports, same rationale as the methods region above
        // — reuses the exact same line-building helpers
        // addDelegationMethods()/addActionMethods() call incrementally, so
        // the two paths can never drift apart on what an import line looks
        // like.
        $customImports = [];
        foreach ($this->delegations as $delegationKey => $delegation) {
            $customImports[] = $this->generateDelegationImport($delegationKey, $delegation);
        }
        foreach ($this->actions as $actionKey => $action) {
            $customImports[] = $this->generateActionImport($actionKey, $action);
        }
        $customImportsBlock = implode("\n", array_unique(array_filter($customImports)));

        // --- engine v3.5.17: hand-methods/hand-imports migration + hand-wins filtering ---

        $freshMethodSignatures = array_map(
            fn (array $chunk): string => $this->codeSignature($chunk['text']),
            array_merge(
                $this->splitClassMembers(implode("\n\n", $standardMethodBlocks)),
                $this->splitClassMembers($customMethodsBlock),
            ),
        );
        $freshImportSignatures = array_map(
            fn (string $chunk): string => $this->codeSignature($chunk),
            array_merge(
                $this->splitPhpStatements($usesBlock),
                $this->splitPhpStatements($customImportsBlock),
            ),
        );

        $existingCustomMethodsInner = $existingContent !== null ? $this->extractRegion($existingContent, self::CUSTOM_METHODS_REGION) : null;
        $existingHandMethodsInner = $existingContent !== null ? $this->extractRegion($existingContent, self::HAND_METHODS_REGION) : null;
        $existingCustomImportsInner = $existingContent !== null ? $this->extractRegion($existingContent, self::CUSTOM_IMPORTS_REGION) : null;
        $existingHandImportsInner = $existingContent !== null ? $this->extractRegion($existingContent, self::HAND_IMPORTS_REGION) : null;

        $issues = [];

        $migratedMethods = [];
        if ($existingCustomMethodsInner !== null && trim($existingCustomMethodsInner) !== '') {
            [$migratedMethods, $methodMigrationWarning] = $this->migrateMembersToHand($existingCustomMethodsInner, $freshMethodSignatures);
            if ($methodMigrationWarning !== null) {
                $issues[] = $methodMigrationWarning;
            }
        }
        $handMethodsInnerParts = array_values(array_filter(
            array_merge(
                [$this->normalizeRegionText($existingHandMethodsInner ?? '')],
                array_map(fn (array $chunk): string => $this->normalizeRegionText($chunk['text']), $migratedMethods),
            ),
            static fn (string $part): bool => $part !== '',
        ));
        $handMethodsInner = implode("\n\n", $handMethodsInnerParts);

        $migratedImports = [];
        if ($existingCustomImportsInner !== null && trim($existingCustomImportsInner) !== '') {
            [$migratedImports, $importMigrationWarning] = $this->migrateStatementsToHand($existingCustomImportsInner, $freshImportSignatures);
            if ($importMigrationWarning !== null) {
                $issues[] = $importMigrationWarning;
            }
        }
        $handImportsInnerParts = array_values(array_filter(
            array_merge(
                [$this->normalizeRegionText($existingHandImportsInner ?? '')],
                array_map(fn (string $chunk): string => $this->normalizeRegionText($chunk), $migratedImports),
            ),
            static fn (string $part): bool => $part !== '',
        ));
        $handImportsInner = implode("\n", $handImportsInnerParts);

        $handMethodsByName = $this->indexHandMethods($handMethodsInner);
        $handImportSignatures = $this->indexHandImportSignatures($handImportsInner);

        [$standardMethodsOut, $standardMethodIssues] = $this->filterMethodBlocks($standardMethodBlocks, $handMethodsByName);
        [$customMethodsOut, $customMethodIssues] = $this->filterMethodBlock($customMethodsBlock, $handMethodsByName);
        $issues = array_merge($issues, $standardMethodIssues, $customMethodIssues);

        $usesBlockFiltered = $this->filterImportBlock($usesBlock, $handImportSignatures);
        $customImportsBlockFiltered = $this->filterImportBlock($customImportsBlock, $handImportSignatures);

        foreach ($issues as $issue) {
            PathManager::reportIssue($issue);
        }

        $methods = $standardMethodsOut;

        $customMethodsTrimmed = trim($customMethodsOut, "\n");
        $methods[] = '    // [generator:region:' . self::CUSTOM_METHODS_REGION . ":start]\n"
            . ($customMethodsTrimmed !== '' ? $customMethodsTrimmed . "\n" : '')
            . '    // [generator:region:' . self::CUSTOM_METHODS_REGION . ':end]';
        $methods[] = $this->renderRegion(self::HAND_METHODS_REGION, $handMethodsInner, '    ');

        $customImportsRegion = '// [generator:region:' . self::CUSTOM_IMPORTS_REGION . ":start]\n"
            . ($customImportsBlockFiltered !== '' ? $customImportsBlockFiltered . "\n" : '')
            . '// [generator:region:' . self::CUSTOM_IMPORTS_REGION . ':end]';
        $handImportsRegion = $this->renderRegion(self::HAND_IMPORTS_REGION, $handImportsInner, '');

        $content = str_replace(
            "use App\Http\Controllers\Controller;",
            "use App\Http\Controllers\Controller;\n{$usesBlockFiltered}\n{$customImportsRegion}\n{$handImportsRegion}",
            $content
        );

        $activityTrait = "    use HasActivityHistory;\n\n    protected string \$activityServiceClass = {$this->moduleName}ActivityListService::class;\n\n";

        $content = $this->replacePlaceholders($content, [
            '[[methods]]' => $activityTrait . implode("\n\n", $methods)
        ]);

        return $content;
    }

    /**
     * Split an existing custom-methods region's inner text into class
     * members and decide, per member, whether module.json still generates
     * it (engine v3.5.17, Design rule 2). Same rationale as
     * RoutesGenerator::migrateRouteChunksToHand() — a member that is
     * uncommented AND whose codeSignature() matches one of today's fresh
     * chunks needs no action; everything else is migrated into hand-methods
     * with a warning naming every migrated method.
     *
     * @param list<string> $freshSignatures
     * @return array{0: list<array{name: ?string, text: string}>, 1: ?string}
     */
    private function migrateMembersToHand(string $existingCustomMethodsInner, array $freshSignatures): array
    {
        $migrated = [];
        $labels = [];

        foreach ($this->splitClassMembers($existingCustomMethodsInner) as $chunk) {
            if (!$this->hasComment($chunk['text']) && in_array($this->codeSignature($chunk['text']), $freshSignatures, true)) {
                continue;
            }

            $migrated[] = $chunk;
            $labels[] = $chunk['name'] !== null ? "{$chunk['name']}()" : $this->truncatedSignature($chunk['text']);
        }

        if (empty($migrated)) {
            return [[], null];
        }

        $filePath = "{$this->modulePath}/{$this->moduleName}Controller.php";
        $warning = "{$filePath}: moved into hand-methods (differs from what module.json generates now): "
            . implode(', ', $labels)
            . '. If one is a stale copy of a delegation/action you removed or changed in module.json, delete it from hand-methods so the change applies.';

        return [$migrated, $warning];
    }

    /**
     * Same as migrateMembersToHand(), for the custom-imports region — a
     * flat list of `use` statements rather than class members, so the label
     * is simply the statement itself (Design's "the statement for imports").
     *
     * @param list<string> $freshSignatures
     * @return array{0: list<string>, 1: ?string}
     */
    private function migrateStatementsToHand(string $existingCustomImportsInner, array $freshSignatures): array
    {
        $migrated = [];
        $labels = [];

        foreach ($this->splitPhpStatements($existingCustomImportsInner) as $chunk) {
            if (!$this->hasComment($chunk) && in_array($this->codeSignature($chunk), $freshSignatures, true)) {
                continue;
            }

            $migrated[] = $chunk;
            $labels[] = trim($chunk);
        }

        if (empty($migrated)) {
            return [[], null];
        }

        $filePath = "{$this->modulePath}/{$this->moduleName}Controller.php";
        $warning = "{$filePath}: moved into hand-imports (differs from what module.json generates now): "
            . implode(', ', $labels)
            . '. If one is a stale copy of a delegation/action you removed or changed in module.json, delete it from hand-imports so the change applies.';

        return [$migrated, $warning];
    }

    /** @return array<string, string> method name => codeSignature of its hand-methods copy */
    private function indexHandMethods(string $handMethodsInner): array
    {
        $byName = [];
        if ($handMethodsInner === '') {
            return $byName;
        }

        foreach ($this->splitClassMembers($handMethodsInner) as $chunk) {
            if ($chunk['name'] === null) {
                continue;
            }
            $byName[$chunk['name']] = $this->codeSignature($chunk['text']);
        }

        return $byName;
    }

    /** @return array<string, true> a set of every hand-imports statement's codeSignature */
    private function indexHandImportSignatures(string $handImportsInner): array
    {
        $signatures = [];
        if ($handImportsInner === '') {
            return $signatures;
        }

        foreach ($this->splitPhpStatements($handImportsInner) as $chunk) {
            $signatures[$this->codeSignature($chunk)] = true;
        }

        return $signatures;
    }

    /**
     * Apply the hand-wins filter (Design rule 3) to every one of several
     * same-origin method blocks (the standard feature/export/import/splash
     * methods), dropping a block from the output entirely once its one
     * method was omitted.
     *
     * @param list<string> $blocks
     * @param array<string, string> $handMethodsByName
     * @return array{0: list<string>, 1: list<string>}
     */
    private function filterMethodBlocks(array $blocks, array $handMethodsByName): array
    {
        $out = [];
        $issues = [];

        foreach ($blocks as $block) {
            [$filtered, $blockIssues] = $this->filterMethodBlock($block, $handMethodsByName);
            if (trim($filtered) !== '') {
                $out[] = $filtered;
            }
            $issues = array_merge($issues, $blockIssues);
        }

        return [$out, $issues];
    }

    /**
     * Apply the hand-wins filter (Design rule 3) to one block of generated
     * methods, by name. Byte-identical to the input when nothing collides
     * (Design rule 5, parity); otherwise rebuilt from the surviving members,
     * normalize()d and joined by "\n\n" (Emission bytes).
     *
     * @param array<string, string> $handMethodsByName
     * @return array{0: string, 1: list<string>}
     */
    private function filterMethodBlock(string $block, array $handMethodsByName): array
    {
        if (trim($block) === '') {
            return ['', []];
        }

        $chunks = $this->splitClassMembers($block);
        $survivors = [];
        $issues = [];
        $omittedAny = false;

        foreach ($chunks as $chunk) {
            if ($chunk['name'] === null || !isset($handMethodsByName[$chunk['name']])) {
                $survivors[] = $chunk['text'];
                continue;
            }

            $omittedAny = true;
            $handSignature = $handMethodsByName[$chunk['name']];
            if ($this->codeSignature($chunk['text']) === $handSignature) {
                continue; // identical copy -- silent
            }

            $filePath = "{$this->modulePath}/{$this->moduleName}Controller.php";
            $issues[] = "{$filePath}: hand-methods defines {$chunk['name']}(); omitted the generated {$chunk['name']}(). Delete it from hand-methods to use the module.json version.";
        }

        if (!$omittedAny) {
            return [$block, []];
        }

        $rejoined = implode("\n\n", array_map(fn (string $chunk): string => $this->normalizeRegionText($chunk), $survivors));

        return [$rejoined, $issues];
    }

    /**
     * Apply the hand-wins filter to one block of `use` statements. Imports
     * never warn (Design rule 3) — a duplicate import is harmless and the
     * whole point of hand-imports is "this exact line already lives
     * elsewhere", so there is nothing a developer needs to act on.
     *
     * @param array<string, true> $handImportSignatures
     */
    private function filterImportBlock(string $block, array $handImportSignatures): string
    {
        if (trim($block) === '') {
            return $block;
        }

        $chunks = $this->splitPhpStatements($block);
        $survivors = [];
        $omittedAny = false;

        foreach ($chunks as $chunk) {
            if (isset($handImportSignatures[$this->codeSignature($chunk)])) {
                $omittedAny = true;
                continue;
            }
            $survivors[] = $chunk;
        }

        if (!$omittedAny) {
            return $block;
        }

        return implode("\n", array_map(fn (string $chunk): string => $this->normalizeRegionText($chunk), $survivors));
    }

    /** normalize($s) from the hand-region Emission bytes spec — strips leading blank lines and trailing whitespace. */
    private function normalizeRegionText(string $s): string
    {
        return rtrim((string) preg_replace('/\A(?:[ \t]*\n)+/', '', $s));
    }

    /** Fallback label (Design: "else the first 60 chars of the signature") for a chunk with no parseable name. */
    private function truncatedSignature(string $chunk): string
    {
        $signature = $this->codeSignature($chunk);

        return strlen($signature) > 60 ? substr($signature, 0, 60) : $signature;
    }

    /**
     * Append one delegation's controller method(s) and its service import to
     * an already-generated module's Controller — inside the custom-imports/
     * custom-methods regions generate() wraps in markers. Additive and
     * idempotent: never touches anything outside those regions, and
     * re-running for the same delegation only adds whatever operations
     * weren't already wired (see addCustomMethod()'s per-method guard).
     *
     * Self-heals a Controller generated before these regions existed.
     *
     * @throws \RuntimeException if the module was never generated (no
     *         Controller file to patch).
     */
    public function addDelegationMethods(string $delegationKey, array $delegation): bool
    {
        $importAdded = $this->addCustomImport($this->generateDelegationImport($delegationKey, $delegation));
        $methodAdded = $this->addCustomMethod($this->generateDelegationMethods($delegationKey, $delegation));

        return $importAdded || $methodAdded;
    }

    /** @see addDelegationMethods() — identical contract, for actions[] instead. */
    public function addActionMethods(string $actionKey, array $action): bool
    {
        $importAdded = $this->addCustomImport($this->generateActionImport($actionKey, $action));
        $methodAdded = $this->addCustomMethod($this->generateActionMethods($actionKey, $action));

        return $importAdded || $methodAdded;
    }

    /** Builds one delegation's service `use` line. Shared by generate() and addDelegationMethods() so the two can never drift apart. */
    protected function generateDelegationImport(string $delegationKey, array $delegation): string
    {
        $delegationName = \Illuminate\Support\Str::studly($delegation['name'] ?? $delegationKey);
        $servicesNs = $this->getNamespace() . "\\Services";

        return "use {$servicesNs}\\{$this->moduleName}{$delegationName}Service;";
    }

    /** Builds one action's service `use` line. Shared by generate() and addActionMethods() so the two can never drift apart. */
    protected function generateActionImport(string $actionKey, array $action): string
    {
        // Same base generateActionMethods()/RoutesGenerator::generateActionRoutes() resolve —
        // see plans/038.
        $serviceNameRaw = $this->resolveActionServiceNameRaw($actionKey, $action);
        $servicesNs = $this->getNamespace() . "\\Services";

        $imports = ["use {$servicesNs}\\{$this->moduleName}{$serviceNameRaw}Service;"];

        // An action with opt-in splash also needs its splash service imported — the method emitted
        // by generateActionMethods() references it by short name.
        if (!empty($action['splash'])) {
            $imports[] = "use {$servicesNs}\\{$this->moduleName}{$serviceNameRaw}SplashService;";
        }

        return implode("\n", $imports);
    }

    private function addCustomImport(string $importLine): bool
    {
        $importLine = trim($importLine);
        if ($importLine === '') {
            return false;
        }

        $filePath = "{$this->modulePath}/{$this->moduleName}Controller.php";
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Cannot add an import to {$filePath} — module was never generated. Run make:module first.");
        }

        $this->ensureCustomImportsRegion($filePath);

        $existing = $this->getRegionContent($filePath, self::CUSTOM_IMPORTS_REGION) ?? '';
        if ($existing !== '' && str_contains($existing, $importLine)) {
            return false; // already imported — idempotent no-op
        }

        // Design rule 4 (engine v3.5.17): a statement hand-imports already
        // owns is never appended to custom-imports either — see the
        // identical rationale on RoutesGenerator::addCustomRoute().
        $handInner = $this->getRegionContent($filePath, self::HAND_IMPORTS_REGION) ?? '';
        if (isset($this->indexHandImportSignatures($handInner)[$this->codeSignature($importLine)])) {
            return false;
        }

        $newContent = trim($existing) === '' ? $importLine : rtrim($existing) . "\n" . $importLine;

        return $this->patchRegion($filePath, self::CUSTOM_IMPORTS_REGION, rtrim($newContent));
    }

    private function addCustomMethod(string $methodBody): bool
    {
        if (trim($methodBody) === '') {
            return false; // no operations enabled — nothing to add
        }

        $filePath = "{$this->modulePath}/{$this->moduleName}Controller.php";
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Cannot add a method to {$filePath} — module was never generated. Run make:module first.");
        }

        $this->ensureCustomMethodsRegion($filePath);

        $existing = $this->getRegionContent($filePath, self::CUSTOM_METHODS_REGION) ?? '';

        // Design rule 4 (engine v3.5.17): a method name hand-methods already
        // owns is never appended to custom-methods either — see the
        // identical rationale on RoutesGenerator::addCustomRoute().
        $handInner = $this->getRegionContent($filePath, self::HAND_METHODS_REGION) ?? '';
        $handMethodsByName = $this->indexHandMethods($handInner);

        // Per-method, not whole-block: generateDelegationMethods()/
        // generateActionMethods() can return several method definitions (one
        // per enabled operation), and re-running after a delegation/action
        // gains a newly-enabled operation must add only the new method(s) —
        // a whole-block match would never match on a second call once even
        // one method already differs, duplicating everything already there.
        //
        // trim(..., "\n") only when splitting, and rtrim() (not trim()) on
        // each piece kept — a full trim() would strip every method's
        // leading 4-space indent, not just the outer edges of the block.
        $newMethods = [];
        foreach (preg_split('/\n\n(?=\s*public function)/', trim($methodBody, "\n")) as $singleMethod) {
            if (trim($singleMethod) === '') {
                continue;
            }
            preg_match('/public function (\w+)\(/', $singleMethod, $m);
            $methodName = $m[1] ?? null;
            if ($methodName !== null && str_contains($existing, "public function {$methodName}(")) {
                continue; // already added
            }
            if ($methodName !== null && isset($handMethodsByName[$methodName])) {
                continue; // hand-methods already owns this name
            }
            $newMethods[] = rtrim($singleMethod);
        }

        if (empty($newMethods)) {
            return false; // every method in this block is already present
        }

        $toAppend = implode("\n\n", $newMethods);
        $newContent = trim($existing) === '' ? $toAppend : rtrim($existing) . "\n\n" . $toAppend;

        return $this->patchRegion($filePath, self::CUSTOM_METHODS_REGION, rtrim($newContent));
    }

    /**
     * Insert an empty custom-imports region right after the
     * `use App\Http\Controllers\Controller;` line of a Controller generated
     * before this region existed. Falls back to inserting after the last
     * `use` statement if that exact line isn't found, or right after the
     * opening `<?php` tag if there are no `use` statements at all.
     */
    private function ensureCustomImportsRegion(string $filePath): void
    {
        if ($this->getRegionContent($filePath, self::CUSTOM_IMPORTS_REGION) !== null) {
            return;
        }

        $fileContent = file_get_contents($filePath);
        $marker = '// [generator:region:' . self::CUSTOM_IMPORTS_REGION . ":start]\n"
            . '// [generator:region:' . self::CUSTOM_IMPORTS_REGION . ":end]\n";

        $anchor = 'use App\Http\Controllers\Controller;';
        if (str_contains($fileContent, $anchor)) {
            $fileContent = str_replace($anchor, $anchor . "\n" . rtrim($marker), $fileContent);
        } elseif (preg_match_all('/^use .+;$/m', $fileContent, $m, PREG_OFFSET_CAPTURE) && !empty($m[0])) {
            $lastUse = end($m[0]);
            $insertAt = $lastUse[1] + strlen($lastUse[0]);
            $fileContent = substr($fileContent, 0, $insertAt) . "\n" . rtrim($marker) . substr($fileContent, $insertAt);
        } else {
            $fileContent = preg_replace('/^<\?php\s*/', "<?php\n\n" . rtrim($marker) . "\n\n", $fileContent, 1);
        }

        file_put_contents($filePath, $fileContent);
    }

    /**
     * Insert an empty custom-methods region immediately before the class's
     * closing `}` of a Controller generated before this region existed.
     * Every sampled real controller in this codebase ends with the class's
     * closing brace as the file's final `}` — a safe, universal anchor.
     */
    private function ensureCustomMethodsRegion(string $filePath): void
    {
        if ($this->getRegionContent($filePath, self::CUSTOM_METHODS_REGION) !== null) {
            return;
        }

        $fileContent = file_get_contents($filePath);
        $lastBrace = strrpos($fileContent, '}');
        if ($lastBrace === false) {
            throw new \RuntimeException("Cannot locate the class's closing brace in {$filePath} to insert delegation/action methods.");
        }

        $marker = "\n    // [generator:region:" . self::CUSTOM_METHODS_REGION . ":start]\n"
            . "    // [generator:region:" . self::CUSTOM_METHODS_REGION . ":end]\n";

        $fileContent = substr($fileContent, 0, $lastBrace) . $marker . substr($fileContent, $lastBrace);
        file_put_contents($filePath, $fileContent);
    }

    protected function generateControllerMethod(string $feature): string
    {
        $content = $this->getTemplateContent("Features/{$feature}/controller_method", 'backend');
        return $this->replacePlaceholders($content);
    }

    protected function generateDelegationMethods(string $delegationKey, array $delegation): string
    {
        $methods = [];
        $delegationName = \Illuminate\Support\Str::studly($delegation['name'] ?? $delegationKey);
        // shelui-engine fork: must resolve identically to RoutesGenerator::
        // generateDelegationRoutes()'s own parentKey default (see its
        // docblock) -- the route segment name and this controller method's
        // parameter name have to match exactly, or Laravel can never bind
        // the URL segment to the method argument.
        $parentKey = $delegation['parentKey'] ?? (ModuleConfigContract::hasUuid($this->config) ? 'uuid' : 'id');
        $operations = $delegation['operations'] ?? [];

        foreach (['list', 'create', 'edit', 'view', 'delete'] as $op) {
            if (empty($operations[$op]['enabled'])) {
                continue;
            }

            $stub = $this->getTemplateContent("Features/delegation/controller_method_{$op}", 'backend');
            $methods[] = $this->replacePlaceholders($stub, [
                '[[DelegationName]]' => $delegationName,
                '[[parentKey]]' => $parentKey,
            ]);
        }

        // deleteCheck piggybacks on delete being enabled — same convention
        // native deleteCheck already uses relative to native delete.
        if (!empty($operations['delete']['enabled'])) {
            $stub = $this->getTemplateContent('Features/delegation/controller_method_deleteCheck', 'backend');
            $methods[] = $this->replacePlaceholders($stub, [
                '[[DelegationName]]' => $delegationName,
                '[[parentKey]]' => $parentKey,
            ]);
        }

        // export/bulkAction/import: nested under list's own backend config,
        // not top-level operation keys — same gating RoutesGenerator::
        // generateDelegationRoutes() uses for these three.
        $listOp = $operations['list'] ?? [];
        if (!empty($listOp['enabled'])) {
            $listBackend = $listOp['backend'] ?? [];

            if (!empty($listBackend['export'])) {
                $stub = $this->getTemplateContent('Features/delegation/controller_method_export', 'backend');
                $methods[] = $this->replacePlaceholders($stub, [
                    '[[DelegationName]]' => $delegationName,
                    '[[parentKey]]' => $parentKey,
                ]);
            }

            if (!empty($listBackend['bulk_actions'])) {
                $stub = $this->getTemplateContent('Features/delegation/controller_method_bulkAction', 'backend');
                $methods[] = $this->replacePlaceholders($stub, [
                    '[[DelegationName]]' => $delegationName,
                    '[[parentKey]]' => $parentKey,
                ]);
            }

            if (!empty($listBackend['import'])) {
                $stub = $this->getTemplateContent('Features/delegation/controller_method_importTemplate', 'backend');
                $methods[] = $this->replacePlaceholders($stub, [
                    '[[DelegationName]]' => $delegationName,
                    '[[parentKey]]' => $parentKey,
                ]);

                $stub = $this->getTemplateContent('Features/delegation/controller_method_import', 'backend');
                $methods[] = $this->replacePlaceholders($stub, [
                    '[[DelegationName]]' => $delegationName,
                    '[[parentKey]]' => $parentKey,
                ]);
            }
        }

        return implode("\n\n", $methods);
    }

    protected function generateActionMethods(string $actionKey, array $action): string
    {
        // Resolved BEFORE anything is loaded or written -- an invalid
        // serviceMethod/serviceArgs (e.g. a typo) must fail loudly here,
        // while generating, rather than producing a controller that calls
        // a method that doesn't exist (the NJIWA symptom this plan fixes).
        $invocation = ActionServiceInvocation::resolve($actionKey, $action);

        $methods = [];
        // Same base RoutesGenerator::generateActionRoutes() resolves — see plans/038: this
        // method used to compute its own copy of the strip-prefix/strip-suffix formula, and
        // the splash block below used it directly, ignoring methodName entirely.
        $serviceNameRaw = $this->resolveActionServiceNameRaw($actionKey, $action);
        $baseMethod = $this->resolveActionBaseMethod($actionKey, $action);

        $urlParamsArr = $action['urlParams'] ?? [];
        $urlParamsDecl = '';
        $urlParamsArgs = '';
        if (!empty($urlParamsArr)) {
            $urlParamsDecl = ', ' . implode(', ', array_map(fn($p) => "string \${$p}", $urlParamsArr));
            $urlParamsArgs = ', ' . implode(', ', array_map(fn($p) => "\${$p}", $urlParamsArr));
        }

        $stub = $this->getTemplateContent('Features/action/controller_method', 'backend');

        if ($invocation['declared'] && !str_contains($stub, '[[serviceMethod]]')) {
            PathManager::reportIssue(
                "{$this->moduleName} action '{$actionKey}': the Features/action/controller_method stub in use has no [[serviceMethod]] placeholder (a project override under stubs/generator/backend/?), so serviceMethod/serviceArgs are ignored — copy the placeholders from the engine stub into the override."
            );
        }

        // Opt-in splash endpoint for this action — see ActionSplashServiceGenerator.
        if (!empty($action['splash'])) {
            $splashStub = $this->getTemplateContent('Features/actionSplash/controller_method', 'backend');
            // shelui-engine fork: switched from a raw str_replace() to
            // replacePlaceholders() so this stub picks up [[routeKeyParam]]/
            // [[routeKeyLabel]] from the shared defaults (BaseGenerator::
            // replacePlaceholders()) -- a raw str_replace() bypassed them
            // entirely, so a has_uuid: false module's splash method still
            // hardcoded `string $uuid` regardless of the route it's actually
            // bound to (see RoutesGenerator::generateActionRoutes()'s splash
            // route, which already resolves the matching segment name).
            $methods[] = $this->replacePlaceholders($splashStub, [
                '[[methodName]]' => lcfirst($baseMethod),
                '[[ActionName]]' => $serviceNameRaw,
            ]);
        }

        foreach (['list', 'create', 'edit', 'view', 'delete'] as $op) {
            if (empty($action['operations'][$op]['enabled'])) {
                continue;
            }

            $methodName = $op === 'list' ? lcfirst($baseMethod) : $op . ucfirst($baseMethod);

            $methods[] = $this->replacePlaceholders($stub, [
                '[[methodName]]' => $methodName,
                '[[ActionName]]' => $serviceNameRaw,
                '[[urlParams]]' => $urlParamsDecl,
                '[[urlParamsArgs]]' => $urlParamsArgs,
                '[[serviceMethod]]' => $invocation['method'],
                '[[serviceArgs]]' => ActionServiceInvocation::controllerArguments($invocation),
            ]);
        }

        return implode("\n\n", $methods);
    }

    /** @deprecated Use generateDelegationMethods or generateActionMethods */
    protected function generateCustomFeatureMethods(string $featureKey, array $customFeature): string
    {
        $methods = [];
        $featureName = \Illuminate\Support\Str::studly($customFeature['name'] ?? $featureKey);
        $displayType = $customFeature['displayType'] ?? 'header-action';
        $backendFeatures = $customFeature['features']['backend'] ?? [];
        $enabledOps = $customFeature['enabledOperations'] ?? []; // Fallback for backward compatibility

        // Bare-endpoint: Support multiple operations (list, create, view, edit, delete)
        if ($displayType === 'bare-endpoint') {
            // Check each operation's enabled flag
            $listEnabled = isset($backendFeatures['list']['enabled']) 
                ? ($backendFeatures['list']['enabled'] ?? false)
                : ($enabledOps['list'] ?? true);
            $createEnabled = isset($backendFeatures['create']['enabled'])
                ? ($backendFeatures['create']['enabled'] ?? false)
                : ($enabledOps['create'] ?? false); // Default to false for bare endpoints
            $viewEnabled = isset($backendFeatures['view']['enabled'])
                ? ($backendFeatures['view']['enabled'] ?? false)
                : ($enabledOps['view'] ?? false);
            $editEnabled = isset($backendFeatures['edit']['enabled'])
                ? ($backendFeatures['edit']['enabled'] ?? false)
                : ($enabledOps['edit'] ?? false);
            $deleteEnabled = isset($backendFeatures['delete']['enabled'])
                ? ($backendFeatures['delete']['enabled'] ?? false)
                : ($enabledOps['delete'] ?? false);

            if ($listEnabled) {
                $methods[] = $this->generateCustomFeatureMethod($featureKey, $customFeature, 'list');
            }
            if ($createEnabled) {
                $methods[] = $this->generateCustomFeatureMethod($featureKey, $customFeature, 'create');
            }
            if ($viewEnabled) {
                $methods[] = $this->generateCustomFeatureMethod($featureKey, $customFeature, 'view');
            }
            if ($editEnabled) {
                $methods[] = $this->generateCustomFeatureMethod($featureKey, $customFeature, 'edit');
            }
            if ($deleteEnabled) {
                $methods[] = $this->generateCustomFeatureMethod($featureKey, $customFeature, 'delete');
            }
            return implode("\n\n", $methods);
        }

        // For tab-action and header-action: Generate methods based on enabled flags
        // Default to enabled if neither is explicitly set
        $listEnabled = isset($backendFeatures['list']['enabled']) 
            ? ($backendFeatures['list']['enabled'] ?? false)
            : ($enabledOps['list'] ?? true);
        $createEnabled = isset($backendFeatures['create']['enabled'])
            ? ($backendFeatures['create']['enabled'] ?? false)
            : ($enabledOps['create'] ?? true);
        $viewEnabled = isset($backendFeatures['view']['enabled'])
            ? ($backendFeatures['view']['enabled'] ?? false)
            : ($enabledOps['view'] ?? true);
        $editEnabled = isset($backendFeatures['edit']['enabled'])
            ? ($backendFeatures['edit']['enabled'] ?? false)
            : ($enabledOps['edit'] ?? true);
        $deleteEnabled = isset($backendFeatures['delete']['enabled'])
            ? ($backendFeatures['delete']['enabled'] ?? false)
            : ($enabledOps['delete'] ?? true);

        if ($listEnabled) {
            $methods[] = $this->generateCustomFeatureMethod($featureKey, $customFeature, 'list');
        }
        if ($createEnabled) {
            $methods[] = $this->generateCustomFeatureMethod($featureKey, $customFeature, 'create');
            
            // Generate splash method for custom features with create enabled
            $methods[] = $this->generateCustomFeatureSplashMethod($featureKey, $customFeature);
        }
        if ($viewEnabled) {
            $methods[] = $this->generateCustomFeatureMethod($featureKey, $customFeature, 'view');
        }
        if ($editEnabled) {
            $methods[] = $this->generateCustomFeatureMethod($featureKey, $customFeature, 'edit');
        }
        if ($deleteEnabled) {
            $methods[] = $this->generateCustomFeatureMethod($featureKey, $customFeature, 'delete');
        }

        return implode("\n\n", $methods);
    }

    protected function generateCustomFeatureMethod(string $featureKey, array $customFeature, string $operation): string
    {
        $content = $this->getTemplateContent("Features/custom/controller_method_{$operation}", 'backend');

        $featureName = \Illuminate\Support\Str::studly($customFeature['name'] ?? $featureKey);
        $displayType = $customFeature['displayType'] ?? 'header-action';
        
        // For bare-endpoint, method name should include operation unless it's list
        if ($displayType === 'bare-endpoint' && $operation !== 'list') {
            $baseMethodName = $customFeature['methodName'] ?? $featureName;
            $methodName = $operation . ucfirst($baseMethodName);
        } else {
            $methodName = $customFeature['methodName'] ?? $this->getCustomFeatureMethodName($featureName, $operation);
        }
        
        $serviceName = $customFeature['serviceName'] ?? $this->getCustomFeatureServiceName($featureName);
        $urlParams = '';
        $requestData = '';

        // Bare endpoint - support multiple operations with proper endpoint config per operation
        if ($displayType === 'bare-endpoint') {
            // Get endpoint config for this specific operation
            $operationConfig = $customFeature['features']['backend'][$operation] ?? [];
            $endpoint = $operationConfig['endpoint'] ?? [];
            $method = strtolower($endpoint['method'] ?? ($operation === 'list' || $operation === 'view' ? 'get' : 'post'));
            
            // Build URL params from urlParams array
            $urlParamsArray = $customFeature['urlParams'] ?? [];
            if (!empty($urlParamsArray)) {
                $params = array_map(fn($p) => "\${$p}", $urlParamsArray);
                $urlParams = ', ' . implode(', ', $params);
            }
            
            // For operations that need item ID (view, edit, delete), add uuid parameter
            if (in_array($operation, ['view', 'edit', 'delete'])) {
                $itemKey = ($customFeature['name'] ?? $featureKey) . 'Uuid';
                if (empty($urlParams)) {
                    $urlParams = ", \${$itemKey}";
                } else {
                    $urlParams .= ", \${$itemKey}";
                }
            }
            
            // Build request data based on operation type and URL params
            $requestDataParts = [];
            
            // Add URL params to request data
            if (!empty($urlParamsArray)) {
                foreach ($urlParamsArray as $param) {
                    $requestDataParts[] = "'{$param}' => \${$param}";
                }
            }
            
            // Add item UUID for view/edit/delete operations
            if (in_array($operation, ['view', 'edit', 'delete'])) {
                $itemKey = ($customFeature['name'] ?? $featureKey) . 'Uuid';
                $requestDataParts[] = "'uuid' => \${$itemKey}";
            }
            
            // Add query params for list operation
            if ($operation === 'list') {
                $requestDataParts[] = "'params' => \$request->query()";
            }
            
            // Build final request data string
            if ($method === 'get') {
                $requestData = '[' . implode(', ', $requestDataParts) . ']';
            } else {
                $requestData = "\$request->all()" . (!empty($requestDataParts) ? " + [" . implode(', ', $requestDataParts) . "]" : '');
            }
        }
        // Tab action with parent context
        else if ($displayType === 'tab-action') {
            $parentKey = $customFeature['parentKey'] ?? 'uuid';
            // Map parentKey to parent_uuid for service
            $parentUuidKey = 'parent_uuid';
            if ($operation === 'list' || $operation === 'create') {
                $urlParams = ", \${$parentKey}";
                $requestData = $operation === 'list'
                    ? "['{$parentUuidKey}' => \${$parentKey}, 'params' => \$request->query()]"
                    : "\$request->all() + ['{$parentUuidKey}' => \${$parentKey}]";
            } else {
                $itemKey = ($customFeature['name'] ?? $featureKey) . 'Uuid';
                $urlParams = ", \${$parentKey}, \${$itemKey}";
                $requestData = $operation === 'view'
                    ? "['{$parentUuidKey}' => \${$parentKey}, 'uuid' => \${$itemKey}]"
                    : "\$request->all() + ['{$parentUuidKey}' => \${$parentKey}, 'uuid' => \${$itemKey}]";
            }
        }
        // Header action (existing logic)
        else {
            $urlParams = ', $uuid';
            $requestData = "\$request->all() + ['parent_uuid' => \$uuid]";
        }

        // For bare-endpoint, service name should be full name (module + feature + Service)
        // The template expects just the feature name part, not the full service name
        $serviceNameForTemplate = $serviceName;
        if ($displayType === 'bare-endpoint') {
            // Template format: [[ModuleName]][[ServiceName]]Service
            // So we need just the feature name part
            $featureNameForService = \Illuminate\Support\Str::studly($customFeature['name'] ?? $featureKey);
            $serviceNameForTemplate = $featureNameForService;
        }

        return $this->replacePlaceholders($content, [
            '[[methodName]]' => $methodName,
            '[[ServiceName]]' => $serviceNameForTemplate,
            '[[FeatureName]]' => $featureName,
            '[[urlParams]]' => $urlParams,
            '[[requestData]]' => $requestData
        ]);
    }

    protected function getCustomFeatureMethodName(string $featureName, string $operation): string
    {
        $operationMap = [
            'list' => 'list' . $featureName,
            'create' => 'create' . $featureName,
            'edit' => 'edit' . $featureName,
            'view' => 'view' . $featureName,
            'delete' => 'delete' . $featureName
        ];

        return $operationMap[$operation] ?? $operation . $featureName;
    }

    protected function getCustomFeatureServiceName(string $featureName): string
    {
        return $featureName;
    }

    protected function generateCustomFeatureSplashMethod(string $featureKey, array $customFeature): string
    {
        $featureName = \Illuminate\Support\Str::studly($customFeature['name'] ?? $featureKey);
        $displayType = $customFeature['displayType'] ?? 'header-action';
        $methodName = ($customFeature['name'] ?? $featureKey) . 'Splash';
        
        // Get service name
        $serviceName = $customFeature['serviceName'] ?? '';
        if (empty($serviceName)) {
            $serviceName = $this->moduleName . $featureName . 'SplashService';
        } else {
            $serviceName = $this->moduleName . $serviceName . 'SplashService';
        }
        
        // Build method signature based on display type
        $params = '';
        $requestData = '';
        
        if ($displayType === 'tab-action') {
            // Tab-action: needs parent UUID parameter
            $parentKey = $customFeature['parentKey'] ?? 'uuid';
            $params = "Request \$request, \${$parentKey}";
            $requestData = "\${$parentKey}";
        } else {
            // Header-action: needs parent UUID if endpoint path includes {uuid}
            $backendFeatures = $customFeature['features']['backend'] ?? [];
            $createConfig = $backendFeatures['create'] ?? [];
            $endpointPath = $createConfig['endpoint']['path'] ?? '';
            
            if (strpos($endpointPath, '{uuid}') !== false) {
                $params = "Request \$request, \$uuid";
                $requestData = "\$uuid";
            } else {
                $params = "Request \$request";
                $requestData = '';
            }
        }
        
        $content = "    public function {$methodName}({$params})\n    {\n";
        if (!empty($requestData)) {
            $content .= "        \$result = {$serviceName}::execute(['parent_uuid' => {$requestData}]);\n";
        } else {
            $content .= "        \$result = {$serviceName}::execute();\n";
        }
        $content .= "        return response()->json(\$result, \$result['code']);\n    }";

        return $content;
    }

    private function generateExportMethod(): string
    {
        $name = $this->moduleName;
        $svc = "{$name}ListService";
        $routePath = \Illuminate\Support\Str::kebab($name);
        return <<<PHP
    /**
     * Export {$name} records to CSV, XLSX, or PDF.
     * GET /api/{$routePath}/list/export?format=csv
     */
    public function export{$name}(Request \$request): mixed
    {
        \$data = \$request->all();
        \$data['params']['paginate'] = false;
        return {$svc}::execute(\$data, export: true, format: \$request->get('format', 'csv'));
    }
PHP;
    }

    private function generateImportTemplateMethod(): string
    {
        $name = $this->moduleName;
        $svc = "{$name}ListService";
        $routePath = \Illuminate\Support\Str::kebab($name);
        return <<<PHP
    /**
     * Download import template with correct column headers.
     * GET /api/{$routePath}/import/template?format=csv
     */
    public function importTemplate{$name}(Request \$request): mixed
    {
        return {$svc}::getImportTemplate(\$request->get('format', 'csv'));
    }
PHP;
    }

    private function generateImportMethod(): string
    {
        $name = $this->moduleName;
        $svc = "{$name}ListService";
        $routePath = \Illuminate\Support\Str::kebab($name);
        return <<<PHP
    /**
     * Import {$name} records from uploaded CSV or XLSX.
     * POST /api/{$routePath}/import
     */
    public function import{$name}(Request \$request): \Illuminate\Http\JsonResponse
    {
        \$result = {$svc}::execute_import(\$request->all(), \$request->file('file'));
        return response()->json(\$result, \$result['code']);
    }
PHP;
    }
}

